<?php

declare(strict_types=1);

namespace Zana\Communication;

/**
 * The sender rules every mailer applies before sending:
 *
 * - a message needs a recipient, a subject, and a text or HTML body;
 * - From is the configured sender (mail.from) or one of mail.allowed_from:
 *   application code never sends as an address taken from user input;
 * - a message without Reply-To gets mail.reply_to when it is set.
 */
final readonly class MailRules
{
    /**
     * @param list<Address> $allowedFrom
     */
    public function __construct(
        private Address $from,
        private array $allowedFrom = [],
        private ?Address $replyTo = null,
    ) {
    }

    /**
     * @throws MailException When the message breaks a rule
     */
    public function apply(Message $message): Message
    {
        if ($message->to === [] && $message->cc === [] && $message->bcc === []) {
            throw new MailException('A message needs at least one recipient.');
        }
        if (trim($message->subject) === '') {
            throw new MailException('A message needs a subject.');
        }
        if ($message->text === null && $message->html === null) {
            throw new MailException('A message needs a text or HTML body.');
        }

        if ($message->from === null) {
            $message = $message->from($this->from);
        } elseif (!$this->allows($message->from)) {
            throw new MailException("The application may not send from {$message->from->email}; use mail.from or list it in mail.allowed_from, and put visitors' addresses in Reply-To.");
        }

        if ($message->replyTo === [] && $this->replyTo !== null) {
            $message = $message->replyTo($this->replyTo);
        }

        return $message;
    }

    private function allows(Address $from): bool
    {
        foreach ([$this->from, ...$this->allowedFrom] as $allowed) {
            if ($allowed->equals($from)) {
                return true;
            }
        }

        return false;
    }
}
