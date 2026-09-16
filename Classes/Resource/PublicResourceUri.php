<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Resource;

use TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface;
use TYPO3\CMS\Core\SystemResource\SystemResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Public URL of an extension resource ("EXT:my_ext/Resources/Public/..."); other values (absolute
 * URLs, site relative paths) pass through unchanged. TYPO3 14 resolves through the System Resource
 * API, TYPO3 13 through PathUtility (the API does not exist there yet).
 */
class PublicResourceUri
{
    private ?SystemResourceFactory $systemResourceFactory = null;
    private ?SystemResourcePublisherInterface $resourcePublisher = null;
    private ?bool $hasSystemResourceApi = null;

    public function resolve(string $pathOrUrl): string
    {
        if (!str_starts_with($pathOrUrl, 'EXT:')) {
            return $pathOrUrl;
        }
        if (!$this->hasSystemResourceApi()) {
            return PathUtility::getPublicResourceWebPath($pathOrUrl);
        }
        $this->systemResourceFactory ??= GeneralUtility::makeInstance(SystemResourceFactory::class);
        $this->resourcePublisher ??= GeneralUtility::makeInstance(SystemResourcePublisherInterface::class);

        return (string)$this->resourcePublisher->generateUri($this->systemResourceFactory->createPublicResource($pathOrUrl), null);
    }

    private function hasSystemResourceApi(): bool
    {
        return $this->hasSystemResourceApi ??= class_exists(SystemResourceFactory::class) && interface_exists(SystemResourcePublisherInterface::class);
    }
}
