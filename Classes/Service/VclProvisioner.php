<?php

declare(strict_types=1);

namespace Fastly\Cdn\Service;

use Fastly\ApiException;
use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Cdn\Exception\VclProvisioningException;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Synchronises the resolved custom VCL file set onto a Fastly service.
 *
 * The sync is idempotent: it diffs the local files against the version it would
 * write to (a reusable managed draft if one exists, otherwise the active
 * version) and only clones/uploads/activates when something actually differs.
 * Running it twice on an in-sync service performs no write calls. Files present
 * on the service but not shipped locally are left untouched (upsert only).
 */
final readonly class VclProvisioner implements SingletonInterface
{
    public function __construct(
        private FastlyClientInterface $client,
        private ManagedVersionResolver $versions,
        private VclFileResolver $files,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function provision(string $serviceId, bool $activate, bool $dryRun = false): array
    {
        $local = $this->files->resolveFiles();
        if (!isset($local[VclFileResolver::MAIN_NAME])) {
            throw new VclProvisioningException(
                sprintf('No "%s.vcl" found in the configured VCL root paths.', VclFileResolver::MAIN_NAME),
            );
        }

        $activeVersion = $this->versions->resolveActiveVersion($serviceId);
        $managedDraft = $this->versions->findManagedDraft($serviceId);
        $baseVersion = $managedDraft ?? $activeVersion;

        ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged]
            = $this->computeDiff($serviceId, $baseVersion, $local);

        $needsWrite = $created !== [] || $updated !== [];

        if ($dryRun) {
            return $this->result($serviceId, $baseVersion, false, false, $created, $updated, $unchanged);
        }

        $targetVersion = $baseVersion;
        $cloned = false;
        if ($needsWrite) {
            $draft = $this->versions->acquireEditableDraft($serviceId, $activeVersion);
            $targetVersion = $draft['version'];
            $cloned = $draft['cloned'];

            foreach ($created as $name) {
                $this->client->createCustomVcl(
                    $serviceId,
                    $targetVersion,
                    $name,
                    $local[$name],
                    $name === VclFileResolver::MAIN_NAME,
                );
            }
            foreach ($updated as $name) {
                $this->client->updateCustomVcl($serviceId, $targetVersion, $name, $local[$name]);
            }
            if (in_array(VclFileResolver::MAIN_NAME, $created, true)) {
                $this->client->setCustomVclMain($serviceId, $targetVersion, VclFileResolver::MAIN_NAME);
            }
        }

        // Activate whenever the target is a staged draft distinct from the active
        // version — this also publishes a draft left behind by an earlier
        // --no-activate run even when there is nothing new to upload.
        $activated = false;
        if ($activate && $targetVersion !== $activeVersion) {
            $this->client->activateServiceVersion($serviceId, $targetVersion);
            $activated = true;
        }

        return $this->result($serviceId, $targetVersion, $cloned, $activated, $created, $updated, $unchanged);
    }

    /**
     * Read-only comparison of the resolved local VCL against what is currently on
     * the service, without cloning or writing anything.
     *
     * @return array<string, mixed>
     */
    public function diff(string $serviceId): array
    {
        $local = $this->files->resolveFiles();
        if (!isset($local[VclFileResolver::MAIN_NAME])) {
            throw new VclProvisioningException(
                sprintf('No "%s.vcl" found in the configured VCL root paths.', VclFileResolver::MAIN_NAME),
            );
        }

        $activeVersion = $this->versions->resolveActiveVersion($serviceId);
        $baseVersion = $this->versions->findManagedDraft($serviceId) ?? $activeVersion;

        return ['serviceId' => $serviceId, 'version' => $baseVersion]
            + $this->computeDiff($serviceId, $baseVersion, $local);
    }

    /**
     * @param array<string, string> $local
     * @return array{created: string[], updated: string[], unchanged: string[]}
     */
    private function computeDiff(string $serviceId, int $baseVersion, array $local): array
    {
        $created = [];
        $updated = [];
        $unchanged = [];
        foreach ($local as $name => $content) {
            $remote = $this->readRemote($serviceId, $baseVersion, $name);
            if ($remote === null) {
                $created[] = $name;
            } elseif ($this->normalize($remote) !== $this->normalize($content)) {
                $updated[] = $name;
            } else {
                $unchanged[] = $name;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged];
    }

    private function readRemote(string $serviceId, int $version, string $name): ?string
    {
        try {
            return $this->client->getCustomVclRaw($serviceId, $version, $name);
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Fastly stores VCL verbatim; guard idempotency against trailing-whitespace
     * and line-ending round-tripping so an in-sync file is not seen as changed.
     */
    private function normalize(string $content): string
    {
        return rtrim(str_replace("\r\n", "\n", $content));
    }

    /**
     * @param string[] $created
     * @param string[] $updated
     * @param string[] $unchanged
     * @return array<string, mixed>
     */
    private function result(
        string $serviceId,
        int $version,
        bool $cloned,
        bool $activated,
        array $created,
        array $updated,
        array $unchanged,
    ): array {
        return [
            'serviceId' => $serviceId,
            'version' => $version,
            'cloned' => $cloned,
            'activated' => $activated,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
        ];
    }
}
