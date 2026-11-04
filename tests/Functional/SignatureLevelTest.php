<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Tests\Functional;

use Conceptlz\AdobeSign\Builder\AgreementInfo;
use Conceptlz\AdobeSign\Symfony\AdobeSignManager;
use Conceptlz\AdobeSign\Symfony\Tests\TestCase;

final class SignatureLevelTest extends TestCase
{
    private static function manager(): AdobeSignManager
    {
        $manager = static::getContainer()->get(AdobeSignManager::class);
        self::assertInstanceOf(AdobeSignManager::class, $manager);

        return $manager;
    }

    public function test_configured_default_level_reaches_the_request(): void
    {
        self::bootKernel(['config' => ['signature_level' => 'qes']]);
        self::queue(200, 'base_uris');
        self::queue(200, 'agreement_created');

        self::manager()->client()->agreements()->create(AgreementInfo::create('Contract')->file('tdoc')->signers('a@example.com'));

        $sent = json_decode((string) self::sent(1)->getBody(), true);
        self::assertIsArray($sent);
        self::assertTrue($sent['participantSetsInfo'][0]['isDigitalSignatureRequired']);
    }

    public function test_shortfalls_use_the_configured_level_and_qualified_issuers(): void
    {
        self::bootKernel(['config' => ['signature_level' => 'qes', 'qualified_issuers' => ['TrustPro QTSP']]]);
        self::queue(200, 'base_uris');
        self::queue(200, '{"id":"agr-1","name":"x","status":"OUT_FOR_SIGNATURE","signatureType":"ESIGN","participantSetsInfo":[]}');
        self::queue(200, 'evidence_members');
        self::queue(200, 'evidence_events');

        self::assertSame(
            [
                'Jane@Client.example signed with an electronic signature (TYPED), not a digital one: SES, QES required.',
                'ceo@vendor.example has not signed yet.',
            ],
            self::manager()->signatureShortfalls('agr-1'),
            'the CFO signed with the configured QTSP, so only Jane and the CEO fall short',
        );
    }
}
