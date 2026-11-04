<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Tests\Functional;

use Conceptlz\AdobeSign\Auth\TokenStore;
use Conceptlz\AdobeSign\Symfony\Event\OAuthConnectedEvent;
use Conceptlz\AdobeSign\Symfony\Tests\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class OAuthTest extends TestCase
{
    private const CALLBACK = 'http://localhost/adobe-sign/oauth/callback';

    /** @var list<OAuthConnectedEvent> */
    private array $connected = [];

    /**
     * @param array<string, mixed> $options
     */
    private function client(array $options = []): KernelBrowser
    {
        $client = static::createClient($options);
        $client->disableReboot();
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(OAuthConnectedEvent::class, function (OAuthConnectedEvent $e): void {
            $this->connected[] = $e;
        });

        return $client;
    }

    /** Start the flow and return the state Adobe would send back. */
    private static function startFlow(KernelBrowser $client): string
    {
        $client->request('GET', '/adobe-sign/oauth/redirect');
        self::assertResponseRedirects();
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);

        return $query['state'];
    }

    public function test_redirect_sends_the_user_to_adobe_with_state_scopes_and_callback(): void
    {
        $client = $this->client();
        self::signIn();
        $client->request('GET', '/adobe-sign/oauth/redirect');

        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://secure.adobesign.com/public/oauth/v2?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame(self::CLIENT_ID, $query['client_id']);
        self::assertSame(self::CALLBACK, $query['redirect_uri']);
        self::assertIsString($query['scope']);
        self::assertStringContainsString('agreement_send:self', $query['scope']);
        self::assertSame(64, strlen((string) $query['state']));
    }

    public function test_callback_stores_the_token_encrypted_and_dispatches_the_event(): void
    {
        $client = $this->client();
        self::signIn('jane@example.com');
        $state = self::startFlow($client);
        self::queue(200, 'oauth_token');

        $client->request('GET', '/adobe-sign/oauth/callback?' . http_build_query([
            'code' => 'auth-code', 'state' => $state, 'api_access_point' => 'https://api.eu1.adobesign.com/',
        ]));

        self::assertResponseRedirects('/');
        self::assertSame('https://api.eu1.adobesign.com/oauth/v2/token', (string) self::sent(0)->getUri());
        parse_str((string) self::sent(0)->getBody(), $form);
        self::assertSame(self::CALLBACK, $form['redirect_uri']);

        $token = static::getContainer()->get(TokenStore::class)?->get('jane@example.com');
        self::assertSame('3AAABLblqZhAccessTokenFromCodeExchange*', $token?->accessToken);
        self::assertCount(1, $this->connected);
        self::assertSame('jane@example.com', $this->connected[0]->key);
        self::assertSame('https://api.eu1.adobesign.com/', $this->connected[0]->apiAccessPoint);
    }

    public function test_callback_redirects_to_redirect_after(): void
    {
        $client = $this->client(['config' => ['oauth' => ['redirect_after' => '/settings/integrations']]]);
        self::signIn();
        $state = self::startFlow($client);
        self::queue(200, 'oauth_token');

        $client->request('GET', '/adobe-sign/oauth/callback?code=c&state=' . $state);

        self::assertResponseRedirects('/settings/integrations');
    }

    public function test_forged_or_replayed_state_is_400_without_calling_adobe(): void
    {
        $client = $this->client();
        self::signIn();
        $state = self::startFlow($client);

        $client->request('GET', '/adobe-sign/oauth/callback?code=c&state=attacker');
        self::assertResponseStatusCodeSame(400);

        // The state was single-use: the genuine one no longer works either.
        $client->request('GET', '/adobe-sign/oauth/callback?code=c&state=' . $state);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], self::http()->getRequests());
    }

    public function test_forged_access_point_is_400_and_the_secret_never_leaves(): void
    {
        $client = $this->client();
        self::signIn();
        $state = self::startFlow($client);

        $client->request('GET', '/adobe-sign/oauth/callback?' . http_build_query([
            'code' => 'c', 'state' => $state, 'api_access_point' => 'https://api.adobesign.com.evil.example/',
        ]));

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], self::http()->getRequests());
    }

    public function test_user_denying_access_is_403(): void
    {
        $client = $this->client();
        self::signIn();
        $state = self::startFlow($client);

        $client->request('GET', '/adobe-sign/oauth/callback?error=ACCESS_DENIED&error_description=nope&state=' . $state);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_callback_needs_a_signed_in_user(): void
    {
        $client = $this->client();
        $client->request('GET', '/adobe-sign/oauth/callback?code=c&state=s');

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], self::http()->getRequests());
    }
}
