<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

/**
 * A transactional email to be rendered from templates/email/{template}.html.twig
 * and {template}.text.twig. The subject is the `subject` block of the HTML template.
 */
final readonly class EmailMessage
{
    /** @param array<string, mixed> $context JSON-serializable template variables */
    public function __construct(
        public string $template,
        public string $to,
        public array $context = [],
    ) {}
}
