# Provado

Revenue protection and diagnostics for Laravel ecommerce applications.

Provado ingests operational signals from ecommerce-adjacent sources, normalizes them into a
single canonical model, correlates related signals, runs deterministic diagnostic patterns over
them, and produces a readable incident report — all wired into Laravel and runnable from one
artisan command.

> **Status: Alpha.** The full diagnostic loop runs end to end inside Laravel, driven by
> **fixtures**. Real provider clients (New Relic NerdGraph, Adobe Commerce REST) are deferred
> behind the existing source-adapter seam until a live environment is available. See
> [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) and
> [`docs/roadmaps/v0.2.0.md`](docs/roadmaps/v0.2.0.md).

## Requirements

- PHP 8.2+
- Laravel 11 or 12 (`illuminate/support` and `illuminate/http` ^11 | ^12)

## Installation

```bash
composer require mquevedob/provado
```

The package registers itself via Laravel package discovery (`ProvadoServiceProvider`). To
customize configuration, publish the config file:

```bash
php artisan vendor:publish --tag=provado-config
```

If you use the database storage driver (below), run migrations to create the signals table:

```bash
php artisan migrate
```

## Configuration

Configuration lives in `config/provado.php` and is driven by environment variables.

```php
return [
    'enabled' => env('PROVADO_ENABLED', true),

    'observability' => [
        // Log pipeline progress through Laravel's logger (PsrLoggerObserver)
        // instead of the default NullPipelineObserver.
        'logging' => env('PROVADO_LOGGING_ENABLED', false),
    ],

    'http' => [
        // Default timeouts (seconds) for outbound source-client requests. The
        // default HttpClient makes no call until a source adapter invokes it,
        // so these enable no real source on their own.
        'timeout' => env('PROVADO_HTTP_TIMEOUT', 10),
        'connect_timeout' => env('PROVADO_HTTP_CONNECT_TIMEOUT', 5),
    ],

    'storage' => [
        // 'memory' (default, ephemeral) or 'database' (persisted per run).
        'driver' => env('PROVADO_STORAGE_DRIVER', 'memory'),
        'connection' => env('PROVADO_STORAGE_CONNECTION'), // null = default connection
        'table' => env('PROVADO_STORAGE_TABLE', 'provado_signals'),
    ],

    'sources' => [
        'new_relic' => [
            'enabled' => env('PROVADO_NEW_RELIC_ENABLED', false),
            'options' => ['account_id' => env('PROVADO_NEW_RELIC_ACCOUNT_ID')],
            'credentials' => ['api_key' => env('PROVADO_NEW_RELIC_API_KEY')],
        ],
        'adobe_commerce' => [
            'enabled' => env('PROVADO_ADOBE_COMMERCE_ENABLED', false),
            'options' => ['base_url' => env('PROVADO_ADOBE_COMMERCE_BASE_URL')],
            // Magento integrations authenticate via OAuth 1.0a — all four are required.
            'credentials' => [
                'consumer_key' => env('PROVADO_ADOBE_COMMERCE_CONSUMER_KEY'),
                'consumer_secret' => env('PROVADO_ADOBE_COMMERCE_CONSUMER_SECRET'),
                'access_token' => env('PROVADO_ADOBE_COMMERCE_ACCESS_TOKEN'),
                'access_token_secret' => env('PROVADO_ADOBE_COMMERCE_ACCESS_TOKEN_SECRET'),
            ],
        ],
    ],
];
```

Notes:

- Sources ship **disabled** by default; enabling a source requires its options and credentials.
- Credentials are never rendered in serialized config output (redacted).
- Adapters select their client by **credential presence**: when a source's credentials are
  configured the adapter uses the live client; otherwise it falls back to the fixture client (no
  outbound calls). The live clients exist:
  - **New Relic** — a User API key (`PROVADO_NEW_RELIC_API_KEY`) + account id; queries NerdGraph/NRQL.
  - **Magento / Adobe Commerce** — an integration's **OAuth 1.0a** credentials (all four:
    consumer key/secret + access token/secret). A plain Bearer token is *not* honored for the
    integration's ACL, so all four are required; `base_url` may be given with or without `/rest`.

## Usage

Run the full diagnostic loop and render an incident report:

```bash
# Run over the bundled fixtures, no credentials needed:
php artisan provado:diagnose --demo

# Run over your configured sources for an explicit window:
php artisan provado:diagnose --from="2026-06-08T00:00:00+00:00" --to="2026-06-08T12:30:00+00:00"

# Defaults to the last 24 hours when no window is given:
php artisan provado:diagnose
```

Options:

| Option | Description |
|---|---|
| `--demo` | Run over the bundled fixtures with no credentials (a window covering them). |
| `--from` | Window start as an ISO 8601 datetime (defaults to 24h before `--to`). |
| `--to` | Window end as an ISO 8601 datetime (defaults to now). |

The command streams stage progress, renders the incident report (or a no-incident message),
and prints per-stage timings. Isolated source/stage failures are surfaced without aborting the
run.

### Inspecting raw signals

`provado:signals` fetches each configured source and prints the canonical signals (metric values)
it returns in a per-source table — no correlation or pattern evaluation. Useful for eyeballing
what each source reports live.

```bash
php artisan provado:signals --window=30d        # lookback window (e.g. 60m, 24h, 7d, 30d)
php artisan provado:signals --from=... --to=...  # explicit ISO 8601 window
php artisan provado:signals --demo               # bundled fixtures, no credentials
```

In this package (no host app), run the artisan commands through Testbench, which loads the
service provider via `testbench.yaml` and reads credentials from the local `.env`:

```bash
php vendor/bin/testbench provado:signals --window=30d
```

## Storage

By default signals are kept in memory for the duration of a run. Set
`PROVADO_STORAGE_DRIVER=database` (and run `php artisan migrate`) to persist signals to the
`provado_signals` table. Each run is scoped to its own run id, so persistence does not change
diagnostic behavior — it only lets runs survive beyond a single process.

## Architecture

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the layers, seams, and data flow of the
working Alpha. The longer-term direction lives in
[`docs/ARCHITECTURE_DIRECTION_SOURCE.md`](docs/ARCHITECTURE_DIRECTION_SOURCE.md).

## Testing

```bash
composer test
```

## License

Proprietary.
