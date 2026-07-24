<?php

namespace Tests\Unit;

use App\Services\RequestContext;
use Illuminate\Http\Request;
use Swoole\Coroutine;
use Tests\TestCase;

class RequestContextTest extends TestCase
{
    public function test_same_request_reuses_context_and_clean_resets_it(): void
    {
        $request = Request::create('/api/system/version');
        $this->app->instance('request', $request);

        $firstRequestId = RequestContext::getCurrentRequestId();
        $secondRequestId = RequestContext::getCurrentRequestId();

        $this->assertSame($firstRequestId, $secondRequestId);

        RequestContext::set('probe', 'value');
        $this->assertTrue(RequestContext::has('probe'));
        $this->assertSame('value', RequestContext::get('probe'));

        RequestContext::clean();

        $thirdRequestId = RequestContext::getCurrentRequestId();
        $this->assertNotSame($firstRequestId, $thirdRequestId);
        $this->assertFalse(RequestContext::has('probe'));

        RequestContext::clean();
    }

    public function test_cleaning_explicit_context_does_not_reset_current_request(): void
    {
        $request = Request::create('/api/system/version');
        $this->app->instance('request', $request);

        $currentRequestId = RequestContext::getCurrentRequestId();
        $otherRequestId = RequestContext::generateRequestId();
        RequestContext::set('other', 'value', $otherRequestId);

        RequestContext::clean($otherRequestId);

        $this->assertSame($currentRequestId, RequestContext::getCurrentRequestId());
        RequestContext::clean();
    }

    public function test_coroutines_use_isolated_contexts(): void
    {
        $results = [];

        Coroutine\run(function () use (&$results): void {
            $channel = new Coroutine\Channel(2);

            for ($index = 0; $index < 2; $index++) {
                Coroutine::create(function () use ($channel, $index): void {
                    RequestContext::set('probe', $index);
                    Coroutine::sleep(0.01);
                    $channel->push([
                        'request_id' => RequestContext::getCurrentRequestId(),
                        'value' => RequestContext::get('probe'),
                    ]);
                    RequestContext::clean();
                });
            }

            $results[] = $channel->pop();
            $results[] = $channel->pop();
        });

        $this->assertNotSame($results[0]['request_id'], $results[1]['request_id']);

        $values = array_values(array_unique(array_column($results, 'value')));
        sort($values);
        $this->assertSame([0, 1], $values);
    }
}
