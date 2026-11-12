<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use Twig\Environment;

/**
 * Renders the HTML + text template pair for an email. The subject comes from
 * the `subject` block of the HTML template.
 */
final class EmailRenderer
{
    public function __construct(private readonly Environment $twig) {}

    /** @param array<string, mixed> $context */
    public function render(string $template, string $to, array $context, string $messageId): OutboundEmail
    {
        $html = $this->twig->load("email/{$template}.html.twig");
        $subject = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html->renderBlock('subject', $context)), ENT_QUOTES | ENT_HTML5)) ?? '');

        return new OutboundEmail(
            to: $to,
            subject: $subject,
            html: $html->render($context),
            text: $this->twig->render("email/{$template}.text.twig", $context),
            messageId: $messageId,
        );
    }
}
