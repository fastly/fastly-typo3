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

#[AsCommand('fastly:vcl:provision', 'Upload the extension custom VCL to the Fastly service and activate it.')]
#[AsNonSchedulableCommand]
final class FastlyVclProvisionCommand extends Command
{
    public function __construct(
        private readonly FastlyClientInterface $client,
        private readonly VclProvisioner $provisioner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('service-id', null, InputOption::VALUE_REQUIRED, 'Fastly service ID. Defaults to extension serviceId.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned changes without calling write APIs.')
            ->addOption('no-activate', null, InputOption::VALUE_NONE, 'Leave the changed service version inactive.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $serviceId = trim((string)($input->getOption('service-id') ?: $this->client->getConfiguredServiceId()));
        if ($serviceId === '') {
            $io->error('No Fastly service ID configured. Pass --service-id or set the extension serviceId.');
            return Command::FAILURE;
        }

        $dryRun = (bool)$input->getOption('dry-run');
        try {
            $result = $this->provisioner->provision($serviceId, !(bool)$input->getOption('no-activate'), $dryRun);
        } catch (Throwable $throwable) {
            $io->error('Fastly API request failed: ' . $throwable->getMessage());
            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->note('Dry run: no changes were written to Fastly.');
        }

        $io->definitionList(
            ['Service ID' => (string)$result['serviceId']],
            ['Version' => (string)$result['version']],
            ['Cloned' => $result['cloned'] ? 'yes' : 'no'],
            ['Activated' => $result['activated'] ? 'yes' : 'no'],
        );
        $io->table(['VCL file', 'Status'], $this->fileRows($result));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, array<int, string>>
     */
    private function fileRows(array $result): array
    {
        $verb = $result['activated'] || $result['cloned'] ? '' : 'would ';
        $rows = [];
        foreach ($result['created'] as $name) {
            $rows[] = [(string)$name, $verb === '' ? 'created' : 'to create'];
        }

        foreach ($result['updated'] as $name) {
            $rows[] = [(string)$name, $verb === '' ? 'updated' : 'to update'];
        }

        foreach ($result['unchanged'] as $name) {
            $rows[] = [(string)$name, 'unchanged'];
        }

        return $rows === [] ? [['-', 'no VCL files resolved']] : $rows;
    }
}
