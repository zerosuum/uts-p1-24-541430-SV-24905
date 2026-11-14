<?php

namespace Tests\Feature;

use Tests\TestCase;

class MetricsEndpointTest extends TestCase
{
    private string $metricsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metricsPath = sys_get_temp_dir().'/mulaidulu-metrics-'.bin2hex(random_bytes(8)).'.json';
        config()->set('monitoring.requests_file', $this->metricsPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->metricsPath);
        parent::tearDown();
    }

    public function test_metrics_counts_api_responses_without_exposing_user_data(): void
    {
        $this->getJson('/api/tasks')->assertUnauthorized();
        $response = $this->get('/metrics');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
        $body = $response->getContent();
        $this->assertStringContainsString('mulaidulu_http_requests_total{method="GET",status="401"} 1', $body);
        $this->assertStringNotContainsString('demo@example.test', $body);
        $this->assertStringNotContainsString('Bearer', $body);
    }

    public function test_scraping_metrics_does_not_increment_its_own_counter(): void
    {
        $this->get('/metrics')->assertOk();
        $this->get('/metrics')->assertOk();
        $body = $this->get('/metrics')->getContent();
        $this->assertStringNotContainsString('} 3', $body);
    }
}
