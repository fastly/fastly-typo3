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

#[AsCommand('fastly:service:update', 'Update the configured Fastly service from TYPO3 site domains.')]
#[AsNonSchedulableCommand]
final class FastlyServiceUpdateCommand extends AbstractFastlyServiceCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('service-id', null, InputOption::VALUE_REQUIRED, 'Fastly service ID. Defaults to extension serviceId.')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Update the Fastly service name.')
            ->addOption('comment', null, InputOption::VALUE_REQUIRED, 'Update the Fastly service comment.');
        $this->addFeatureOptions();
        $this->addWriteOptions();
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
            $result = $this->provisioner->updateService(
                $serviceId,
                $domains,
                $this->selectedFeatures($input),
                !(bool)$input->getOption('no-activate'),
                (bool)$input->getOption('dry-run'),
                $input->getOption('name') === null ? null : trim((string)$input->getOption('name')),
                $input->getOption('comment') === null ? null : (string)$input->getOption('comment'),
            );
        } catch (Throwable $throwable) {
            $io->error('Fastly API request failed: ' . $throwable->getMessage());
            return Command::FAILURE;
        }

        $this->renderWriteResult($io, $result);
        return Command::SUCCESS;
    }
}
