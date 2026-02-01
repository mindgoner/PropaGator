<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Tests\Feature;

use Illuminate\Http\Request;
use Mindgoner\Propagator\Facades\Propagator;
use Mindgoner\Propagator\Models\PropagatorRequest;
use Mindgoner\Propagator\Tests\TestCase;

class PropagatorRecorderTest extends TestCase
{
    public function test_it_records_path_not_full_url(): void
    {
        $request = Request::create('https://example.com/webhooks/provider?foo=bar', 'POST', [
            'foo' => 'bar',
        ], [], [], [], '{"event":"paid"}');
        $request->headers->set('User-Agent', 'ProviderBot/1.0');

        Propagator::record($request);

        $record = PropagatorRequest::query()->first();

        $this->assertNotNull($record);
        $this->assertSame('/webhooks/provider', $record->path);
        $this->assertSame('POST', $record->method);
        $this->assertSame(['foo' => 'bar'], $record->query_params);
        $this->assertSame('ProviderBot/1.0', $record->user_agent);
        $this->assertNotNull($record->received_at);
    }
}
