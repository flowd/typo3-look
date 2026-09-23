<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Preview;

use Flowd\Typo3Look\Asset\IsolatedRendering;
use Flowd\Typo3Look\Asset\PreviewAssetMarkup;
use Flowd\Typo3Look\Resource\PublicResourceUri;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\Features;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * The HTML document shown inside the preview frame: the rendered content element, the site's
 * stylesheets and (with the feature flag) scripts, Look's own frame script, and the Content
 * Security Policy that limits scripts to the ones this document emits. Used for the srcdoc of
 * in-process previews and as the response body of the isolated preview request.
 */
final readonly class PreviewDocument
{
    public const FEATURE_ALLOW_SITE_SCRIPTS = 'look.contentPreview.allowSiteScripts';
    public const FEATURE_ALLOW_MEDIA = 'look.contentPreview.allowMedia';

    private const TEMPLATE_ROOT_PATHS = ['EXT:look/Resources/Private/Templates/Backend/ContentPreview/'];

    public function __construct(
        private Features $features,
        private PublicResourceUri $publicResourceUri,
        private PreviewAssetMarkup $previewAssetMarkup,
        private ViewFactoryInterface $viewFactory,
    ) {}

    /**
     * @param list<string> $css stylesheets as EXT: paths or URLs
     * @param list<string> $js script modules as EXT: paths or URLs, loaded only with the allowSiteScripts flag
     */
    public function render(
        ServerRequestInterface $request,
        string $nonce,
        IsolatedRendering $rendering,
        float $scale,
        int $height,
        string $bodyClass,
        array $css,
        array $js,
    ): string {
        $allowSiteScripts = $this->features->isFeatureEnabled(self::FEATURE_ALLOW_SITE_SCRIPTS);
        $view = $this->viewFactory->create(new ViewFactoryData(templateRootPaths: self::TEMPLATE_ROOT_PATHS, request: $request));
        $view->assignMultiple([
            'scale' => $scale,
            'height' => $height,
            'bodyClass' => $bodyClass,
            'allowMedia' => $this->features->isFeatureEnabled(self::FEATURE_ALLOW_MEDIA),
            'nonce' => $nonce,
            'csp' => $this->contentSecurityPolicy($nonce),
            'previewContent' => $rendering->content,
            'assets' => [
                'css' => [$this->publicResourceUri->resolve('EXT:look/Resources/Public/Css/Backend/iFramePreview.css'), ...$this->resolve($css)],
                'lookScript' => $this->publicResourceUri->resolve('EXT:look/Resources/Public/Javascript/Backend/iFramePreview.js'),
                'js' => $allowSiteScripts ? $this->resolve($js) : [],
                'collectedStyles' => $this->previewAssetMarkup->styles($rendering->assets),
                'collectedScripts' => $allowSiteScripts ? $this->previewAssetMarkup->scripts($rendering->assets, $nonce) : '',
            ],
        ]);

        return trim($view->render('IFramePreview'));
    }

    /**
     * Document shown when the preview request cannot render: the message (details only where the
     * caller decided so) and, for an expired token, the signal that lets the page module fetch a
     * fresh token and reload the frame.
     */
    public function renderError(ServerRequestInterface $request, string $nonce, string $message, bool $expired): string
    {
        $view = $this->viewFactory->create(new ViewFactoryData(templateRootPaths: self::TEMPLATE_ROOT_PATHS, request: $request));
        $view->assignMultiple([
            'nonce' => $nonce,
            'csp' => $this->contentSecurityPolicy($nonce),
            'message' => $message,
            'expired' => $expired,
            'lookScript' => $this->publicResourceUri->resolve('EXT:look/Resources/Public/Javascript/Backend/iFramePreview.js'),
            'css' => $this->publicResourceUri->resolve('EXT:look/Resources/Public/Css/Backend/iFramePreview.css'),
        ]);

        return trim($view->render('PreviewError'));
    }

    /**
     * The complete policy of the frame document. Assets only from the backend's own host (plus data:
     * images and fonts, as inlined by build tools), scripts only with this document's nonce
     * ('strict-dynamic' covers their module imports), no plugins, no <base>, no form targets, no
     * connections to other hosts; media and embedded frames (from any https host, that is where
     * players live) only with the allowMedia flag.
     *
     * A document loaded from the preview request inherits nothing from the backend page, unlike a
     * srcdoc document, so every directive is spelled out here. Sent as HTTP header by the preview
     * request and repeated as <meta> in the document.
     */
    public function contentSecurityPolicy(string $nonce): string
    {
        $directives = [
            "default-src 'self'",
            "script-src 'nonce-" . $nonce . "' 'strict-dynamic'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "base-uri 'none'",
            "object-src 'none'",
            "form-action 'none'",
        ];
        if ($this->features->isFeatureEnabled(self::FEATURE_ALLOW_MEDIA)) {
            // videos and embedded players come from other hosts by nature (YouTube, Vimeo, CDNs); the flag is the opt-in
            $directives[] = "media-src 'self' data: blob: https:";
            $directives[] = 'frame-src https:';
        } else {
            $directives[] = "media-src 'none'";
            $directives[] = "frame-src 'none'";
        }

        return implode('; ', $directives) . ';';
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function resolve(array $paths): array
    {
        $uris = [];
        foreach ($paths as $path) {
            if ($path !== '') {
                $uris[] = $this->publicResourceUri->resolve($path);
            }
        }

        return $uris;
    }
}
