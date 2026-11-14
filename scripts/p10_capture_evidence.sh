#!/usr/bin/env bash
# Real (non-fabricated) evidence collection with automatic DB recovery on failure.
set -euo pipefail
cd "$(dirname "$0")/.."

if ! command -v docker >/dev/null || ! docker info >/dev/null 2>&1; then
  echo 'Docker Desktop is not running.' >&2
  exit 1
fi
if ! command -v curl >/dev/null || ! command -v python3 >/dev/null; then
  echo 'curl and Python 3 are required.' >&2
  exit 1
fi

mkdir -p evidence-p10
DATABASE_STOPPED=0
restore_db() {
  if [ "$DATABASE_STOPPED" -eq 1 ]; then
    echo 'Restoring PostgreSQL on exit...'
    docker compose start db >/dev/null || true
  fi
}
trap restore_db EXIT

backend_id="$(docker compose ps -q backend)"
if [ -z "$backend_id" ]; then
  echo 'Start the app first: docker compose up -d --build' >&2
  exit 1
fi

wait_backend_status() {
  target="$1"
  for attempt in $(seq 1 30); do
    current="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$backend_id" 2>/dev/null || true)"
    printf 'backend health: %s (target %s)\n' "$current" "$target"
    if [ "$current" = "$target" ]; then
      return 0
    fi
    sleep 3
  done
  echo "Backend did not reach status $target" >&2
  return 1
}

capture_http() {
  label="$1"
  code="$(curl --silent --show-error --max-time 10 \
    -H 'Accept: application/json' \
    -o "evidence-p10/${label}-health.json" \
    -w '%{http_code}' http://localhost:8088/health)"
  printf '%s\n' "$code" > "evidence-p10/${label}-http-status.txt"
  printf '%s: HTTP %s\n' "$label" "$code"
}

printf '\n== PHASE A: healthy ==\n'
wait_backend_status healthy
docker compose ps --all > evidence-p10/healthy-ps.txt
capture_http healthy
if [ "$(cat evidence-p10/healthy-http-status.txt)" != 200 ]; then
  echo 'Expected HTTP 200 before outage.' >&2
  exit 1
fi

printf '\n== Emit safe sample structured INFO + WARNING ==\n'
python3 scripts/p10_demo_events.py | tee evidence-p10/demo-events.txt

docker inspect -f '{{json .HostConfig.LogConfig}}' "$backend_id" \
  > evidence-p10/log-rotation.json

printf '\n== PHASE B: stop PostgreSQL intentionally ==\n'
docker compose stop db
DATABASE_STOPPED=1
wait_backend_status unhealthy
docker compose ps --all > evidence-p10/unhealthy-ps.txt
capture_http unhealthy
if [ "$(cat evidence-p10/unhealthy-http-status.txt)" != 503 ]; then
  echo 'Expected HTTP 503 during DB outage.' >&2
  exit 1
fi

printf '\n== PHASE C: restore PostgreSQL ==\n'
docker compose start db
DATABASE_STOPPED=0
wait_backend_status healthy
docker compose ps --all > evidence-p10/restored-ps.txt
capture_http restored
if [ "$(cat evidence-p10/restored-http-status.txt)" != 200 ]; then
  echo 'Expected HTTP 200 after restoring DB.' >&2
  exit 1
fi

# Show only application events; avoid request dumps, raw user fields and headers.
docker compose logs --no-color backend \
  | grep -E '"message":"(task\.created|task\.validation_failed|health\.dependency_failed)"' \
  | tail -n 45 > evidence-p10/structured-logs.txt || true

printf '\n== Summary ==\n'
printf 'healthy status:   %s\n' "$(cat evidence-p10/healthy-http-status.txt)"
printf 'unhealthy status: %s\n' "$(cat evidence-p10/unhealthy-http-status.txt)"
printf 'restored status:  %s\n' "$(cat evidence-p10/restored-http-status.txt)"
printf 'Structured events: %s\n' "$(wc -l < evidence-p10/structured-logs.txt | tr -d ' ')"
echo 'All files saved under evidence-p10/ (ignored by Git).'
echo 'To see the proof, cat evidence-p10/{healthy,unhealthy,restored}-ps.txt'
echo 'Then run: python3 scripts/p10_make_report.py (requires reportlab).'
