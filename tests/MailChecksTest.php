<?php

declare(strict_types=1);

namespace Zana\Communication\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Zana\Communication\CommunicationServiceProvider;
use Zana\Communication\Console\MailTestCommand;
use Zana\Communication\MailDsnCheck;
use Zana\Communication\Mailer;
use Zana\Communication\MailerInterface;
use Zana\Communication\MailRules;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\Application;
use Zana\Core\Deployment\CheckStatus;

/**
 * FE-43: production that sends no email is reported, and mail:test shows the
 * mail server's answer without ever showing a password.
 */
final class MailChecksTest extends TestCase
{
    public function testProductionWithoutARealTransportIsReported(): void
    {
        $check = new MailDsnCheck();

        $this->assertSame(CheckStatus::Warning, $check->check($this->app('production', []))->status, 'unset: memory://');
        $this->assertStringContainsString('mail.dsn is memory:// in production: no email is sent.', $check->check($this->app('production', []))->message);
        $this->assertSame(CheckStatus::Warning, $check->check($this->app('production', ['mail.dsn' => 'null://null']))->status);
        $this->assertSame(CheckStatus::Ok, $check->check($this->app('production', ['mail.dsn' => 'smtp://mail.example.com:587']))->status);
        $this->assertSame(CheckStatus::Ok, $check->check($this->app('development', []))->status, 'tests and development record mail on purpose');
    }

    public function testPasswordsAreMasked(): void
    {
        $this->assertSame('smtp://noreply%40acme.example:****@mail.acme.example:465', MailTestCommand::maskDsn('smtp://noreply%40acme.example:S3cret!@mail.acme.example:465'));
        $this->assertSame('smtp://mail.acme.example:587', MailTestCommand::maskDsn('smtp://mail.acme.example:587'));
        $this->assertSame('ses+api://KEY:****@default?region=eu-west-1&token=****', MailTestCommand::maskDsn('ses+api://KEY:SECRET@default?region=eu-west-1&token=abc'));
    }

    public function testTheSmtpLoginIsHidden(): void
    {
        $transcript = implode("\n", [
            '< 220 mail.acme.example ESMTP',
            '> EHLO [127.0.0.1]',
            '< 250-AUTH PLAIN LOGIN',
            '> AUTH PLAIN AG5vcmVwbHlAYWNtZS5leGFtcGxlAFMzY3JldCE=',
            '< 235 Authentication succeeded',
            '> AUTH LOGIN',
            '< 334 VXNlcm5hbWU6',
            '> bm9yZXBseUBhY21lLmV4YW1wbGU=',
            '< 334 UGFzc3dvcmQ6',
            '> UzNjcmV0IQ==',
            '< 235 Authentication succeeded',
            '> MAIL FROM:<noreply@acme.example>',
        ]);

        $hidden = MailTestCommand::hideCredentials($transcript);

        $this->assertStringContainsString('> AUTH PLAIN [hidden]', $hidden);
        $this->assertStringNotContainsString('AG5vcmVwbHlA', $hidden);
        $this->assertStringNotContainsString('bm9yZXBseUBh', $hidden);
        $this->assertStringNotContainsString('UzNjcmV0IQ==', $hidden);
        $this->assertSame(2, substr_count($hidden, '> [credentials hidden]'));
        $this->assertStringContainsString('> MAIL FROM:<noreply@acme.example>', $hidden, 'the rest of the conversation is kept');
    }

    public function testMailTestShowsTheServersAnswer(): void
    {
        [$code, $output] = $this->mailTest(['mail.dsn' => 'smtp://noreply:S3cret!@mail.acme.example:465'], new ConversationTransport());

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('smtp://noreply:****@mail.acme.example:465', $output);
        $this->assertStringContainsString('< 250 OK queued as 1A2B3C', $output);
        $this->assertStringContainsString('> AUTH PLAIN [hidden]', $output);
        $this->assertStringContainsString('The mail server accepted the message for ada@example.com', $output);
        $this->assertStringNotContainsString('S3cret!', $output);
    }

    public function testMailTestShowsWhyTheServerRefused(): void
    {
        [$code, $output] = $this->mailTest(['mail.dsn' => 'smtp://noreply:S3cret!@127.0.0.1:1'], null);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('could not be sent', $output);
        $this->assertStringNotContainsString('S3cret!', $output);
    }

    public function testMailTestRefusesTheRecorder(): void
    {
        [$code, $output] = $this->mailTest([], null);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('memory:// records messages and sends nothing.', $output);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function app(string $environment, array $config): Application
    {
        return Application::configure(sys_get_temp_dir() . '/zana-mail-checks')
            ->withEnvironment($environment)
            ->withConfiguration(['app.deployment_checks' => false, ...$config])
            ->build();
    }

    /**
     * @param array<string, mixed> $config
     * @return array{int, string}
     */
    private function mailTest(array $config, ?TransportInterface $transport): array
    {
        $kernel = Application::configure(sys_get_temp_dir() . '/zana-mail-test')
            ->withEnvironment('testing')
            ->withConfiguration(['mail.from' => 'noreply@acme.example', 'app.url' => 'https://acme.example', ...$config])
            ->withProviders(ConsoleServiceProvider::class, CommunicationServiceProvider::class)
            ->withServices(static fn (ServiceCollectionInterface $s) => $transport === null ? null : $s->replace(
                MailerInterface::class,
                static fn (ServiceResolverInterface $r): MailerInterface => new Mailer($r->get(MailRules::class), $transport),
            ))
            ->build()
            ->container()
            ->get(ConsoleKernel::class);
        assert($kernel instanceof ConsoleKernel);
        $kernel->application()->setAutoExit(false);

        $output = new BufferedOutput();
        $code = $kernel->run(new ArrayInput(['command' => 'mail:test', 'to' => 'ada@example.com']), $output);

        return [$code, $output->fetch()];
    }
}

/** A transport that accepts the message and reports an SMTP conversation. */
final class ConversationTransport implements TransportInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        $sent = new SentMessage($message, $envelope ?? Envelope::create($message));
        $sent->appendDebug("< 220 mail.acme.example ESMTP\n> AUTH PLAIN AG5vcmVwbHkAUzNjcmV0IQ==\n< 235 Authentication succeeded\n> MAIL FROM:<noreply@acme.example>\n< 250 OK queued as 1A2B3C\n");

        return $sent;
    }

    public function __toString(): string
    {
        return 'conversation://';
    }
}
