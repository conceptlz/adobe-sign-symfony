<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Tests;

use Conceptlz\AdobeSign\Symfony\Tests\App\TestKernel;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

abstract class TestCase extends WebTestCase
{
    protected const KEY = 'test-integration-key';
    protected const CLIENT_ID = 'CBJCHBCAABAAclientid';
    protected const API = 'https://api.na4.adobesign.com/api/rest/v6';

    /**
     * The adobe_sign configuration every test starts from; override per test with $config.
     *
     * @return array<string, mixed>
     */
    protected static function baseConfig(): array
    {
        return [
            'integration_key' => self::KEY,
            'oauth' => ['client_id' => self::CLIENT_ID, 'client_secret' => 'test-secret'],
        ];
    }

    /**
     * @param array<string, mixed> $options ['config' => adobe_sign overrides, 'messenger' => bool]
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        /** @var array<string, mixed> $config */
        $config = array_replace_recursive(static::baseConfig(), $options['config'] ?? []);

        return new TestKernel($config, (bool) ($options['messenger'] ?? false));
    }

    protected static function http(): MockClient
    {
        $http = static::getContainer()->get('test.http_client');
        self::assertInstanceOf(MockClient::class, $http);

        return $http;
    }

    /** Queue a response: a fixture name from tests/Fixtures, or a literal body. */
    protected static function queue(int $status, ?string $body = null): void
    {
        $contents = '';
        $headers = [];
        if ($body !== null) {
            $file = __DIR__ . '/Fixtures/' . $body . '.json';
            $contents = is_file($file) ? (string) file_get_contents($file) : $body;
            $headers['Content-Type'] = 'application/json';
        }
        static::http()->addResponse(new Response($status, $headers, $contents));
    }

    protected static function sent(int $index): RequestInterface
    {
        $requests = static::http()->getRequests();
        self::assertArrayHasKey($index, $requests, "No request #$index was sent.");

        return $requests[$index];
    }

    protected static function signIn(string $identifier = 'jane@example.com'): void
    {
        $storage = static::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $user = new InMemoryUser($identifier, null, ['ROLE_USER']);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    protected static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/Fixtures/' . $name . '.json');
    }
}
