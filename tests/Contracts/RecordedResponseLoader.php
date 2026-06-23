<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests\Contracts;

use Mquevedob\Provado\Http\HttpResponse;
use RuntimeException;

/**
 * Loads recorded HTTP responses for provider contract tests.
 *
 * This is the scaffolding the deferred real clients (New Relic NerdGraph, Adobe
 * Commerce REST) will use to assert they map provider responses into canonical
 * signals — without any live capture. Recordings live under:
 *
 *   tests/Contracts/recordings/{provider}/{name}.json
 *
 * and have the shape:
 *
 *   {
 *     "status": 200,
 *     "headers": { "Content-Type": "application/json" },
 *     "body": "...raw string..."          // OR an inline JSON object/array
 *     "body_fixture": "new_relic/latency_spike"  // OR reuse a tests/Fixtures payload
 *   }
 *
 * `body_fixture` reuses an existing sample payload under tests/Fixtures rather
 * than duplicating it, so recordings stay anchored to real example payloads.
 */
final readonly class RecordedResponseLoader
{
    public function load(string $provider, string $name): HttpResponse
    {
        $recording = $this->decodeJsonFile($this->recordingPath($provider, $name), 'recording');

        $status = $recording['status'] ?? null;

        if (! is_int($status)) {
            throw new RuntimeException(sprintf('Recording "%s/%s" must define an integer "status".', $provider, $name));
        }

        return new HttpResponse(
            status: $status,
            body: $this->body($recording, $provider, $name),
            headers: $this->headers($recording, $provider, $name),
        );
    }

    /**
     * @param array<string, mixed> $recording
     */
    private function body(array $recording, string $provider, string $name): string
    {
        if (isset($recording['body_fixture'])) {
            $bodyFixture = $recording['body_fixture'];

            if (! is_string($bodyFixture) || trim($bodyFixture) === '') {
                throw new RuntimeException(sprintf('Recording "%s/%s" "body_fixture" must be a non-empty string.', $provider, $name));
            }

            return $this->readFile($this->fixturePath($bodyFixture));
        }

        $body = $recording['body'] ?? '';

        if (is_array($body)) {
            return json_encode($body, JSON_THROW_ON_ERROR);
        }

        if (! is_string($body)) {
            throw new RuntimeException(sprintf('Recording "%s/%s" "body" must be a string or JSON object.', $provider, $name));
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $recording
     * @return array<string, string>
     */
    private function headers(array $recording, string $provider, string $name): array
    {
        $headers = $recording['headers'] ?? [];

        if (! is_array($headers)) {
            throw new RuntimeException(sprintf('Recording "%s/%s" "headers" must be an object.', $provider, $name));
        }

        $normalized = [];

        foreach ($headers as $headerName => $value) {
            if (! is_string($headerName) || trim($headerName) === '' || ! is_string($value)) {
                throw new RuntimeException(sprintf('Recording "%s/%s" headers must map non-empty names to string values.', $provider, $name));
            }

            $normalized[$headerName] = $value;
        }

        return $normalized;
    }

    private function recordingPath(string $provider, string $name): string
    {
        return __DIR__.DIRECTORY_SEPARATOR.'recordings'.DIRECTORY_SEPARATOR.$provider.DIRECTORY_SEPARATOR.basename($name, '.json').'.json';
    }

    private function fixturePath(string $bodyFixture): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.$bodyFixture.'.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonFile(string $path, string $label): array
    {
        $decoded = json_decode($this->readFile($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Contract %s "%s" must contain a JSON object.', $label, $path));
        }

        return $decoded;
    }

    private function readFile(string $path): string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read contract file "%s".', $path));
        }

        return $contents;
    }
}
