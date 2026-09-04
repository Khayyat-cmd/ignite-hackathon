<?php

namespace Tests\Feature;

use App\Services\Simulation\NokiaReachability;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NokiaReachabilityTest extends TestCase
{
    public function test_mapping_caching_and_failure_do_not_fabricate_connectivity(): void
    {
        config(['cache.default' => 'array', 'aman.reachability.key' => 'test', 'aman.reachability.enabled' => true]);
        Http::preventStrayRequests();
        $failed = false;
        Http::fake(function ($request) use (&$failed) {
            return $failed ? Http::response([], 503) : Http::response([
                'reachable' => $request['device']['phoneNumber'] !== '+99999991003',
                'connectivity' => $request['device']['phoneNumber'] === '+99999991003' ? [] : ['DATA'],
                'lastStatusTime' => now()->toISOString(),
            ]);
        });
        $service = app(NokiaReachability::class);
        $service->refresh();
        $service->refresh();
        Http::assertSentCount(3);
        foreach ([0, 1, 2] as $index) {
            $this->assertTrue($service->forResponder($index)['dataReachable']);
        }
        $this->assertFalse($service->forResponder(3)['dataReachable']);
        $previous = $service->forResponder(0);
        $this->travel(31)->seconds();
        $this->assertSame($previous, $service->forResponder(0));
        $failed = true;
        $service->refresh();
        $this->assertSame($previous, $service->forResponder(0));
        $service->refresh();
        Http::assertSentCount(6);
        $this->travel(31)->seconds();
        $failed = false;
        $service->refresh();
        $this->assertTrue($service->forResponder(0)['dataReachable']);
        $this->assertNotSame($previous['checkedAt'], $service->forResponder(0)['checkedAt']);
        $this->assertFalse($service->forResponder(3)['dataReachable']);
    }
}
