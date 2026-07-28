<?php

declare(strict_types=1);

namespace Fastly\Cdn\Command;

use Throwable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Attribute\AsNonSchedulableCommand;

#[AsCommand('fastly:service:check', 'Check the configured Fastly service against TYPO3 site domains.')]
#[AsNonSchedulableCommand]
final class FastlyServiceCheckCommand extends AbstractFastlyServiceCommand
{
    protected function configure(): void
    {
        $this->addOption('service-id', null, InputOption::VALUE_REQUIRED, 'Fastly service ID. Defaults to extension serviceId.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $serviceId = $this->configuredServiceId($input, $io);
        $domains = $this->configuredDomains($io);
        if ($serviceId === '' || $domains === []) {
            return Command::FAILURE;
        }

        try {
            $status = $this->provisioner->checkService($serviceId, $domains);
        } catch (Throwable $throwable) {
            $io->error('Fastly API request failed: ' . $throwable->getMessage());
            return Command::FAILURE;
        }

        $this->renderCheckResult($io, $status);
        return $status['missingDomains'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
