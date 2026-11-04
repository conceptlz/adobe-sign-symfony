<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Tests\Functional;

use Conceptlz\AdobeSign\Auth\AccessToken;
use Conceptlz\AdobeSign\Auth\TokenStore;
use Conceptlz\AdobeSign\Symfony\MisconfiguredException;
use Conceptlz\AdobeSign\Symfony\Tests\TestCase;
use Conceptlz\AdobeSign\Symfony\TokenStore\CacheTokenStore;
use Conceptlz\AdobeSign\Symfony\TokenStore\DoctrineDbalTokenStore;
use Conceptlz\AdobeSign\Symfony\TokenStore\TokenEncryptor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;

final class TokenStoreTest extends TestCase
{
    private static function token(string $access = 'access-123'): AccessToken
    {
        return new AccessToken($access, 'refresh-456', new \DateTimeImmutable('2026-11-04T12:00:00+00:00'), 'https://api.eu1.adobesign.com/');
    }

    // --- Encryption ---------------------------------------------------------

    public function test_encryptor_round_trips_and_hides_the_tokens(): void
    {
        $encryptor = new TokenEncryptor('app-secret');
        $sealed = $encryptor->encrypt(self::token());

        self::assertStringStartsWith('v1:', $sealed);
        self::assertStringNotContainsString('access-123', $sealed);
        self::assertStringNotContainsString('refresh-456', $sealed);
        self::assertEquals(self::token(), $encryptor->decrypt($sealed));
        self::assertNotSame($sealed, $encryptor->encrypt(self::token()), 'a fresh nonce every time');
    }

    public function test_encryptor_rejects_tampering_and_other_keys(): void
    {
        $sealed = (new TokenEncryptor('app-secret'))->encrypt(self::token());
        $tampered = substr($sealed, 0, -4) . (str_ends_with($sealed, 'AAAA') ? 'BBBB' : 'AAAA');

        self::assertNull((new TokenEncryptor('another-secret'))->decrypt($sealed));
        self::assertNull((new TokenEncryptor('app-secret'))->decrypt($tampered));
        self::assertNull((new TokenEncryptor('app-secret'))->decrypt('not a token'));
    }

    public function test_encryptor_needs_a_secret(): void
    {
        $this->expectException(MisconfiguredException::class);
        (new TokenEncryptor(''))->encrypt(self::token());
    }

    public function test_encryptor_does_not_print_its_secret(): void
    {
        self::assertStringNotContainsString('app-secret', print_r(new TokenEncryptor('app-secret'), true));
    }

    // --- Cache pool store ---------------------------------------------------

    public function test_cache_store_round_trips_keys_with_reserved_characters(): void
    {
        $pool = new ArrayAdapter(0, false); // store values as-is (the parameter name differs between Symfony 7 and 8)
        $store = new CacheTokenStore($pool, new TokenEncryptor('app-secret'));

        $store->put('jane@example.com', self::token());

        self::assertEquals(self::token(), $store->get('jane@example.com'));
        self::assertNull($store->get('someone@example.com'));
        $stored = array_values(array_filter($pool->getValues(), 'is_string')); // misses are recorded as null
        self::assertCount(1, $stored);
        self::assertStringNotContainsString('access-123', $stored[0]);

        $store->forget('jane@example.com');
        self::assertNull($store->get('jane@example.com'));
    }

    public function test_cache_store_is_the_default(): void
    {
        self::bootKernel();

        self::assertInstanceOf(CacheTokenStore::class, static::getContainer()->get(TokenStore::class));
    }

    // --- Doctrine DBAL store ------------------------------------------------

    public function test_doctrine_store_is_wired_and_its_table_created_by_command(): void
    {
        $kernel = self::bootKernel(['config' => ['oauth' => ['token_store' => 'doctrine']]]);
        $store = static::getContainer()->get(TokenStore::class);
        self::assertInstanceOf(DoctrineDbalTokenStore::class, $store);

        $tester = new CommandTester((new Application($kernel))->find('adobe-sign:create-token-table'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Created table "adobe_sign_tokens"', $tester->getDisplay());
        $tester->execute([]);
        self::assertStringContainsString('already exists', $tester->getDisplay());

        $store->put('jane@example.com', self::token('first'));
        $store->put('jane@example.com', self::token('second'));   // update, not a duplicate row
        self::assertSame('second', $store->get('jane@example.com')?->accessToken);
        self::assertNull($store->get('nobody'));

        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $rows = $connection->fetchAllAssociative('SELECT * FROM adobe_sign_tokens');
        self::assertCount(1, $rows);
        self::assertStringNotContainsString('second', (string) $rows[0]['payload']);

        $store->forget('jane@example.com');
        self::assertNull($store->get('jane@example.com'));
    }

    public function test_doctrine_store_adds_its_table_to_a_schema_once(): void
    {
        self::bootKernel(['config' => ['oauth' => ['token_store' => 'doctrine', 'doctrine' => ['table' => 'esign_tokens']]]]);
        $store = static::getContainer()->get(TokenStore::class);
        self::assertInstanceOf(DoctrineDbalTokenStore::class, $store);
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $schema = new Schema();
        $store->configureSchema($schema, $connection);
        $store->configureSchema($schema, $connection);

        self::assertTrue($schema->hasTable('esign_tokens'));
        self::assertSame(['user_key', 'payload', 'updated_at'], array_map(static fn ($c) => $c->getName(), array_values($schema->getTable('esign_tokens')->getColumns())));
    }

    public function test_doctrine_store_ignores_schemas_of_other_connections(): void
    {
        $store = new DoctrineDbalTokenStore(\Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new TokenEncryptor('s'));
        $schema = new Schema();

        $store->configureSchema($schema, \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

        self::assertFalse($schema->hasTable('adobe_sign_tokens'));
    }
}
