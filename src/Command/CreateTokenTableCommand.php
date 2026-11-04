<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Command;

use Conceptlz\AdobeSign\Symfony\TokenStore\DoctrineDbalTokenStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * adobe-sign:create-token-table: creates the OAuth token table for token_store: doctrine. With
 * Doctrine ORM and migrations you don't need it: doctrine:migrations:diff includes the table.
 */
#[AsCommand(name: 'adobe-sign:create-token-table', description: 'Create the Acrobat Sign OAuth token table (token_store: doctrine)')]
final class CreateTokenTableCommand extends Command
{
    public function __construct(private readonly DoctrineDbalTokenStore $store)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->success($this->store->createTable()
            ? sprintf('Created table "%s".', $this->store->table())
            : sprintf('Table "%s" already exists.', $this->store->table()));

        return Command::SUCCESS;
    }
}
