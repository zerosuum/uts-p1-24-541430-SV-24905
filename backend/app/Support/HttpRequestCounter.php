<?php

namespace App\Support;

use RuntimeException;

/** A small, file-backed counter for this single-backend Docker Compose app. */
final class HttpRequestCounter
{
    private function filePath(): string
    {
        return (string) config('monitoring.requests_file');
    }

    public function increment(string $method, int $status): void
    {
        $allowed = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
        $method = strtoupper($method);
        $method = in_array($method, $allowed, true) ? $method : 'OTHER';
        $status = $status >= 100 && $status <= 599 ? $status : 500;
        $key = $method.':'.$status;

        $this->withLock(function (array $counts) use ($key): array {
            $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
            return $counts;
        }, write: true);
    }

    public function render(): string
    {
        $counts = $this->withLock(fn (array $counts) => $counts);
        ksort($counts);
        $lines = [
            '# HELP mulaidulu_http_requests_total Total Laravel HTTP responses (excluding metrics and readiness probes).',
            '# TYPE mulaidulu_http_requests_total counter',
        ];

        foreach ($counts as $key => $value) {
            if (! preg_match('/^(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS|OTHER):([1-5][0-9]{2})$/', $key, $matches)) {
                continue;
            }
            $lines[] = sprintf('mulaidulu_http_requests_total{method="%s",status="%s"} %d',
                $matches[1], $matches[2], max(0, (int) $value));
        }

        if (count($lines) === 2) {
            $lines[] = 'mulaidulu_http_requests_total{method="GET",status="200"} 0';
        }

        return implode("\n", $lines)."\n";
    }

    /** @template T */
    private function withLock(callable $transform, bool $write = false): array
    {
        $path = $this->filePath();
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('Monitoring storage directory unavailable');
        }

        $stream = @fopen($path, 'c+');
        if ($stream === false) {
            throw new RuntimeException('Monitoring counter storage unavailable');
        }

        try {
            if (! flock($stream, LOCK_EX)) {
                throw new RuntimeException('Monitoring counter lock unavailable');
            }
            rewind($stream);
            $raw = stream_get_contents($stream);
            $counts = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($counts)) {
                $counts = [];
            }
            $result = $transform($counts);
            if ($write) {
                rewind($stream);
                ftruncate($stream, 0);
                if (fwrite($stream, json_encode($result, JSON_THROW_ON_ERROR)) === false) {
                    throw new RuntimeException('Monitoring counter write failed');
                }
                fflush($stream);
            }
            return $result;
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }
}
