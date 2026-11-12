<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

/**
 * Test double: records mail instead of sending it.
 */
final class InMemoryMailSender implements MailSender
{
    /** @var list<OutboundEmail> */
    public array $sent = [];

    public function send(OutboundEmail $email): void
    {
        $this->sent[] = $email;
    }
}
