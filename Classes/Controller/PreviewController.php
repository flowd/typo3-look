<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Controller;

use Flowd\Typo3Look\Asset\AssetCollectorIsolation;
use Flowd\Typo3Look\Preview\InvalidPreviewTokenException;
use Flowd\Typo3Look\Preview\PreviewDescriptor;
use Flowd\Typo3Look\Preview\PreviewDocument;
use Flowd\Typo3Look\Preview\PreviewTokenService;
use Flowd\Typo3Look\Rendering\ContentElementRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Resource\PublicUrlPrefixer;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Domain\RecordFactory;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\Event\GeneratePublicUrlForResourceEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * The isolated preview request: renders one content element with the frontend TypoScript of its
 * page and answers with the preview frame document. Reached through the public backend route
 * "look_preview" with a short-lived token (see PreviewTokenService), without a backend session:
 * the request is answered before the backend authentication and the session is never evaluated,
 * because the sandboxed frame has an opaque origin and must work without one.
 *
 * Running the site's PHP in its own request is the point: whatever a template, data processor or
 * plugin does (exceptions, header() calls, global state, timeouts) stays in this request and
 * never reaches the page module. There is no backend user here; workspace and language come
 * from the token.
 */
#[AsController]
final class PreviewController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly PreviewTokenService $tokenService,
        private readonly ContentElementRenderer $renderer,
        private readonly RecordFactory $recordFactory,
        private readonly AssetCollectorIsolation $assetCollectorIsolation,
        private readonly PreviewDocument $document,
        private readonly Context $context,
        private readonly ListenerProvider $listenerProvider,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function render(ServerRequestInterface $request): ResponseInterface
    {
        // what the backend RequestHandler does for routed requests and this request skips by design: absolute public URLs for FAL resources
        $this->listenerProvider->addListener(GeneratePublicUrlForResourceEvent::class, PublicUrlPrefixer::class, 'prefixWithSitePath');

        $nonce = new ConsumableNonce();
        $nonceValue = $nonce->consumeStatic('look.contentPreview');
        $request = $request->withAttribute('nonce', $nonce);
        $token = $request->getQueryParams()['previewToken'] ?? null;
        try {
            $descriptor = $this->tokenService->verifyToken(is_string($token) ? $token : '');
        } catch (InvalidPreviewTokenException $e) {
            return $this->errorResponse($request, $nonceValue, $e->expired ? 'The preview token has expired' : 'Invalid preview token', $e->expired, $e->expired ? 410 : 403);
        }
        // From here on, core code that reads the global request (storage permissions, image processing, URL
        // prefixes, view helper guards) sees a frontend request, like on the website; there is no backend
        // user in this request and nothing may assume one.
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);

        try {
            $tca = $GLOBALS['TCA'] ?? null;
            if (!is_array($tca) || !isset($tca[$descriptor->table])) {
                throw new \RuntimeException('Unknown table ' . $descriptor->table, 1790100023);
            }
            $this->context->setAspect('workspace', new WorkspaceAspect($descriptor->workspace));
            $this->context->setAspect('language', new LanguageAspect($descriptor->language, $descriptor->language));
            $row = $this->row($descriptor);
            $record = $this->recordFactory->createResolvedRecordFromDatabaseRow($descriptor->table, $row, $this->context);
            $frontendRequest = $this->renderer->frontendRequest($record, $request);
            $GLOBALS['TYPO3_REQUEST'] = $frontendRequest;
            $language = $frontendRequest->getAttribute('language');
            if ($language instanceof SiteLanguage) {
                $GLOBALS['LANG'] = $this->languageServiceFactory->createFromSiteLanguage($language);
            }
            $rendering = $this->assetCollectorIsolation->run(
                fn(): string => $this->renderer->render($record, $frontendRequest, $descriptor->typoScriptObjectPath),
            );
            $html = $this->document->render(
                $request,
                $nonceValue,
                $rendering,
                $descriptor->scale,
                $descriptor->height,
                $descriptor->bodyClass,
                $descriptor->css,
                $descriptor->js,
            );

            return $this->response($html, $nonceValue, 200);
        } catch (\Throwable $e) {
            $this->logger?->error('Content preview could not be rendered: ' . $e->getMessage(), ['exception' => $e]);
            $message = $this->showErrorDetails()
                ? $e->getMessage() . ' (' . $e->getCode() . ')'
                : 'See the TYPO3 log for details (error ' . $e->getCode() . ').';

            return $this->errorResponse($request, $nonceValue, $message, false, 200);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PreviewDescriptor $descriptor): array
    {
        $row = BackendUtility::getRecord($descriptor->table, $descriptor->uid);
        if (!is_array($row)) {
            throw new \RuntimeException('Record ' . $descriptor->table . ':' . $descriptor->uid . ' does not exist', 1790100021);
        }
        if ($descriptor->workspace > 0) {
            BackendUtility::workspaceOL($descriptor->table, $row, $descriptor->workspace);
            if (!is_array($row)) {
                throw new \RuntimeException('Record ' . $descriptor->table . ':' . $descriptor->uid . ' is not available in workspace ' . $descriptor->workspace, 1790100022);
            }
        }
        $pid = $row['pid'] ?? null;
        if (!is_numeric($pid) || (int)$pid !== $descriptor->pid) {
            // moved since the token was issued: the page module would show it elsewhere, with other TypoScript
            throw new \RuntimeException('Record ' . $descriptor->table . ':' . $descriptor->uid . ' is no longer on page ' . $descriptor->pid, 1790100024);
        }
        $result = [];
        foreach ($row as $field => $value) {
            $result[(string)$field] = $value;
        }

        return $result;
    }

    private function errorResponse(ServerRequestInterface $request, string $nonce, string $message, bool $expired, int $status): ResponseInterface
    {
        return $this->response($this->document->renderError($request, $nonce, $message, $expired), $nonce, $status);
    }

    /**
     * Own security headers plus the headers configured for backend responses ($TYPO3_CONF_VARS[BE][HTTP]
     * [Response][Headers], e.g. Strict-Transport-Security), which the core's middleware would add to a
     * routed response and this request skips.
     */
    private function response(string $html, string $nonce, int $status): ResponseInterface
    {
        $response = (new HtmlResponse($html, $status))
            ->withHeader('Content-Security-Policy', $this->document->contentSecurityPolicy($nonce) . " frame-ancestors 'self';")
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        $configured = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        foreach (['BE', 'HTTP', 'Response', 'Headers'] as $key) {
            $configured = is_array($configured) ? ($configured[$key] ?? null) : null;
        }
        foreach (is_array($configured) ? $configured : [] as $header) {
            if (!is_string($header) || !str_contains($header, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $header, 2);
            if (!$response->hasHeader($name)) {
                $response = $response->withAddedHeader($name, trim($value));
            }
        }

        return $response;
    }

    private function showErrorDetails(): bool
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $backendConfiguration = is_array($configuration) ? ($configuration['BE'] ?? null) : null;
        $debug = is_array($backendConfiguration) ? ($backendConfiguration['debug'] ?? false) : false;

        return Environment::getContext()->isDevelopment() || (bool)$debug;
    }
}
