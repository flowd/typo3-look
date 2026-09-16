<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\ViewHelper\Backend;

use Flowd\Typo3Look\Asset\AssetCollectorIsolation;
use Flowd\Typo3Look\Asset\PreviewAssetMarkup;
use Flowd\Typo3Look\Backend\RecordEditAccess;
use Flowd\Typo3Look\Resource\PublicResourceUri;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\ViewHelpers\Link\EditRecordViewHelper;
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
 * Renders its children inside a scaled iframe (srcdoc) that loads the given site assets, so the
 * page module shows the real frontend markup of a content element.
 *
 * The iframe is sandboxed ("allow-scripts" only: opaque origin, no access to the backend document,
 * cookies or storage, no forms, popups or navigation) and takes no pointer events, so nothing
 * rendered from editor content can reach the backend session. Its height is reported to the backend
 * page via postMessage (iFramePreview.js -> ContentPreviewHost.js).
 *
 * The opaque origin has two consequences for the site assets: web fonts and JavaScript modules are
 * fetched in CORS mode and only load when the server sends "Access-Control-Allow-Origin", and
 * external <svg><use href> references do not work inside. Look's own script is therefore a classic
 * script, so the preview works without any web server configuration. The srcdoc document also
 * inherits the Content Security Policy of the backend, which only allows assets from its own host.
 *
 * Assets the preview content registers with f:asset.css / f:asset.script end up in the head of
 * the iframe (AssetCollectorIsolation), not in the backend page.
 *
 * Scripts inside the frame are limited by a Content Security Policy in the preview document to the
 * script tags the template emits (nonce of the backend request, 'strict-dynamic' for their module
 * imports); script tags in the preview content itself do not run.
 *
 * Feature flag "look.contentPreview.allowSiteScripts" (off by default): loads the "js" modules of
 * the site inside the frame. Without it only the look script runs (height, fade-out overlay).
 *
 * Feature flag "look.contentPreview.allowMedia" (off by default): loads video, audio and embedded
 * players (iframes) inside the frame. Off, "media-src 'none'; frame-src 'none'" in the preview
 * document keeps them from being loaded; video elements stay as placeholder boxes.
 *
 * Both flags live in $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']; the default is always the
 * most restrictive preview.
 *
 * Feature flag "look.contentPreview.editOverlay" (off by default): wraps the preview in a hover
 * overlay that opens the record for editing, but only for users who may edit it (RecordEditAccess,
 * same rules as the edit button in the element header). It behaves like that button: where
 * be:link.editRecord knows the "contextual" argument (TYPO3 14.3+) the contextual edit panel opens,
 * otherwise the classic edit form. The record is taken from the template variables: "data"
 * (Content Blocks, Record API object) or "record" (classic preview templates, tt_content row).
 *
 * "scale" and "height" fall back to the extension configuration (contentPreview.scale,
 * contentPreview.height) when the view helper is called without them.
 *
 *   <look:backend.contentPreview scale="0.6" bodyClass="application"
 *       css="{0: 'EXT:my_site/Resources/Public/Build/main.css'}"
 *       js="{0: 'EXT:my_site/Resources/Public/Build/main.js'}">
 *       ...frontend markup...
 *   </look:backend.contentPreview>
 */
class ContentPreviewViewHelper extends AbstractViewHelper implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const FEATURE_ALLOW_SITE_SCRIPTS = 'look.contentPreview.allowSiteScripts';
    public const FEATURE_ALLOW_MEDIA = 'look.contentPreview.allowMedia';
    public const FEATURE_EDIT_OVERLAY = 'look.contentPreview.editOverlay';

    protected $escapeOutput = false;

    /** whether be:link.editRecord supports the contextual edit panel; does not change within a request */
    private static ?bool $supportsContextualEditing = null;

    public function __construct(
        protected readonly PageRenderer $pageRenderer,
        protected readonly Features $features,
        protected readonly ExtensionConfiguration $extensionConfiguration,
        protected readonly AssetCollectorIsolation $assetCollectorIsolation,
        protected readonly PreviewAssetMarkup $previewAssetMarkup,
        protected readonly RecordEditAccess $recordEditAccess,
        protected readonly PublicResourceUri $publicResourceUri,
        protected readonly ViewFactoryInterface $viewFactory,
    ) {}

    public function initializeArguments(): void
    {
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
            $nonce = $request instanceof ServerRequestInterface ? $request->getAttribute('nonce') : null;
            if (!$nonce instanceof ConsumableNonce) {
                throw new \RuntimeException('The content preview needs the CSP nonce of the backend request', 1789500000);
            }

            $allowSiteScripts = $this->features->isFeatureEnabled(self::FEATURE_ALLOW_SITE_SCRIPTS);
            // scale must be positive; height 0 is a valid value (no limit) and must not fall back to the configured default
            $scale = $this->positiveNumber($this->arguments['scale'] ?? null) ?? $this->configuredDefault('scale', 0.5);
            $height = (int)($this->nonNegativeNumber($this->arguments['height'] ?? null) ?? $this->configuredDefault('height', 0));
            $bodyClass = is_string($this->arguments['bodyClass'] ?? null) ? $this->arguments['bodyClass'] : '';
            $css = $this->resolvedAssetUris($this->arguments['css'] ?? null);
            $js = $allowSiteScripts ? $this->resolvedAssetUris($this->arguments['js'] ?? null) : [];
            $nonceValue = $nonce->consumeStatic('look.contentPreview');
            $rendering = $this->assetCollectorIsolation->run(function (): string {
                $children = $this->renderChildren();
                return is_scalar($children) ? (string)$children : '';
            });

            $view = $this->createView($request);
            $view->assignMultiple([
                'scale' => $scale,
                'height' => $height,
                'bodyClass' => $bodyClass,
                'allowMedia' => $this->features->isFeatureEnabled(self::FEATURE_ALLOW_MEDIA),
                'nonce' => $nonceValue,
                'previewContent' => $rendering->content,
                'assets' => [
                    'css' => [$this->publicResourceUri->resolve('EXT:look/Resources/Public/Css/Backend/iFramePreview.css'), ...$css],
                    'lookScript' => $this->publicResourceUri->resolve('EXT:look/Resources/Public/Javascript/Backend/iFramePreview.js'),
                    'js' => $js,
                    'collectedStyles' => $this->previewAssetMarkup->styles($rendering->assets),
                    'collectedScripts' => $allowSiteScripts ? $this->previewAssetMarkup->scripts($rendering->assets, $nonceValue) : '',
                ],
            ]);

            $style = 'width:100%;';
            $style .= 'pointer-events: none;';
            if ($height > 0) {
                $style .= sprintf('max-height: %dpx;', $height);
            }

            $this->pageRenderer->loadJavaScriptModule('@flowd/look/Backend/ContentPreviewHost.js');

            $tagBuilder = new TagBuilder('iframe');
            $tagBuilder->addAttribute('class', 'look-content-preview');
            $tagBuilder->addAttribute('style', $style);
            $tagBuilder->addAttribute('srcdoc', trim($view->render('IFramePreview')));
            $tagBuilder->addAttribute('sandbox', 'allow-scripts');
            $tagBuilder->addAttribute('referrerpolicy', 'no-referrer');
            $tagBuilder->addAttribute('title', 'Content preview');
            $tagBuilder->addAttribute('loading', 'lazy');
            $tagBuilder->forceClosingTag(true);

            return $this->wrapWithEditOverlay($tagBuilder->render(), $request);
        } catch (\Throwable $e) {
            return $this->renderError($e);
        }
    }

    private function wrapWithEditOverlay(string $iframe, ServerRequestInterface $request): string
    {
        if (!$this->features->isFeatureEnabled(self::FEATURE_EDIT_OVERLAY)) {
            return $iframe;
        }
        [$table, $row] = $this->recordFromTemplateVariables();
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
     * The record the preview is rendered for: "data" (Content Blocks) or "record" (classic preview templates).
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
    private function resolvedAssetUris(mixed $paths): array
    {
        $uris = [];
        foreach (is_array($paths) ? $paths : [] as $path) {
            if (is_string($path) && $path !== '') {
                $uris[] = $this->publicResourceUri->resolve($path);
            }
        }

        return $uris;
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
