<?php

namespace App\Domain\Operations\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthCheckService
{
    public function check(): array
    {
        $checks = [
            'application' => true,
            'database' => $this->databaseIsHealthy(),
            'redis' => $this->redisIsHealthy(),
            'queue' => $this->queueIsConfigured(),
        ];

        return ['healthy' => ! in_array(false, $checks, true), 'checks' => $checks];
    }

    private function databaseIsHealthy(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function redisIsHealthy(): bool
    {
        try {
            return in_array(Redis::connection('default')->ping(), [true, 'PONG', '+PONG'], true);
        } catch (Throwable) {
            return false;
        }
    }

    private function queueIsConfigured(): bool
    {
        $connection = config('queue.default');

        return $connection === 'redis'
            && config("queue.connections.$connection.driver") === 'redis'
            && filled(config("queue.connections.$connection.connection"));
    }
}
