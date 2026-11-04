<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Event;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A user finished the OAuth flow; their tokens are stored under $key.
 */
final class OAuthConnectedEvent extends Event
{
    public function __construct(
        public readonly UserInterface $user,
        /** TokenStore key, from AdobeSignManager::userKey(). */
        public readonly string $key,
        /** The account's API host, e.g. https://api.eu1.adobesign.com/ */
        public readonly ?string $apiAccessPoint,
    ) {
    }
}
