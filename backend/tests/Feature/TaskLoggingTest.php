<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_creation_has_safe_structured_info_log(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Log::spy();

        $this->postJson('/api/tasks', [
            'title' => 'Private task content (must never be logged)',
        ])->assertCreated();

        Log::shouldHaveReceived('info')->once()->withArgs(function ($message, $context) {
            $encoded = json_encode($context);

            return $message === 'task.created'
                && $context['actor_type'] === 'authenticated_user'
                && str_starts_with($context['actor_ref'], 'usr_')
                && $context['action'] === 'task_create'
                && $context['reason'] === 'user_requested_create'
                && ! str_contains($encoded, 'Private task content');
        });
    }

    public function test_invalid_creation_logs_field_names_not_values(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Log::spy();

        $this->postJson('/api/tasks', [
            'title' => '',
            'status' => 'confidential-invalid-value',
        ])->assertUnprocessable();

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) use ($user) {
            $encoded = json_encode($context);

            return $message === 'task.validation_failed'
                && $context['action'] === 'task_create'
                && $context['reason'] === 'validation_failed'
                && in_array('title', $context['invalid_fields'], true)
                && in_array('status', $context['invalid_fields'], true)
                && ! str_contains($encoded, 'confidential-invalid-value')
                && ! str_contains($encoded, $user->email);
        });
    }
}
