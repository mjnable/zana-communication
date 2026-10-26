<?php

declare(strict_types=1);

namespace Zana\Communication\Newsletter;

final readonly class Subscriber
{
    public const string PENDING = 'pending';
    public const string CONFIRMED = 'confirmed';

    public function __construct(
        public string $email,
        public string $locale,
        public string $status,
        public int $subscribedAt,
        public ?int $confirmedAt = null,
    ) {
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::CONFIRMED;
    }

    public function confirm(int $at): self
    {
        return new self($this->email, $this->locale, self::CONFIRMED, $this->subscribedAt, $at);
    }
}
