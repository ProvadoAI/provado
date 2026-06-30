<?php

/**
 * Provado "Instrument" signal shipper — Magento cron + New Relic PHP agent.
 *
 * Companion to provado-ship.php (the "Wire-Up" shipper, raw SQL over app/etc/env.php).
 * Some signals cannot be read with a plain SELECT: cache invalidation state
 * (`Cache\TypeListInterface::getInvalidated()`) and message-queue consumer liveness
 * live behind Magento's internal APIs, not in a DB table. This shipper **bootstraps
 * the Magento application** so it can reach those APIs, then ships each reading as a
 * `ProvadoSignal` custom event (raw state only — dwell, baselines, correlation and
 * graph collapse are Provado's job, not the shipper's; see docs/signal-shipping.md).
 *
 * Why a separate file from provado-ship.php: bootstrapping the full framework is much
 * heavier than the raw-SQL path, so the two run on independent schedules — keep the
 * fast Wire-Up shipper frequent and run this heavier Instrument shipper on its own
 * (possibly less frequent) cron entry. The Wire-Up shipper is left untouched.
 *
 * Requires the New Relic PHP extension (already present on Adobe Commerce Cloud); do
 * NOT disable it for this script (that is the test path, not the shipping path).
 *
 * Usage:
 *   MAGENTO_ROOT=/var/www/html/magento php provado-ship-instrument.php
 *   MAGENTO_ROOT=/var/www/html/magento php provado-ship-instrument.php --self-check
 *
 * `--self-check` proves the Instrument capability — bootstrap + reach the internal
 * APIs the signals need — and ships NO event. It exits non-zero if any probe fails,
 * so it doubles as a readiness check on a new merchant host.
 */

declare(strict_types=1);

$magentoRoot = getenv('MAGENTO_ROOT') ?: '/var/www/html/magento';
$source = getenv('PROVADO_SOURCE') ?: 'magento';
$selfCheck = in_array('--self-check', $argv, true);

require $magentoRoot.'/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create($magentoRoot, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

// Magento's internal APIs require an area code. This shipper runs from cron and only
// reads state, so the crontab area is the right (and lightest) context.
try {
    $objectManager->get(\Magento\Framework\App\State::class)
        ->setAreaCode(\Magento\Framework\App\Area::AREA_CRONTAB);
} catch (\Throwable $e) {
    // Area already set (re-entrant run) — safe to ignore.
}

/**
 * Emit one ProvadoSignal custom event. Mirrors provado-ship.php::ship() so both
 * shippers produce the identical event shape; falls back to a printed line when the
 * New Relic extension is absent, so the shipper stays testable off-agent.
 *
 * @param array<string, scalar> $attributes
 */
function ship(string $signal, string $source, array $attributes): void
{
    $event = ['signal' => $signal, 'source' => $source] + $attributes;

    if (function_exists('newrelic_record_custom_event')) {
        newrelic_record_custom_event('ProvadoSignal', $event);
    }

    fwrite(STDERR, 'shipped: '.json_encode($event).PHP_EOL);
}

// --- Self-check: prove the Instrument capability, ship nothing ----------------
// Reaches each internal API the deferred Instrument signals depend on and reports
// readiness. cache_validity (Phase 2) needs TypeListInterface; consumer liveness
// (Phase 3) needs the consumer config + the lock manager (Magento's own
// ConsumersRunner uses LockManagerInterface::isLocked() as the liveness probe).
if ($selfCheck) {
    $report = ['object_manager' => get_class($objectManager)];
    $ok = true;

    try {
        $typeList = $objectManager->get(\Magento\Framework\App\Cache\TypeListInterface::class);
        $report['cache_typelist'] = 'ok ('.count($typeList->getInvalidated()).' invalidated)';
    } catch (\Throwable $e) {
        $report['cache_typelist'] = 'FAIL: '.$e->getMessage();
        $ok = false;
    }

    try {
        $consumerConfig = $objectManager->get(\Magento\Framework\MessageQueue\Consumer\ConfigInterface::class);
        // getConsumers() returns a Traversable iterator, not an array.
        $consumers = $consumerConfig->getConsumers();
        $consumerCount = is_array($consumers) ? count($consumers) : iterator_count($consumers);
        $report['consumer_config'] = 'ok ('.$consumerCount.' consumers)';
    } catch (\Throwable $e) {
        $report['consumer_config'] = 'FAIL: '.$e->getMessage();
        $ok = false;
    }

    try {
        $objectManager->get(\Magento\Framework\Lock\LockManagerInterface::class);
        $report['lock_manager'] = 'ok';
    } catch (\Throwable $e) {
        $report['lock_manager'] = 'FAIL: '.$e->getMessage();
        $ok = false;
    }

    fwrite(STDERR, 'instrument self-check: '.json_encode($report).PHP_EOL);
    exit($ok ? 0 : 1);
}

// --- Instrument signals -------------------------------------------------------

// --- signal: cache_validity ---------------------------------------------------
// Which cache types are currently invalidated. Cron is the worker that cleans an
// invalidated cache, so a cache left invalidated while cron is degraded is the
// cron→cache staleness symptom. Emitted one event per *enabled* cache type with a
// 0/1 invalidated flag — not just the invalidated ones — so each type has a fresh
// per-poll reading for Provado's latest-per-entity reduction and dwell (otherwise a
// type that gets cleaned would keep its last "invalidated" snapshot forever). Raw
// state only: how long it has been invalidated (dwell) is Provado's job.
$typeList = $objectManager->get(\Magento\Framework\App\Cache\TypeListInterface::class);

$invalidatedIds = [];
foreach ($typeList->getInvalidated() as $id => $type) {
    $invalidatedIds[(string) (is_object($type) && method_exists($type, 'getId') ? $type->getId() : $id)] = true;
}

foreach ($typeList->getTypes() as $id => $type) {
    $cacheType = (string) (is_object($type) && method_exists($type, 'getId') ? $type->getId() : $id);

    ship('cache_validity', $source, [
        'cache' => $cacheType,
        'invalidated' => isset($invalidatedIds[$cacheType]) ? 1 : 0,
    ]);
}

// --- signal: consumer liveness (Phase 3) --------------------------------------
// Added in Phase 3: enumerate the message-queue consumers and probe liveness via
// LockManagerInterface::isLocked(), shipping raw state for the cron→email edge.
