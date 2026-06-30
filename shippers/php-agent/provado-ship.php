<?php

/**
 * Provado signal shipper — Magento cron + New Relic PHP agent.
 *
 * Reads Magento operational state read-only and ships each signal into New Relic
 * as a `ProvadoSignal` custom event (see docs/signal-shipping.md). Schedule it from
 * cron (e.g. every 1–5 min). Requires the New Relic PHP extension (already present
 * on Adobe Commerce Cloud); do NOT disable it for this script.
 *
 * Ship raw state only — dwell, baselines, correlation and deploy-stamping are
 * Provado's job, not the shipper's.
 *
 * Usage:
 *   MAGENTO_ROOT=/var/www/html/magento php shippers/php-agent/provado-ship.php
 */

declare(strict_types=1);

$magentoRoot = getenv('MAGENTO_ROOT') ?: '/var/www/html/magento';
$source = getenv('PROVADO_SOURCE') ?: 'magento';

$env = require $magentoRoot.'/app/etc/env.php';
$db = $env['db']['connection']['default'];
$prefix = $env['db']['table_prefix'] ?? '';

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8', $db['host'], $db['dbname']),
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

/**
 * Emit one ProvadoSignal custom event. Falls back to a printed line when the
 * New Relic extension is absent, so the shipper is testable off-agent.
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

// --- signal: cron_health --------------------------------------------------
// cron_schedule status counts (pending/running/success/missed/error). A dead
// cron is one root cause behind stale cache/index/email at once.
$counts = $pdo->query("SELECT status, COUNT(*) AS c FROM {$prefix}cron_schedule GROUP BY status")
    ->fetchAll(PDO::FETCH_KEY_PAIR);

ship('cron_health', $source, [
    'pending' => (int) ($counts['pending'] ?? 0),
    'running' => (int) ($counts['running'] ?? 0),
    'success' => (int) ($counts['success'] ?? 0),
    'missed'  => (int) ($counts['missed'] ?? 0),
    'error'   => (int) ($counts['error'] ?? 0),
]);

// --- signal: indexer_status ------------------------------------------------
// Per scheduled view: changelog backlog (MAX(<view>_cl.version_id) −
// mview_state.version_id) plus working/invalid flags. A view stuck "working"
// with zero backlog is the valid-while-failed case (ACSD-51431); status alone
// is unreliable, so always pair it with backlog.
$views = $pdo->query("SELECT view_id, version_id, status FROM {$prefix}mview_state")->fetchAll(PDO::FETCH_ASSOC);
$indexerState = $pdo->query("SELECT indexer_id, status FROM {$prefix}indexer_state")->fetchAll(PDO::FETCH_KEY_PAIR);

foreach ($views as $view) {
    $clMax = 0;
    try {
        $clMax = (int) $pdo->query('SELECT MAX(version_id) FROM `'.$prefix.$view['view_id'].'_cl`')->fetchColumn();
    } catch (\Throwable $e) {
        // No changelog table for this view; treat backlog as 0.
    }

    ship('indexer_status', $source, [
        'indexer' => $view['view_id'],
        'backlog' => max(0, $clMax - (int) $view['version_id']),
        'working' => $view['status'] === 'working' ? 1 : 0,
        'invalid' => ($indexerState[$view['view_id']] ?? '') === 'invalid' ? 1 : 0,
    ]);
}
