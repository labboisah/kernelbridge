<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_checks_dependencies_without_exposing_configuration(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.connection', 'default');
        Redis::shouldReceive('connection')->once()->with('default')->andReturn(new class
        {
            public function ping(): bool
            {
                return true;
            }
        });

        $response = $this->getJson('/health')->assertOk()->assertExactJson([
            'status' => 'ok',
            'checks' => ['application' => 'ok', 'database' => 'ok', 'redis' => 'ok', 'queue' => 'ok'],
        ])->assertHeader('Cache-Control', 'no-store, private');

        $payload = $response->getContent();
        $this->assertStringNotContainsString('password', strtolower($payload));
        $this->assertStringNotContainsString('127.0.0.1', $payload);
        $this->assertStringNotContainsString('exception', strtolower($payload));
    }
}
