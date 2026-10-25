<?php

declare(strict_types=1);

namespace Zana\Communication\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Zana\Communication\Address;
use Zana\Communication\CommunicationServiceProvider;
use Zana\Communication\Mailer;
use Zana\Communication\MailerInterface;
use Zana\Communication\MailException;
use Zana\Communication\MailRecorder;
use Zana\Communication\MailRules;
use Zana\Communication\Message;
use Zana\Core\Application;

final class MailTest extends TestCase
{
    public function testMessagesAreImmutable(): void
    {
        $base = Message::create()->to('a@example.com');
        $more = $base->to('b@example.com')->subject('Hi')->text('Hello');

        $this->assertCount(1, $base->to);
        $this->assertSame(['a@example.com', 'b@example.com'], array_map(static fn (Address $a): string => $a->email, $more->to));
        $this->assertSame('', $base->subject);
    }

    public function testHeaderInjectionIsRefused(): void
    {
        foreach ([
            static fn () => Message::create()->subject("Hi\r\nBcc: victim@example.com"),
            static fn () => Message::create()->header('X-Tag', "a\nb"),
            static fn () => Message::create()->header('Bad Name', 'x'),
            static fn () => new Address('a@example.com', "Ada\r\nBcc: x@example.com"),
            static fn () => Message::create()->to('not-an-address'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected a MailException.');
            } catch (MailException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFromComesFromConfiguration(): void
    {
        $rules = new MailRules(new Address('site@example.com', 'Site'), [new Address('news@example.com')], new Address('contact@example.com'));

        $sent = $rules->apply(self::message());
        $this->assertSame('Site <site@example.com>', (string) $sent->from);
        $this->assertSame(['contact@example.com'], array_map(static fn (Address $a): string => $a->email, $sent->replyTo));

        $this->assertSame('NEWS@example.com', $rules->apply(self::message()->from('NEWS@example.com'))->from?->email, 'allowed, case-insensitive');

        $visitor = $rules->apply(self::message()->replyTo('visitor@elsewhere.example'));
        $this->assertSame('visitor@elsewhere.example', $visitor->replyTo[0]->email, 'a given Reply-To is kept');

        $this->expectExceptionMessage('The application may not send from visitor@elsewhere.example');
        $rules->apply(self::message()->from('visitor@elsewhere.example'));
    }

    public function testIncompleteMessagesAreRefused(): void
    {
        $rules = new MailRules(new Address('site@example.com'));

        foreach ([
            'A message needs at least one recipient.' => Message::create()->subject('Hi')->text('x'),
            'A message needs a subject.' => Message::create()->to('a@example.com')->text('x'),
            'A message needs a text or HTML body.' => Message::create()->to('a@example.com')->subject('Hi'),
        ] as $error => $message) {
            try {
                $rules->apply($message);
                $this->fail("Expected: {$error}");
            } catch (MailException $e) {
                $this->assertSame($error, $e->getMessage());
            }
        }
    }

    public function testMessagesBecomeSymfonyEmails(): void
    {
        $transport = new RecordingTransport();
        (new Mailer(new MailRules(new Address('site@example.com', 'Site')), $transport))->send(
            self::message()->cc('c@example.com')->bcc('b@example.com')->html('<p>Hello</p>')->header('X-Form', 'contact')
        );

        $email = $transport->emails[0];
        $this->assertSame('"Site" <site@example.com>', $email->getFrom()[0]->toString());
        $this->assertSame('to@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('c@example.com', $email->getCc()[0]->getAddress());
        $this->assertSame('b@example.com', $email->getBcc()[0]->getAddress());
        $this->assertSame('Website message', $email->getSubject());
        $this->assertSame('Hello', $email->getTextBody());
        $this->assertSame('<p>Hello</p>', $email->getHtmlBody());
        $this->assertSame('contact', $email->getHeaders()->get('X-Form')?->getBodyAsString());
    }

    public function testTransportFailuresAreWrapped(): void
    {
        $transport = new RecordingTransport(fail: true);

        try {
            (new Mailer(new MailRules(new Address('site@example.com')), $transport))->send(self::message());
            $this->fail('Expected a MailException.');
        } catch (MailException $e) {
            $this->assertSame('The message "Website message" could not be sent: Connection refused', $e->getMessage());
            $this->assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    public function testTheMemoryDsnRecordsMessages(): void
    {
        $container = Application::configure(sys_get_temp_dir() . '/zana-communication')
            ->withEnvironment('testing')
            ->withConfiguration(['mail.from' => ['address' => 'site@example.com', 'name' => 'Site']])
            ->withProviders(CommunicationServiceProvider::class)
            ->build()
            ->container();

        $mailer = $container->get(MailerInterface::class);
        $recorder = $container->get(MailRecorder::class);
        assert($mailer instanceof MailerInterface && $recorder instanceof MailRecorder);

        $mailer->send(self::message());
        $mailer->send(self::message()->subject('Second'));

        $this->assertSame($recorder, $mailer);
        $this->assertSame(2, $recorder->count());
        $this->assertSame('site@example.com', $recorder->last()?->from?->email, 'the rules were applied');
        $this->assertCount(1, $recorder->sent(static fn (Message $m): bool => $m->subject === 'Second'));

        $recorder->clear();
        $this->assertSame([], $recorder->sent());
    }

    public function testARealDsnBuildsATransport(): void
    {
        $mailer = Application::configure(sys_get_temp_dir() . '/zana-communication')
            ->withEnvironment('testing')
            ->withConfiguration(['mail.from' => 'site@example.com', 'mail.dsn' => 'null://null'])
            ->withProviders(CommunicationServiceProvider::class)
            ->build()
            ->container()
            ->get(MailerInterface::class);

        $this->assertInstanceOf(Mailer::class, $mailer);
        $mailer->send(self::message());
    }

    private static function message(): Message
    {
        return Message::create()->to('to@example.com')->subject('Website message')->text('Hello');
    }
}

final class RecordingTransport implements TransportInterface
{
    /** @var list<Email> */
    public array $emails = [];

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->fail) {
            throw new TransportException('Connection refused');
        }
        assert($message instanceof Email);
        $this->emails[] = $message;

        return null;
    }

    public function __toString(): string
    {
        return 'recording://';
    }
}
