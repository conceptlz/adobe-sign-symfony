<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony;

use Conceptlz\AdobeSign\AdobeSign;
use Conceptlz\AdobeSign\Auth\OAuthClient;
use Conceptlz\AdobeSign\Auth\OAuthConfig;
use Conceptlz\AdobeSign\Auth\OAuthTokenProvider;
use Conceptlz\AdobeSign\Auth\SystemClock;
use Conceptlz\AdobeSign\Auth\TokenStore;
use Conceptlz\AdobeSign\ClientOptions;
use Conceptlz\AdobeSign\Enum\SignatureLevel;
use Conceptlz\AdobeSign\Webhook\WebhookHandler;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Builds configured AdobeSign clients. Inject it (autowired) wherever you need a client:
 *
 *     public function __construct(private AdobeSignManager $adobeSign) {}
 *     $this->adobeSign->client()->agreements()->create(...);
 *     $this->adobeSign->forUser($user)->agreements()->all();
 *
 * Clients are cached for the life of the service and dropped on kernel.reset, so long-running
 * workers (Messenger consumers, FrankenPHP worker mode) start each request or message fresh.
 */
class AdobeSignManager implements ResetInterface
{
    private ?AdobeSign $default = null;

    /** @var array<string, AdobeSign> */
    private array $users = [];

    private ?OAuthClient $oauth = null;

    /**
     * @param array{
     *     auth: string|null,
     *     integration_key: string|null,
     *     oauth: array{client_id: string|null, client_secret: string|null, redirect_uri: string|null},
     *     signature_level: string|null,
     *     qualified_issuers: list<string>,
     *     bootstrap_url: string,
     *     base_uri_ttl: int,
     *     retries: array{max: int, max_delay_seconds: float},
     *     webhook_client_ids: list<string>,
     * } $config resolved bundle configuration (see AdobeSignBundle)
     *
     * @internal Created by the container from the adobe_sign configuration.
     */
    public function __construct(
        private readonly array $config,
        private readonly TokenStore $tokens,
        private readonly UserKeyResolver $userKeys,
        private readonly CacheInterface $cache,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ClientInterface $httpClient = null,
        private readonly ?UrlGeneratorInterface $urls = null,
        private readonly ?TokenStorageInterface $security = null,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    /**
     * The default client: the integration key, or with auth "oauth" the signed-in user's connection.
     *
     * @throws MisconfiguredException when the key is missing, or auth is "oauth" and nobody is
     *                                signed in or OAuth isn't configured
     */
    public function client(): AdobeSign
    {
        if ($this->authMode() === 'oauth') {
            $user = $this->security?->getToken()?->getUser();
            if (!$user instanceof UserInterface) {
                throw new MisconfiguredException('adobe_sign.auth is "oauth" but no user is signed in; use AdobeSignManager::forUser($user).');
            }

            return $this->forUser($user);
        }

        $key = (string) ($this->config['integration_key'] ?? '');
        if ($key === '') {
            throw new MisconfiguredException('Set adobe_sign.integration_key (usually "%env(ADOBE_SIGN_INTEGRATION_KEY)%"), or adobe_sign.auth: oauth.');
        }

        return $this->default ??= AdobeSign::withIntegrationKey($key, ...$this->dependencies());
    }

    /**
     * A client acting with the OAuth tokens this user connected (refreshed automatically).
     * A user who never connected only fails on the first API call, with OAuthException
     * (NO_TOKEN); check isConnected() first.
     *
     * @param UserInterface|string|int $user a user, or a key as produced by userKey()
     * @throws MisconfiguredException when the OAuth client id or secret is not configured
     */
    public function forUser(UserInterface|string|int $user): AdobeSign
    {
        $key = $this->userKey($user);

        return $this->users[$key] ??= new AdobeSign(
            new OAuthTokenProvider($this->oauth(), $this->tokens, $key, $this->clock ?? new SystemClock()),
            ...$this->dependencies(),
        );
    }

    /** Whether a token is stored for this user (it may still be expired or revoked). */
    public function isConnected(UserInterface|string|int $user): bool
    {
        return $this->tokens->get($this->userKey($user)) !== null;
    }

    /**
     * The OAuth client for the authorization-code flow. The redirect URI defaults to the absolute
     * URL of the adobe_sign_oauth_callback route.
     *
     * @throws MisconfiguredException when the client id or secret is not set, or no redirect URI can be built
     */
    public function oauth(): OAuthClient
    {
        if ($this->oauth !== null) {
            return $this->oauth;
        }

        $oauth = $this->config['oauth'];
        if (($oauth['client_id'] ?? '') === '' || ($oauth['client_secret'] ?? '') === '') {
            throw new MisconfiguredException('Set adobe_sign.oauth.client_id and client_secret to use OAuth.');
        }

        $redirect = $oauth['redirect_uri'] ?? '';
        if ($redirect === '') {
            if ($this->urls === null) {
                throw new MisconfiguredException('Set adobe_sign.oauth.redirect_uri: the router is not available to build it.');
            }
            $redirect = $this->urls->generate('adobe_sign_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $args = [new OAuthConfig((string) $oauth['client_id'], (string) $oauth['client_secret'], $redirect), $this->httpClient];
        if ($this->clock !== null) {
            $args['clock'] = $this->clock;
        }

        return $this->oauth = new OAuthClient(...$args);
    }

    /** The configured TokenStore (CacheTokenStore by default). */
    public function tokens(): TokenStore
    {
        return $this->tokens;
    }

    /**
     * A WebhookHandler allowlisting webhooks.client_ids, or oauth.client_id when that is empty.
     *
     * @throws MisconfiguredException when neither is set
     */
    public function webhookHandler(): WebhookHandler
    {
        $ids = array_values(array_filter($this->config['webhook_client_ids'], static fn ($id) => $id !== ''));
        if ($ids === [] && ($this->config['oauth']['client_id'] ?? '') !== '') {
            $ids = [(string) $this->config['oauth']['client_id']];
        }
        if ($ids === []) {
            throw new MisconfiguredException('Set adobe_sign.webhooks.client_ids (or adobe_sign.oauth.client_id) to receive webhooks.');
        }

        return new WebhookHandler($ids);
    }

    /**
     * The TokenStore key for a user: strings and ints are used as-is, users go through the
     * configured UserKeyResolver (by default their user identifier).
     */
    public function userKey(UserInterface|string|int $user): string
    {
        return $user instanceof UserInterface ? $this->userKeys->keyFor($user) : (string) $user;
    }

    /**
     * The project's default signature level (adobe_sign.signature_level).
     *
     * @throws MisconfiguredException for a value other than ses, aes or qes
     */
    public function signatureLevel(): SignatureLevel
    {
        $value = strtoupper(trim((string) ($this->config['signature_level'] ?? 'ses')));

        return SignatureLevel::tryFrom($value)
            ?? throw new MisconfiguredException(sprintf('adobe_sign.signature_level must be ses, aes or qes; got "%s".', $value));
    }

    /**
     * Certificate issuers accepted as qualified (adobe_sign.qualified_issuers).
     *
     * @return list<string>
     */
    public function qualifiedIssuers(): array
    {
        return $this->config['qualified_issuers'];
    }

    /**
     * Whether a completed agreement met the project's signature level, using the configured
     * qualified issuers.
     *
     * @return list<string> shortfalls; empty when every signer reached the level
     */
    public function signatureShortfalls(string $agreementId, ?SignatureLevel $required = null): array
    {
        return $this->client()->agreements()->signatureEvidence($agreementId)
            ->shortfalls($required ?? $this->signatureLevel(), $this->qualifiedIssuers());
    }

    /** Drop cached clients (kernel.reset), e.g. between Messenger messages. */
    public function reset(): void
    {
        $this->default = null;
        $this->users = [];
        $this->oauth = null;
    }

    /** @throws MisconfiguredException for an auth value other than integration_key or oauth */
    private function authMode(): string
    {
        $mode = strtolower(trim((string) ($this->config['auth'] ?? 'integration_key')));
        if (!in_array($mode, ['integration_key', 'oauth'], true)) {
            throw new MisconfiguredException(sprintf('adobe_sign.auth must be "integration_key" or "oauth"; got "%s".', $mode));
        }

        return $mode;
    }

    /**
     * Named constructor arguments shared by every client.
     *
     * @return array<string, mixed>
     */
    private function dependencies(): array
    {
        $deps = [
            'cache' => $this->cache,
            'logger' => $this->logger ?? new NullLogger(),
            'options' => new ClientOptions(
                bootstrapUrl: $this->config['bootstrap_url'],
                baseUriTtl: $this->config['base_uri_ttl'],
                maxRetries: $this->config['retries']['max'],
                maxRetryDelay: $this->config['retries']['max_delay_seconds'],
                defaultSignatureLevel: $this->signatureLevel(),
            ),
        ];
        if ($this->httpClient !== null) {
            $deps['httpClient'] = $this->httpClient;
        }

        return $deps;
    }
}
