<?php

namespace App\Http\Controllers;

use App\Support\SafeLogContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Readiness probe: explicitly tests database AND cache independently. */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [];

        try {
            DB::select('SELECT 1');
            $checks['database'] = ['status' => 'healthy'];
        } catch (Throwable $exception) {
            // Do not log $exception or SQL details: they may contain secrets.
            $checks['database'] = [
                'status' => 'unhealthy',
                'reason' => 'database_query_failed',
            ];
            Log::error('health.dependency_failed', [
                'event' => 'health.dependency_failed',
                ...SafeLogContext::forSystem('database_readiness_probe', 'database_query_failed'),
                'component' => 'database',
            ]);
        }

        try {
            $key = 'health:'.bin2hex(random_bytes(8));
            $value = 'ok';
            if (! Cache::put($key, $value, 30) || Cache::get($key) !== $value) {
                throw new RuntimeException('Cache round-trip unsuccessful');
            }
            Cache::forget($key);
            $checks['cache'] = ['status' => 'healthy'];
        } catch (Throwable $exception) {
            $checks['cache'] = [
                'status' => 'unhealthy',
                'reason' => 'cache_roundtrip_failed',
            ];
            Log::error('health.dependency_failed', [
                'event' => 'health.dependency_failed',
                ...SafeLogContext::forSystem('cache_readiness_probe', 'cache_roundtrip_failed'),
                'component' => 'cache',
            ]);
        }

        $healthy = $checks['database']['status'] === 'healthy'
            && $checks['cache']['status'] === 'healthy';

        return response()->json([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }
}
