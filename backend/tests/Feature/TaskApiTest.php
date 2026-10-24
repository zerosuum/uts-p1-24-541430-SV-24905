<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_a_sanctum_token(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('test-password-123'),
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'test-password-123',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'message',
                'token',
                'user' => ['id', 'name', 'email'],
            ]);
    }

    public function test_api_requires_authentication(): void
    {
        $this->getJson('/api/tasks')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->get('/api/tasks')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_create_read_update_and_delete_tasks(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('feature-test')->plainTextToken;

        $created = $this->withToken($token)
            ->postJson('/api/tasks', [
                'title' => 'Kerjakan laporan UTS',
                'first_step' => 'Buat halaman judul',
            ]);

        $created
            ->assertCreated()
            ->assertJsonPath('data.title', 'Kerjakan laporan UTS')
            ->assertJsonPath('data.status', 'inbox');

        $id = $created->json('data.id');

        $this->withToken($token)
            ->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($token)
            ->getJson("/api/tasks/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->withToken($token)
            ->patchJson("/api/tasks/{$id}", [
                'title' => 'Laporan UTS diperbarui',
                'status' => 'in_progress',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('tasks', [
            'id' => $id,
            'title' => 'Laporan UTS diperbarui',
            'user_id' => $user->id,
        ]);

        $this->withToken($token)
            ->deleteJson("/api/tasks/{$id}")
            ->assertOk();

        $this->withToken($token)
            ->getJson("/api/tasks/{$id}")
            ->assertNotFound();

        $this->assertDatabaseMissing('tasks', [
            'id' => $id,
        ]);
    }

    public function test_invalid_task_returns_422(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('feature-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/tasks', [
                'title' => '',
                'status' => 'invalid',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title',
                'status',
            ]);
    }

    public function test_user_cannot_access_another_users_tasks(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $task = $owner->tasks()->create([
            'title' => 'Tugas pribadi',
            'status' => 'inbox',
        ]);

        $token = $otherUser
            ->createToken('other-user-token')
            ->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/tasks/{$task->id}")
            ->assertNotFound();

        $this->withToken($token)
            ->patchJson("/api/tasks/{$task->id}", [
                'title' => 'Diubah orang lain',
            ])
            ->assertNotFound();

        $this->withToken($token)
            ->deleteJson("/api/tasks/{$task->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Tugas pribadi',
        ]);
    }
}
