<?php

declare(strict_types=1);

namespace Zana\Communication\Newsletter;

use Zana\Database\ConnectionInterface;
use Zana\Database\Schema\Blueprint;

/**
 * Subscribers in a table of the application's database (mj-zana/database).
 * Create the table in a migration with DatabaseSubscriberStore::createTable().
 */
final readonly class DatabaseSubscriberStore implements SubscriberStoreInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'newsletter_subscribers',
    ) {
    }

    public static function createTable(ConnectionInterface $connection, string $table = 'newsletter_subscribers'): void
    {
        $connection->schema()->create($table, static function (Blueprint $t): void {
            $t->string('email', 254)->primary();
            $t->string('locale', 16);
            $t->string('status', 16);
            $t->integer('subscribed_at');
            $t->integer('confirmed_at')->nullable();
            $t->index('status');
        });
    }

    public function find(string $email): ?Subscriber
    {
        $row = $this->connection->table($this->table)->where('email', strtolower($email))->first();

        return $row === null ? null : self::subscriber($row);
    }

    public function save(Subscriber $subscriber): void
    {
        $values = [
            'locale' => $subscriber->locale,
            'status' => $subscriber->status,
            'subscribed_at' => $subscriber->subscribedAt,
            'confirmed_at' => $subscriber->confirmedAt,
        ];
        $email = strtolower($subscriber->email);
        $this->connection->transaction(function () use ($email, $values): void {
            if ($this->connection->table($this->table)->where('email', $email)->exists()) {
                $this->connection->table($this->table)->where('email', $email)->update($values);
            } else {
                $this->connection->table($this->table)->insert(['email' => $email, ...$values]);
            }
        });
    }

    public function delete(string $email): void
    {
        $this->connection->table($this->table)->where('email', strtolower($email))->delete();
    }

    public function confirmed(): iterable
    {
        foreach ($this->connection->table($this->table)->where('status', Subscriber::CONFIRMED)->orderBy('email')->get() as $row) {
            yield self::subscriber($row);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function subscriber(array $row): Subscriber
    {
        return new Subscriber(
            (string) $row['email'],
            (string) $row['locale'],
            (string) $row['status'],
            (int) $row['subscribed_at'],
            $row['confirmed_at'] === null ? null : (int) $row['confirmed_at'],
        );
    }
}
