<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\TokenStore;

use Conceptlz\AdobeSign\Auth\AccessToken;
use Conceptlz\AdobeSign\Auth\TokenStore;
use Psr\Cache\CacheItemPoolInterface;

/**
 * TokenStore in a cache pool (token_store: cache, the default), encrypted with TokenEncryptor.
 * Items never expire, but a cache can still be cleared or evict entries: use a persistent pool
 * (Redis, database, filesystem), or token_store: doctrine for a database table.
 */
final class CacheTokenStore implements TokenStore
{
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly TokenEncryptor $encryptor,
    ) {
    }

    public function get(string $key): ?AccessToken
    {
        $item = $this->pool->getItem(self::itemKey($key));
        $value = $item->get();

        return $item->isHit() && is_string($value) ? $this->encryptor->decrypt($value) : null;
    }

    public function put(string $key, AccessToken $token): void
    {
        $item = $this->pool->getItem(self::itemKey($key));
        $this->pool->save($item->set($this->encryptor->encrypt($token))->expiresAfter(null));
    }

    /**
     * Delete the stored token, e.g. when a user disconnects. Not part of TokenStore. This does not
     * revoke it at Adobe; call OAuthClient::revoke() first if that matters.
     */
    public function forget(string $key): void
    {
        $this->pool->deleteItem(self::itemKey($key));
    }

    /** User keys are often emails; PSR-6 keys can't contain "@" and a few other characters. */
    private static function itemKey(string $key): string
    {
        return 'adobe_sign.token.' . hash('sha256', $key);
    }
}
