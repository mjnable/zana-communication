<?php

declare(strict_types=1);

namespace Zana\Communication\Newsletter;

use Closure;
use Zana\Communication\Address;
use Zana\Communication\MailerInterface;
use Zana\Communication\MailException;
use Zana\Communication\Message;

/**
 * Newsletter subscriptions with double opt-in (FE-35):
 *
 *     $newsletter->subscribe($email, $locale, fn (string $token): Message => Message::create()
 *         ->subject($t->translate('newsletter.confirm.subject'))
 *         ->text($t->translate('newsletter.confirm.body', ['url' => $urls->generate('newsletter.confirm', ['token' => $token])])));
 *
 *     $newsletter->confirm($token);       // from the link in that email
 *     $newsletter->unsubscribe($token);   // from the link in every newsletter
 *
 * - An address is added only after its owner confirms it; the link expires.
 * - subscribe() behaves the same for new and existing addresses, so the form
 *   does not reveal who is subscribed.
 * - Every newsletter carries List-Unsubscribe and one-click unsubscribe
 *   headers; unsubscribe links work without login and delete the address.
 * - Links are signed with HMAC-SHA256 (mail.newsletter.secret, else
 *   security.secret).
 */
final readonly class Newsletter
{
    /** @var Closure(): int */
    private Closure $now;

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private SubscriberStoreInterface $store,
        private MailerInterface $mailer,
        private string $secret,
        private int $confirmationLifetime = 604800,
        ?Closure $clock = null,
    ) {
        if (strlen($secret) < 32) {
            throw new MailException('The newsletter secret must be at least 32 bytes.');
        }
        $this->now = $clock ?? static fn (): int => time();
    }

    /**
     * Record a pending subscription and send the confirmation email.
     *
     * @param callable(string $token, Subscriber $subscriber): Message $compose The confirmation message, linking to $token
     */
    public function subscribe(string $email, string $locale, callable $compose): void
    {
        $address = new Address(strtolower(trim($email)));
        $existing = $this->store->find($address->email);
        if ($existing?->isConfirmed() === true) {
            return;   // already subscribed: say nothing, change nothing
        }

        $subscriber = new Subscriber($address->email, $locale, Subscriber::PENDING, ($this->now)());
        $this->store->save($subscriber);
        $this->mailer->send($compose($this->token('confirm', $address->email, ($this->now)() + $this->confirmationLifetime), $subscriber)->to($address));
    }

    public function confirm(string $token): ?Subscriber
    {
        $email = $this->verify($token, 'confirm');
        $subscriber = $email === null ? null : $this->store->find($email);
        if ($subscriber === null) {
            return null;
        }
        if (!$subscriber->isConfirmed()) {
            $subscriber = $subscriber->confirm(($this->now)());
            $this->store->save($subscriber);
        }

        return $subscriber;
    }

    public function unsubscribe(string $token): bool
    {
        $email = $this->verify($token, 'unsubscribe');
        if ($email === null || $this->store->find($email) === null) {
            return false;
        }
        $this->store->delete($email);

        return true;
    }

    public function unsubscribeToken(string $email): string
    {
        return $this->token('unsubscribe', strtolower($email), null);
    }

    /**
     * Send a newsletter to every confirmed subscriber.
     *
     * @param callable(Subscriber $subscriber): Message $compose The message for one subscriber (in their locale)
     * @param callable(string $token): string $unsubscribeUrl The absolute unsubscribe URL for a token
     * @return int How many were sent
     */
    public function send(callable $compose, callable $unsubscribeUrl): int
    {
        $sent = 0;
        foreach ($this->store->confirmed() as $subscriber) {
            $url = $unsubscribeUrl($this->unsubscribeToken($subscriber->email));
            $this->mailer->send($compose($subscriber)
                ->to($subscriber->email)
                ->header('List-Unsubscribe', "<{$url}>")
                ->header('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click'));
            $sent++;
        }

        return $sent;
    }

    private function token(string $purpose, string $email, ?int $expires): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['p' => $purpose, 'e' => strtolower($email), 'x' => $expires], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $payload . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->secret, true)), '+/', '-_'), '=');
    }

    private function verify(string $token, string $purpose): ?string
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->secret, true)), '+/', '-_'), '=');
        if ($payload === '' || !hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (!is_array($data) || ($data['p'] ?? null) !== $purpose || !is_string($data['e'] ?? null)) {
            return null;
        }
        if (is_int($data['x'] ?? null) && $data['x'] < ($this->now)()) {
            return null;
        }

        return $data['e'];
    }
}
