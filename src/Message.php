<?php

declare(strict_types=1);

namespace Zana\Communication;

/**
 * An email, immutable: each method returns a new message.
 *
 *     $mailer->send(Message::create()
 *         ->to('contact@example.com')
 *         ->replyTo($visitorEmail)            // visitor input: Reply-To, never From
 *         ->subject('New message from the website')
 *         ->text($body));
 *
 * Without from(), the mailer uses mail.from.
 */
final readonly class Message
{
    /**
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $bcc
     * @param list<Address> $replyTo
     * @param array<string, string> $headers
     */
    private function __construct(
        public ?Address $from = null,
        public array $to = [],
        public array $cc = [],
        public array $bcc = [],
        public array $replyTo = [],
        public string $subject = '',
        public ?string $text = null,
        public ?string $html = null,
        public array $headers = [],
    ) {
    }

    public static function create(): self
    {
        return new self();
    }

    public function from(string|Address $address): self
    {
        return clone($this, ['from' => Address::from($address)]);
    }

    public function to(string|Address ...$addresses): self
    {
        return clone($this, ['to' => [...$this->to, ...self::addresses($addresses)]]);
    }

    public function cc(string|Address ...$addresses): self
    {
        return clone($this, ['cc' => [...$this->cc, ...self::addresses($addresses)]]);
    }

    public function bcc(string|Address ...$addresses): self
    {
        return clone($this, ['bcc' => [...$this->bcc, ...self::addresses($addresses)]]);
    }

    public function replyTo(string|Address ...$addresses): self
    {
        return clone($this, ['replyTo' => [...$this->replyTo, ...self::addresses($addresses)]]);
    }

    /**
     * @throws MailException For a subject with line breaks
     */
    public function subject(string $subject): self
    {
        self::assertSingleLine('The subject', $subject);

        return clone($this, ['subject' => $subject]);
    }

    public function text(string $body): self
    {
        return clone($this, ['text' => $body]);
    }

    public function html(string $body): self
    {
        return clone($this, ['html' => $body]);
    }

    /**
     * @throws MailException For an invalid header name, or a value with line breaks
     */
    public function header(string $name, string $value): self
    {
        if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1) {
            throw new MailException("\"{$name}\" is not a valid header name.");
        }
        self::assertSingleLine("The header {$name}", $value);

        return clone($this, ['headers' => [...$this->headers, $name => $value]]);
    }

    /**
     * @param array<array-key, string|Address> $addresses
     * @return list<Address>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_map(Address::from(...), $addresses));
    }

    private static function assertSingleLine(string $what, string $value): void
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new MailException("{$what} cannot contain line breaks.");
        }
    }
}
