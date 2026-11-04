<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony;

use Conceptlz\AdobeSign\Exception\AdobeSignException;

/**
 * A feature was used without the configuration it needs; the message names the setting.
 */
final class MisconfiguredException extends \RuntimeException implements AdobeSignException
{
}
