<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Whitelisted diagnostic fields; never include raw request data or credentials.
 */
final class SafeLogContext
{
    public static function forUser(Request $request, string $action, string $reason): array
    {
        // HMAC makes the actor reference stable for debugging, without logging
        // the actual user ID, name, email, password, or bearer token.
        $userId = (string) $request->user()->getAuthIdentifier();
        $key = (string) config('app.key');

        return [
            'actor_type' => 'authenticated_user',
            'actor_ref' => 'usr_'.substr(hash_hmac('sha256', $userId, $key), 0, 12),
            'action' => $action,
            'reason' => $reason,
        ];
    }

    public static function forSystem(string $action, string $reason): array
    {
        return [
            'actor_type' => 'system_healthcheck',
            'actor_ref' => 'docker_health_probe',
            'action' => $action,
            'reason' => $reason,
        ];
    }
}
