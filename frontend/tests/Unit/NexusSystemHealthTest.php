<?php

namespace Tests\Unit;

use App\Exceptions\NexusApiException;
use App\Services\NexusSystemHealth;
use PHPUnit\Framework\TestCase;

class NexusSystemHealthTest extends TestCase
{
    public function test_it_parses_the_actual_fastapi_health_payload(): void
    {
        $health = NexusSystemHealth::fromApi([
            'status' => 'ok',
            'services' => [
                'api' => ['status' => 'ok'],
                'model' => ['status' => 'error', 'error' => 'model unavailable'],
                'embeddings' => ['status' => 'ok'],
            ],
            'timestamp' => 1_760_000_000,
        ]);

        $this->assertSame('degraded', $health->overallStatus);
        $this->assertSame('api', $health->services['api']->service);
        $this->assertSame('healthy', $health->services['api']->status);
        $this->assertSame('down', $health->services['model']->status);
        $this->assertSame('model unavailable', $health->services['model']->message);
        $this->assertCount(1, $health->unhealthyServices());
        $this->assertNotNull($health->updatedAt);
    }

    public function test_it_does_not_report_empty_or_unknown_service_statuses_as_healthy(): void
    {
        $empty = NexusSystemHealth::fromApi(['status' => 'ok', 'services' => []]);
        $unknown = NexusSystemHealth::fromApi([
            'services' => ['api' => ['status' => 'not-a-known-status']],
        ]);

        $this->assertSame('unknown', $empty->overallStatus);
        $this->assertSame('degraded', $unknown->overallStatus);
        $this->assertSame('unknown', $unknown->services['api']->status);
    }

    public function test_it_rejects_malformed_service_status_values(): void
    {
        $this->expectException(NexusApiException::class);

        NexusSystemHealth::fromApi([
            'services' => ['api' => ['status' => ['ok']]],
        ]);
    }
}
