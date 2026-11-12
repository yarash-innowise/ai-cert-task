<?php

declare(strict_types=1);

namespace App\Shared\Http\Exception;

/**
 * Input validation failure; every message is keyed by the offending field name.
 * Optionally carries the form template to re-render (422) and the non-secret
 * submitted values; never put passwords in `$old`.
 */
final class ValidationFailed extends HttpException
{
    /**
     * @param array<string, string> $errors field name => message
     * @param array<string, mixed> $old previously submitted, non-secret values
     */
    public function __construct(
        public readonly array $errors,
        public readonly ?string $template = null,
        public readonly array $old = [],
    ) {
        parent::__construct('Validation failed: ' . implode('; ', $errors));
    }

    public static function forField(string $field, string $message): self
    {
        return new self([$field => $message]);
    }

    /** @param array<string, mixed> $old */
    public function forForm(string $template, array $old = []): self
    {
        return new self($this->errors, $template, $old);
    }
}
