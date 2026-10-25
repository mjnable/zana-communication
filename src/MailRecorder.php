<?php

declare(strict_types=1);

namespace Zana\Communication;

/**
 * A mailer that keeps messages instead of sending them, after the same
 * sender rules as the real one. With mail.dsn "memory://" it is the
 * application's mailer, so tests read what was sent:
 *
 *     $sent = $mail->sent(fn (Message $m): bool => $m->subject === 'Welcome');
 */
final class MailRecorder implements MailerInterface
{
    /** @var list<Message> */
    private array $messages = [];

    public function __construct(private readonly ?MailRules $rules = null)
    {
    }

    public function send(Message $message): void
    {
        $this->messages[] = $this->rules?->apply($message) ?? $message;
    }

    /**
     * @param (callable(Message): bool)|null $filter
     * @return list<Message>
     */
    public function sent(?callable $filter = null): array
    {
        return $filter === null ? $this->messages : array_values(array_filter($this->messages, $filter));
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function last(): ?Message
    {
        return $this->messages === [] ? null : $this->messages[array_key_last($this->messages)];
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
