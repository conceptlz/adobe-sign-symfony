# conceptlz/adobe-sign-symfony (bundle)

Thin Symfony 7.4 / 8.x bundle over `conceptlz/adobe-sign`. Read the monorepo root `CLAUDE.md` first.

**Rule:** API behaviour belongs in the core package. This bundle only wires the core into Symfony:
configuration, services, routes, token persistence, events and Messenger. Keep it behaviourally in step
with the Laravel bridge (`packages/adobe-sign-laravel`): same defaults, same webhook semantics, same
error messages, adapted to Symfony conventions.

## Pieces

| File | Role |
| --- | --- |
| `src/AdobeSignBundle.php` | `AbstractBundle`: the `adobe_sign` config tree and all service definitions (no XML/YAML service files) |
| `src/AdobeSignManager.php` | Builds clients: `client()`, `forUser()`, `isConnected()`, `oauth()`, `tokens()`, `webhookHandler()`, `userKey()`, signature-level helpers; `reset()` on `kernel.reset` |
| `src/UserKeyResolver.php`, `src/DefaultUserKeyResolver.php` | Map a `UserInterface` to its token key (default: `getUserIdentifier()`) |
| `src/TokenStore/TokenEncryptor.php` | libsodium secretbox, key derived from `oauth.encryption_key` (`kernel.secret`) |
| `src/TokenStore/CacheTokenStore.php` | Default store: a PSR-6 pool, hashed keys, encrypted values |
| `src/TokenStore/DoctrineDbalTokenStore.php` | Optional DBAL store (`token_store: doctrine`): table, upsert, `configureSchema()`, `createTable()` |
| `src/TokenStore/DoctrineSchemaListener.php` | Adds the table to the ORM schema (`postGenerateSchema`); registered only when doctrine/orm exists |
| `src/Controller/OAuthController.php`, `src/Controller/WebhookController.php` | Connect/callback and the webhook endpoint |
| `src/Event/*`, `src/Message/AdobeSignWebhookMessage.php` | `OAuthConnectedEvent`, `WebhookReceivedEvent`; the Messenger message (plain payload, `event()` rebuilds the typed event) |
| `src/Command/*` | `adobe-sign:verify`, `adobe-sign:create-token-table` |
| `config/routes.php`, `config/routes/*.php` | Importable routes: `adobe_sign_oauth_redirect`, `adobe_sign_oauth_callback`, `adobe_sign_webhook` |

Doctrine DBAL/ORM, Messenger and Security are optional: never `use` them in code that runs without
them, and register their services conditionally in `loadExtension()`.

## Testing

```bash
composer install && composer test     # functional tests on a MicroKernel (tests/App/TestKernel.php)
composer analyse                      # PHPStan level 8 (src + config)
```

`TestKernel` takes the `adobe_sign` config per test (`self::bootKernel(['config' => [...], 'messenger' => true])`)
and wires a mock PSR-18 client (`test.http_client`), a token storage, an SQLite DBAL connection and a
non-resetting cache pool. Use `$client->disableReboot()` when a test spans requests. CI runs Symfony 7.4
(PHP 8.2–8.4) and 8.x (PHP 8.4–8.5); with Symfony 7.4.0 itself PHPUnit reports handler-related risky
tests, which later 7.4 patches fix.

## Dependency on the core

Requires `conceptlz/adobe-sign` `^0.4`, resolved locally to `../adobe-sign` through a path repository
pinned to `0.4.x-dev`. Bump both, and `branch-alias`, on every minor release (see Releasing in the root
`CLAUDE.md`).
