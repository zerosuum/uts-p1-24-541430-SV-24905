<?php

namespace App\Http\Middleware;

use App\Support\HttpRequestCounter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Terminating middleware also records 401/422/500 responses handled by Laravel's exception handler. */
final class CountHttpRequests
{
    public function __construct(private readonly HttpRequestCounter $counter) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->is('metrics', 'health', 'up')) {
            return;
        }

        try {
            $this->counter->increment($request->method(), $response->getStatusCode());
        } catch (Throwable) {
            // Metrics must never take down the application or expose request data.
            Log::warning('metrics.counter_write_failed', [
                'event' => 'metrics.counter_write_failed',
                'actor_type' => 'system',
                'action' => 'increment_http_counter',
                'reason' => 'metrics_storage_unavailable',
            ]);
        }
    }
}
