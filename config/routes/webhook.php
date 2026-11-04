<?php

declare(strict_types=1);

use Conceptlz\AdobeSign\Symfony\Controller\WebhookController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Public and stateless: Adobe sends no cookies or CSRF tokens. Keep it out of access_control rules.
return static function (RoutingConfigurator $routes): void {
    $routes->add('adobe_sign_webhook', '/adobe-sign/webhook')
        ->controller(WebhookController::class)
        ->methods(['GET', 'POST'])
        ->stateless();
};
