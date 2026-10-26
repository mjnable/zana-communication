<?php

declare(strict_types=1);

namespace Zana\Communication\Newsletter;

/**
 * Where subscribers live: the application's database (DatabaseSubscriberStore),
 * or a provider such as Brevo or Mailchimp behind the same interface.
 */
interface SubscriberStoreInterface
{
    public function find(string $email): ?Subscriber;

    public function save(Subscriber $subscriber): void;

    public function delete(string $email): void;

    /**
     * @return iterable<Subscriber>
     */
    public function confirmed(): iterable;
}
