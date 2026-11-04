<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Tests\App;

use Conceptlz\AdobeSign\Symfony\AdobeSignBundle;
use Conceptlz\AdobeSign\Symfony\Message\AdobeSignWebhookMessage;
use Doctrine\DBAL\DriverManager;
use Http\Mock\Client as MockClient;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * A minimal app around the bundle. $adobeSign is the adobe_sign configuration under test;
 * $messenger adds an in-memory "test" transport that AdobeSignWebhookMessage is routed to.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $adobeSign
     */
    public function __construct(
        private readonly array $adobeSign = [],
        private readonly bool $messenger = false,
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new AdobeSignBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/adobe-sign-symfony-tests/' . $this->configHash() . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/adobe-sign-symfony-tests/' . $this->configHash() . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = [
            'secret' => 'test-kernel-secret',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'router' => ['utf8' => true],
            'cache' => ['app' => 'cache.adapter.array'],
        ];
        if ($this->messenger) {
            $framework['messenger'] = [
                'transports' => ['test' => 'in-memory://'],
                'routing' => [AdobeSignWebhookMessage::class => 'test'],
            ];
        }
        $container->extension('framework', $framework);
        $container->extension('adobe_sign', $this->adobeSign + ['http_client' => 'test.http_client', 'cache_pool' => 'test.cache']);

        $services = $container->services();
        $services->set('test.http_client', MockClient::class)->public();
        // Like a real shared pool (Redis, ...): survives between test requests, unlike cache.app's array
        // adapter, which the kernel resets after every request.
        $services->set('test.cache', \Symfony\Component\Cache\Adapter\ArrayAdapter::class)->public();
        $services->set('security.token_storage', TokenStorage::class)->public();
        $services->set('logger', \Psr\Log\NullLogger::class);
        $services->set('doctrine.dbal.default_connection')
            ->class(\Doctrine\DBAL\Connection::class)
            ->factory([DriverManager::class, 'getConnection'])
            ->args([['driver' => 'pdo_sqlite', 'memory' => true]])
            ->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@AdobeSignBundle/config/routes.php');
    }

    private function configHash(): string
    {
        return substr(hash('sha256', serialize([$this->adobeSign, $this->messenger, self::VERSION])), 0, 16);
    }
}
