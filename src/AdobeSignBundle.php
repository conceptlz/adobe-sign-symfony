<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony;

use Conceptlz\AdobeSign\AdobeSign;
use Conceptlz\AdobeSign\Auth\TokenStore;
use Conceptlz\AdobeSign\Symfony\Command\CreateTokenTableCommand;
use Conceptlz\AdobeSign\Symfony\Command\VerifyCommand;
use Conceptlz\AdobeSign\Symfony\Controller\OAuthController;
use Conceptlz\AdobeSign\Symfony\Controller\WebhookController;
use Conceptlz\AdobeSign\Symfony\TokenStore\CacheTokenStore;
use Conceptlz\AdobeSign\Symfony\TokenStore\DoctrineDbalTokenStore;
use Conceptlz\AdobeSign\Symfony\TokenStore\DoctrineSchemaListener;
use Conceptlz\AdobeSign\Symfony\TokenStore\TokenEncryptor;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Symfony bundle for conceptlz/adobe-sign: configuration under "adobe_sign", autowirable
 * AdobeSignManager and AdobeSign services, encrypted OAuth token storage, OAuth and webhook
 * routes (import them from config/routes), Symfony events, optional Messenger dispatch and the
 * adobe-sign:verify command.
 */
final class AdobeSignBundle extends AbstractBundle
{
    protected string $extensionAlias = 'adobe_sign';

    public const DEFAULT_SCOPES = [
        'user_login:self',
        'agreement_read:self',
        'agreement_write:self',
        'agreement_send:self',
        'webhook_read:self',
        'webhook_write:self',
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('auth')
                    ->info('"integration_key" (one account, your own key) or "oauth" (each user connects their own account).')
                    ->defaultValue('integration_key')
                ->end()
                ->scalarNode('integration_key')
                    ->info('Usually "%env(ADOBE_SIGN_INTEGRATION_KEY)%".')
                    ->defaultNull()
                ->end()
                ->arrayNode('oauth')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('client_id')->defaultNull()->end()
                        ->scalarNode('client_secret')->defaultNull()->end()
                        ->scalarNode('redirect_uri')
                            ->info('Defaults to the absolute URL of the adobe_sign_oauth_callback route.')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('scopes')
                            ->info('Scope names with optional :self / :group / :account modifiers.')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::DEFAULT_SCOPES)
                        ->end()
                        ->scalarNode('redirect_after')
                            ->info('Path or URL to send the user to after connecting.')
                            ->defaultValue('/')
                        ->end()
                        ->scalarNode('user_key_resolver')
                            ->info('Service id implementing UserKeyResolver; the default uses UserInterface::getUserIdentifier().')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('token_store')
                            ->info('"cache" (a cache pool), "doctrine" (a database table via Doctrine DBAL), or the id of your own TokenStore service.')
                            ->defaultValue('cache')
                        ->end()
                        ->scalarNode('token_cache_pool')
                            ->info('Pool for token_store "cache". Use a persistent pool (Redis, database, filesystem), never an in-memory one.')
                            ->defaultValue('cache.app')
                        ->end()
                        ->scalarNode('encryption_key')
                            ->info('Secret the stored tokens are encrypted with. Changing it makes stored tokens unreadable.')
                            ->defaultValue('%kernel.secret%')
                        ->end()
                        ->arrayNode('doctrine')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('connection')->defaultValue('default')->end()
                                ->scalarNode('table')->defaultValue('adobe_sign_tokens')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('signature_level')
                    ->info('Default level for new agreements: ses, aes or qes.')
                    ->defaultValue('ses')
                ->end()
                ->arrayNode('qualified_issuers')
                    ->info('Certificate issuers you accept as qualified, for QES checks.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('bootstrap_url')->defaultValue('https://api.adobesign.com')->end()
                ->integerNode('base_uri_ttl')->info('Seconds to cache the account\'s data centre.')->defaultValue(86400)->end()
                ->arrayNode('retries')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max')->defaultValue(3)->end()
                        ->floatNode('max_delay_seconds')->defaultValue(60.0)->end()
                    ->end()
                ->end()
                ->scalarNode('cache_pool')
                    ->info('Cache pool for the data-centre lookup and webhook de-duplication. Shared between servers in production.')
                    ->defaultValue('cache.app')
                ->end()
                ->scalarNode('http_client')
                    ->info('Id of a PSR-18 client service. Default: the framework\'s PSR-18 client if registered, otherwise discovery.')
                    ->defaultNull()
                ->end()
                ->arrayNode('webhooks')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('client_ids')
                            ->info('Client ids Adobe may send in X-AdobeSign-ClientId. Empty: oauth.client_id.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->integerNode('dedupe_ttl')
                            ->info('Seconds to remember a notification id. Adobe retries for up to 72 hours.')
                            ->defaultValue(72 * 3600)
                        ->end()
                        ->arrayNode('messenger')
                            ->canBeEnabled()
                            ->info('Also dispatch an AdobeSignWebhookMessage, to handle notifications asynchronously.')
                            ->children()
                                ->scalarNode('bus')->defaultValue('messenger.default_bus')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $oauth = $config['oauth'];

        $services->set('adobe_sign.cache', Psr16Cache::class)
            ->args([service($config['cache_pool'])]);

        $services->set(TokenEncryptor::class)
            ->args([$oauth['encryption_key']]);

        $tokenStore = match ($oauth['token_store']) {
            'cache' => 'adobe_sign.token_store.cache',
            'doctrine' => 'adobe_sign.token_store.doctrine',
            default => $oauth['token_store'],
        };

        if ($oauth['token_store'] === 'cache') {
            $services->set('adobe_sign.token_store.cache', CacheTokenStore::class)
                ->args([service($oauth['token_cache_pool']), service(TokenEncryptor::class)]);
        }

        if ($oauth['token_store'] === 'doctrine') {
            $services->set('adobe_sign.token_store.doctrine', DoctrineDbalTokenStore::class)
                ->args([
                    service(sprintf('doctrine.dbal.%s_connection', $oauth['doctrine']['connection'])),
                    service(TokenEncryptor::class),
                    $oauth['doctrine']['table'],
                ]);
            $services->alias(DoctrineDbalTokenStore::class, 'adobe_sign.token_store.doctrine');

            $services->set(CreateTokenTableCommand::class)
                ->args([service('adobe_sign.token_store.doctrine')])
                ->tag('console.command');

            // With Doctrine ORM, doctrine:migrations:diff and doctrine:schema:update include the table.
            if (class_exists(\Doctrine\ORM\Tools\ToolEvents::class)) {
                $services->set(DoctrineSchemaListener::class)
                    ->args([service('adobe_sign.token_store.doctrine')])
                    ->tag('doctrine.event_listener', ['event' => 'postGenerateSchema']);
            }
        }

        $services->alias(TokenStore::class, $tokenStore);

        $services->set(UserKeyResolver::class, DefaultUserKeyResolver::class);

        $services->set(AdobeSignManager::class)
            ->args([
                [
                    'auth' => $config['auth'],
                    'integration_key' => $config['integration_key'],
                    'oauth' => [
                        'client_id' => $oauth['client_id'],
                        'client_secret' => $oauth['client_secret'],
                        'redirect_uri' => $oauth['redirect_uri'],
                    ],
                    'signature_level' => $config['signature_level'],
                    'qualified_issuers' => $config['qualified_issuers'],
                    'bootstrap_url' => $config['bootstrap_url'],
                    'base_uri_ttl' => $config['base_uri_ttl'],
                    'retries' => $config['retries'],
                    'webhook_client_ids' => $config['webhooks']['client_ids'],
                ],
                service(TokenStore::class),
                service($oauth['user_key_resolver'] ?? UserKeyResolver::class),
                service('adobe_sign.cache'),
                service('logger')->nullOnInvalid(),
                service($config['http_client'] ?? 'psr18.http_client')->nullOnInvalid(),
                service('router')->nullOnInvalid(),
                service('security.token_storage')->nullOnInvalid(),
                service('clock')->nullOnInvalid(),
            ])
            ->tag('monolog.logger', ['channel' => 'adobe_sign'])
            ->tag('kernel.reset', ['method' => 'reset'])
            ->public();

        // The default client. Not shared: with auth "oauth" it belongs to whoever is signed in.
        $services->set(AdobeSign::class)
            ->factory([service(AdobeSignManager::class), 'client'])
            ->share(false)
            ->public();

        $services->set(OAuthController::class)
            ->args([
                service(AdobeSignManager::class),
                service('event_dispatcher'),
                service('security.token_storage')->nullOnInvalid(),
                $oauth['scopes'],
                $oauth['redirect_after'],
            ])
            ->tag('controller.service_arguments')
            ->public();

        $messenger = $config['webhooks']['messenger'];
        $services->set(WebhookController::class)
            ->args([
                service(AdobeSignManager::class),
                service('event_dispatcher'),
                service($config['cache_pool']),
                $config['webhooks']['dedupe_ttl'],
                $messenger['enabled'] ? service($messenger['bus']) : null,
                service('logger')->nullOnInvalid(),
            ])
            ->tag('monolog.logger', ['channel' => 'adobe_sign'])
            ->tag('controller.service_arguments')
            ->public();

        $services->set(VerifyCommand::class)
            ->args([service(AdobeSignManager::class)])
            ->tag('console.command');
    }
}
