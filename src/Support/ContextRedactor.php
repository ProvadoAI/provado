<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Support;

/**
 * Recursively redacts values whose keys look like secrets, so diagnostic
 * context, evidence, and errors can be surfaced without leaking credentials.
 */
final readonly class ContextRedactor
{
    private const REDACTED = '[redacted]';

    private const SECRET_KEY_FRAGMENTS = [
        'api_key',
        'apikey',
        'access_token',
        'authorization',
        'bearer',
        'credential',
        'password',
        'secret',
        'token',
    ];

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public function redact(array $context): array
    {
        $redacted = [];

        foreach ($context as $name => $value) {
            if (is_string($name) && $this->isSecretKey($name)) {
                $redacted[$name] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $redacted[$name] = $this->redact($value);

                continue;
            }

            $redacted[$name] = $value;
        }

        return $redacted;
    }

    private function isSecretKey(string $name): bool
    {
        $normalized = strtolower($name);

        foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
