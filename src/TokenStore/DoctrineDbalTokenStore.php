<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\TokenStore;

use Conceptlz\AdobeSign\Auth\AccessToken;
use Conceptlz\AdobeSign\Auth\TokenStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * TokenStore in a database table through Doctrine DBAL (token_store: doctrine). One row per user
 * key; the token pair is encrypted with TokenEncryptor, so a database dump doesn't expose it.
 *
 * Create the table with doctrine:migrations:diff (with Doctrine ORM, the table is added to the
 * schema automatically) or bin/console adobe-sign:create-token-table.
 */
final class DoctrineDbalTokenStore implements TokenStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TokenEncryptor $encryptor,
        private readonly string $table = 'adobe_sign_tokens',
    ) {
    }

    public function get(string $key): ?AccessToken
    {
        $payload = $this->connection->fetchOne(
            sprintf('SELECT payload FROM %s WHERE user_key = ?', $this->quotedTable()),
            [$key],
        );

        return is_string($payload) ? $this->encryptor->decrypt($payload) : null;
    }

    public function put(string $key, AccessToken $token): void
    {
        $row = ['payload' => $this->encryptor->encrypt($token), 'updated_at' => new \DateTimeImmutable()];
        $types = ['updated_at' => Types::DATETIME_IMMUTABLE];

        if ($this->connection->update($this->quotedTable(), $row, ['user_key' => $key], $types) > 0) {
            return;
        }
        try {
            $this->connection->insert($this->quotedTable(), ['user_key' => $key] + $row, $types);
        } catch (UniqueConstraintViolationException) {
            // Another request inserted the row in between; ours is newer.
            $this->connection->update($this->quotedTable(), $row, ['user_key' => $key], $types);
        }
    }

    /**
     * Delete the stored token, e.g. when a user disconnects. Not part of TokenStore. This does not
     * revoke it at Adobe; call OAuthClient::revoke() first if that matters.
     */
    public function forget(string $key): void
    {
        $this->connection->delete($this->quotedTable(), ['user_key' => $key]);
    }

    /**
     * Add the table to $schema, if missing. Used by the Doctrine ORM schema listener and
     * createTable().
     */
    public function configureSchema(Schema $schema, Connection $forConnection): void
    {
        if ($forConnection !== $this->connection || $schema->hasTable($this->table)) {
            return;
        }

        $table = $schema->createTable($this->table);
        $table->addColumn('user_key', Types::STRING, ['length' => 191]);
        $table->addColumn('payload', Types::TEXT);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['user_key']);
    }

    /** Create the table if it doesn't exist. Returns false when it already did. */
    public function createTable(): bool
    {
        if ($this->connection->createSchemaManager()->tablesExist([$this->table])) {
            return false;
        }

        $schema = new Schema();
        $this->configureSchema($schema, $this->connection);
        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }

        return true;
    }

    public function table(): string
    {
        return $this->table;
    }

    private function quotedTable(): string
    {
        return $this->connection->quoteIdentifier($this->table);
    }
}
