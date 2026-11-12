<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

interface MailSender
{
    /** @throws \RuntimeException when delivery fails */
    public function send(OutboundEmail $email): void;
}
