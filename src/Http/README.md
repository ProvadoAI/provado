# Http

Provider-agnostic HTTP seam that real source clients sit on. `HttpClient` performs
no outbound I/O until `send()` is invoked; `LaravelHttpClient` is the default
implementation over Laravel's HTTP client, and `FakeHttpClient` is the test double.
`HttpSourceErrorFactory` maps transport failures and HTTP error statuses to
`SourceFetchError` with the `retryable` classification the existing `RetryPolicy`
drives retries from. No vendor response shapes cross this boundary.
