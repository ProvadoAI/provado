<?php

declare(strict_types=1);

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

function assertTrue(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertThrows(callable $callback, string $expectedException, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $expectedException) {
            return;
        }

        throw new RuntimeException($message.' Expected '.$expectedException.', got '.get_class($exception).'.');
    }

    throw new RuntimeException($message.' Expected '.$expectedException.' to be thrown.');
}

$id = new SignalId('signal-1');
$source = new SignalSource('observability');
$type = new SignalType('latency_spike');
$timestamp = new DateTimeImmutable('2026-06-08T12:00:00+00:00');
$severity = SignalSeverity::warning();
$entityReference = new EntityReference('service', 'checkout');
$rawPayloadReference = new RawPayloadReference('payload-1', 'fixtures/payloads/payload-1.json');

$signal = new Signal(
    id: $id,
    source: $source,
    type: $type,
    timestamp: $timestamp,
    severity: $severity,
    entityReferences: [$entityReference],
    attributes: ['duration_ms' => 1250, 'region' => 'us-east'],
    rawPayloadReference: $rawPayloadReference,
);

assertTrue($signal->id->equals(new SignalId('signal-1')), 'Signal id should be retained.');
assertTrue($signal->source->equals(new SignalSource('observability')), 'Signal source should be retained.');
assertTrue($signal->type->equals(new SignalType('latency_spike')), 'Signal type should be retained.');
assertTrue($signal->severity->equals(SignalSeverity::warning()), 'Signal severity should be retained.');
assertTrue($signal->hasEntity(new EntityReference('service', 'checkout')), 'Signal should match entity references by value.');
assertTrue($signal->rawPayloadReference->equals($rawPayloadReference), 'Signal raw payload reference should be retained.');
assertTrue($signal->equals(new Signal(
    id: new SignalId('signal-1'),
    source: new SignalSource('commerce'),
    type: new SignalType('configuration_change'),
    timestamp: $timestamp,
    severity: SignalSeverity::info(),
    entityReferences: [new EntityReference('store', 'default')],
    attributes: [],
    rawPayloadReference: new RawPayloadReference('payload-2'),
)), 'Signals should be equal when their ids match.');

assertThrows(fn () => new SignalId(''), InvalidArgumentException::class, 'SignalId should reject empty values.');
assertThrows(fn () => new SignalSource(' '), InvalidArgumentException::class, 'SignalSource should reject empty values.');
assertThrows(fn () => new SignalType(''), InvalidArgumentException::class, 'SignalType should reject empty values.');
assertThrows(fn () => new SignalSeverity('debug'), InvalidArgumentException::class, 'SignalSeverity should reject unknown values.');
assertThrows(fn () => new EntityReference('', 'checkout'), InvalidArgumentException::class, 'EntityReference should reject empty types.');
assertThrows(fn () => new EntityReference('service', ''), InvalidArgumentException::class, 'EntityReference should reject empty ids.');
assertThrows(fn () => new RawPayloadReference('payload-1', ' '), InvalidArgumentException::class, 'RawPayloadReference should reject empty locations when provided.');
assertThrows(fn () => new DeploymentReference(''), InvalidArgumentException::class, 'DeploymentReference should reject empty ids.');
assertThrows(
    fn () => new TimeWindow(new DateTimeImmutable('2026-06-08T12:00:00+00:00'), new DateTimeImmutable('2026-06-08T11:59:59+00:00')),
    InvalidArgumentException::class,
    'TimeWindow should reject an end before the start.',
);
assertThrows(
    fn () => new Signal(
        id: $id,
        source: $source,
        type: $type,
        timestamp: $timestamp,
        severity: $severity,
        entityReferences: [],
        attributes: [],
        rawPayloadReference: $rawPayloadReference,
    ),
    InvalidArgumentException::class,
    'Signal should reject empty entity reference lists.',
);
assertThrows(
    fn () => new Signal(
        id: $id,
        source: $source,
        type: $type,
        timestamp: $timestamp,
        severity: $severity,
        entityReferences: [$entityReference],
        attributes: ['' => 'invalid'],
        rawPayloadReference: $rawPayloadReference,
    ),
    InvalidArgumentException::class,
    'Signal should reject empty attribute names.',
);

$timeWindow = new TimeWindow(
    new DateTimeImmutable('2026-06-08T11:55:00+00:00'),
    new DateTimeImmutable('2026-06-08T12:05:00+00:00'),
);

assertTrue($timeWindow->contains($signal->timestamp), 'TimeWindow should include timestamps within the window.');
assertTrue($timeWindow->equals(new TimeWindow(
    new DateTimeImmutable('2026-06-08T11:55:00+00:00'),
    new DateTimeImmutable('2026-06-08T12:05:00+00:00'),
)), 'TimeWindow equality should compare bounds by value.');
assertTrue((new DeploymentReference('deploy-1', $timestamp))->equals(new DeploymentReference('deploy-1', $timestamp)), 'DeploymentReference equality should compare by value.');

echo "Signal model tests passed.\n";
