<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Command;

use Conceptlz\AdobeSign\Exception\AdobeSignException;
use Conceptlz\AdobeSign\Symfony\AdobeSignManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * adobe-sign:verify: calls GET /users/me with the default client (or --user's OAuth connection)
 * and prints who the credentials belong to.
 */
#[AsCommand(name: 'adobe-sign:verify', description: 'Check Acrobat Sign credentials by fetching the authenticated user')]
final class VerifyCommand extends Command
{
    public function __construct(private readonly AdobeSignManager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Check this user key\'s OAuth connection instead of the default client');
    }

    /** Exits non-zero with the SDK's message on any AdobeSignException. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $user = $input->getOption('user');
            $client = is_string($user) && $user !== '' ? $this->manager->forUser($user) : $this->manager->client();
            $me = $client->users()->me();
        } catch (AdobeSignException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Connected to Acrobat Sign.');
        $io->definitionList(
            ['User' => (string) $me->getEmail()],
            ['Account admin' => $me->getIsAccountAdmin() ? 'yes' : 'no'],
            ['Account' => (string) $me->getAccountId()],
        );

        return Command::SUCCESS;
    }
}
