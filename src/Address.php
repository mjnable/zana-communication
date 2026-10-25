<?php

declare(strict_types=1);

namespace Zana\Communication;

/**
 * An email address with an optional display name.
 */
final readonly class Address implements \Stringable
{
    /**
     * @throws MailException For an invalid address, or a name with line breaks
     */
    public function __construct(
        public string $email,
        public string $name = '',
    ) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException("\"{$email}\" is not a valid email address.");
        }
        if (preg_match('/[\r\n]/', $name) === 1) {
            throw new MailException('An address name cannot contain line breaks.');
        }
    }

    /**
     * @param string|Address|array{address: string, name?: string} $address
     */
    public static function from(string|self|array $address): self
    {
        return match (true) {
            $address instanceof self => $address,
            is_array($address) => new self($address['address'], $address['name'] ?? ''),
            default => new self($address),
        };
    }

    public function equals(self $other): bool
    {
        return strcasecmp($this->email, $other->email) === 0;
    }

    public function __toString(): string
    {
        return $this->name === '' ? $this->email : "{$this->name} <{$this->email}>";
    }
}
