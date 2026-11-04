<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\TokenStore;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Adds the token table to the schema Doctrine ORM generates, so doctrine:migrations:diff and
 * doctrine:schema:update include it. Registered only with token_store: doctrine and doctrine/orm.
 *
 * @internal
 */
final class DoctrineSchemaListener
{
    public function __construct(private readonly DoctrineDbalTokenStore $store)
    {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $this->store->configureSchema($event->getSchema(), $event->getEntityManager()->getConnection());
    }
}
