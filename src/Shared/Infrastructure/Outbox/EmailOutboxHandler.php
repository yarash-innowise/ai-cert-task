<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Infrastructure\Mail\EmailRenderer;
use App\Shared\Infrastructure\Mail\MailSender;

final class EmailOutboxHandler implements OutboxHandler
{
    public function __construct(
        private readonly EmailRenderer $renderer,
        private readonly MailSender $mailSender,
    ) {}

    public function handle(OutboxMessage $message): void
    {
        $to = $message->payload['to'] ?? null;
        $context = $message->payload['context'] ?? [];
        if (!is_string($to) || !is_array($context)) {
            throw new \UnexpectedValueException('Malformed email outbox payload.');
        }
        /** @var array<string, mixed> $context */
        $this->mailSender->send(
            $this->renderer->render($message->topic, $to, $context, $message->id . '@outbox.local'),
        );
    }
}
