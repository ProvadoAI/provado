<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Sources\NewRelic;

use JsonException;
use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Core\SignalType;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Http\HttpClient;
use Mquevedob\Provado\Http\HttpRequest;
use Mquevedob\Provado\Http\HttpResponse;
use Mquevedob\Provado\Http\HttpSourceErrorFactory;
use Mquevedob\Provado\Http\HttpTransportException;
use Mquevedob\Provado\Sources\SourceFetchError;
use Mquevedob\Provado\Sources\SourceFetchResult;
use Throwable;

/**
 * Live New Relic client over NerdGraph (GraphQL + NRQL).
 *
 * Implements the {@see NewRelicClient} seam: the adapter selects it when an
 * `api_key` credential is present, falling back to fixtures otherwise. It POSTs
 * a single NRQL query (scoped to the configured `account_id`, with the requested
 * {@see TimeWindow} injected) to NerdGraph over the provider-agnostic
 * {@see HttpClient}, and returns canonical {@see SourceFetchResult}s — no
 * NerdGraph response shape crosses this boundary.
 *
 * The NRQL row → {@see \Mquevedob\Provado\Core\Signal} translation is delegated
 * to {@see NewRelicPayloadMapper::mapNrqlRow()}; Phase 2 hardens that mapping for
 * the full range of real result shapes. The default NRQL/entity-field config is
 * a sensible Tier-0 starting point and is validated against a live account at the
 * v0.4.0 live-validation checkpoint.
 */
final readonly class NerdGraphClient implements NewRelicClient
{
    private const SOURCE_NAME = 'new_relic';

    private const DEFAULT_ENDPOINT = 'https://api.newrelic.com/graphql';

    /**
     * Tier-0 transaction-health query. The time window is appended by the client
     * (SINCE/UNTIL), so a configured query should NOT include its own time clause.
     */
    private const DEFAULT_NRQL = "SELECT count(*) AS 'throughput', average(duration) AS 'duration_ms', "
        ."percentage(count(*), WHERE error IS true) AS 'error_rate' FROM Transaction FACET appName, name";

    private const DEFAULT_SIGNAL_TYPE = 'transaction_health';

    /**
     * Canonical entity type per facet position, matching the default query's
     * `FACET appName, name`: facet[0] → service, facet[1] → transaction. A row
     * needs at least one resolvable facet entity to become a signal. Override via
     * the `facet_entities` option to match a customized FACET clause.
     *
     * @var list<string>
     */
    private const DEFAULT_FACET_ENTITIES = ['service', 'transaction'];

    /**
     * Operational-signal mode reads the `ProvadoSignal` custom events shipped into
     * New Relic (see docs/signal-shipping.md) instead of faceted APM transactions.
     */
    private const MODE_OPERATIONAL = 'operational_signals';

    /**
     * `LIMIT MAX` is required: a bare `SELECT *` defaults to ~100 most-recent
     * events, which truncates the per-(signal,entity) series to a couple of polls
     * and caps dwell at a few minutes regardless of the read window. The window's
     * SINCE/UNTIL is appended after, so LIMIT precedes SINCE per NRQL clause order.
     */
    private const DEFAULT_PROVADO_SIGNAL_NRQL = 'SELECT * FROM ProvadoSignal LIMIT MAX';

    /**
     * Event attribute names treated as entity dimensions when mapping
     * `ProvadoSignal` events; any present one becomes an entity of that type.
     *
     * @var list<string>
     */
    private const DEFAULT_SIGNAL_ENTITY_FIELDS = ['store', 'indexer', 'queue', 'cron_job', 'host', 'service', 'transaction'];

    private const GRAPHQL_QUERY = 'query ProvadoNrql($accountId: Int!, $nrql: Nrql!) '
        .'{ actor { account(id: $accountId) { nrql(query: $nrql) { results } } } }';

    public function __construct(
        private HttpClient $httpClient,
        private NewRelicPayloadMapper $mapper = new NewRelicPayloadMapper(),
        private HttpSourceErrorFactory $errorFactory = new HttpSourceErrorFactory(),
    ) {
    }

    public function fetch(SourceConfig $config, TimeWindow $window): SourceFetchResult
    {
        $accountId = $this->accountId($config);

        if ($accountId === null) {
            return $this->errorResult(
                'New Relic account_id option is required for live NerdGraph queries.',
                'missing_account_id',
            );
        }

        $apiKey = $config->credentials->get('api_key');

        if ($apiKey === null) {
            return $this->errorResult(
                'New Relic api_key credential is required for live NerdGraph queries.',
                'missing_credentials',
            );
        }

        // A source can read more than one query mode (e.g. transaction_health AND
        // operational_signals); each is its own NerdGraph call, combined into one
        // result. A failing mode is isolated and does not drop the others.
        $signals = [];
        $errors = [];

        foreach ($this->modes($config) as $mode) {
            $request = $this->buildRequest($config, $window, $accountId, $apiKey, $mode);

            try {
                $response = $this->httpClient->send($request);
            } catch (HttpTransportException $exception) {
                $errors[] = $this->errorFactory->fromTransportException(self::SOURCE_NAME, $request, $exception);

                continue;
            }

            if (! $response->isSuccessful()) {
                $errors[] = $this->errorFactory->fromResponse(self::SOURCE_NAME, $request, $response);

                continue;
            }

            $result = $this->mapResponse($config, $window, $response, $mode);
            $signals = array_merge($signals, $result->signals());
            $errors = array_merge($errors, $result->errors());
        }

        return new SourceFetchResult($signals, $errors);
    }

    private function buildRequest(SourceConfig $config, TimeWindow $window, int $accountId, string $apiKey, string $mode): HttpRequest
    {
        return new HttpRequest(
            method: 'POST',
            uri: $this->endpoint($config),
            headers: [
                'API-Key' => $apiKey,
                'Content-Type' => 'application/json',
            ],
            jsonBody: [
                'query' => self::GRAPHQL_QUERY,
                'variables' => [
                    'accountId' => $accountId,
                    'nrql' => $this->nrql($config, $window, $mode),
                ],
            ],
        );
    }

    /**
     * The query modes this source reads. Accepts `modes` (a list or comma string)
     * or the singular `mode`; defaults to transaction_health.
     *
     * @return list<string>
     */
    private function modes(SourceConfig $config): array
    {
        $modes = $config->option('modes', $config->option('mode'));

        if (is_string($modes)) {
            $modes = explode(',', $modes);
        }

        if (is_array($modes)) {
            $valid = [];

            foreach ($modes as $mode) {
                if (is_string($mode) && trim($mode) !== '') {
                    $valid[] = trim($mode);
                }
            }

            if ($valid !== []) {
                return $valid;
            }
        }

        return ['transaction_health'];
    }

    private function mapResponse(SourceConfig $config, TimeWindow $window, HttpResponse $response, string $mode): SourceFetchResult
    {
        try {
            $decoded = $response->json();
        } catch (JsonException $exception) {
            return $this->errorResult(
                'New Relic response body was not valid JSON.',
                'invalid_response',
                ['reason' => $exception->getMessage()],
            );
        }

        if (! is_array($decoded)) {
            return $this->errorResult('New Relic response body was not a JSON object.', 'invalid_response');
        }

        if ($this->hasGraphQlErrors($decoded)) {
            return $this->errorResult(
                'New Relic NerdGraph returned a GraphQL error.',
                'graphql_error',
                ['reason' => $this->firstGraphQlError($decoded)],
            );
        }

        $results = $this->results($decoded);

        if ($results === null) {
            return $this->errorResult(
                'New Relic response did not contain an NRQL results array.',
                'invalid_response',
            );
        }

        $operational = $mode === self::MODE_OPERATIONAL;
        $type = $operational ? null : new SignalType($this->signalType($config));
        $facetEntities = $operational ? [] : $this->facetEntities($config);
        $signalEntityFields = $operational ? $this->signalEntityFields($config) : [];

        $signals = [];
        $errors = [];

        foreach ($results as $index => $row) {
            if (! is_array($row)) {
                $errors[] = $this->rowError($index, 'NRQL result row was not an object.');

                continue;
            }

            try {
                $signals[] = $operational
                    ? $this->mapper->mapProvadoSignalEvent($row, $signalEntityFields, (int) $index, $window->end)
                    : $this->mapper->mapNrqlRow($row, $type, $window->end, $facetEntities, 'nrql:'.$index);
            } catch (Throwable $exception) {
                $errors[] = $this->rowError($index, $exception->getMessage());
            }
        }

        return new SourceFetchResult($signals, $errors);
    }

    private function accountId(SourceConfig $config): ?int
    {
        $accountId = $config->option('account_id');

        if (is_int($accountId)) {
            return $accountId;
        }

        if (is_string($accountId)) {
            $trimmed = trim($accountId);

            if ($trimmed !== '' && ctype_digit($trimmed)) {
                return (int) $trimmed;
            }
        }

        return null;
    }

    private function endpoint(SourceConfig $config): string
    {
        $endpoint = $config->option('endpoint', self::DEFAULT_ENDPOINT);

        return is_string($endpoint) && trim($endpoint) !== '' ? trim($endpoint) : self::DEFAULT_ENDPOINT;
    }

    private function nrql(SourceConfig $config, TimeWindow $window, string $mode): string
    {
        $default = $mode === self::MODE_OPERATIONAL ? self::DEFAULT_PROVADO_SIGNAL_NRQL : self::DEFAULT_NRQL;
        $nrql = $config->option('nrql', $default);

        if (! is_string($nrql) || trim($nrql) === '') {
            $nrql = $default;
        }

        return sprintf(
            '%s SINCE %d UNTIL %d',
            trim($nrql),
            $window->start->getTimestamp() * 1000,
            $window->end->getTimestamp() * 1000,
        );
    }

    private function signalType(SourceConfig $config): string
    {
        $type = $config->option('signal_type', self::DEFAULT_SIGNAL_TYPE);

        return is_string($type) && trim($type) !== '' ? trim($type) : self::DEFAULT_SIGNAL_TYPE;
    }

    /**
     * Ordered canonical entity types, one per facet position. Override via the
     * `facet_entities` option (a list of non-empty strings) to match a customized
     * FACET clause; malformed config falls back to the default.
     *
     * @return list<string>
     */
    private function facetEntities(SourceConfig $config): array
    {
        $configured = $config->option('facet_entities', self::DEFAULT_FACET_ENTITIES);

        if (! is_array($configured)) {
            return self::DEFAULT_FACET_ENTITIES;
        }

        $facetEntities = [];

        foreach ($configured as $entityType) {
            if (is_string($entityType) && trim($entityType) !== '') {
                $facetEntities[] = trim($entityType);
            }
        }

        return $facetEntities === [] ? self::DEFAULT_FACET_ENTITIES : $facetEntities;
    }

    /**
     * Event attribute names to treat as entity dimensions when mapping
     * `ProvadoSignal` events. Override via the `signal_entity_fields` option.
     *
     * @return list<string>
     */
    private function signalEntityFields(SourceConfig $config): array
    {
        $configured = $config->option('signal_entity_fields', self::DEFAULT_SIGNAL_ENTITY_FIELDS);

        if (! is_array($configured)) {
            return self::DEFAULT_SIGNAL_ENTITY_FIELDS;
        }

        $fields = [];

        foreach ($configured as $field) {
            if (is_string($field) && trim($field) !== '') {
                $fields[] = trim($field);
            }
        }

        return $fields === [] ? self::DEFAULT_SIGNAL_ENTITY_FIELDS : $fields;
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    private function hasGraphQlErrors(array $decoded): bool
    {
        return isset($decoded['errors']) && is_array($decoded['errors']) && $decoded['errors'] !== [];
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    private function firstGraphQlError(array $decoded): string
    {
        $errors = $decoded['errors'] ?? [];

        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            $message = $errors[0]['message'] ?? null;

            if (is_string($message) && trim($message) !== '') {
                return $message;
            }
        }

        return 'unknown GraphQL error';
    }

    /**
     * Extract `data.actor.account.nrql.results` without letting the envelope
     * shape leak past this method. Returns null when the path is absent.
     *
     * @param array<array-key, mixed> $decoded
     * @return list<mixed>|null
     */
    private function results(array $decoded): ?array
    {
        $results = $decoded['data']['actor']['account']['nrql']['results'] ?? null;

        return is_array($results) ? array_values($results) : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function errorResult(string $message, string $code, array $context = []): SourceFetchResult
    {
        return SourceFetchResult::empty()->withErrors([
            new SourceFetchError(
                sourceName: self::SOURCE_NAME,
                message: $message,
                code: $code,
                retryable: false,
                context: $context,
            ),
        ]);
    }

    private function rowError(int|string $index, string $reason): SourceFetchError
    {
        return new SourceFetchError(
            sourceName: self::SOURCE_NAME,
            message: 'Unable to map New Relic NRQL result row.',
            code: 'invalid_nrql_row',
            retryable: false,
            context: [
                'row' => $index,
                'reason' => $reason,
            ],
        );
    }
}
