<?php

declare(strict_types=1);

namespace Zana\Communication;

interface MailerInterface
{
    /**
     * @throws MailException When the message breaks the rules or cannot be sent
     */
    public function send(Message $message): void;
}
