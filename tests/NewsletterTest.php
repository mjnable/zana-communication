<?php

declare(strict_types=1);

namespace Zana\Communication\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Communication\Address;
use Zana\Communication\MailRecorder;
use Zana\Communication\MailRules;
use Zana\Communication\Message;
use Zana\Communication\Newsletter\DatabaseSubscriberStore;
use Zana\Communication\Newsletter\InMemorySubscriberStore;
use Zana\Communication\Newsletter\Newsletter;
use Zana\Communication\Newsletter\Subscriber;
use Zana\Communication\Newsletter\SubscriberStoreInterface;
use Zana\Database\ConnectionFactory;

final class NewsletterTest extends TestCase
{
    private int $now = 1_800_000_000;

    private MailRecorder $mail;

    protected function setUp(): void
    {
        $this->mail = new MailRecorder(new MailRules(new Address('news@acme.example')));
    }

    public function testDoubleOptIn(): void
    {
        [$newsletter, $store] = $this->newsletter();

        $newsletter->subscribe(' Ada@Example.com ', 'fr', self::confirmation(...));

        $this->assertSame(Subscriber::PENDING, $store->find('ada@example.com')?->status);
        $sent = $this->mail->last();
        $this->assertNotNull($sent);
        $this->assertSame('ada@example.com', $sent->to[0]->email);
        $token = self::tokenIn((string) $sent->text);

        $this->assertSame([], iterator_to_array($store->confirmed()), 'not before confirming');
        $confirmed = $newsletter->confirm($token);
        $this->assertNotNull($confirmed);
        $this->assertTrue($confirmed->isConfirmed());
        $this->assertSame($this->now, $confirmed->confirmedAt);
        $this->assertEquals($confirmed, $store->find('ada@example.com'));
    }

    public function testConfirmationLinksExpireAndCannotBeForged(): void
    {
        [$newsletter] = $this->newsletter();
        $newsletter->subscribe('ada@example.com', 'en', self::confirmation(...));
        $token = self::tokenIn((string) $this->mail->last()?->text);

        $this->assertNull($newsletter->confirm($token . 'x'));
        $this->assertNull($newsletter->confirm('garbage'));
        $this->assertNull($newsletter->confirm($newsletter->unsubscribeToken('ada@example.com')), 'another purpose');

        $this->now += 604801;
        $this->assertNull($newsletter->confirm($token), 'expired');
    }

    public function testSubscribingTwiceRevealsNothing(): void
    {
        [$newsletter] = $this->newsletter();
        $newsletter->subscribe('ada@example.com', 'en', self::confirmation(...));
        $newsletter->confirm(self::tokenIn((string) $this->mail->last()?->text));
        $this->mail->clear();

        $newsletter->subscribe('ada@example.com', 'en', self::confirmation(...));

        $this->assertSame(0, $this->mail->count(), 'confirmed addresses get no new email');
    }

    public function testNewslettersCarryOneClickUnsubscribe(): void
    {
        [$newsletter, $store] = $this->newsletter();
        $store->save(new Subscriber('ada@example.com', 'en', Subscriber::CONFIRMED, $this->now, $this->now));
        $store->save(new Subscriber('bob@example.com', 'fr', Subscriber::CONFIRMED, $this->now, $this->now));
        $store->save(new Subscriber('eve@example.com', 'en', Subscriber::PENDING, $this->now));

        $count = $newsletter->send(
            static fn (Subscriber $s): Message => Message::create()->subject($s->locale === 'fr' ? 'Nouvelles' : 'News')->text('…'),
            static fn (string $token): string => "https://acme.example/newsletter/unsubscribe/{$token}",
        );

        $this->assertSame(2, $count);
        $first = $this->mail->sent()[0];
        $this->assertStringStartsWith('<https://acme.example/newsletter/unsubscribe/', $first->headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $first->headers['List-Unsubscribe-Post']);

        preg_match('#/unsubscribe/([^>]+)>#', $first->headers['List-Unsubscribe'], $match);
        $this->assertTrue($newsletter->unsubscribe($match[1] ?? ''));
        $this->assertNull($store->find($first->to[0]->email), 'the address is deleted');
        $this->assertFalse($newsletter->unsubscribe($match[1] ?? ''));
    }

    public function testTheDatabaseStore(): void
    {
        $connection = ConnectionFactory::create(['driver' => 'sqlite', 'database' => ':memory:']);
        DatabaseSubscriberStore::createTable($connection);
        $store = new DatabaseSubscriberStore($connection);

        $store->save(new Subscriber('Ada@Example.com', 'fr', Subscriber::PENDING, 100));
        $store->save(($store->find('ada@example.com') ?? throw new \LogicException())->confirm(200));
        $store->save(new Subscriber('bob@example.com', 'en', Subscriber::PENDING, 150));

        $this->assertEquals(new Subscriber('ada@example.com', 'fr', Subscriber::CONFIRMED, 100, 200), $store->find('ADA@example.com'));
        $this->assertSame(['ada@example.com'], array_map(static fn (Subscriber $s): string => $s->email, iterator_to_array($store->confirmed(), false)));
        $store->delete('ada@example.com');
        $this->assertNull($store->find('ada@example.com'));
    }

    /**
     * @return array{Newsletter, SubscriberStoreInterface}
     */
    private function newsletter(): array
    {
        $store = new InMemorySubscriberStore();

        return [new Newsletter($store, $this->mail, str_repeat('n', 32), clock: fn (): int => $this->now), $store];
    }

    private static function confirmation(string $token, Subscriber $subscriber): Message
    {
        return Message::create()->subject($subscriber->locale === 'fr' ? 'Confirmez' : 'Confirm')->text("https://acme.example/newsletter/confirm/{$token}");
    }

    private static function tokenIn(string $text): string
    {
        return substr($text, strrpos($text, '/') + 1);
    }
}
