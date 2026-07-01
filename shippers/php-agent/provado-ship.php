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
 * Every event carries `source_instance`: a stable per-Magento-instance id so all
 * signals from one instance share an entity and correlate by design (the reader's
 * `signal_entity_fields` maps it to an entity). This is intentional and shipper-
 * independent — it does NOT rely on the New Relic agent's auto-`host` (which the
 * Event API shipper never gets). Resolved once, injected here so no call site can
 * omit it and both shippers keep an identical event shape.
 *
 * @param array<string, scalar> $attributes
 */
function ship(string $signal, string $source, array $attributes): void
{
    static $instance = null;
    if ($instance === null) {
        $instance = getenv('PROVADO_INSTANCE') ?: (gethostname() ?: $source);
    }

    $event = ['signal' => $signal, 'source' => $source, 'source_instance' => $instance] + $attributes;

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

// --- signal: queue_backlog -------------------------------------------------
// Per RabbitMQ queue: ready/unacked backlog + consumer liveness. consumers > 0
// with ready > 0 and a flat ack rate is alive-but-stalled; consumers = 0 with
// ready > 0 is disconnected. (Magento may also use the DB queue; queue_message_status
// is shipped as a fallback summary.)
$rabbitUrl  = getenv('PROVADO_RABBITMQ_URL')  ?: 'http://localhost:15672';
$rabbitUser = getenv('PROVADO_RABBITMQ_USER') ?: 'guest';
$rabbitPass = getenv('PROVADO_RABBITMQ_PASS') ?: 'guest';

$ctx = stream_context_create(['http' => [
    'timeout' => 10,
    'ignore_errors' => true,
    'header' => 'Authorization: Basic '.base64_encode($rabbitUser.':'.$rabbitPass),
]]);
$queuesJson = @file_get_contents($rabbitUrl.'/api/queues', false, $ctx);
$queues = is_string($queuesJson) ? json_decode($queuesJson, true) : null;

if (is_array($queues)) {
    foreach ($queues as $queue) {
        if (! is_array($queue) || ! isset($queue['name']) || ! is_string($queue['name'])) {
            continue;
        }

        ship('queue_backlog', $source, [
            'queue'     => $queue['name'],
            'ready'     => (int) ($queue['messages_ready'] ?? 0),
            'unacked'   => (int) ($queue['messages_unacknowledged'] ?? 0),
            'consumers' => (int) ($queue['consumers'] ?? 0),
        ]);
    }
}

// DB-queue fallback: queue_message_status counts (status 2 = NEW piling up = backlog).
$dbQueue = $pdo->query("SELECT status, COUNT(*) AS c FROM {$prefix}queue_message_status GROUP BY status")
    ->fetchAll(PDO::FETCH_KEY_PAIR);

if ($dbQueue !== []) {
    ship('queue_backlog', $source, [
        'queue'       => 'db',
        'new'         => (int) ($dbQueue[2] ?? 0),
        'in_progress' => (int) ($dbQueue[3] ?? 0),
        'error'       => (int) ($dbQueue[6] ?? 0),
    ]);
}

// --- signal: config_change -------------------------------------------------
// core_config_data churn — the marker-less change surface New Relic never sees
// (admin config edits emit no deploy marker). A summary of recent change volume
// and recency; Provado stamps symptom onset against it. updated_at is the only
// change timestamp on this table.
$config = $pdo->query("SELECT
        SUM(updated_at >= NOW() - INTERVAL 1 HOUR)  AS changed_1h,
        SUM(updated_at >= NOW() - INTERVAL 24 HOUR) AS changed_24h,
        TIMESTAMPDIFF(SECOND, MAX(updated_at), NOW()) AS latest_change_age_seconds
    FROM {$prefix}core_config_data")->fetch(PDO::FETCH_ASSOC);

ship('config_change', $source, [
    'changed_1h'                => (int) ($config['changed_1h'] ?? 0),
    'changed_24h'               => (int) ($config['changed_24h'] ?? 0),
    'latest_change_age_seconds' => (int) ($config['latest_change_age_seconds'] ?? 0),
]);
