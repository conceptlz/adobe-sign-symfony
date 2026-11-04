# conceptlz/adobe-sign-symfony

**Full documentation: [conceptlz.github.io/adobe-sign-symfony](https://conceptlz.github.io/adobe-sign-symfony/)** (guides, reference, FAQ; also in [`docs/`](docs/index.html)).

Adobe Acrobat Sign for Symfony 7.4 and 8: send PDFs for e-signature, follow signing through
webhooks (optionally through Messenger), and store the signed documents.

This is the Symfony bundle for [`conceptlz/adobe-sign`](https://github.com/conceptlz/adobe-sign).
It adds:
- an `adobe_sign` configuration tree, with environment variables
- autowirable `AdobeSignManager` and `AdobeSign` services
- OAuth connect routes with encrypted token storage: a cache pool, or a database table through Doctrine DBAL
- a ready-made webhook endpoint that dispatches a Symfony event and, if you enable it, a Messenger message
- `adobe-sign:verify` and `adobe-sign:create-token-table` commands

All Acrobat Sign features, including the builders, resources and models, come from the core
package; its README is the API reference.

## Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Using the client](#using-the-client)
- [OAuth: users connect their own Acrobat Sign accounts](#oauth-users-connect-their-own-acrobat-sign-accounts)
- [Webhooks and Messenger](#webhooks-and-messenger)
- [Signature levels](#signature-levels)
- [Testing your application](#testing-your-application)

## Installation

```bash
composer require conceptlz/adobe-sign-symfony
```

Register the bundle if Flex didn't (`config/bundles.php`):

```php
Conceptlz\AdobeSign\Symfony\AdobeSignBundle::class => ['all' => true],
```

The SDK needs a PSR-18 HTTP client. If your app has none yet:

```bash
composer require symfony/http-client nyholm/psr7
```

Add your key to `.env.local` and check it:

```bash
ADOBE_SIGN_INTEGRATION_KEY=...
php bin/console adobe-sign:verify
```

## Configuration

Create `config/packages/adobe_sign.yaml`. Only `integration_key` is needed for Integration Key use:

```yaml
adobe_sign:
    integration_key: '%env(ADOBE_SIGN_INTEGRATION_KEY)%'

    # Everything below is optional; values shown are the defaults.
    auth: integration_key            # or "oauth": AdobeSign / client() use the signed-in user's connection
    signature_level: ses             # default for new agreements: ses, aes or qes
    qualified_issuers: []            # certificate issuers you accept as qualified (QES checks)
    cache_pool: cache.app            # data-centre lookup and webhook de-duplication
    http_client: ~                   # id of a PSR-18 service; default: the framework's, else discovery
    retries: { max: 3, max_delay_seconds: 60 }

    oauth:
        client_id: '%env(ADOBE_SIGN_CLIENT_ID)%'
        client_secret: '%env(ADOBE_SIGN_CLIENT_SECRET)%'
        redirect_uri: ~              # default: absolute URL of the adobe_sign_oauth_callback route
        redirect_after: /            # where users land after connecting
        token_store: cache           # "cache", "doctrine", or the id of your own TokenStore service
        token_cache_pool: cache.app  # use a persistent pool (Redis, database, filesystem)
        encryption_key: '%kernel.secret%'
        doctrine: { connection: default, table: adobe_sign_tokens }

    webhooks:
        client_ids: []               # default: [oauth.client_id]
        dedupe_ttl: 259200           # 72 hours, matching Adobe's retry window
        messenger:
            enabled: false
            bus: messenger.default_bus
```

Import the routes you use in `config/routes/adobe_sign.yaml`:

```yaml
adobe_sign:
    resource: '@AdobeSignBundle/config/routes.php'   # both; or routes/oauth.php / routes/webhook.php
```

| Route | Path | Purpose |
| --- | --- | --- |
| `adobe_sign_oauth_redirect` | `GET /adobe-sign/oauth/redirect` | send the signed-in user to Adobe |
| `adobe_sign_oauth_callback` | `GET /adobe-sign/oauth/callback` | store their tokens, dispatch `OAuthConnectedEvent` |
| `adobe_sign_webhook` | `GET, POST /adobe-sign/webhook` | Adobe's verification and notifications (stateless) |

## Using the client

Inject `AdobeSign` (the default client) or `AdobeSignManager` (to choose a client) anywhere:

```php
use Conceptlz\AdobeSign\AdobeSign;
use Conceptlz\AdobeSign\Builder\{AgreementInfo, FileInfo, ParticipantSetInfo};

final class ContractController
{
    public function __construct(private readonly AdobeSign $adobeSign) {}

    #[Route('/contracts/{id}/send', methods: ['POST'])]
    public function send(Contract $contract): Response
    {
        $documentId = $this->adobeSign->transientDocuments()->upload($contract->pdfPath());

        $agreementId = $this->adobeSign->agreements()->create(
            AgreementInfo::create($contract->title())
                ->file(FileInfo::transientDocument($documentId))
                ->participantSet(ParticipantSetInfo::signer($contract->clientEmail())->order(1))
                ->participantSet(ParticipantSetInfo::signer('ceo@example.com')->order(2)),
        );

        $contract->markSent($agreementId);
        // ...
    }
}
```

`AdobeSignManager` offers `client()`, `forUser($user)`, `isConnected($user)`, `oauth()`, `tokens()`,
`webhookHandler()`, `userKey($user)`, `signatureLevel()`, `qualifiedIssuers()` and
`signatureShortfalls($agreementId)`. Cached clients are dropped on `kernel.reset`, so Messenger
workers and FrankenPHP worker mode start fresh.

## OAuth: users connect their own Acrobat Sign accounts

1. Register an application in Acrobat Sign (*Account → Acrobat Sign API → API Applications*) with
   the redirect URI `https://your-app.example/adobe-sign/oauth/callback`.
2. Set `oauth.client_id` and `oauth.client_secret`, and import `routes/oauth.php`.
3. Require a signed-in user on both routes:

   ```yaml
   # config/packages/security.yaml
   security:
       access_control:
           - { path: ^/adobe-sign/oauth, roles: ROLE_USER }
   ```

4. Link to `path('adobe_sign_oauth_redirect')`. After consent the tokens are stored, encrypted with
   `kernel.secret`, under the user's identifier, and `OAuthConnectedEvent` is dispatched.

Then act as that user anywhere, including in a worker:

```php
$client = $manager->forUser($user);          // a UserInterface, or the stored key (e.g. the email)
$client->agreements()->all();                // tokens refresh automatically
```

**Token storage.** `token_store: cache` (default) keeps tokens in a cache pool: point
`token_cache_pool` at a persistent pool. `token_store: doctrine` uses a table through Doctrine DBAL
(`composer require doctrine/dbal`). With Doctrine ORM, `doctrine:migrations:diff` picks the table up;
otherwise run `bin/console adobe-sign:create-token-table`. To key tokens per team instead of per
user, implement `UserKeyResolver` and set `oauth.user_key_resolver` to its service id.

## Webhooks and Messenger

Import `routes/webhook.php`, set `webhooks.client_ids` (or rely on `oauth.client_id`), and register
the webhook with Adobe pointing at `https://your-app.example/adobe-sign/webhook`. The endpoint answers
Adobe's verification, rejects unknown client ids (403), drops retried duplicates and dispatches
`WebhookReceivedEvent`:

```php
use Conceptlz\AdobeSign\Symfony\Event\WebhookReceivedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class OnAdobeSignWebhook
{
    public function __invoke(WebhookReceivedEvent $webhook): void
    {
        // Runs inside Adobe's 5-second window: keep it quick.
    }
}
```

For real work, enable Messenger. The controller then also dispatches an `AdobeSignWebhookMessage`
(plain payload, safe for any transport), which you route to an async transport:

```yaml
# config/packages/adobe_sign.yaml
adobe_sign:
    webhooks:
        messenger: true

# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            Conceptlz\AdobeSign\Symfony\Message\AdobeSignWebhookMessage: async
```

```php
use Conceptlz\AdobeSign\AdobeSign;
use Conceptlz\AdobeSign\Symfony\Message\AdobeSignWebhookMessage;
use Conceptlz\AdobeSign\Webhook\AgreementEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StoreSignedAgreement
{
    public function __construct(private readonly AdobeSign $adobeSign, private readonly string $storageDir) {}

    public function __invoke(AdobeSignWebhookMessage $message): void
    {
        $event = $message->event();
        if (!$event instanceof AgreementEvent || !$event->isWorkflowCompleted()) {
            return;
        }

        $pdf = $this->adobeSign->agreements()->combinedDocument($event->agreementId(), attachAuditReport: true);
        file_put_contents("{$this->storageDir}/{$event->agreementId()}.pdf", $pdf->detach());
    }
}
```

## Signature levels

`signature_level` sets the default for every agreement the bundle's clients create (SES unless you
change it); agreements and individual signers can still override it with the core builders. After
signing, `$manager->signatureShortfalls($agreementId)` checks every signer against that level, using
`qualified_issuers` for QES. See the
[signature levels guide](https://conceptlz.github.io/adobe-sign/signature-levels.html).

## Testing your application

Point the bundle at a fake PSR-18 client in the test environment and queue responses:

```yaml
# config/packages/test/adobe_sign.yaml
adobe_sign:
    http_client: test.adobe_sign_http

services:
    test.adobe_sign_http:
        class: Http\Mock\Client      # composer require --dev php-http/mock-client
        public: true
```

To test a webhook flow, POST a payload to `/adobe-sign/webhook` with the `X-AdobeSign-ClientId`
header set to a configured client id, and assert on your listener or on the in-memory Messenger
transport.

## License

MIT. An independent project by Conceptualize, not affiliated with or endorsed by Adobe.
