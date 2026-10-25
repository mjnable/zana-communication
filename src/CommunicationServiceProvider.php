<?php

declare(strict_types=1);

namespace Zana\Communication;

use Symfony\Component\Mailer\Transport;
use Zana\Configuration\ConfigurationInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\ServiceProvider;

/**
 * Registers the mailer. Reads:
 *
 * - mail.dsn: a Symfony Mailer DSN (smtp://user:pass@host:587,
 *   sendmail://default, null://null), or "memory://" (the default) for
 *   MailRecorder;
 * - mail.from (required to send): an address, or ['address' => ..., 'name' => ...];
 * - mail.allowed_from: other addresses the application may send from;
 * - mail.reply_to: the default Reply-To.
 */
final class CommunicationServiceProvider extends ServiceProvider
{
    public const string MEMORY_DSN = 'memory://';

    public function register(ServiceCollectionInterface $services): void
    {
        $services
            ->singleton(MailRules::class, static function (ServiceResolverInterface $r): MailRules {
                $config = $r->get(ConfigurationInterface::class);
                if (!$config->has('mail.from')) {
                    throw new MailException('Set mail.from, the address the application sends from.');
                }
                /** @var string|array{address: string, name?: string} $from */
                $from = $config->get('mail.from');
                /** @var list<string|array{address: string, name?: string}> $allowed */
                $allowed = $config->array('mail.allowed_from', []);
                /** @var string|array{address: string, name?: string}|null $replyTo */
                $replyTo = $config->get('mail.reply_to');

                return new MailRules(
                    Address::from($from),
                    array_map(Address::from(...), $allowed),
                    $replyTo === null || $replyTo === '' ? null : Address::from($replyTo),
                );
            })
            ->singleton(MailRecorder::class)
            ->singleton(MailerInterface::class, static function (ServiceResolverInterface $r): MailerInterface {
                $dsn = $r->get(ConfigurationInterface::class)->string('mail.dsn', self::MEMORY_DSN);
                $rules = $r->get(MailRules::class);
                assert($rules instanceof MailRules);

                if ($dsn === self::MEMORY_DSN) {
                    $recorder = $r->get(MailRecorder::class);
                    assert($recorder instanceof MailRecorder);

                    return $recorder;
                }

                return new Mailer($rules, Transport::fromDsn($dsn));
            });
    }

    public function origin(): string
    {
        return 'package:mj-zana/communication';
    }
}
