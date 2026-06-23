# Provado Roadmap

## Phase 1 — HTTP source-client foundation
1. Define the `HttpClient` seam with request/response value objects and a timeout, auth-header, and JSON-handling contract — status: todo
2. Add a default `HttpClient` implementation over Laravel's HTTP client that performs no outbound calls until invoked — status: todo
3. Add a fake transport double implementing the seam for tests with no live calls — status: todo
4. Map transport and HTTP failures to `SourceFetchError` with retryable classification (timeouts, 5xx, 429 retryable; 4xx auth/config not), tested over the fake transport — status: todo
5. Add rate-limit awareness that honors `Retry-After` and 429 as retryable, with tests — status: todo
6. Bind the `HttpClient` seam into the container and `config/provado.php` without enabling any real source — status: todo

## Phase 2 — Credential-driven adapter wiring
1. Add credential-presence checks on `SourceConfig` and `SourceCredentials` so an adapter can decide which client to use — status: todo
2. Add client selection in the source adapters that falls back to the fixture client when credentials are absent, tested with a stub client — status: todo

## Phase 3 — Deepen fixture-based diagnostic coverage
1. Add a 3DS and payment-config regression Tier 0 pattern (the v1 lead pattern) diagnosable from fixtures, with full PHPUnit coverage — status: todo
2. Add a catalog and feed sync-failure Tier 0 pattern over fixtures, with PHPUnit coverage — status: todo
3. Add time-proximity correlation that joins signals close in time alongside shared-entity joins, with tests — status: todo
4. Improve incident-report evidence ordering and de-duplication, with tests — status: todo

## Phase 4 — Contract-test scaffolding and docs
1. Add a recorded-response fixture loader and directory convention for future provider contract tests, reusing existing sample payloads with no live capture — status: todo
2. Update `docs/ARCHITECTURE.md` to document the HTTP source-client seam, credential-driven fixture fallback, and contract-test convention — status: todo
3. Update README and config docs for the new HTTP client options — status: todo
