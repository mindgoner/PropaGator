<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Mindgoner\Propagator\Console\PropagatorListenCommand;
use Mindgoner\Propagator\Tests\TestCase;
use ReflectionMethod;

class PropagatorListenCommandTest extends TestCase
{
    public function test_replay_targets_local_base_url(): void
    {
        $this->app['config']->set('propagator.local_base_url', 'http://local.test');

        Http::fake();

        $command = $this->app->make(PropagatorListenCommand::class);
        $record = [
            'id' => 'test-id',
            'method' => 'POST',
            'path' => '/hooks/incoming',
            'headers' => ['content-type' => ['application/json']],
            'queryParams' => ['foo' => 'bar'],
            'body' => '{"event":"paid"}',
            'ip' => '203.0.113.10',
            'userAgent' => 'ProviderBot/1.0',
            'receivedAt' => '2024-01-01T00:00:05Z',
        ];

        $method = new ReflectionMethod($command, 'replayToLocal');
        $method->setAccessible(true);
        $method->invoke($command, $record);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://local.test/hooks/incoming'
                && $request->method() === 'POST';
        });
    }
}
