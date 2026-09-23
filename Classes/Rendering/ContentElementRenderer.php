<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Rendering;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Context\AspectInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\FileProcessingAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\RegisterStack;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Renders a content record in a backend request the way the frontend renders it: with the frontend
 * TypoScript of the record's page and the content object registered there (by default "tt_content").
 * Which templates, partials, layouts and data processors apply is decided by the site's TypoScript,
 * so overrides in a site package show up in the preview without a second configuration.
 *
 * The request is cloned with the attributes the frontend rendering chain reads: the site, the
 * record's language, the calculated TypoScript (needed by ContentObjectRenderer::mergeTSRef to
 * resolve "=<" references; the core's f:cObject falls back to Extbase here and silently renders
 * nothing for referenced objects), the page information for typolink and getData, and fresh cache
 * and register objects. Meant to run in the isolated preview request (PreviewController); the
 * aspects and globals it touches are restored either way.
 *
 * While rendering, the visibility aspect hides unpublished relations like the website does, and file
 * processing is switched to immediate so image variants exist right away instead of being deferred
 * as in other backend requests.
 */
final readonly class ContentElementRenderer
{
    public function __construct(
        private PageTypoScript $pageTypoScript,
        private SiteFinder $siteFinder,
        private Context $context,
        private FrontendSimulation $frontendSimulation,
    ) {}

    /**
     * The request the record is rendered with: the given one plus the attributes the frontend
     * rendering chain reads. Callers that want the rest of the process to see a frontend request
     * (e.g. the isolated preview request) can put it into $GLOBALS['TYPO3_REQUEST'] before render().
     */
    public function frontendRequest(RecordInterface $record, ServerRequestInterface $request): ServerRequestInterface
    {
        $row = self::row($record->getRawRecord()?->toArray() ?? []);
        $pageId = $record->getPid();
        $site = $this->siteFinder->getSiteByPageId($pageId);
        // site, language and request type first: TypoScript conditions such as [siteLanguage("languageId") == 1]
        // or [applicationContext] read them from the request while the setup is calculated
        $request = $request
            ->withAttribute('site', $site)
            ->withAttribute('language', $this->language($site, $record, $row))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $page = $this->pageTypoScript->forPage($site, $pageId, $request);

        $pageInformation = new PageInformation();
        $pageInformation->setId($pageId);
        $pageInformation->setContentFromPid($pageId);
        $pageInformation->setPageRecord($page->pageRecord());
        $pageInformation->setRootLine($page->rootLine);
        $pageInformation->setLocalRootLine($page->rootLine);

        $frontendRequest = $request
            ->withAttribute('frontend.typoscript', $page->typoScript)
            ->withAttribute('frontend.page.information', $pageInformation);

        return $this->withFrontendRuntimeAttributes($frontendRequest);
    }

    /**
     * @param string $typoScriptObjectPath dotted path of the content object to render, e.g. "tt_content" or "lib.contentElement"
     */
    public function render(RecordInterface $record, ServerRequestInterface $request, string $typoScriptObjectPath = 'tt_content'): string
    {
        $row = self::row($record->getRawRecord()?->toArray() ?? []);
        $pageId = $record->getPid();
        $frontendRequest = $this->frontendRequest($record, $request);
        $site = $this->siteFinder->getSiteByPageId($pageId);
        $page = $this->pageTypoScript->forPage($site, $pageId, $frontendRequest);
        [$name, $configuration] = $this->contentObject($page, $typoScriptObjectPath, $pageId);

        // the backend never sets the fileProcessing aspect itself; restore means "remove" then, not "set to default"
        $previousVisibility = $this->context->hasAspect('visibility') ? $this->context->getAspect('visibility') : null;
        $previousFileProcessing = $this->context->hasAspect('fileProcessing') ? $this->context->getAspect('fileProcessing') : null;
        $restoreFrontend = static function (): void {};
        try {
            $this->context->setAspect('visibility', new VisibilityAspect());
            $this->context->setAspect('fileProcessing', new FileProcessingAspect(false));
            $restoreFrontend = $this->frontendSimulation->start($frontendRequest, $pageId, $page->pageRecord(), $page->rootLine);
            $contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
            $contentObjectRenderer->setRequest($frontendRequest);
            $contentObjectRenderer->start($row, $record->getMainType());

            return $contentObjectRenderer->cObjGetSingle($name, $configuration, $typoScriptObjectPath);
        } finally {
            $restoreFrontend();
            $this->restoreAspect('visibility', $previousVisibility);
            $this->restoreAspect('fileProcessing', $previousFileProcessing);
        }
    }

    private function restoreAspect(string $name, ?AspectInterface $previous): void
    {
        if ($previous instanceof AspectInterface) {
            $this->context->setAspect($name, $previous);
        } else {
            $this->context->unsetAspect($name);
        }
    }

    /**
     * Objects the frontend middleware chain puts on the request and content objects read without a
     * null check: cache instruction and collector (stdWrap cache., addPageCacheTags, COA_INT),
     * register stack (LOAD_REGISTER, getData register:). Fresh instances per rendering, so nothing
     * is cached or tagged beyond this preview. The classes are @internal and differ per version,
     * hence the guards.
     */
    private function withFrontendRuntimeAttributes(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request->getAttribute('frontend.cache.instruction') === null && class_exists(CacheInstruction::class)) {
            $instruction = new CacheInstruction();
            $instruction->disableCache('Look content preview');
            $request = $request->withAttribute('frontend.cache.instruction', $instruction);
        }
        if ($request->getAttribute('frontend.cache.collector') === null && class_exists(CacheDataCollector::class)) {
            $request = $request->withAttribute('frontend.cache.collector', new CacheDataCollector());
        }
        $registerStackClass = RegisterStack::class;
        if ($request->getAttribute('frontend.register.stack') === null && class_exists($registerStackClass)) {
            $request = $request->withAttribute('frontend.register.stack', GeneralUtility::makeInstance($registerStackClass));
        }

        return $request;
    }

    /**
     * Name and configuration of the content object at the dotted path, e.g. ["CASE", [...]] for "tt_content".
     *
     * @return array{0: string, 1: array<mixed>}
     */
    private function contentObject(PageTypoScriptResult $page, string $typoScriptObjectPath, int $pageId): array
    {
        $segments = GeneralUtility::trimExplode('.', $typoScriptObjectPath, true);
        $last = array_pop($segments);
        $setup = $page->typoScript->getSetupArray();
        foreach ($segments as $segment) {
            $setup = $setup[$segment . '.'] ?? null;
            if (!is_array($setup)) {
                throw new \RuntimeException('The TypoScript of page ' . $pageId . ' has no object path "' . $typoScriptObjectPath . '"', 1790000002);
            }
        }
        if ($last === null || !is_string($setup[$last] ?? null)) {
            throw new \RuntimeException('The TypoScript of page ' . $pageId . ' defines no content object "' . $typoScriptObjectPath . '"', 1790000003);
        }
        $configuration = $setup[$last . '.'] ?? [];

        return [$setup[$last], is_array($configuration) ? $configuration : []];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function language(Site $site, RecordInterface $record, array $row): SiteLanguage
    {
        $tca = $GLOBALS['TCA'] ?? null;
        $tableTca = is_array($tca) ? ($tca[$record->getMainType()] ?? null) : null;
        $control = is_array($tableTca) ? ($tableTca['ctrl'] ?? null) : null;
        $languageField = is_array($control) ? ($control['languageField'] ?? null) : null;
        $languageValue = is_string($languageField) ? ($row[$languageField] ?? null) : null;
        $languageId = is_numeric($languageValue) ? (int)$languageValue : 0;
        try {
            return $site->getLanguageById(max($languageId, 0));
        } catch (\InvalidArgumentException) {
            return $site->getDefaultLanguage();
        }
    }

    /**
     * @param array<mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        $result = [];
        foreach ($row as $field => $value) {
            $result[(string)$field] = $value;
        }

        return $result;
    }
}
