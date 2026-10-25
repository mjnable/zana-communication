<?php

declare(strict_types=1);

namespace Zana\Communication;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email;

/**
 * Sends messages through a Symfony Mailer transport, after the sender rules.
 */
final readonly class Mailer implements MailerInterface
{
    public function __construct(
        private MailRules $rules,
        private TransportInterface $transport,
    ) {
    }

    public function send(Message $message): void
    {
        $email = self::toEmail($this->rules->apply($message));

        try {
            $this->transport->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new MailException("The message \"{$message->subject}\" could not be sent: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @internal Public for tests.
     */
    public static function toEmail(Message $message): Email
    {
        $address = static fn (Address $a): SymfonyAddress => new SymfonyAddress($a->email, $a->name);

        $email = (new Email())->subject($message->subject);
        if ($message->from !== null) {
            $email->from($address($message->from));
        }
        $email->to(...array_map($address, $message->to));
        $email->cc(...array_map($address, $message->cc));
        $email->bcc(...array_map($address, $message->bcc));
        $email->replyTo(...array_map($address, $message->replyTo));
        if ($message->text !== null) {
            $email->text($message->text);
        }
        if ($message->html !== null) {
            $email->html($message->html);
        }
        foreach ($message->headers as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        return $email;
    }
}
