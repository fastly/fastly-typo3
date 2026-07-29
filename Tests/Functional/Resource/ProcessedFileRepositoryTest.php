<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Functional\Resource;

use Fastly\Cdn\Resource\ProcessedFileRepository as FastlyProcessedFileRepository;
use Fastly\Cdn\Resource\V13\ProcessedFileRepository as FastlyProcessedFileRepositoryV13;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository as CoreProcessedFileRepository;
use TYPO3\CMS\Core\Resource\Processing\TaskTypeRegistry;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\Service\ConfigurationService;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The extension XCLASSes the core ProcessedFileRepository (see ext_localconf.php)
 * so that IO-eligible images always get a fresh ProcessedFile — the Fastly
 * processor then builds an IO URL instead of TYPO3 serving a locally processed
 * variant. These tests run against a booted TYPO3 with a real local storage,
 * a real file index and a real database.
 */
final class ProcessedFileRepositoryTest extends FunctionalTestCase
{
    /**
     * A real 8x8 JPEG so the FAL indexer extracts genuine width/height metadata.
     */
    private const string TINY_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNzAK/9sAQwAKBwcIBwYKCAgICwoKCw4YEA4NDQ4dFRYRGCMfJSQiHyIhJis3LyYpNCkhIjBBMTQ5Oz4+PiUuRElDPEg3PT47/9sAQwEKCwsODQ4cEBAcOygiKDs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7/8AAEQgACAAIAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A5miiivIP0Q//2Q==';

    protected array $testExtensionsToLoad = ['fastly'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'fastly' => [
                'serviceId' => 'SERVICE_ID_PLACEHOLDER',
                'apiToken' => 'API_TOKEN_PLACEHOLDER',
                'assetUrl' => '_images/',
                // Boolean toggles must be real booleans: the autowire expressions
                // bind them to strictly typed bool constructor parameters.
                'enableImageOptimizer' => true,
                'enableCdn' => false,
                'quality' => '85,75',
                'allowedExtensions' => 'jpg,jpeg,webp,avif,png,tiff',
                'ignoreAssets' => false,
                'vclRootPaths' => '',
            ],
        ],
    ];

    private ResourceStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin');
        $storageRepository = $this->get(StorageRepository::class);
        $storageUid = $storageRepository->createLocalStorage('fileadmin', 'fileadmin/', 'relative');
        $this->storage = $storageRepository->findByUid($storageUid);
    }

    private function addFileToStorage(string $name, string $content): File
    {
        $sourcePath = $this->instancePath . '/typo3temp/' . $name;
        file_put_contents($sourcePath, $content);
        $file = $this->storage->addFile($sourcePath, $this->storage->getRootLevelFolder(), $name);
        $this->assertInstanceOf(File::class, $file);

        return $file;
    }

    /**
     * Persist a sys_file_processedfile record exactly as the core repository
     * would look it up (same task-sanitized configuration checksum), so the
     * tests can tell apart "fresh object from the Fastly branch" and
     * "persisted record from the core fallback".
     *
     * @param array<string, mixed> $configuration
     */
    private function insertProcessedFileRecord(File $file, string $taskType, array $configuration): int
    {
        $temporaryProcessedFile = new ProcessedFile($file, $taskType, $configuration);
        $task = GeneralUtility::makeInstance(TaskTypeRegistry::class)
            ->getTaskForType($taskType, $temporaryProcessedFile, $configuration);
        $task->sanitizeConfiguration();

        $sanitizedConfiguration = $task->getConfiguration();

        $connection = $this->getConnectionPool()->getConnectionForTable('sys_file_processedfile');
        $connection->insert('sys_file_processedfile', [
            'original' => $file->getUid(),
            'task_type' => $taskType,
            'configurationsha1' => sha1((new ConfigurationService())->serialize($sanitizedConfiguration)),
            'configuration' => serialize($sanitizedConfiguration),
            'originalfilesha1' => $file->getSha1(),
            'storage' => $this->storage->getUid(),
            'identifier' => '/_processed_/persisted_' . $file->getUid() . '.' . $file->getExtension(),
            'name' => 'persisted_' . $file->getUid() . '.' . $file->getExtension(),
            'checksum' => 'testcafe00',
        ]);

        return (int)$connection->lastInsertId();
    }

    public function testCoreRepositoryResolvesToFastlyOverrideWhenImageOptimizerIsEnabled(): void
    {
        $repository = GeneralUtility::makeInstance(CoreProcessedFileRepository::class);

        $expectedClass = (new Typo3Version())->getMajorVersion() < 14
            ? FastlyProcessedFileRepositoryV13::class
            : FastlyProcessedFileRepository::class;
        $this->assertInstanceOf($expectedClass, $repository);
    }

    public function testIoEligibleImageBypassesPersistedProcessedFileRecord(): void
    {
        $file = $this->addFileToStorage('example.jpg', base64_decode(self::TINY_JPEG_BASE64));
        $this->assertSame(8, (int)$file->getProperty('width'), 'the indexer must extract real image dimensions');
        $configuration = ['width' => 4, 'height' => 4];
        $persistedUid = $this->insertProcessedFileRecord($file, ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, $configuration);

        $repository = GeneralUtility::makeInstance(CoreProcessedFileRepository::class);
        $processedFile = $repository->findOneByOriginalFileAndTaskTypeAndConfiguration(
            $file,
            ProcessedFile::CONTEXT_IMAGECROPSCALEMASK,
            $configuration,
        );

        $this->assertTrue($processedFile->isNew(), 'an IO-eligible image must get a fresh ProcessedFile (uid ' . $persistedUid . ' must be ignored)');
    }

    public function testNonEligibleFileFallsBackToPersistedProcessedFileRecord(): void
    {
        $file = $this->addFileToStorage('document.txt', 'plain text, not an image');
        $configuration = ['width' => 4, 'height' => 4];
        $persistedUid = $this->insertProcessedFileRecord($file, ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, $configuration);

        $repository = GeneralUtility::makeInstance(CoreProcessedFileRepository::class);
        $processedFile = $repository->findOneByOriginalFileAndTaskTypeAndConfiguration(
            $file,
            ProcessedFile::CONTEXT_IMAGECROPSCALEMASK,
            $configuration,
        );

        $this->assertFalse($processedFile->isNew(), 'non-eligible files must use the core lookup');
        $this->assertSame($persistedUid, $processedFile->getUid(), 'the persisted record must be returned unchanged');
    }
}
