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

#[AsCommand('fastly:service:add', 'Create a Fastly service from TYPO3 site domains.')]
#[AsNonSchedulableCommand]
final class FastlyServiceAddCommand extends AbstractFastlyServiceCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Fastly service name.', 'TYPO3 Fastly Service')
            ->addOption('comment', null, InputOption::VALUE_REQUIRED, 'Fastly service comment.', '');
        $this->addFeatureOptions();
        $this->addWriteOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $domains = $this->configuredDomains($io);
        if ($domains === []) {
            return Command::FAILURE;
        }

        try {
            $result = $this->provisioner->addService(
                trim((string)$input->getOption('name')) ?: 'TYPO3 Fastly Service',
                (string)$input->getOption('comment'),
                $domains,
                $this->selectedFeatures($input),
                !(bool)$input->getOption('no-activate'),
                (bool)$input->getOption('dry-run'),
            );
        } catch (Throwable $throwable) {
            $io->error('Fastly API request failed: ' . $throwable->getMessage());
            return Command::FAILURE;
        }

        $this->renderWriteResult($io, $result);
        if (($result['serviceId'] ?? '') !== '') {
            $io->note('Set this value as the extension serviceId: ' . $result['serviceId']);
        }

        return Command::SUCCESS;
    }
}
