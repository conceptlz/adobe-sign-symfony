<?php

declare(strict_types=1);

use Conceptlz\AdobeSign\Symfony\Controller\OAuthController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Behind your firewall: require a signed-in user with access_control (see the documentation).
return static function (RoutingConfigurator $routes): void {
    $routes->add('adobe_sign_oauth_redirect', '/adobe-sign/oauth/redirect')
        ->controller([OAuthController::class, 'redirect'])
        ->methods(['GET']);

    $routes->add('adobe_sign_oauth_callback', '/adobe-sign/oauth/callback')
        ->controller([OAuthController::class, 'callback'])
        ->methods(['GET']);
};
