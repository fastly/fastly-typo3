<?php

declare(strict_types=1);

namespace Fastly\Cdn\Service;

use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Model\VersionResponse;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Resolves which Fastly service version to read from or write to, and hands out
 * an editable draft for write operations.
 *
 * Extracted so both {@see FastlyServiceProvisioner} and the VCL provisioner share
 * one idempotent draft strategy: a draft this extension previously cloned
 * (recognised by its comment marker) is reused instead of cloning again, so
 * repeated runs — notably under --no-activate — do not pile up draft versions,
 * while a stray draft created outside the extension is never touched.
 */
final readonly class ManagedVersionResolver implements SingletonInterface
{
    /**
     * Marker written to the comment of versions this extension clones for a
     * write. Only versions carrying this marker are reused on a later run.
     */
    public const string MANAGED_VERSION_COMMENT = 'Draft managed by the TYPO3 Fastly extension.';

    public function __construct(private FastlyClientInterface $client)
    {
    }

    public function resolveActiveVersion(string $serviceId): int
    {
        $versions = $this->client->listServiceVersions($serviceId);
        foreach ($versions as $version) {
            if ((bool)$version->getActive()) {
                return (int)$version->getNumber();
            }
        }

        return $this->latestVersionNumber($versions) ?? 1;
    }

    public function resolveEditableVersion(string $serviceId): int
    {
        $versions = $this->client->listServiceVersions($serviceId);
        foreach ($versions as $version) {
            if ((bool)$version->getLocked() === false) {
                return (int)$version->getNumber();
            }
        }

        return $this->latestVersionNumber($versions) ?? 1;
    }

    /**
     * Return an editable draft to write to: reuse this extension's own managed
     * draft when one exists, otherwise clone the active version and tag the clone
     * so a later run can recognise and reuse it.
     *
     * @return array{version: int, cloned: bool}
     */
    public function acquireEditableDraft(string $serviceId, int $activeVersion): array
    {
        $managed = $this->findManagedDraft($serviceId);
        if ($managed !== null) {
            return ['version' => $managed, 'cloned' => false];
        }

        $version = (int)$this->client->cloneServiceVersion($serviceId, $activeVersion)->getNumber();
        $this->client->updateServiceVersionComment($serviceId, $version, self::MANAGED_VERSION_COMMENT);

        return ['version' => $version, 'cloned' => true];
    }

    /**
     * The highest-numbered inactive, unlocked version this extension previously
     * created (recognised by its comment marker), or null if there is none.
     * Public so callers can peek at a reusable draft without triggering a clone.
     */
    public function findManagedDraft(string $serviceId): ?int
    {
        $managed = null;
        foreach ($this->client->listServiceVersions($serviceId) as $version) {
            if ((bool)$version->getActive() === false
                && (bool)$version->getLocked() === false
                && (string)$version->getComment() === self::MANAGED_VERSION_COMMENT
            ) {
                $number = (int)$version->getNumber();
                if ($managed === null || $number > $managed) {
                    $managed = $number;
                }
            }
        }

        return $managed;
    }

    /**
     * @param VersionResponse[] $versions
     */
    private function latestVersionNumber(array $versions): ?int
    {
        $latest = null;
        foreach ($versions as $version) {
            $number = (int)$version->getNumber();
            if ($latest === null || $number > $latest) {
                $latest = $number;
            }
        }

        return $latest;
    }
}
