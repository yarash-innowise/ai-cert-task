<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class SymfonyMailSender implements MailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $from,
    ) {}

    public function send(OutboundEmail $email): void
    {
        $message = (new Email())
            ->from($this->from)
            ->to($email->to)
            ->subject($email->subject)
            ->html($email->html)
            ->text($email->text);
        // Stable Message-ID per outbox row lets receivers de-duplicate a retry after a crash.
        $message->getHeaders()->addIdHeader('Message-ID', $email->messageId);

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Mail delivery failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
