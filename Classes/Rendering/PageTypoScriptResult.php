<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Rendering;

use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;

/**
 * Frontend TypoScript of a page together with the rootline it was calculated for.
 */
final readonly class PageTypoScriptResult
{
    /**
     * @param array<int, array<string, mixed>> $rootLine as RootlineUtility returns it: the page first, the site root last
     */
    public function __construct(
        public FrontendTypoScript $typoScript,
        public array $rootLine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function pageRecord(): array
    {
        $first = array_key_first($this->rootLine);

        return $first === null ? [] : $this->rootLine[$first];
    }
}
