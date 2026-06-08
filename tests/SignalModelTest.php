<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Provado\Core\DeploymentReference;
use Provado\Core\EntityReference;
use Provado\Core\RawPayloadReference;
use Provado\Core\Signal;
use Provado\Core\SignalId;
use Provado\Core\SignalSeverity;
use Provado\Core\SignalSource;
use Provado\Core\SignalType;
use Provado\Core\TimeWindow;

require_once __DIR__.'/../src/Core/SignalId.php';
require_once __DIR__.'/../src/Core/SignalSource.php';
require_once __DIR__.'/../src/Core/SignalType.php';
require_once __DIR__.'/../src/Core/SignalSeverity.php';
require_once __DIR__.'/../src/Core/EntityReference.php';
require_once __DIR__.'/../src/Core/TimeWindow.php';
require_once __DIR__.'/../src/Core/DeploymentReference.php';
require_once __DIR__.'/../src/Core/RawPayloadReference.php';
require_once __DIR__.'/../src/Core/Signal.php';

class SignalModelTest extends TestCase
{
    public function test_signal_model_keeps_expected_values(): void
    {
        $timestamp = new DateTimeImmutable('2026-06-08T12:00:00+00:00');
        $rawPayloadReference = new RawPayloadReference('payload-1', 'fixtures/payloads/payload-1.json');

        $signal = new Signal(
            id: new SignalId('signal-1'),
            source: new SignalSource('observability'),
            type: new SignalType('latency_spike'),
            timestamp: $timestamp,
            severity: SignalSeverity::warning(),
            entityReferences: [new EntityReference('service', 'checkout')],
            attributes: ['duration_ms' => 1250, 'region' => 'us-east'],
            rawPayloadReference: $rawPayloadReference,
        );

        $this->assertTrue($signal->id->equals(new SignalId('signal-1')));
        $this->assertTrue($signal->source->equals(new SignalSource('observability')));
        $this->assertTrue($signal->type->equals(new SignalType('latency_spike')));
        $this->assertTrue($signal->severity->equals(SignalSeverity::warning()));
        $this->assertTrue($signal->hasEntity(new EntityReference('service', 'checkout')));
        $this->assertTrue($signal->rawPayloadReference->equals($rawPayloadReference));
    }

    public function test_signals_are_equal_when_ids_match(): void
    {
        $timestamp = new DateTimeImmutable('2026-06-08T12:00:00+00:00');

        $signal = new Signal(
            id: new SignalId('signal-1'),
            source: new SignalSource('observability'),
            type: new SignalType('latency_spike'),
            timestamp: $timestamp,
            severity: SignalSeverity::warning(),
            entityReferences: [new EntityReference('service', 'checkout')],
            attributes: ['duration_ms' => 1250],
            rawPayloadReference: new RawPayloadReference('payload-1'),
        );

        $this->assertTrue($signal->equals(new Signal(
            id: new SignalId('signal-1'),
            source: new SignalSource('commerce'),
            type: new SignalType('configuration_change'),
            timestamp: $timestamp,
            severity: SignalSeverity::info(),
            entityReferences: [new EntityReference('store', 'default')],
            attributes: [],
            rawPayloadReference: new RawPayloadReference('payload-2'),
        )));
    }

    public function test_value_objects_reject_invalid_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SignalId('');
    }

    public function test_time_window_rejects_end_before_start(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TimeWindow(
            new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            new DateTimeImmutable('2026-06-08T11:59:59+00:00'),
        );
    }

    public function test_time_window_contains_timestamp_and_compares_by_value(): void
    {
        $timestamp = new DateTimeImmutable('2026-06-08T12:00:00+00:00');
        $timeWindow = new TimeWindow(
            new DateTimeImmutable('2026-06-08T11:55:00+00:00'),
            new DateTimeImmutable('2026-06-08T12:05:00+00:00'),
        );

        $this->assertTrue($timeWindow->contains($timestamp));
        $this->assertTrue($timeWindow->equals(new TimeWindow(
            new DateTimeImmutable('2026-06-08T11:55:00+00:00'),
            new DateTimeImmutable('2026-06-08T12:05:00+00:00'),
        )));
    }

    public function test_deployment_reference_compares_by_value(): void
    {
        $timestamp = new DateTimeImmutable('2026-06-08T12:00:00+00:00');

        $this->assertTrue(
            (new DeploymentReference('deploy-1', $timestamp))->equals(new DeploymentReference('deploy-1', $timestamp))
        );
    }
}
