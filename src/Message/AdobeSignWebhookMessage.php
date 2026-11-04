<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Message;

use Conceptlz\AdobeSign\Webhook\WebhookEvent;

/**
 * A webhook notification, dispatched to the Messenger bus when webhooks.messenger is enabled.
 * It carries only the decoded payload (plain arrays), so any transport and serializer can carry
 * it; event() rebuilds the typed WebhookEvent in your handler:
 *
 *     #[AsMessageHandler]
 *     final class OnAdobeSignWebhook
 *     {
 *         public function __invoke(AdobeSignWebhookMessage $message): void
 *         {
 *             $event = $message->event();   // AgreementEvent, MegaSignEvent, ...
 *         }
 *     }
 *
 * Route it to an async transport in config/packages/messenger.yaml, otherwise it is handled
 * synchronously within the webhook request.
 */
final class AdobeSignWebhookMessage
{
    /**
     * @param array<string, mixed> $payload the notification body as Adobe sent it
     */
    public function __construct(public readonly array $payload)
    {
    }

    public function event(): WebhookEvent
    {
        return WebhookEvent::fromArray($this->payload);
    }

    /** Adobe's notification id, unique per notification. */
    public function notificationId(): ?string
    {
        $id = $this->payload['webhookNotificationId'] ?? null;

        return is_string($id) ? $id : null;
    }
}
