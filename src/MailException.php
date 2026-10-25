<?php

declare(strict_types=1);

namespace Zana\Communication;

/**
 * A message that breaks the rules, or a transport failure (with the original
 * as the previous exception).
 */
final class MailException extends \RuntimeException
{
}
