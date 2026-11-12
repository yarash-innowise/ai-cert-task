<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

final readonly class OutboundEmail
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $html,
        public string $text,
        public string $messageId,
    ) {}
}
