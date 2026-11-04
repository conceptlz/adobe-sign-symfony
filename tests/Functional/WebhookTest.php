<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Tests\Functional;

use Conceptlz\AdobeSign\Symfony\Event\WebhookReceivedEvent;
use Conceptlz\AdobeSign\Symfony\Message\AdobeSignWebhookMessage;
use Conceptlz\AdobeSign\Symfony\Tests\TestCase;
use Conceptlz\AdobeSign\Webhook\AgreementEvent;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class WebhookTest extends TestCase
{
    /** @var list<WebhookReceivedEvent> */
    private array $received = [];

    /**
     * @param array<string, mixed> $options
     */
    private function client(array $options = []): KernelBrowser
    {
        $client = static::createClient($options);
        $client->disableReboot();
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(WebhookReceivedEvent::class, function (WebhookReceivedEvent $e): void {
            $this->received[] = $e;
        });

        return $client;
    }

    private static function post(KernelBrowser $client, string $body, string $clientId = self::CLIENT_ID): void
    {
        $client->request('POST', '/adobe-sign/webhook', server: [
            'HTTP_X_ADOBESIGN_CLIENTID' => $clientId,
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);
    }

    public function test_verification_get_echoes_the_client_id(): void
    {
        $client = $this->client();
        $client->request('GET', '/adobe-sign/webhook', server: ['HTTP_X_ADOBESIGN_CLIENTID' => self::CLIENT_ID]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-AdobeSign-ClientId', self::CLIENT_ID);
        self::assertSame(['xAdobeSignClientId' => self::CLIENT_ID], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertSame([], $this->received);
    }

    public function test_notification_is_dispatched_once_even_when_adobe_retries(): void
    {
        $client = $this->client();

        self::post($client, self::fixture('webhook_agreement_workflow_completed'));
        self::assertResponseIsSuccessful();
        self::post($client, self::fixture('webhook_agreement_workflow_completed'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-AdobeSign-ClientId', self::CLIENT_ID);

        self::assertCount(1, $this->received);
        $event = $this->received[0]->event;
        self::assertInstanceOf(AgreementEvent::class, $event);
        self::assertTrue($event->isWorkflowCompleted());
        self::assertSame('CBJCHBCAABAAx1aFQyYF-aGrLsBZtVqaT9gPh6Xw42kK', $event->agreementId());
    }

    public function test_a_failed_delivery_is_not_marked_handled_so_the_retry_gets_through(): void
    {
        $client = $this->client();
        $client->catchExceptions(true);
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $failures = 1;
        $dispatcher->addListener(WebhookReceivedEvent::class, static function () use (&$failures): void {
            if ($failures-- > 0) {
                throw new \RuntimeException('database unavailable');
            }
        }, 10);

        self::post($client, self::fixture('webhook_agreement_workflow_completed'));
        self::assertResponseStatusCodeSame(500);

        self::post($client, self::fixture('webhook_agreement_workflow_completed'));   // Adobe's retry
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->received, 'delivered on the retry instead of being dropped as a duplicate');
    }

    public function test_unknown_client_is_rejected_without_dispatching(): void
    {
        $client = $this->client();
        self::post($client, self::fixture('webhook_agreement_workflow_completed'), 'someone-else');

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($client->getResponse()->headers->has('X-AdobeSign-ClientId'));
        self::assertSame([], $this->received);
    }

    public function test_unreadable_body_is_400_so_adobe_retries(): void
    {
        $client = $this->client();
        self::post($client, '{nope');

        self::assertResponseStatusCodeSame(400);
    }

    public function test_explicit_client_ids_replace_the_oauth_client_id(): void
    {
        $client = $this->client(['config' => ['webhooks' => ['client_ids' => ['other-app']]]]);

        $client->request('GET', '/adobe-sign/webhook', server: ['HTTP_X_ADOBESIGN_CLIENTID' => self::CLIENT_ID]);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/adobe-sign/webhook', server: ['HTTP_X_ADOBESIGN_CLIENTID' => 'other-app']);
        self::assertResponseIsSuccessful();
    }

    public function test_no_message_is_sent_unless_messenger_is_enabled(): void
    {
        $client = $this->client(['messenger' => true]);
        self::post($client, self::fixture('webhook_agreement_workflow_completed'));

        self::assertResponseIsSuccessful();
        self::assertSame([], self::transport()->getSent());
    }

    public function test_messenger_receives_one_message_per_notification(): void
    {
        $client = $this->client(['messenger' => true, 'config' => ['webhooks' => ['messenger' => true]]]);

        self::post($client, self::fixture('webhook_agreement_workflow_completed'));
        self::assertResponseIsSuccessful();
        $sent = self::transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(AdobeSignWebhookMessage::class, $message);
        self::assertSame(json_decode(self::fixture('webhook_agreement_workflow_completed'), true), $message->payload);
        self::assertNotNull($message->notificationId());

        // Adobe retries the same notification: acknowledged, but no second message.
        // (The in-memory transport is reset between test requests, so it starts empty again.)
        self::post($client, self::fixture('webhook_agreement_workflow_completed'));
        self::assertResponseIsSuccessful();
        self::assertSame([], self::transport()->getSent());

        // What a handler gets after the message crossed a real transport.
        $copy = unserialize(serialize($message));
        self::assertInstanceOf(AdobeSignWebhookMessage::class, $copy);
        $event = $copy->event();
        self::assertInstanceOf(AgreementEvent::class, $event);
        self::assertSame('CBJCHBCAABAAx1aFQyYF-aGrLsBZtVqaT9gPh6Xw42kK', $event->agreementId());
        self::assertCount(1, $this->received, 'the synchronous event still fires');
    }

    private static function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.test');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
