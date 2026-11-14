import unittest
from unittest.mock import patch

import exporter


class ExporterTests(unittest.TestCase):
    def test_collect_emits_identified_container_metrics(self):
        containers = [
            {
                "Id": "abc123",
                "Names": ["/mulaidulu-backend-1"],
                "Labels": {
                    "com.docker.compose.project": "mulaidulu",
                    "com.docker.compose.service": "backend",
                },
            },
            {
                "Id": "ignore",
                "Names": ["/other-app"],
                "Labels": {"com.docker.compose.project": "other"},
            },
        ]
        stats = {
            "cpu_stats": {"cpu_usage": {"total_usage": 2_500_000_000}},
            "memory_stats": {"usage": 100_000_000, "stats": {"inactive_file": 20_000_000}},
        }

        def fake_get(path):
            if path.startswith("/containers/json"):
                return containers
            if path.startswith("/containers/abc123/stats"):
                return stats
            raise AssertionError(path)

        with patch.object(exporter, "docker_get", side_effect=fake_get):
            output = exporter.collect()

        self.assertIn('container="mulaidulu-backend-1",service="backend"', output)
        self.assertIn(" 2.500000000", output)
        self.assertIn(" 80000000", output)
        self.assertIn("mulaidulu_container_exporter_containers 1", output)
        self.assertNotIn("other-app", output)

    def test_empty_project_has_no_spoofed_series(self):
        with patch.object(exporter, "docker_get", return_value=[]):
            output = exporter.collect()
        self.assertIn("mulaidulu_container_exporter_containers 0", output)
        self.assertNotIn("container=", output)

    def test_label_escaping(self):
        self.assertEqual(exporter.escape_label('hello"\\world\n'), 'hello\\"\\\\world\\n')


if __name__ == "__main__":
    unittest.main()
