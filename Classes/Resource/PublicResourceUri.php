<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Resource;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\SystemResource\Exception\SystemResourceException;
use TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface;
use TYPO3\CMS\Core\SystemResource\SystemResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Public URL of an asset the way the frontend AssetRenderer resolves it: resource identifiers
 * ("EXT:my_ext/Resources/Public/...", on TYPO3 14 also "PKG:", "FAL:", "URI:"), paths relative
 * to the public directory (cache busted, prefixed with the site path) and URLs (unchanged).
 * TYPO3 14 resolves through the System Resource API, TYPO3 13 through PathUtility (the API does
 * not exist there yet).
 *
 * A value that does not resolve to a public file (missing file, unknown extension) yields null
 * and a logged warning instead of an exception: one broken asset must not break the preview.
 */
class PublicResourceUri implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private ?SystemResourceFactory $systemResourceFactory = null;
    private ?SystemResourcePublisherInterface $resourcePublisher = null;
    private ?bool $hasSystemResourceApi = null;

    public function resolve(string $pathOrUrl): ?string
    {
        $uri = $this->hasSystemResourceApi() ? $this->resolveWithSystemResourceApi($pathOrUrl) : $this->resolveWithPathUtility($pathOrUrl);
        if ($uri === null) {
            $this->logger?->warning('Asset "{asset}" does not resolve to a public resource and is left out of the preview', ['asset' => $pathOrUrl]);
        }

        return $uri;
    }

    private function resolveWithSystemResourceApi(string $pathOrUrl): ?string
    {
        $this->systemResourceFactory ??= GeneralUtility::makeInstance(SystemResourceFactory::class);
        $this->resourcePublisher ??= GeneralUtility::makeInstance(SystemResourcePublisherInterface::class);
        try {
            return (string)$this->resourcePublisher->generateUri($this->systemResourceFactory->createPublicResource($pathOrUrl), null);
        } catch (SystemResourceException) {
            return null;
        }
    }

    /**
     * TYPO3 13's AssetRenderer::getAbsoluteWebPath() with the rules of TYPO3 14's System Resource
     * API: an extension path must point into Resources/Public of an installed extension (TYPO3 13
     * would return the file system path of any other file), any other path must be an existing
     * file in the public directory, a leading slash included (TYPO3 13 would treat that as a file
     * system path).
     */
    private function resolveWithPathUtility(string $pathOrUrl): ?string
    {
        if (PathUtility::hasProtocolAndScheme($pathOrUrl)) {
            return $pathOrUrl;
        }
        if (PathUtility::isExtensionPath($pathOrUrl)) {
            $absolutePath = GeneralUtility::getFileAbsFileName($pathOrUrl);
            $isPublic = str_contains($absolutePath, '/Resources/Public/');
        } else {
            $absolutePath = GeneralUtility::getFileAbsFileName(ltrim($pathOrUrl, '/'));
            $isPublic = str_starts_with($absolutePath, Environment::getPublicPath() . '/') && is_file($absolutePath);
        }
        if (!$isPublic) {
            return null;
        }

        return PathUtility::getAbsoluteWebPath(GeneralUtility::createVersionNumberedFilename($absolutePath));
    }

    private function hasSystemResourceApi(): bool
    {
        return $this->hasSystemResourceApi ??= class_exists(SystemResourceFactory::class) && interface_exists(SystemResourcePublisherInterface::class);
    }
}
