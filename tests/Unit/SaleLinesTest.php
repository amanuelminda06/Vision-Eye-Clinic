<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Sales\Lines;
use PHPUnit\Framework\TestCase;

/**
 * Server-side line computation, ported from src/modules/sales/lines.test.ts.
 *
 * The client never supplies prices: they always come from the DB snapshots
 * passed in here.
 */
class SaleLinesTest extends TestCase
{
    /** @return array{id: string, name: string, currentPrice: string, active: bool} */
    private function consultation(): array
    {
        return ['id' => 'svc-1', 'name' => 'Eye Consultation', 'currentPrice' => '300.00', 'active' => true];
    }

    /** @return array{id: string, name: string, currentPrice: string, active: bool} */
    private function oct(): array
    {
        return ['id' => 'svc-2', 'name' => 'OCT Scan', 'currentPrice' => '1500.50', 'active' => true];
    }

    public function test_it_uses_the_server_price_snapshot_never_a_client_value(): void
    {
        $result = Lines::computeSaleLines(
            [$this->consultation(), $this->oct()],
            [
                ['serviceId' => 'svc-1', 'qty' => 2],
                ['serviceId' => 'svc-2', 'qty' => 1],
            ],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('Eye Consultation', $result['lines'][0]['serviceNameSnapshot']);
        $this->assertSame('300.00', $result['lines'][0]['unitPrice']);
        $this->assertSame(2, $result['lines'][0]['qty']);
        $this->assertSame('600.00', $result['lines'][0]['lineTotal']);
        $this->assertSame('2100.50', $result['total']);
    }

    public function test_it_merges_duplicate_service_rows_by_summing_quantities(): void
    {
        $result = Lines::computeSaleLines(
            [$this->consultation()],
            [
                ['serviceId' => 'svc-1', 'qty' => 1],
                ['serviceId' => 'svc-1', 'qty' => 2],
            ],
        );

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['lines']);
        $this->assertSame(3, $result['lines'][0]['qty']);
        $this->assertSame('900.00', $result['lines'][0]['lineTotal']);
    }

    public function test_it_keeps_the_price_frozen_at_sale_time(): void
    {
        $first = Lines::computeSaleLines([$this->consultation()], [['serviceId' => 'svc-1', 'qty' => 1]]);
        $this->assertTrue($first['ok']);
        $this->assertSame('300.00', $first['lines'][0]['unitPrice']);

        $raised = array_merge($this->consultation(), ['currentPrice' => '400.00']);
        $second = Lines::computeSaleLines([$raised], [['serviceId' => 'svc-1', 'qty' => 1]]);
        $this->assertTrue($second['ok']);
        $this->assertSame('400.00', $second['lines'][0]['unitPrice']);
        // The first computation is untouched by the later price change.
        $this->assertSame('300.00', $first['lines'][0]['unitPrice']);
    }

    public function test_it_rejects_inactive_services(): void
    {
        $inactive = array_merge($this->consultation(), ['active' => false]);
        $result = Lines::computeSaleLines([$inactive], [['serviceId' => 'svc-1', 'qty' => 1]]);

        $this->assertFalse($result['ok']);
        $this->assertSame('serviceInactive', $result['error']);
        $this->assertSame(['Eye Consultation'], $result['serviceNames']);
    }

    public function test_it_rejects_services_that_no_longer_exist(): void
    {
        $result = Lines::computeSaleLines([$this->consultation()], [['serviceId' => 'missing', 'qty' => 1]]);

        $this->assertFalse($result['ok']);
        $this->assertSame('serviceMissing', $result['error']);
    }

    public function test_it_rejects_quantities_below_one_or_above_the_cap(): void
    {
        foreach ([0, 1000] as $qty) {
            $result = Lines::computeSaleLines([$this->consultation()], [['serviceId' => 'svc-1', 'qty' => $qty]]);
            $this->assertFalse($result['ok']);
            $this->assertSame('quantityTooHigh', $result['error']);
        }
    }

    public function test_an_empty_line_set_is_service_missing(): void
    {
        $result = Lines::computeSaleLines([$this->consultation()], []);

        $this->assertFalse($result['ok']);
        $this->assertSame('serviceMissing', $result['error']);
        $this->assertSame([], $result['serviceNames']);
    }

    public function test_inactive_error_takes_precedence_over_missing(): void
    {
        $inactive = array_merge($this->consultation(), ['active' => false]);
        $result = Lines::computeSaleLines(
            [$inactive],
            [
                ['serviceId' => 'svc-1', 'qty' => 1],
                ['serviceId' => 'missing', 'qty' => 1],
            ],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('serviceInactive', $result['error']);
    }
}
