<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Rendering;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * The frontend TypoScript of a page, calculated in a backend request.
 *
 * Same approach as Extbase takes for backend modules (BackendConfigurationManager): rootline and
 * sys_template rows of the page, the sets of its site, then the core's FrontendTypoScriptFactory
 * with the persistent TypoScript cache (parsing happens once per TypoScript state, shared with the
 * frontend). Within one request the result is kept per page, because building the frontend request
 * and rendering ask for it in turn; the preview request renders a single element anyway.
 */
final class PageTypoScript
{
    /** @var array<int, PageTypoScriptResult> */
    private array $results = [];

    public function __construct(
        private readonly SysTemplateRepository $sysTemplateRepository,
        private readonly FrontendTypoScriptFactory $frontendTypoScriptFactory,
        #[Autowire(service: 'cache.typoscript')]
        private readonly PhpFrontend $typoScriptCache,
    ) {}

    public function forPage(Site $site, int $pageId, ServerRequestInterface $request): PageTypoScriptResult
    {
        if (isset($this->results[$pageId])) {
            return $this->results[$pageId];
        }

        /** @var array<int, array<string, mixed>> $rootLine */
        $rootLine = GeneralUtility::makeInstance(RootlineUtility::class, $pageId)->get();
        $rootLineForSysTemplates = $rootLine;
        if ($site->isTypoScriptRoot()) {
            // like the frontend: sys_template rows of parent sites must not leak in
            $rootLineForSysTemplates = [];
            foreach ($rootLine as $index => $page) {
                $rootLineForSysTemplates[$index] = $page;
                $uid = $page['uid'] ?? null;
                if (is_numeric($uid) && (int)$uid === $site->getRootPageId()) {
                    break;
                }
            }
        }
        $sysTemplateRows = $this->sysTemplateRepository->getSysTemplateRowsByRootline($rootLineForSysTemplates, $request);

        $expressionMatcherVariables = [
            'request' => $request,
            'pageId' => $pageId,
            'page' => $rootLine[array_key_first($rootLine) ?? 0] ?? [],
            'fullRootLine' => $rootLine,
            'site' => $site,
        ];
        $typoScript = $this->frontendTypoScriptFactory->createSettingsAndSetupConditions($site, $sysTemplateRows, $expressionMatcherVariables, $this->typoScriptCache);
        $typoScript = $this->frontendTypoScriptFactory->createSetupConfigOrFullSetup(true, $typoScript, $site, $sysTemplateRows, $expressionMatcherVariables, '0', $this->typoScriptCache, $request);

        return $this->results[$pageId] = new PageTypoScriptResult($typoScript, $rootLine);
    }
}
