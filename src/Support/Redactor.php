<?php

namespace Abhishek\Yardmaster\Support;

/**
 * Masks sensitive values in a captured job payload.
 *
 * Matching is on the key, at any depth, case-insensitively and as a substring —
 * 'stripe_access_token' is caught by the 'access_token' pattern. Values are
 * replaced rather than removed so the payload's shape stays readable in the
 * dashboard.
 */
final class Redactor
{
    /**
     * @param  array<int, string>  $keys
     */
    public function __construct(
        private readonly array $keys = [],
        private readonly string $placeholder = '[redacted]',
        private readonly bool $enabled = true,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function scrub(array $payload): array
    {
        if (! $this->enabled || $this->keys === []) {
            return $payload;
        }

        return $this->walk($payload);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    private function walk(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $input[$key] = $this->placeholder;

                continue;
            }

            if (is_array($value)) {
                $input[$key] = $this->walk($value);
            }
        }

        return $input;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->keys as $pattern) {
            if (str_contains($key, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}
