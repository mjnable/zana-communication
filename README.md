# mj-zana/communication

Email for Zana applications: immutable messages, a configured sender,
Symfony Mailer transports, and a recorder for tests.

```
composer require mj-zana/communication
```

## Setup

Register `CommunicationServiceProvider` and configure the sender:

```php
// config/mail.php
return [
    'dsn' => 'smtp://user:pass@mail.example.com:587',   // memory:// records instead (the default)
    'from' => ['address' => 'website@example.com', 'name' => 'Example'],
    'reply_to' => 'contact@example.com',               // optional default Reply-To
];
```

Keep the DSN secret: set it from the environment, e.g.
`APP_MAIL__DSN=smtp://...`.

## Sending

```php
$mailer->send(Message::create()
    ->to('contact@example.com')
    ->replyTo($visitor->email)
    ->subject('New message from the website')
    ->text($body));
```

## Rules

- **From comes from configuration.** A message without `from()` gets
  `mail.from`. Any other From must be listed in `mail.allowed_from`. A visitor's
  address goes in Reply-To, never in From: this keeps SPF and DMARC aligned
  and prevents spoofing.
- **Required:** a recipient, a subject, and a text or HTML body.
- **No header injection:** line breaks in subjects, header values and names
  are refused.
- **Transport failures** become `MailException`.

## Testing

With `mail.dsn: memory://`, the mailer is a `MailRecorder`, which applies the
same rules:

```php
$recorder = $container->get(MailRecorder::class);
$this->assertCount(1, $recorder->sent(fn (Message $m): bool => $m->subject === 'Welcome'));
```

`mj-zana/testing` adds `assertMailSent()` and `assertNoMailSent()`.

## License

MIT
