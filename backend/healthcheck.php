<?php

/** Docker-only probe; stdout/stderr is captured by Docker health status. */
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 3,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\n",
    ],
]);

$body = @file_get_contents('http://127.0.0.1:80/health', false, $context);
$headers = $http_response_header ?? [];
$code = isset($headers[0]) && preg_match('/\s200\s/', $headers[0]);
$data = is_string($body) ? json_decode($body, true) : null;

$healthy = $code && is_array($data)
    && ($data['status'] ?? null) === 'healthy'
    && ($data['checks']['database']['status'] ?? null) === 'healthy'
    && ($data['checks']['cache']['status'] ?? null) === 'healthy';

if (! $healthy) {
    fwrite(STDERR, "Laravel readiness probe unhealthy\n");
    exit(1);
}

exit(0);
