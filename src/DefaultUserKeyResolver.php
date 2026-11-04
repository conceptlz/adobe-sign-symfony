<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Keys tokens by UserInterface::getUserIdentifier() (e.g. the email or username).
 */
final class DefaultUserKeyResolver implements UserKeyResolver
{
    public function keyFor(UserInterface $user): string
    {
        return $user->getUserIdentifier();
    }
}
