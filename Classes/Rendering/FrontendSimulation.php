<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Rendering;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * TYPO3 13 only: the ContentObjectRenderer still reaches for $GLOBALS['TSFE'] in a few places
 * (typolink inside parseFunc, getData, SPLIT), which a backend request does not have. The core's own
 * f:cObject view helper works around that outside the frontend by putting a stand-in into the
 * global; this class does the same with a real, minimally initialised TypoScriptFrontendController,
 * because the stand-in of the core is a stdClass that fails the return type of
 * ContentObjectRenderer::getTypoScriptFrontendController() as soon as typolink is involved.
 *
 * TYPO3 14 has removed the controller, so the class is referenced by name only and the simulation
 * is skipped when it does not exist. The code path disappears with TYPO3 13 support.
 */
final class FrontendSimulation
{
    private const CONTROLLER_CLASS = 'TYPO3\\CMS\\Frontend\\Controller\\TypoScriptFrontendController';

    public function isNeeded(): bool
    {
        return class_exists(self::CONTROLLER_CLASS);
    }

    /**
     * Sets up $GLOBALS['TSFE'] for the given page and returns the closure that restores the
     * previous state. Call it in a finally block.
     *
     * @param array<string, mixed> $pageRecord
     * @param array<int, array<string, mixed>> $rootLine
     * @return \Closure(): void
     */
    public function start(ServerRequestInterface $request, int $pageId, array $pageRecord, array $rootLine): \Closure
    {
        $previous = $GLOBALS['TSFE'] ?? null;
        $restore = static function () use ($previous): void {
            $GLOBALS['TSFE'] = $previous;
        };
        if (!$this->isNeeded()) {
            return $restore;
        }

        $controller = GeneralUtility::makeInstance(self::CONTROLLER_CLASS);
        $controller->initializePageRenderer($request);
        $controller->initializeLanguageService($request);
        $controller->id = $pageId;
        $controller->page = $pageRecord;
        $controller->rootLine = $rootLine;
        $GLOBALS['TSFE'] = $controller;
        // the renderer picks the controller up from the global, as any renderer created during rendering will
        $controller->cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);

        return $restore;
    }
}
