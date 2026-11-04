<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Event;

use Conceptlz\AdobeSign\Webhook\WebhookEvent;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * An Acrobat Sign webhook notification arrived (duplicates already dropped). Dispatched
 * synchronously, inside Adobe's 5-second window: keep listeners fast, or enable
 * webhooks.messenger and do the work in a handler for AdobeSignWebhookMessage.
 */
final class WebhookReceivedEvent extends Event
{
    public function __construct(public readonly WebhookEvent $event)
    {
    }
}
