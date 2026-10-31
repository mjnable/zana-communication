<?php

declare(strict_types=1);

namespace Zana\Communication;

use Zana\Core\ApplicationInterface;
use Zana\Core\Deployment\CheckResult;
use Zana\Core\Deployment\DeploymentCheck;

/**
 * Warns when production would not send email (FE-43): mail.dsn is unset
 * (the default "memory://" records messages) or "null://" (discards them).
 * Forms then look successful while nobody receives anything.
 */
final readonly class MailDsnCheck implements DeploymentCheck
{
    public function check(ApplicationInterface $application): CheckResult
    {
        if (!$application->environment()->isProduction()) {
            return CheckResult::ok();
        }

        $dsn = $application->config()->string('mail.dsn', CommunicationServiceProvider::MEMORY_DSN);
        if ($dsn === CommunicationServiceProvider::MEMORY_DSN || str_starts_with(strtolower($dsn), 'null://')) {
            return CheckResult::warning("mail.dsn is {$dsn} in production: no email is sent. Set APP_MAIL__DSN, e.g. smtp://user:password@mail.example.com:587, and try it with: php bin/console mail:test you@example.com");
        }

        return CheckResult::ok();
    }
}
