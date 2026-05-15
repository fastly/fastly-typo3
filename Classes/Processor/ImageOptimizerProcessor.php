<?php

declare(strict_types=1);

namespace Fastly\Cdn\Processor;

use Fastly\Cdn\Service\UrlBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Imaging\ImageDimension;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Processing\ProcessorInterface;
use TYPO3\CMS\Core\Resource\Processing\TaskInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class ImageOptimizerProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(expression: 'service("extension-configuration").get("fastly", "enableImageOptimizer")')]
        protected bool $enabled,
        #[Autowire(expression: 'service("extension-configuration").get("fastly", "allowedExtensions")')]
        protected string $allowedExtensions,
        #[Autowire(expression: 'service("extension-configuration").get("fastly", "assetUrl")')]
        protected string $assetUrl,
        #[Autowire(expression: 'service("extension-configuration").get("fastly", "quality")')]
        protected string $quality,
        #[Autowire(expression: 'service("extension-configuration").get("fastly", "ignoreAssets")')]
        protected bool $ignoreAssets = false,
    ) {
    }

    public function canProcessTask(TaskInterface $task)
    {
        if ($this->enabled === false) {
            return false;
        }

        $allowedFileExtensions = GeneralUtility::trimExplode(
            ',',
            empty($this->allowedExtensions) ? 'jpg,jpeg,webp,avif,png,tiff' : $this->allowedExtensions
        );

        $sourceFile = $task->getSourceFile();
        return ($sourceFile->getStorage()->isPublic()
            && in_array(strtolower($task->getSourceFile()->getExtension()), $allowedFileExtensions)
            && in_array($task->getName(), ['Preview', 'CropScaleMask'], true)
            && $sourceFile->getProperty('width') > 0
            && $sourceFile->getProperty('height') > 0
            && !($this->ignoreAssets && str_starts_with($sourceFile->getPublicUrl(), '/_assets/'))
        );
    }

    public function processTask(TaskInterface $task)
    {
        $processedFile = $task->getTargetFile();
        $processingConfiguration = $processedFile->getProcessingConfiguration();
        $imageDimension = ImageDimension::fromProcessingTask($task);

        $urlBuilder = new UrlBuilder($this->assetUrl);
        $urlBuilder->setWidth($imageDimension->getWidth())
            ->setHeight($imageDimension->getHeight())
            ->setSourceUrl($this->getPublicUrlOfSourceFile($task->getSourceFile()))
            ->setQuality($this->quality)
            ->setCacheBuster($task->getSourceFile()->getSha1());

        if ($GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_allowUpscaling']) {
            $urlBuilder->allowUpscaling();
        }

        if (!empty($this->quality)) {
            $urlBuilder->setQuality($this->quality);
        } else {
            $urlBuilder->setQuality($GLOBALS['TYPO3_CONF_VARS']['GFX']['jpg_quality']);
        }

        $cropData = $processingConfiguration['crop'] ?? false;
        if (is_string($cropData) && $cropArea = json_decode($cropData)) {
            $urlBuilder->setCrop(
                $cropArea->width,
                $cropArea->height,
                $cropArea->x,
                $cropArea->y,
            );
            $urlBuilder->setResizeType('force');
        } elseif ($cropData instanceof Area) {
            $urlBuilder->setCrop(
                $cropData->getWidth(),
                $cropData->getHeight(),
                $cropData->getOffsetLeft(),
                $cropData->getOffsetTop(),
            );
            $urlBuilder->setResizeType('force');
        } elseif (
            str_ends_with($processingConfiguration['width'] ?? '', 'c') ||
            str_ends_with($processingConfiguration['height'] ?? '', 'c')
        ) {
            $urlBuilder->setResizeType('fill');
        }

        $processedFile->setName($task->getTargetFileName());
        $processedFile->updateProperties(
            [
                'width' => $imageDimension->getWidth(),
                'height' => $imageDimension->getHeight(),
                'size' => 0,
                'checksum' => $task->getConfigurationChecksum(),
                'processing_url' => $urlBuilder->generate()
            ]
        );
        $task->setExecuted(false);
    }

    protected function getPublicUrlOfSourceFile(FileInterface $sourceFile): string
    {
        $publicUrl = $sourceFile->getPublicUrl();
        if (!str_starts_with($publicUrl, 'http://') && !str_starts_with($publicUrl, 'https://')) {
            if (!empty($this->assetUrl)) {
                $publicUrl = rtrim($this->assetUrl, '/') . '/' . ltrim($publicUrl, '/');
            } else {
                $publicUrl = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST') . '/' . ltrim($publicUrl, '/');
            }
        }

        return $publicUrl;
    }
}
