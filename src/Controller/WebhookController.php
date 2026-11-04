<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Controller;

use Conceptlz\AdobeSign\Exception\WebhookException;
use Conceptlz\AdobeSign\Symfony\AdobeSignManager;
use Conceptlz\AdobeSign\Symfony\Event\WebhookReceivedEvent;
use Conceptlz\AdobeSign\Symfony\Message\AdobeSignWebhookMessage;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Verification GET + notification POST endpoint (route adobe_sign_webhook). Acknowledges with the
 * client-id echo Adobe requires, drops retried duplicates, dispatches WebhookReceivedEvent and,
 * with webhooks.messenger enabled, an AdobeSignWebhookMessage. If a listener or the bus throws,
 * the request fails (Adobe retries) and the notification is not marked as handled.
 */
final class WebhookController
{
    public function __construct(
        private readonly AdobeSignManager $manager,
        private readonly EventDispatcherInterface $events,
        private readonly CacheItemPoolInterface $dedupe,
        private readonly int $dedupeTtl,
        private readonly ?MessageBusInterface $bus = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * 403 for a missing or unknown client id, 400 for an unreadable POST body, otherwise 200 with
     * the echo. Exceptions from listeners or the bus propagate (500), so Adobe delivers again.
     */
    public function __invoke(Request $request): Response
    {
        $handler = $this->manager->webhookHandler();

        try {
            $clientId = $handler->verifyClientId($request->headers->get($handler::CLIENT_ID_HEADER));
        } catch (WebhookException $e) {
            $this->logger?->warning('Rejected Acrobat Sign webhook', ['reason' => $e->getMessage(), 'ip' => $request->getClientIp()]);

            return new Response('', 403);
        }

        if ($request->isMethod('POST')) {
            try {
                $event = $handler->parse($request->getContent());
            } catch (WebhookException $e) {
                $this->logger?->error('Unreadable Acrobat Sign webhook', ['reason' => $e->getMessage()]);

                return new Response('', 400); // Adobe retries; nothing is silently lost
            }

            if ($this->claim($event->notificationId)) {
                try {
                    $this->events->dispatch(new WebhookReceivedEvent($event));
                    $this->bus?->dispatch(new AdobeSignWebhookMessage($event->raw));
                } catch (\Throwable $e) {
                    // Let Adobe's retry through: otherwise the failed delivery would count as handled.
                    $this->release($event->notificationId);
                    throw $e;
                }
            }
        }

        return new JsonResponse(
            [$handler::CLIENT_ID_BODY_KEY => $clientId],
            200,
            [$handler::CLIENT_ID_HEADER => $clientId],
        );
    }

    /**
     * Record the notification id; false when it was already handled within dedupe_ttl.
     * Notifications without an id always pass.
     */
    private function claim(?string $notificationId): bool
    {
        if ($notificationId === null || $notificationId === '') {
            return true;
        }

        $item = $this->dedupe->getItem(self::dedupeKey($notificationId));
        if ($item->isHit()) {
            return false;
        }
        $this->dedupe->save($item->set(true)->expiresAfter($this->dedupeTtl));

        return true;
    }

    private function release(?string $notificationId): void
    {
        if ($notificationId !== null && $notificationId !== '') {
            $this->dedupe->deleteItem(self::dedupeKey($notificationId));
        }
    }

    private static function dedupeKey(string $notificationId): string
    {
        return 'adobe_sign.webhook.' . hash('sha256', $notificationId);
    }
}
