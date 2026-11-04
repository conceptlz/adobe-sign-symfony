<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\TokenStore;

use Conceptlz\AdobeSign\Auth\AccessToken;
use Conceptlz\AdobeSign\Symfony\MisconfiguredException;

/**
 * Encrypts stored OAuth tokens with libsodium (XSalsa20-Poly1305), using a key derived from
 * adobe_sign.oauth.encryption_key (kernel.secret by default). Tampered or foreign values are
 * rejected; values encrypted with another key decrypt to null, so the user simply reconnects.
 */
final class TokenEncryptor
{
    private const PREFIX = 'v1:';

    private ?string $key = null;

    public function __construct(#[\SensitiveParameter] private readonly string $secret)
    {
    }

    public function encrypt(AccessToken $token): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = json_encode($token->toArray(), JSON_THROW_ON_ERROR);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    /** Null when the value is not something this key encrypted (wrong key, tampered, garbage). */
    public function decrypt(string $value): ?AccessToken
    {
        if (!str_starts_with($value, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );
        if ($plain === false) {
            return null;
        }

        $data = json_decode($plain, true);
        if (!is_array($data) || !is_string($data['access_token'] ?? null)) {
            return null;
        }

        return AccessToken::fromArray([
            'access_token' => $data['access_token'],
            'refresh_token' => is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : null,
            'expires_at' => is_int($data['expires_at'] ?? null) ? $data['expires_at'] : null,
            'api_access_point' => is_string($data['api_access_point'] ?? null) ? $data['api_access_point'] : null,
        ]);
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        if ($this->secret === '') {
            throw new MisconfiguredException('adobe_sign.oauth.encryption_key is empty; set it or kernel.secret (APP_SECRET).');
        }

        // Domain-separated, so the key differs from other uses of the same secret.
        return $this->key = sodium_crypto_generichash('conceptlz/adobe-sign-symfony token store|' . $this->secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function __debugInfo(): array
    {
        return ['secret' => '***'];
    }
}
