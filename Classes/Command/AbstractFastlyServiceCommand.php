<?php

declare(strict_types=1);

namespace Fastly\Cdn\Command;

use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Cdn\Service\FastlyServiceProvisioner;
use Fastly\Cdn\Service\SiteDomainCollector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractFastlyServiceCommand extends Command
{
    public function __construct(
        protected readonly SiteDomainCollector $domainCollector,
        protected readonly FastlyServiceProvisioner $provisioner,
        protected readonly FastlyClientInterface $client,
    ) {
        parent::__construct();
    }

    protected function addFeatureOptions(): void
    {
        $this
            ->addOption('all-features', null, InputOption::VALUE_NONE, 'Enable all supported Fastly service features.')
            ->addOption('http3', null, InputOption::VALUE_NONE, 'Enable HTTP/3.')
            ->addOption('bot-management', null, InputOption::VALUE_NONE, 'Enable Bot Management.')
            ->addOption('waf', null, InputOption::VALUE_NONE, 'Enable Next-Gen WAF.')
            ->addOption('ddos-protection', null, InputOption::VALUE_NONE, 'Enable DDoS Protection.');
    }

    protected function addWriteOptions(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned changes without calling write APIs.')
            ->addOption('no-activate', null, InputOption::VALUE_NONE, 'Leave the changed service version inactive.');
    }

    /**
     * @return array<string, bool>
     */
    protected function selectedFeatures(InputInterface $input): array
    {
        $all = (bool)$input->getOption('all-features');
        return [
            FastlyServiceProvisioner::FEATURE_HTTP3 => $all || (bool)$input->getOption('http3'),
            FastlyServiceProvisioner::FEATURE_BOT_MANAGEMENT => $all || (bool)$input->getOption('bot-management'),
            FastlyServiceProvisioner::FEATURE_NGWAF => $all || (bool)$input->getOption('waf'),
            FastlyServiceProvisioner::FEATURE_DDOS_PROTECTION => $all || (bool)$input->getOption('ddos-protection'),
        ];
    }

    /**
     * @return string[]
     */
    protected function configuredDomains(SymfonyStyle $io): array
    {
        $domains = $this->domainCollector->collectDomains();
        if ($domains === []) {
            $io->error('No absolute TYPO3 site domains found. Configure site base URLs before provisioning Fastly.');
        }

        return $domains;
    }

    protected function configuredServiceId(InputInterface $input, SymfonyStyle $io): string
    {
        $serviceId = trim((string)($input->getOption('service-id') ?: $this->client->getConfiguredServiceId()));
        if ($serviceId === '') {
            $io->error('No Fastly service ID configured. Pass --service-id or set the extension serviceId.');
        }

        return $serviceId;
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function renderWriteResult(SymfonyStyle $io, array $result): void
    {
        $io->definitionList(
            ['Service ID' => (string)$result['serviceId']],
            ['Version' => (string)$result['version']],
            ['Activated' => ($result['activated'] ?? false) ? 'yes' : 'no'],
        );

        $io->table(['Domain', 'Status'], $this->domainRows($result));
        $io->table(['Feature', 'Status'], $this->featureRows($result['features'] ?? []));
    }

    /**
     * @param array<string, mixed> $status
     */
    protected function renderCheckResult(SymfonyStyle $io, array $status): void
    {
        $io->definitionList(
            ['Service ID' => (string)$status['serviceId']],
            ['Active version' => (string)$status['activeVersion']],
        );
        $io->table(['Domain', 'Status'], $this->checkDomainRows($status));
        $io->table(['Feature', 'Active'], $this->featureStatusRows($status['features']));
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, array<int, string>>
     */
    private function domainRows(array $result): array
    {
        $rows = [];
        foreach ($result['addedDomains'] ?? [] as $domain) {
            $rows[] = [(string)$domain, 'added'];
        }
        foreach ($result['existingDomains'] ?? [] as $domain) {
            $rows[] = [(string)$domain, 'already configured'];
        }

        return $rows === [] ? [['-', 'no domain changes']] : $rows;
    }

    /**
     * @param array<string, string> $features
     * @return array<int, array<int, string>>
     */
    private function featureRows(array $features): array
    {
        $rows = [];
        foreach ($features as $feature => $status) {
            $rows[] = [$this->featureLabel($feature), $status];
        }

        return $rows === [] ? [['-', 'no feature changes requested']] : $rows;
    }

    /**
     * @param array<string, mixed> $status
     * @return array<int, array<int, string>>
     */
    private function checkDomainRows(array $status): array
    {
        $rows = [];
        foreach ($status['matchingDomains'] as $domain) {
            $rows[] = [(string)$domain, 'configured'];
        }
        foreach ($status['missingDomains'] as $domain) {
            $rows[] = [(string)$domain, 'missing in Fastly'];
        }
        foreach ($status['unknownDomains'] as $domain) {
            $rows[] = [(string)$domain, 'not in TYPO3 site config'];
        }

        return $rows === [] ? [['-', 'no domains found']] : $rows;
    }

    /**
     * @param array<string, bool> $features
     * @return array<int, array<int, string>>
     */
    private function featureStatusRows(array $features): array
    {
        $rows = [];
        foreach ($features as $feature => $active) {
            $rows[] = [$this->featureLabel($feature), $active ? 'yes' : 'no'];
        }

        return $rows;
    }

    private function featureLabel(string $feature): string
    {
        return match ($feature) {
            FastlyServiceProvisioner::FEATURE_HTTP3 => 'HTTP/3',
            FastlyServiceProvisioner::FEATURE_BOT_MANAGEMENT => 'Bot Management',
            FastlyServiceProvisioner::FEATURE_NGWAF => 'Next-Gen WAF',
            FastlyServiceProvisioner::FEATURE_DDOS_PROTECTION => 'DDoS Protection',
            default => $feature,
        };
    }
}
