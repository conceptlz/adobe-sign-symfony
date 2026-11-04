<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Maps a user to the key their OAuth tokens are stored under. Replace it to connect one Acrobat
 * Sign account per team or organisation instead of per user:
 *
 *     # config/packages/adobe_sign.yaml
 *     adobe_sign:
 *         oauth:
 *             user_key_resolver: App\AdobeSign\TeamKeyResolver
 */
interface UserKeyResolver
{
    public function keyFor(UserInterface $user): string;
}
