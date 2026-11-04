<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Tests\Functional;

use Conceptlz\AdobeSign\AdobeSign;
use Conceptlz\AdobeSign\Auth\AccessToken;
use Conceptlz\AdobeSign\Auth\TokenStore;
use Conceptlz\AdobeSign\Enum\SignatureLevel;
use Conceptlz\AdobeSign\Symfony\AdobeSignManager;
use Conceptlz\AdobeSign\Symfony\MisconfiguredException;
use Conceptlz\AdobeSign\Symfony\Tests\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ClientTest extends TestCase
{
    private static function manager(): AdobeSignManager
    {
        $manager = static::getContainer()->get(AdobeSignManager::class);
        self::assertInstanceOf(AdobeSignManager::class, $manager);

        return $manager;
    }

    public function test_client_uses_the_integration_key_and_the_configured_http_client(): void
    {
        self::bootKernel();
        self::queue(200, 'base_uris');
        self::queue(200, 'user_me');

        $me = self::manager()->client()->users()->me();

        self::assertSame('admin@example.com', $me->getEmail());
        self::assertSame('Bearer ' . self::KEY, self::sent(0)->getHeaderLine('Authorization'));
        self::assertStringStartsWith(self::API . '/users/me', (string) self::sent(1)->getUri());
        self::assertStringStartsWith('conceptlz-adobe-sign-php/', self::sent(1)->getHeaderLine('User-Agent'));
    }

    public function test_adobe_sign_is_autowirable_as_a_service(): void
    {
        self::bootKernel();

        self::assertInstanceOf(AdobeSign::class, static::getContainer()->get(AdobeSign::class));
    }

    public function test_missing_integration_key_names_the_setting(): void
    {
        self::bootKernel(['config' => ['integration_key' => '']]);

        $this->expectException(MisconfiguredException::class);
        $this->expectExceptionMessage('adobe_sign.integration_key');
        self::manager()->client();
    }

    public function test_signature_level_comes_from_config(): void
    {
        self::bootKernel(['config' => ['signature_level' => 'AES', 'qualified_issuers' => ['TrustPro QTSP']]]);

        self::assertSame(SignatureLevel::AES, self::manager()->signatureLevel());
        self::assertSame(['TrustPro QTSP'], self::manager()->qualifiedIssuers());
    }

    public function test_invalid_signature_level_is_reported(): void
    {
        self::bootKernel(['config' => ['signature_level' => 'gold']]);

        $this->expectException(MisconfiguredException::class);
        $this->expectExceptionMessage('ses, aes or qes');
        self::manager()->signatureLevel();
    }

    public function test_oauth_mode_uses_the_signed_in_users_tokens(): void
    {
        self::bootKernel(['config' => ['auth' => 'oauth']]);
        self::signIn('jane@example.com');
        static::getContainer()->get(TokenStore::class)?->put('jane@example.com', new AccessToken('user-token', 'refresh', new \DateTimeImmutable('+1 hour'), 'https://api.na4.adobesign.com/'));
        self::queue(200, 'user_me');

        self::manager()->client()->users()->me();

        self::assertSame('Bearer user-token', self::sent(0)->getHeaderLine('Authorization'));
        self::assertTrue(self::manager()->isConnected('jane@example.com'));
        self::assertFalse(self::manager()->isConnected('someone-else'));
    }

    public function test_oauth_mode_without_a_signed_in_user_is_explained(): void
    {
        self::bootKernel(['config' => ['auth' => 'oauth']]);

        $this->expectException(MisconfiguredException::class);
        $this->expectExceptionMessage('forUser');
        self::manager()->client();
    }

    public function test_reset_drops_cached_clients(): void
    {
        self::bootKernel();
        $first = self::manager()->client();
        self::assertSame($first, self::manager()->client());

        self::manager()->reset();

        self::assertNotSame($first, self::manager()->client());
    }

    public function test_verify_command_prints_the_connected_user(): void
    {
        $kernel = self::bootKernel();
        self::queue(200, 'base_uris');
        self::queue(200, 'user_me');

        $tester = new CommandTester((new Application($kernel))->find('adobe-sign:verify'));
        $status = $tester->execute([]);

        self::assertSame(0, $status, $tester->getDisplay());
        self::assertStringContainsString('Connected to Acrobat Sign', $tester->getDisplay());
        self::assertStringContainsString('admin@example.com', $tester->getDisplay());
    }

    public function test_verify_command_fails_with_the_sdk_message(): void
    {
        $kernel = self::bootKernel();
        self::queue(401, '{"code":"INVALID_ACCESS_TOKEN","message":"Access token provided is invalid or has expired."}');

        $tester = new CommandTester((new Application($kernel))->find('adobe-sign:verify'));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('INVALID_ACCESS_TOKEN', $tester->getDisplay());
        self::assertStringNotContainsString(self::KEY, $tester->getDisplay());
    }
}
