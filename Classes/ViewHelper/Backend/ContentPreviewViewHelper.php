<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\ViewHelper\Backend;

use Flowd\Typo3Look\Asset\AssetCollectorIsolation;
use Flowd\Typo3Look\Backend\RecordEditAccess;
use Flowd\Typo3Look\Preview\PreviewDescriptor;
use Flowd\Typo3Look\Preview\PreviewDocument;
use Flowd\Typo3Look\Preview\PreviewTokenService;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\ViewHelpers\Link\EditRecordViewHelper;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\Features;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\TagBuilder;

/**
 * Shows a content element in the page module as the frontend renders it, inside a sandboxed iframe
 * ("allow-scripts" only: opaque origin, no access to the backend document, cookies or storage, no
 * forms, popups or navigation; no pointer events). Its height is reported to the backend page via
 * postMessage (iFramePreview.js -> ContentPreviewHost.js).
 *
 * Two ways to fill the frame:
 *
 * - Children: the view helper renders its children (a Fluid component, a partial, plain HTML) in the
 *   page module request and puts the document into the srcdoc of the iframe. For Content Blocks with
 *   Fluid Components.
 *
 * - "record": no children. The record is rendered with the frontend TypoScript of its page in a
 *   separate request (PreviewController), so the site's PHP never runs inside the page module. The
 *   view helper emits a signed descriptor of what to render; the page module's script exchanges it
 *   for a short-lived token when the frame comes into view and sets the frame's src. For classic
 *   content types and everything rendered through TypoScript.
 *
 * The frame's Content Security Policy limits scripts to the ones the document emits; scripts in the
 * content itself do not run. Feature flags (all off by default, $GLOBALS['TYPO3_CONF_VARS']['SYS']
 * ['features']): "look.contentPreview.allowSiteScripts" loads the site's "js" modules inside the
 * frame, "look.contentPreview.allowMedia" allows video, audio and embedded players,
 * "look.contentPreview.editOverlay" wraps the preview in a hover overlay that opens the record for
 * editing, only for users who may edit it (RecordEditAccess). The record for the overlay is the
 * "record" argument, or the template variables "data" (Content Blocks) or "record".
 *
 * "scale" and "height" fall back to the extension configuration (contentPreview.scale,
 * contentPreview.height) when the view helper is called without them.
 *
 *   <look:backend.contentPreview css="{0: 'EXT:my_site/Resources/Public/Build/main.css'}">
 *       <my:element.textmedia record="{data}" />
 *   </look:backend.contentPreview>
 *
 *   <look:backend.contentPreview record="{record}" css="{0: 'EXT:my_site/Resources/Public/Css/main.css'}" />
 */
class ContentPreviewViewHelper extends AbstractViewHelper implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const FEATURE_ALLOW_SITE_SCRIPTS = PreviewDocument::FEATURE_ALLOW_SITE_SCRIPTS;
    public const FEATURE_ALLOW_MEDIA = PreviewDocument::FEATURE_ALLOW_MEDIA;
    public const FEATURE_EDIT_OVERLAY = 'look.contentPreview.editOverlay';

    protected $escapeOutput = false;

    /** whether be:link.editRecord supports the contextual edit panel; does not change within a request */
    private static ?bool $supportsContextualEditing = null;

    public function __construct(
        protected readonly PageRenderer $pageRenderer,
        protected readonly Features $features,
        protected readonly ExtensionConfiguration $extensionConfiguration,
        protected readonly AssetCollectorIsolation $assetCollectorIsolation,
        protected readonly RecordEditAccess $recordEditAccess,
        protected readonly ViewFactoryInterface $viewFactory,
        protected readonly PreviewDocument $previewDocument,
        protected readonly PreviewTokenService $tokenService,
        protected readonly UriBuilder $uriBuilder,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('record', RecordInterface::class, 'Render this record with the frontend TypoScript of its page in a separate request instead of the children');
        $this->registerArgument('typoscriptObjectPath', 'string', 'Content object that renders the record (with "record")', false, 'tt_content');
        $this->registerArgument('height', 'integer', 'Limit the height of the preview in pixel (default: extension configuration contentPreview.height, 0 = no limit)');
        $this->registerArgument('scale', 'double', 'Scaling of the preview (default: extension configuration contentPreview.scale)');
        $this->registerArgument('bodyClass', 'string', 'CSS class(es) for the body element inside the preview iframe', false, '');
        $this->registerArgument('css', 'array', 'Stylesheets to load inside the iframe (EXT: paths or URLs)', false, []);
        $this->registerArgument('js', 'array', 'JavaScript modules to load inside the iframe (EXT: paths or URLs)', false, []);
    }

    public function render(): ?string
    {
        try {
            $request = $this->renderingContext()->getAttribute(ServerRequestInterface::class);
            if (!$request instanceof ServerRequestInterface) {
                throw new \RuntimeException('The content preview needs the backend request in the rendering context', 1789500002);
            }
            // scale must be positive; height 0 is a valid value (no limit) and must not fall back to the configured default
            $scale = $this->positiveNumber($this->arguments['scale'] ?? null) ?? $this->configuredDefault('scale', 0.5);
            $height = (int)($this->nonNegativeNumber($this->arguments['height'] ?? null) ?? $this->configuredDefault('height', 0));
            $bodyClass = is_string($this->arguments['bodyClass'] ?? null) ? $this->arguments['bodyClass'] : '';
            $css = $this->stringList($this->arguments['css'] ?? null);
            $js = $this->stringList($this->arguments['js'] ?? null);
            $record = $this->arguments['record'] ?? null;

            $tagBuilder = new TagBuilder('iframe');
            $tagBuilder->addAttribute('class', 'look-content-preview');
            $tagBuilder->addAttribute('style', $this->frameStyle($height));
            $tagBuilder->addAttribute('sandbox', 'allow-scripts');
            $tagBuilder->addAttribute('referrerpolicy', 'no-referrer');
            $tagBuilder->addAttribute('title', 'Content preview');
            $tagBuilder->forceClosingTag(true);

            if ($record instanceof RecordInterface) {
                $this->addIsolatedPreview($tagBuilder, $record, $scale, $height, $bodyClass, $css, $js);
            } else {
                $this->addInlinePreview($tagBuilder, $request, $scale, $height, $bodyClass, $css, $js);
            }
            $this->pageRenderer->loadJavaScriptModule('@flowd/look/Backend/ContentPreviewHost.js');

            return $this->wrapWithEditOverlay($tagBuilder->render(), $request, $record instanceof RecordInterface ? $record : null);
        } catch (\Throwable $e) {
            return $this->renderError($e);
        }
    }

    /**
     * Children rendered here, document as srcdoc.
     *
     * @param list<string> $css
     * @param list<string> $js
     */
    private function addInlinePreview(TagBuilder $tagBuilder, ServerRequestInterface $request, float $scale, int $height, string $bodyClass, array $css, array $js): void
    {
        $nonce = $request->getAttribute('nonce');
        if (!$nonce instanceof ConsumableNonce) {
            throw new \RuntimeException('The content preview needs the CSP nonce of the backend request', 1789500000);
        }
        $rendering = $this->assetCollectorIsolation->run(function (): string {
            $children = $this->renderChildren();
            return is_scalar($children) ? (string)$children : '';
        });
        $document = $this->previewDocument->render($request, $nonce->consumeStatic('look.contentPreview'), $rendering, $scale, $height, $bodyClass, $css, $js);
        $tagBuilder->addAttribute('srcdoc', $document);
        $tagBuilder->addAttribute('loading', 'lazy');
    }

    /**
     * Record rendered in the preview request: the frame gets no src yet, only the signed descriptor
     * and the URL of the token route; ContentPreviewHost.js does the rest when the frame comes into view.
     *
     * @param list<string> $css
     * @param list<string> $js
     */
    private function addIsolatedPreview(TagBuilder $tagBuilder, RecordInterface $record, float $scale, int $height, string $bodyClass, array $css, array $js): void
    {
        $children = $this->renderChildren();
        if (is_string($children) && trim($children) !== '') {
            throw new \RuntimeException('The content preview renders either its children or the "record" argument, not both', 1789500003);
        }
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $userId = $backendUser instanceof BackendUserAuthentication ? $backendUser->getUserId() : null;
        if (!$backendUser instanceof BackendUserAuthentication || $userId === null || $userId <= 0) {
            throw new \RuntimeException('The content preview of a record needs a logged in backend user', 1789500004);
        }
        $path = $this->arguments['typoscriptObjectPath'] ?? null;
        $descriptor = new PreviewDescriptor(
            table: $record->getMainType(),
            uid: $record->getUid(),
            pid: $record->getPid(),
            workspace: $backendUser->workspace,
            language: $this->languageOf($record),
            typoScriptObjectPath: is_string($path) && $path !== '' ? $path : 'tt_content',
            scale: $scale,
            height: $height,
            bodyClass: $bodyClass,
            css: $css,
            js: $js,
            backendUserId: $userId,
            issued: time(),
        );
        $tagBuilder->addAttribute('data-look-preview', $this->tokenService->signDescriptor($descriptor));
        $tagBuilder->addAttribute('data-look-preview-token-url', (string)$this->uriBuilder->buildUriFromRoute('ajax_look_preview_token'));
        // "about:blank" until the script sets the real source; keeps the frame from loading the page module itself
        $tagBuilder->addAttribute('src', 'about:blank');
    }

    private function languageOf(RecordInterface $record): int
    {
        $tca = $GLOBALS['TCA'] ?? null;
        $tableTca = is_array($tca) ? ($tca[$record->getMainType()] ?? null) : null;
        $control = is_array($tableTca) ? ($tableTca['ctrl'] ?? null) : null;
        $languageField = is_array($control) ? ($control['languageField'] ?? null) : null;
        $row = $record->getRawRecord()?->toArray() ?? [];
        $value = is_string($languageField) ? ($row[$languageField] ?? null) : null;

        return is_numeric($value) ? max((int)$value, 0) : 0;
    }

    private function frameStyle(int $height): string
    {
        $style = 'width:100%;pointer-events: none;';
        if ($height > 0) {
            $style .= sprintf('max-height: %dpx;', $height);
        }

        return $style;
    }

    private function wrapWithEditOverlay(string $iframe, ServerRequestInterface $request, ?RecordInterface $record): string
    {
        if (!$this->features->isFeatureEnabled(self::FEATURE_EDIT_OVERLAY)) {
            return $iframe;
        }
        [$table, $row] = $record instanceof RecordInterface
            ? [$record->getMainType(), self::row($record->getRawRecord()?->toArray() ?? [])]
            : $this->recordFromTemplateVariables();
        $uid = is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0;
        if ($table === null || $uid === 0 || !$this->recordEditAccess->isEditable($table, $row)) {
            return $iframe;
        }
        $this->pageRenderer->addCssFile('EXT:look/Resources/Public/Css/Backend/ContentPreview.css');

        $normalizedParams = $request->getAttribute('normalizedParams');
        $anchor = '#element-' . $table . '-' . $uid;
        $view = $this->createView($request);
        $view->assignMultiple([
            'iframe' => $iframe,
            'table' => $table,
            'uid' => $uid,
            'returnUrl' => ($normalizedParams instanceof NormalizedParams ? $normalizedParams->getRequestUri() : '') . $anchor,
            'contextual' => $this->supportsContextualEditing(),
        ]);

        return trim($view->render('EditOverlay'));
    }

    /**
     * be:link.editRecord renders the contextual edit trigger (edit panel like the header button) since
     * TYPO3 14.3; earlier versions do not know the argument and get the classic edit link.
     */
    protected function supportsContextualEditing(): bool
    {
        if (self::$supportsContextualEditing === null) {
            $editRecordViewHelper = GeneralUtility::makeInstance(EditRecordViewHelper::class);
            self::$supportsContextualEditing = array_key_exists('contextual', $editRecordViewHelper->prepareArguments());
        }

        return self::$supportsContextualEditing;
    }

    private function createView(ServerRequestInterface $request): ViewInterface
    {
        return $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:look/Resources/Private/Templates/Backend/ContentPreview/'],
            request: $request,
        ));
    }

    /**
     * The record the preview is rendered for: "data" (Content Blocks) or "record" (classic preview
     * templates), as Record API object or raw row.
     *
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private function recordFromTemplateVariables(): array
    {
        $variables = $this->renderingContext()->getVariableProvider();
        $data = $variables->exists('data') ? $variables->get('data') : null;
        if ($data instanceof RecordInterface) {
            return [$data->getMainType(), self::row($data->getRawRecord()?->toArray() ?? [])];
        }
        $record = $variables->exists('record') ? $variables->get('record') : null;
        if ($record instanceof RecordInterface) {
            return [$record->getMainType(), self::row($record->getRawRecord()?->toArray() ?? [])];
        }
        if (is_array($record) && isset($record['uid'])) {
            return ['tt_content', self::row($record)];
        }

        return [null, []];
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

    /**
     * @return list<string>
     */
    private function stringList(mixed $paths): array
    {
        $list = [];
        foreach (is_array($paths) ? $paths : [] as $path) {
            if (is_string($path) && $path !== '') {
                $list[] = $path;
            }
        }

        return $list;
    }

    private function positiveNumber(mixed $value): ?float
    {
        return is_numeric($value) && (float)$value > 0 ? (float)$value : null;
    }

    private function nonNegativeNumber(mixed $value): ?float
    {
        return is_numeric($value) && (float)$value >= 0 ? (float)$value : null;
    }

    private function renderingContext(): RenderingContextInterface
    {
        return $this->renderingContext ?? throw new \LogicException('The view helper has no rendering context', 1789500001);
    }

    /**
     * Default from the extension configuration (ext_conf_template.txt), typed like the given fallback.
     */
    private function configuredDefault(string $key, float|int $fallback): float|int
    {
        try {
            $value = $this->extensionConfiguration->get('look', 'contentPreview/' . $key);
        } catch (\Exception) {
            return $fallback;
        }
        if (!is_numeric($value) || (float)$value < 0 || (is_float($fallback) && (float)$value <= 0)) {
            return $fallback;
        }

        return is_int($fallback) ? (int)$value : (float)$value;
    }

    /**
     * The error is rendered in place (the page module shows no flash messages) and logged. Details
     * (messages may contain paths or configuration) only in development or with backend debugging
     * enabled; production shows a generic callout.
     */
    private function renderError(\Throwable $e): string
    {
        $this->logger?->error('Content preview could not be rendered: ' . $e->getMessage(), ['exception' => $e]);

        $details = $this->showErrorDetails()
            ? htmlspecialchars($e->getMessage(), ENT_QUOTES) . ' (' . $e->getCode() . ')'
            : 'See the TYPO3 log for details (error ' . $e->getCode() . ').';

        return '<div class="callout callout-danger"><div class="callout-content">'
            . '<div class="callout-title">Preview could not be rendered</div>'
            . '<div class="callout-body">' . $details . '</div>'
            . '</div></div>';
    }

    protected function showErrorDetails(): bool
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $backendConfiguration = is_array($configuration) ? ($configuration['BE'] ?? null) : null;
        $debug = is_array($backendConfiguration) ? ($backendConfiguration['debug'] ?? false) : false;

        return Environment::getContext()->isDevelopment() || (bool)$debug;
    }
}
