<?php

declare(strict_types=1);

namespace Zana\Communication\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Zana\Communication\CommunicationServiceProvider;
use Zana\Communication\MailException;
use Zana\Communication\Mailer;
use Zana\Communication\MailerInterface;
use Zana\Communication\Message;
use Zana\Configuration\ConfigurationInterface;
use Zana\Console\Command;

/**
 * php bin/console mail:test someone@example.com
 *
 * Sends a test message with the application's mail settings and prints the
 * mail server's answer (FE-43): a refused sender, a wrong password or port,
 * or a certificate that does not match. Passwords never appear: the DSN's is
 * masked, and the login lines of the SMTP conversation are hidden. On
 * shared hosting, run it from a cron job: its output arrives by email.
 */
#[AsCommand(name: 'mail:test', description: 'Send a test email and show the mail server\'s answer')]
final class MailTestCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('to', InputArgument::REQUIRED, 'The address to send the test to');
    }

    public function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io, ConfigurationInterface $config, MailerInterface $mailer): int
    {
        $dsn = $config->string('mail.dsn', CommunicationServiceProvider::MEMORY_DSN);
        $io->definitionList(['Mail DSN' => self::maskDsn($dsn)], ['From' => $config->has('mail.from') ? json_encode($config->get('mail.from'), JSON_UNESCAPED_SLASHES) : '(mail.from is not set)']);

        if (!$mailer instanceof Mailer) {
            $io->error("{$dsn} records messages and sends nothing. Set APP_MAIL__DSN, e.g. smtp://user:password@mail.example.com:587.");

            return self::FAILURE;
        }

        $message = Message::create()
            ->to((string) $input->getArgument('to'))
            ->subject('Zana mail test')
            ->text("This message tests the mail settings of {$config->string('app.url', 'the application')}.\n\nIt was sent by: php bin/console mail:test");

        try {
            $transcript = $mailer->deliver($message);
        } catch (MailException $e) {
            $previous = $e->getPrevious();
            $transcript = $previous instanceof TransportExceptionInterface ? $previous->getDebug() : '';
            self::transcript($io, $transcript);
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        self::transcript($io, $transcript);
        $io->success("The mail server accepted the message for {$input->getArgument('to')}. If it does not arrive, check the spam folder, then the sender's SPF and DKIM records.");

        return self::SUCCESS;
    }

    /**
     * The DSN with its password replaced: smtp://user:****@host:587.
     */
    public static function maskDsn(string $dsn): string
    {
        $dsn = (string) preg_replace('#^([a-z0-9+.-]+://[^:@/]*):[^@/]*@#i', '$1:****@', $dsn);

        return (string) preg_replace('/((?:^|[?&])(?:password|pass|token|key)=)[^&]*/i', '$1****', $dsn);
    }

    /**
     * The SMTP conversation without credentials: the AUTH command's argument,
     * and every client line answering a "334" challenge.
     */
    public static function hideCredentials(string $transcript): string
    {
        $lines = preg_split('/\R/', $transcript) ?: [];
        $challenge = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^(\[[^\]]*\]\s*)?>\s*AUTH\s+\S+\s+\S/i', $line) === 1) {
                $lines[$i] = (string) preg_replace('/(AUTH\s+\S+)\s+.*/i', '$1 [hidden]', $line);
            } elseif ($challenge && preg_match('/^(\[[^\]]*\]\s*)?>/', $line) === 1) {
                $lines[$i] = (string) preg_replace('/>.*/', '> [credentials hidden]', $line);
            }
            if (preg_match('/^(\[[^\]]*\]\s*)?</', $line) === 1) {
                $challenge = preg_match('/^(\[[^\]]*\]\s*)?<\s*334\b/', $line) === 1;
            }
        }

        return implode("\n", $lines);
    }

    private static function transcript(SymfonyStyle $io, string $transcript): void
    {
        if (trim($transcript) !== '') {
            $io->section('Mail server conversation');
            $io->writeln(self::hideCredentials(trim($transcript)), OutputInterface::OUTPUT_RAW);
        }
    }
}
