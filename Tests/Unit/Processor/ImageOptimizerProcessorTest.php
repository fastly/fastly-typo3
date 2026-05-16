<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Processor;

use Fastly\Cdn\Processor\ImageOptimizerProcessor;
use ReflectionMethod;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\Processing\TaskInterface;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ImageOptimizerProcessorTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_allowUpscaling'] = false;
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['jpg_quality'] = 75;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['GFX']);
        parent::tearDown();
    }

    private function makeProcessor(
        bool $enabled = true,
        string $allowedExtensions = 'jpg,jpeg,webp,avif,png,tiff',
        string $assetUrl = 'https://cdn.example.com',
        string $quality = '85',
        bool $ignoreAssets = false,
    ): ImageOptimizerProcessor {
        return new ImageOptimizerProcessor($enabled, $allowedExtensions, $assetUrl, $quality, $ignoreAssets);
    }

    private function makeTask(
        bool $publicStorage = true,
        string $extension = 'jpg',
        string $taskName = 'Preview',
        int $width = 800,
        int $height = 600,
        string $publicUrl = '/fileadmin/test.jpg',
    ): TaskInterface {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('isPublic')->willReturn($publicStorage);

        // TaskInterface::getSourceFile() is declared to return File (concrete class),
        // so we must mock File, not FileInterface.
        $sourceFile = $this->createMock(File::class);
        $sourceFile->method('getStorage')->willReturn($storage);
        $sourceFile->method('getExtension')->willReturn($extension);
        $sourceFile->method('getPublicUrl')->willReturn($publicUrl);
        $sourceFile->method('getProperty')->willReturnMap([
            ['width', $width],
            ['height', $height],
        ]);
        $sourceFile->method('getSha1')->willReturn('sha1placeholder');

        $task = $this->createMock(TaskInterface::class);
        $task->method('getSourceFile')->willReturn($sourceFile);
        $task->method('getName')->willReturn($taskName);

        return $task;
    }

    private function processTaskAndReturnProperties(
        ImageOptimizerProcessor $processor,
        array $processingConfiguration = ['width' => '200', 'height' => '100'],
        string $publicUrl = '/fileadmin/img.jpg',
        int $sourceWidth = 800,
        int $sourceHeight = 600,
    ): array {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('isPublic')->willReturn(true);

        $sourceFile = $this->createMock(File::class);
        $sourceFile->method('getStorage')->willReturn($storage);
        $sourceFile->method('getExtension')->willReturn('jpg');
        $sourceFile->method('getPublicUrl')->willReturn($publicUrl);
        $sourceFile->method('getProperty')->willReturnMap([
            ['width', $sourceWidth],
            ['height', $sourceHeight],
        ]);
        $sourceFile->method('getSha1')->willReturn('sha1hash');

        $processedFile = $this->createMock(ProcessedFile::class);
        $processedFile->method('getProcessingConfiguration')->willReturn($processingConfiguration);
        $processedFile->method('getOriginalFile')->willReturn($sourceFile);
        $processedFile->method('getTaskIdentifier')->willReturn('Preview');
        $processedFile->expects(self::once())->method('setName')->with('processed.jpg');

        $capturedProps = null;
        $processedFile->expects(self::once())->method('updateProperties')->willReturnCallback(
            static function (array $props) use (&$capturedProps): void {
                $capturedProps = $props;
            }
        );

        $task = $this->createMock(TaskInterface::class);
        $task->method('getSourceFile')->willReturn($sourceFile);
        $task->method('getTargetFile')->willReturn($processedFile);
        $task->method('getConfiguration')->willReturn($processingConfiguration);
        $task->method('getName')->willReturn('Preview');
        $task->method('getTargetFileName')->willReturn('processed.jpg');
        $task->method('getConfigurationChecksum')->willReturn('chk');
        $task->expects(self::once())->method('setExecuted')->with(false);

        $processor->processTask($task);

        self::assertIsArray($capturedProps);
        return $capturedProps;
    }

    // -------------------------------------------------------------------------
    // canProcessTask()
    // -------------------------------------------------------------------------

    public function testCanProcessTaskReturnsFalseWhenDisabled(): void
    {
        $processor = $this->makeProcessor(enabled: false);
        self::assertFalse($processor->canProcessTask($this->makeTask()));
    }

    public function testCanProcessTaskReturnsTrueForValidJpegPreviewTask(): void
    {
        $processor = $this->makeProcessor();
        self::assertTrue($processor->canProcessTask($this->makeTask()));
    }

    public function testCanProcessTaskReturnsFalseForNonPublicStorage(): void
    {
        $processor = $this->makeProcessor();
        self::assertFalse($processor->canProcessTask($this->makeTask(publicStorage: false)));
    }

    public function testCanProcessTaskReturnsFalseForDisallowedExtension(): void
    {
        $processor = $this->makeProcessor();
        self::assertFalse($processor->canProcessTask($this->makeTask(extension: 'svg')));
    }

    public function testCanProcessTaskReturnsFalseForUnsupportedTaskName(): void
    {
        $processor = $this->makeProcessor();
        self::assertFalse($processor->canProcessTask($this->makeTask(taskName: 'Scale')));
    }

    public function testCanProcessTaskReturnsFalseWhenWidthIsZero(): void
    {
        $processor = $this->makeProcessor();
        self::assertFalse($processor->canProcessTask($this->makeTask(width: 0)));
    }

    public function testCanProcessTaskReturnsFalseWhenHeightIsZero(): void
    {
        $processor = $this->makeProcessor();
        self::assertFalse($processor->canProcessTask($this->makeTask(height: 0)));
    }

    public function testCanProcessTaskReturnsFalseWhenIgnoreAssetsAndAssetUrl(): void
    {
        $processor = $this->makeProcessor(ignoreAssets: true);
        self::assertFalse($processor->canProcessTask($this->makeTask(publicUrl: '/_assets/hash/test.jpg')));
    }

    public function testCanProcessTaskReturnsTrueWhenIgnoreAssetsButNotAssetUrl(): void
    {
        $processor = $this->makeProcessor(ignoreAssets: true);
        self::assertTrue($processor->canProcessTask($this->makeTask(publicUrl: '/fileadmin/test.jpg')));
    }

    public function testCanProcessTaskRespectsCropScaleMaskTaskName(): void
    {
        $processor = $this->makeProcessor();
        self::assertTrue($processor->canProcessTask($this->makeTask(taskName: 'CropScaleMask')));
    }

    public function testCanProcessTaskWithCustomAllowedExtensions(): void
    {
        $processor = $this->makeProcessor(allowedExtensions: 'gif,bmp');
        self::assertTrue($processor->canProcessTask($this->makeTask(extension: 'gif')));
        self::assertFalse($processor->canProcessTask($this->makeTask(extension: 'jpg')));
    }

    // -------------------------------------------------------------------------
    // getPublicUrlOfSourceFile() — tested indirectly
    // -------------------------------------------------------------------------

    public function testGetPublicUrlPrependsAssetUrlForRelativePath(): void
    {
        $processor = $this->makeProcessor(assetUrl: 'https://cdn.example.com');
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('isPublic')->willReturn(true);
        $sourceFile = $this->createMock(File::class);
        $sourceFile->method('getStorage')->willReturn($storage);
        $sourceFile->method('getExtension')->willReturn('jpg');
        $sourceFile->method('getPublicUrl')->willReturn('/fileadmin/img.jpg');
        $sourceFile->method('getProperty')->willReturn(800);
        $sourceFile->method('getSha1')->willReturn('sha1hash');

        $processedFile = $this->createMock(ProcessedFile::class);
        $processedFile->method('getProcessingConfiguration')->willReturn(['width' => '200', 'height' => '100']);
        $processedFile->method('getOriginalFile')->willReturn($sourceFile);
        $processedFile->method('getTaskIdentifier')->willReturn('Preview');
        $capturedProps = null;
        $processedFile->method('updateProperties')->willReturnCallback(
            static function (array $props) use (&$capturedProps): void {
                $capturedProps = $props;
            }
        );
        $processedFile->method('setName');

        $task = $this->createMock(TaskInterface::class);
        $task->method('getSourceFile')->willReturn($sourceFile);
        $task->method('getTargetFile')->willReturn($processedFile);
        $task->method('getConfiguration')->willReturn(['width' => '200', 'height' => '100']);
        $task->method('getName')->willReturn('Preview');
        $task->method('getTargetFileName')->willReturn('processed.jpg');
        $task->method('getConfigurationChecksum')->willReturn('chk');

        $processor->processTask($task);

        self::assertNotNull($capturedProps, 'updateProperties() must have been called');
        self::assertStringContainsString(
            '/fileadmin/img.jpg',
            $capturedProps['processing_url'] ?? '',
            'Processing URL must contain the source image path',
        );
    }

    public function testGetPublicUrlDoesNotModifyAbsoluteUrl(): void
    {
        $processor = $this->makeProcessor(assetUrl: 'https://cdn.example.com');
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('isPublic')->willReturn(true);
        $sourceFile = $this->createMock(File::class);
        $sourceFile->method('getStorage')->willReturn($storage);
        $sourceFile->method('getExtension')->willReturn('jpg');
        $sourceFile->method('getPublicUrl')->willReturn('https://remote.cdn.com/images/photo.jpg');
        $sourceFile->method('getProperty')->willReturn(800);
        $sourceFile->method('getSha1')->willReturn('sha1hash');

        $processedFile = $this->createMock(ProcessedFile::class);
        $processedFile->method('getProcessingConfiguration')->willReturn(['width' => '200', 'height' => '100']);
        $processedFile->method('getOriginalFile')->willReturn($sourceFile);
        $processedFile->method('getTaskIdentifier')->willReturn('Preview');
        $capturedProps = null;
        $processedFile->method('updateProperties')->willReturnCallback(
            static function (array $props) use (&$capturedProps): void {
                $capturedProps = $props;
            }
        );
        $processedFile->method('setName');

        $task = $this->createMock(TaskInterface::class);
        $task->method('getSourceFile')->willReturn($sourceFile);
        $task->method('getTargetFile')->willReturn($processedFile);
        $task->method('getConfiguration')->willReturn(['width' => '200', 'height' => '100']);
        $task->method('getName')->willReturn('Preview');
        $task->method('getTargetFileName')->willReturn('processed.jpg');
        $task->method('getConfigurationChecksum')->willReturn('chk');

        $processor->processTask($task);

        self::assertNotNull($capturedProps, 'updateProperties() must have been called');
        self::assertStringNotContainsString(
            'cdn.example.com/https',
            $capturedProps['processing_url'] ?? '',
            'assetUrl must not be prepended to an already-absolute URL',
        );
    }

    public function testProcessTaskAllowsUpscalingWhenConfigured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_allowUpscaling'] = true;
        $processor = $this->makeProcessor();

        $properties = $this->processTaskAndReturnProperties(
            $processor,
            ['width' => '1000', 'height' => '800'],
        );

        self::assertStringContainsString('enable=upscale', $properties['processing_url']);
    }

    public function testProcessTaskFallsBackToGlobalJpegQualityWhenQualityIsEmpty(): void
    {
        $processor = $this->makeProcessor(quality: '');

        $properties = $this->processTaskAndReturnProperties($processor);

        self::assertStringContainsString('quality=75', $properties['processing_url']);
    }

    public function testProcessTaskAppliesJsonCropConfiguration(): void
    {
        $processor = $this->makeProcessor();

        $properties = $this->processTaskAndReturnProperties(
            $processor,
            [
                'width' => '200',
                'height' => '100',
                'crop' => '{"x":10,"y":20,"width":300,"height":150}',
            ],
        );

        self::assertStringContainsString('precrop=300%2C150%2Cx10%2Cy20', $properties['processing_url']);
        self::assertStringContainsString('fit=crop', $properties['processing_url']);
    }

    public function testProcessTaskAppliesAreaCropConfiguration(): void
    {
        $processor = $this->makeProcessor();

        $properties = $this->processTaskAndReturnProperties(
            $processor,
            [
                'width' => '200',
                'height' => '100',
                'crop' => new Area(15, 25, 320, 160),
            ],
        );

        self::assertStringContainsString('precrop=320%2C160%2Cx15%2Cy25', $properties['processing_url']);
        self::assertStringContainsString('fit=crop', $properties['processing_url']);
    }

    public function testProcessTaskUsesCropFitWhenWidthRequestsCropScale(): void
    {
        $processor = $this->makeProcessor();

        $properties = $this->processTaskAndReturnProperties(
            $processor,
            ['width' => '200c', 'height' => '100'],
        );

        self::assertStringContainsString('fit=crop', $properties['processing_url']);
    }

    // -------------------------------------------------------------------------
    // getPublicUrlOfSourceFile() — tested directly via reflection
    // -------------------------------------------------------------------------

    private function callGetPublicUrl(ImageOptimizerProcessor $processor, string $publicUrl): string
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('getPublicUrl')->willReturn($publicUrl);
        $method = new ReflectionMethod($processor, 'getPublicUrlOfSourceFile');
        return $method->invoke($processor, $file);
    }

    public function testGetPublicUrlOfSourceFilePrependsAssetUrlToRelativePath(): void
    {
        $processor = $this->makeProcessor(assetUrl: 'https://cdn.example.com');
        $result = $this->callGetPublicUrl($processor, '/fileadmin/img.jpg');

        self::assertSame('https://cdn.example.com/fileadmin/img.jpg', $result);
    }

    public function testGetPublicUrlOfSourceFileDoesNotPrependToHttpUrl(): void
    {
        $processor = $this->makeProcessor(assetUrl: 'https://cdn.example.com');
        $result = $this->callGetPublicUrl($processor, 'http://remote.example.com/img.jpg');

        self::assertSame('http://remote.example.com/img.jpg', $result);
    }

    public function testGetPublicUrlOfSourceFileDoesNotPrependToHttpsUrl(): void
    {
        $processor = $this->makeProcessor(assetUrl: 'https://cdn.example.com');
        $result = $this->callGetPublicUrl($processor, 'https://remote.example.com/img.jpg');

        self::assertSame('https://remote.example.com/img.jpg', $result);
    }

    public function testGetPublicUrlOfSourceFileUsesRequestHostWhenAssetUrlEmpty(): void
    {
        $_SERVER['HTTP_HOST'] = 'test.typo3.local';
        try {
            $processor = $this->makeProcessor(assetUrl: '');
            $result = $this->callGetPublicUrl($processor, '/fileadmin/img.jpg');

            self::assertStringContainsString('test.typo3.local', $result);
            self::assertStringContainsString('/fileadmin/img.jpg', $result);
        } finally {
            unset($_SERVER['HTTP_HOST']);
        }
    }
}
