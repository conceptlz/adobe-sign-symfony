<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * All routes. Import them in config/routes/adobe_sign.yaml:
 *
 *     adobe_sign:
 *         resource: '@AdobeSignBundle/config/routes.php'
 *
 * or import only oauth.php / webhook.php from the same folder. Add "prefix:" to move them.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__ . '/routes/oauth.php');
    $routes->import(__DIR__ . '/routes/webhook.php');
};
