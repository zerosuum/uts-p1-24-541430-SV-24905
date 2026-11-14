"""Prometheus metrics for containers in one Docker Compose project."""

import http.client
import json
import os
import socket
from concurrent.futures import ThreadPoolExecutor
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import quote


SOCKET_PATH = os.environ.get("DOCKER_SOCKET", "/var/run/docker.sock")
PROJECT = os.environ.get("COMPOSE_PROJECT", "mulaidulu")
PORT = int(os.environ.get("PORT", "9102"))


class DockerConnection(http.client.HTTPConnection):
    def __init__(self):
        super().__init__("localhost", timeout=5)

    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect(SOCKET_PATH)


def docker_get(path):
    connection = DockerConnection()
    try:
        connection.request("GET", path, headers={"Accept": "application/json"})
        response = connection.getresponse()
        body = response.read()
        if response.status != 200:
            raise RuntimeError(f"Docker API returned HTTP {response.status}")
        return json.loads(body)
    finally:
        connection.close()


def escape_label(value):
    return str(value).replace("\\", "\\\\").replace("\n", "\\n").replace('"', '\\"')


def collect():
    containers = docker_get("/containers/json?all=0")
    containers = [
        c for c in containers
        if c.get("Labels", {}).get("com.docker.compose.project") == PROJECT
    ]

    def read_stats(container):
        identifier = container["Id"]
        statistics = docker_get(f"/containers/{quote(identifier, safe='')}/stats?stream=false&one-shot=true")
        return container, statistics

    results = []
    if containers:
        with ThreadPoolExecutor(max_workers=min(12, len(containers))) as pool:
            for container, stats in pool.map(read_stats, containers):
                results.append((container, stats))

    output = [
        "# HELP mulaidulu_container_cpu_usage_seconds_total CPU time used by a Docker container in seconds.",
        "# TYPE mulaidulu_container_cpu_usage_seconds_total counter",
        "# HELP mulaidulu_container_memory_working_set_bytes Memory usage excluding inactive file cache in bytes.",
        "# TYPE mulaidulu_container_memory_working_set_bytes gauge",
        "# HELP mulaidulu_container_exporter_containers Number of running containers in this Compose project.",
        "# TYPE mulaidulu_container_exporter_containers gauge",
    ]

    for container, stats in sorted(results, key=lambda pair: pair[0].get("Names", [""])[0]):
        name = (container.get("Names") or [container["Id"][:12]])[0].lstrip("/")
        service = container["Labels"].get("com.docker.compose.service", "unknown")
        labels = f'container="{escape_label(name)}",service="{escape_label(service)}"'

        cpu_ns = stats.get("cpu_stats", {}).get("cpu_usage", {}).get("total_usage")
        if isinstance(cpu_ns, (int, float)) and cpu_ns >= 0:
            output.append(f"mulaidulu_container_cpu_usage_seconds_total{{{labels}}} {cpu_ns / 1_000_000_000:.9f}")

        memory = stats.get("memory_stats", {})
        usage = memory.get("usage")
        if isinstance(usage, (int, float)) and usage >= 0:
            inactive = (memory.get("stats") or {}).get("inactive_file", 0)
            if not isinstance(inactive, (int, float)):
                inactive = 0
            working_set = max(0, usage - inactive)
            output.append(f"mulaidulu_container_memory_working_set_bytes{{{labels}}} {working_set:.0f}")

    output.append(f"mulaidulu_container_exporter_containers {len(containers)}")
    return "\n".join(output) + "\n"


class MetricsHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path != "/metrics":
            self.send_error(404)
            return
        try:
            payload = collect().encode("utf-8")
        except Exception as exception:
            # Error type only; do not expose socket paths or Docker response contents.
            print(f"Metric collection failed: {type(exception).__name__}", flush=True)
            self.send_error(503, "Docker metrics unavailable")
            return

        self.send_response(200)
        self.send_header("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, format, *args):
        pass


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", PORT), MetricsHandler).serve_forever()
