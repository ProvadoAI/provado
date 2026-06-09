<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Sources;

use InvalidArgumentException;

final readonly class SourceFetchError
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
     * @var array<string, mixed>
     */
    public array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $sourceName,
        public string $message,
        public ?string $code = null,
        public ?bool $retryable = null,
        array $context = [],
    ) {
        if (trim($sourceName) === '') {
            throw new InvalidArgumentException('Source fetch error source name cannot be empty.');
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException('Source fetch error message cannot be empty.');
        }

        foreach (array_keys($context) as $contextName) {
            if (! is_string($contextName) || trim($contextName) === '') {
                throw new InvalidArgumentException('Source fetch error context names cannot be empty.');
            }
        }

        $this->context = $this->sanitizeContext($context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $name => $value) {
            if ($this->isSecretKey($name)) {
                $sanitized[$name] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $sanitized[$name] = $this->sanitizeNestedContext($value);

                continue;
            }

            $sanitized[$name] = $value;
        }

        return $sanitized;
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private function sanitizeNestedContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $name => $value) {
            if (is_string($name) && $this->isSecretKey($name)) {
                $sanitized[$name] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $sanitized[$name] = $this->sanitizeNestedContext($value);

                continue;
            }

            $sanitized[$name] = $value;
        }

        return $sanitized;
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
