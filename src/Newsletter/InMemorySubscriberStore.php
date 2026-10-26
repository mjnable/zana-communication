<?php

declare(strict_types=1);

namespace Zana\Communication\Newsletter;

/**
 * Subscribers in memory, for tests.
 */
final class InMemorySubscriberStore implements SubscriberStoreInterface
{
    /** @var array<string, Subscriber> */
    private array $subscribers = [];

    public function find(string $email): ?Subscriber
    {
        return $this->subscribers[strtolower($email)] ?? null;
    }

    public function save(Subscriber $subscriber): void
    {
        $this->subscribers[strtolower($subscriber->email)] = $subscriber;
    }

    public function delete(string $email): void
    {
        unset($this->subscribers[strtolower($email)]);
    }

    public function confirmed(): iterable
    {
        return array_values(array_filter($this->subscribers, static fn (Subscriber $s): bool => $s->isConfirmed()));
    }
}
