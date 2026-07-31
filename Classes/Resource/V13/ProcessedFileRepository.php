<?php

declare(strict_types=1);

namespace Fastly\Cdn\Resource\V13;

use Fastly\Cdn\Processor\ImageOptimizerProcessor;
use Override;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class ProcessedFileRepository extends \TYPO3\CMS\Core\Resource\ProcessedFileRepository
{
    #[Override]
    public function findOneByOriginalFileAndTaskTypeAndConfiguration(File $file, string $taskType, array $configuration): ProcessedFile
    {
        $task = $this->prepareTaskObject($file, $taskType, $configuration);
        $processor = GeneralUtility::makeInstance(ImageOptimizerProcessor::class);
        if ($processor->canProcessTask($task)) {
            return $this->createNewProcessedFileObject($file, $taskType, $configuration);
        }

        return parent::findOneByOriginalFileAndTaskTypeAndConfiguration($file, $taskType, $configuration);
    }
}
