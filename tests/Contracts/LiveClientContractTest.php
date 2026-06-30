<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests\Contracts;

use DateTimeImmutable;
use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Config\SourceCredentials;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Http\FakeHttpClient;
use Mquevedob\Provado\Sources\AdobeCommerce\AdobeCommerceRestClient;
use Mquevedob\Provado\Sources\NewRelic\NerdGraphClient;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests: replay redacted recordings of real lab responses through the
 * live clients and assert they map to the expected canonical signals. The
 * recordings (tests/Contracts/recordings/...) are captured shapes — no live calls,
 * no credentials in CI — so a drift in either provider's real response shape or in
 * the mapping is caught here.
 */
class LiveClientContractTest extends TestCase
{
    public function test_nerdgraph_transaction_health_recording_maps_to_signals(): void
    {
        $http = (new FakeHttpClient())->respondWith(
            (new RecordedResponseLoader())->load('new_relic', 'nrql_transaction_health'),
        );

        $result = (new NerdGraphClient($http))->fetch($this->newRelicConfig(), $this->window());

        $this->assertSame([], $result->errors());
        $this->assertCount(2, $result->signals());

        $signal = $result->signals()[0];
        $this->assertSame('new_relic', $signal->source->value);
        $this->assertSame('transaction_health', $signal->type->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('service', 'Magento Lab - Provado')));
        $this->assertTrue($signal->hasEntity(new EntityReference('transaction', 'OtherTransaction/Custom/Cron job consumers_runner')));
        $this->assertSame(8397, $signal->attributes['throughput']);
        $this->assertSame(8.85, $signal->attributes['duration_ms']);
    }

    public function test_adobe_commerce_orders_recording_maps_to_order_activity(): void
    {
        $http = (new FakeHttpClient())->respondWith(
            (new RecordedResponseLoader())->load('adobe_commerce', 'orders'),
        );

        $result = (new AdobeCommerceRestClient($http))->fetch($this->adobeConfig(), $this->window());

        $this->assertSame([], $result->errors());
        $this->assertCount(1, $result->signals());

        $signal = $result->signals()[0];
        $this->assertSame('order_activity', $signal->type->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('store', '1')));
        $this->assertSame(3, $signal->attributes['order_count']);
        $this->assertSame(105.03, $signal->attributes['gross_total']);
        $this->assertSame(2, $signal->attributes['count_processing']);
        $this->assertSame(1, $signal->attributes['count_closed']);
    }

    private function newRelicConfig(): SourceConfig
    {
        return new SourceConfig(
            name: 'new_relic',
            enabled: true,
            options: ['account_id' => '8129476'],
            credentials: new SourceCredentials(['api_key' => 'nr-key']),
        );
    }

    private function adobeConfig(): SourceConfig
    {
        return new SourceConfig(
            name: 'adobe_commerce',
            enabled: true,
            options: ['base_url' => 'https://shop.test'],
            credentials: new SourceCredentials([
                'consumer_key' => 'ck',
                'consumer_secret' => 'cs',
                'access_token' => 'atok',
                'access_token_secret' => 'ats',
            ]),
        );
    }

    private function window(): TimeWindow
    {
        return new TimeWindow(
            start: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            end: new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
        );
    }
}
