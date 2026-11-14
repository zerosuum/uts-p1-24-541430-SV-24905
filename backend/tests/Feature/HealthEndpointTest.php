<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_200_when_database_and_cache_work(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.database.status', 'healthy')
            ->assertJsonPath('checks.cache.status', 'healthy');
    }

    public function test_health_is_503_when_database_fails_without_leaking_errors(): void
    {
        Log::spy();
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT 1')
            ->andThrow(new RuntimeException('DB_PASSWORD=super-private'));

        $response = $this->getJson('/health');
        $response
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('checks.database.status', 'unhealthy')
            ->assertJsonPath('checks.database.reason', 'database_query_failed')
            ->assertJsonPath('checks.cache.status', 'healthy');

        $this->assertStringNotContainsString('super-private', $response->getContent());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_health_is_503_when_cache_roundtrip_fails(): void
    {
        Log::spy();
        Cache::shouldReceive('put')
            ->once()
            ->andThrow(new RuntimeException('private-token=never-log'));

        $response = $this->getJson('/health');
        $response
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('checks.database.status', 'healthy')
            ->assertJsonPath('checks.cache.status', 'unhealthy')
            ->assertJsonPath('checks.cache.reason', 'cache_roundtrip_failed');

        $this->assertStringNotContainsString('private-token', $response->getContent());
        Log::shouldHaveReceived('error')->once();
    }
}
