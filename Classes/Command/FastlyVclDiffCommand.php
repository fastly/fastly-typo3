<?php

declare(strict_types=1);

namespace Fastly\Cdn\Command;

use Throwable;
use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Cdn\Service\VclProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Attribute\AsNonSchedulableCommand;

#[AsCommand('fastly:vcl:diff', 'Show how the extension custom VCL differs from the Fastly service (read-only).')]
#[AsNonSchedulableCommand]
final class FastlyVclDiffCommand extends Command
{
    public function __construct(
        private readonly FastlyClientInterface $client,
        private readonly VclProvisioner $provisioner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('service-id', null, InputOption::VALUE_REQUIRED, 'Fastly service ID. Defaults to extension serviceId.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $serviceId = trim((string)($input->getOption('service-id') ?: $this->client->getConfiguredServiceId()));
        if ($serviceId === '') {
            $io->error('No Fastly service ID configured. Pass --service-id or set the extension serviceId.');
            return Command::FAILURE;
        }

        try {
            $diff = $this->provisioner->diff($serviceId);
        } catch (Throwable $throwable) {
            $io->error('Fastly API request failed: ' . $throwable->getMessage());
            return Command::FAILURE;
        }

        $io->definitionList(
            ['Service ID' => (string)$diff['serviceId']],
            ['Compared against version' => (string)$diff['version']],
        );
        $io->table(['VCL file', 'Status'], $this->rows($diff));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $diff
     * @return array<int, array<int, string>>
     */
    private function rows(array $diff): array
    {
        $rows = [];
        foreach ($diff['created'] as $name) {
            $rows[] = [(string)$name, 'create'];
        }

        foreach ($diff['updated'] as $name) {
            $rows[] = [(string)$name, 'update'];
        }

        foreach ($diff['unchanged'] as $name) {
            $rows[] = [(string)$name, 'unchanged'];
        }

        return $rows === [] ? [['-', 'no VCL files resolved']] : $rows;
    }
}
