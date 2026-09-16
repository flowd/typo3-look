<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Tests\Unit\ViewHelper\Backend;

use Flowd\Typo3Look\Asset\AssetCollectorIsolation;
use Flowd\Typo3Look\Asset\PreviewAssetMarkup;
use Flowd\Typo3Look\Backend\PageAccess;
use Flowd\Typo3Look\Backend\RecordEditAccess;
use Flowd\Typo3Look\Resource\PublicResourceUri;
use Flowd\Typo3Look\ViewHelper\Backend\ContentPreviewViewHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\AccessCheckResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\Features;
use TYPO3\CMS\Core\Domain\RawRecord;
use TYPO3\CMS\Core\Domain\Record\ComputedProperties;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\Variables\StandardVariableProvider;

final class ContentPreviewViewHelperTest extends UnitTestCase
{
    private const NONCE = 'n0nce-n0nce-n0nce-n0nce-n0nce-n0nce-n0nce-n0nce-n0nce-n0nce';

    private Features&MockObject $features;
    private ExtensionConfiguration&MockObject $extensionConfiguration;
    private AssetCollector $assetCollector;
    private PageAccess&MockObject $pageAccess;
    private BackendUserAuthentication&MockObject $backendUser;
    private ViewInterface&MockObject $view;
    private PageRenderer&MockObject $pageRenderer;
    private StandardVariableProvider $variables;
    private ServerRequestInterface&MockObject $request;
    private ContentPreviewViewHelper $subject;

    /** @var array<string, mixed> */
    private array $assignedVariables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->features = $this->createMock(Features::class);
        $this->extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $this->assetCollector = new AssetCollector();
        $this->pageAccess = $this->createMock(PageAccess::class);
        $this->backendUser = $this->createMock(BackendUserAuthentication::class);
        $this->backendUser->method('getPagePermsClause')->willReturn('perms-clause');
        $this->backendUser->method('check')->willReturn(true);
        $GLOBALS['BE_USER'] = $this->backendUser;
        $this->pageRenderer = $this->createMock(PageRenderer::class);
        $this->view = $this->createMock(ViewInterface::class);
        $this->view->method('assignMultiple')->willReturnCallback(function (array $values): ViewInterface {
            foreach ($values as $name => $value) {
                $this->assignedVariables[(string)$name] = $value;
            }
            return $this->view;
        });
        $this->view->method('render')->willReturnCallback(static fn(string $template): string => '<!-- ' . $template . ' -->');
        $viewFactory = self::createStub(ViewFactoryInterface::class);
        $viewFactory->method('create')->willReturn($this->view);

        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->variables = new StandardVariableProvider();
        $renderingContext = self::createStub(RenderingContextInterface::class);
        $renderingContext->method('getAttribute')->willReturn($this->request);
        $renderingContext->method('getVariableProvider')->willReturn($this->variables);

        $this->subject = $this->createSubject($viewFactory);
        $this->subject->setRenderingContext($renderingContext);
        $this->subject->setRenderChildrenClosure(static fn(): string => '<p>content</p>');
        $this->subject->initializeArguments();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function createSubject(ViewFactoryInterface $viewFactory, bool $showErrorDetails = true): ContentPreviewViewHelper
    {
        $publicResourceUri = self::createStub(PublicResourceUri::class);
        $publicResourceUri->method('resolve')->willReturnCallback(
            static fn(string $source): string => str_starts_with($source, 'EXT:') ? '/_assets/abc/' . basename($source) : $source,
        );
        // supportsContextualEditing() instantiates a core view helper through the container, showErrorDetails() reads the environment
        return new class (
            $this->pageRenderer,
            $this->features,
            $this->extensionConfiguration,
            new AssetCollectorIsolation($this->assetCollector),
            new PreviewAssetMarkup($publicResourceUri),
            new RecordEditAccess($this->pageAccess),
            $publicResourceUri,
            $viewFactory,
            $showErrorDetails,
        ) extends ContentPreviewViewHelper {
            public function __construct(
                PageRenderer $pageRenderer,
                Features $features,
                ExtensionConfiguration $extensionConfiguration,
                AssetCollectorIsolation $assetCollectorIsolation,
                PreviewAssetMarkup $previewAssetMarkup,
                RecordEditAccess $recordEditAccess,
                PublicResourceUri $publicResourceUri,
                ViewFactoryInterface $viewFactory,
                private readonly bool $errorDetails,
            ) {
                parent::__construct($pageRenderer, $features, $extensionConfiguration, $assetCollectorIsolation, $previewAssetMarkup, $recordEditAccess, $publicResourceUri, $viewFactory);
            }

            protected function supportsContextualEditing(): bool
            {
                return true;
            }

            protected function showErrorDetails(): bool
            {
                return $this->errorDetails;
            }
        };
    }

    /**
     * @return array<mixed>
     */
    private function assignedAssets(): array
    {
        self::assertIsArray($this->assignedVariables['assets']);

        return $this->assignedVariables['assets'];
    }

    private function withNonce(): void
    {
        $this->request->method('getAttribute')->willReturnMap([
            ['nonce', null, new ConsumableNonce(self::NONCE)],
            ['normalizedParams', null, null],
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function render(array $arguments = []): string
    {
        $this->subject->setArguments($arguments + ['scale' => null, 'height' => null, 'bodyClass' => '', 'css' => [], 'js' => []]);
        return (string)$this->subject->render();
    }

    #[Test]
    public function rendersAnErrorCalloutWithoutNonce(): void
    {
        $subject = $this->createSubject(self::createStub(ViewFactoryInterface::class));
        $renderingContext = self::createStub(RenderingContextInterface::class);
        $renderingContext->method('getAttribute')->willReturn($this->request);
        $subject->setRenderingContext($renderingContext);
        $subject->initializeArguments();
        $subject->setArguments(['scale' => null, 'height' => null, 'bodyClass' => '', 'css' => [], 'js' => []]);

        $html = (string)$subject->render();

        self::assertStringContainsString('callout-danger', $html);
        self::assertStringContainsString('needs the CSP nonce', $html);
        self::assertStringContainsString('1789500000', $html);
    }

    #[Test]
    public function hidesErrorDetailsOutsideDevelopment(): void
    {
        $subject = $this->createSubject(self::createStub(ViewFactoryInterface::class), showErrorDetails: false);
        $renderingContext = self::createStub(RenderingContextInterface::class);
        $renderingContext->method('getAttribute')->willReturn($this->request);
        $subject->setRenderingContext($renderingContext);
        $subject->initializeArguments();
        $subject->setArguments(['scale' => null, 'height' => null, 'bodyClass' => '', 'css' => [], 'js' => []]);

        $html = (string)$subject->render();

        self::assertStringContainsString('callout-danger', $html);
        self::assertStringNotContainsString('CSP nonce', $html);
        self::assertStringContainsString('1789500000', $html);
    }

    #[Test]
    public function rendersAnErrorCalloutWhenTheContentThrowsAnError(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturn(false);
        $this->subject->setRenderChildrenClosure(static function (): string {
            throw new \TypeError('broken view helper');
        });

        $html = $this->render();

        self::assertStringContainsString('callout-danger', $html);
        self::assertStringContainsString('broken view helper', $html);
    }

    #[Test]
    public function rendersASandboxedIframeWithTheDocumentAsSrcdoc(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturn(false);

        $html = $this->render();

        self::assertStringStartsWith('<iframe ', $html);
        self::assertStringContainsString('class="look-content-preview"', $html);
        self::assertStringContainsString('sandbox="allow-scripts"', $html);
        self::assertStringContainsString('referrerpolicy="no-referrer"', $html);
        self::assertStringContainsString('pointer-events: none;', $html);
        self::assertStringContainsString('srcdoc="&lt;!-- IFramePreview --&gt;"', $html);
        self::assertStringNotContainsString('max-height', $html);
        self::assertStringNotContainsString('look-content-preview-wrap', $html);
    }

    #[Test]
    public function passesRenderedContentNonceAndAssetsToTheDocument(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturnMap([
            [ContentPreviewViewHelper::FEATURE_ALLOW_SITE_SCRIPTS, true],
            [ContentPreviewViewHelper::FEATURE_ALLOW_MEDIA, true],
            [ContentPreviewViewHelper::FEATURE_EDIT_OVERLAY, false],
        ]);
        $this->subject->setRenderChildrenClosure(function (): string {
            $this->assetCollector->addStyleSheet('collected', 'EXT:site/collected.css');
            $this->assetCollector->addInlineJavaScript('collected', 'collected();');
            return '<p>content</p>';
        });

        $this->render(['scale' => 0.7, 'height' => 300, 'bodyClass' => 'site', 'css' => ['EXT:site/main.css'], 'js' => ['EXT:site/main.js']]);
        $assets = $this->assignedAssets();

        self::assertSame('<p>content</p>', $this->assignedVariables['previewContent']);
        self::assertSame(self::NONCE, $this->assignedVariables['nonce']);
        self::assertSame(0.7, $this->assignedVariables['scale']);
        self::assertSame(300, $this->assignedVariables['height']);
        self::assertSame('site', $this->assignedVariables['bodyClass']);
        self::assertTrue($this->assignedVariables['allowMedia']);
        self::assertSame(['/_assets/abc/iFramePreview.css', '/_assets/abc/main.css'], $assets['css']);
        self::assertSame('/_assets/abc/iFramePreview.js', $assets['lookScript']);
        self::assertSame(['/_assets/abc/main.js'], $assets['js']);
        self::assertSame('<link rel="stylesheet" href="/_assets/abc/collected.css" />', $assets['collectedStyles']);
        self::assertSame('<script nonce="' . self::NONCE . '">collected();</script>', $assets['collectedScripts']);
        self::assertSame([], $this->assetCollector->getStyleSheets(), 'collected assets must not stay in the backend collector');
    }

    #[Test]
    public function limitsTheHeightOfTheIframe(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturn(false);

        self::assertStringContainsString('max-height: 300px;', $this->render(['height' => 300]));
    }

    #[Test]
    public function siteScriptsAreLeftOutWithoutTheFeatureFlag(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturn(false);
        $this->subject->setRenderChildrenClosure(function (): string {
            $this->assetCollector->addInlineJavaScript('collected', 'collected();');
            return '';
        });

        $this->render(['js' => ['EXT:site/main.js']]);
        $assets = $this->assignedAssets();

        self::assertSame([], $assets['js'], 'no site scripts');
        self::assertSame('/_assets/abc/iFramePreview.js', $assets['lookScript'], 'the look script always runs');
        self::assertSame('', $assets['collectedScripts']);
    }

    #[Test]
    public function scaleAndHeightFallBackToTheExtensionConfiguration(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturn(false);
        $this->extensionConfiguration->method('get')->willReturnMap([
            ['look', 'contentPreview/scale', '0.6'],
            ['look', 'contentPreview/height', '250'],
        ]);

        $html = $this->render();

        self::assertSame(0.6, $this->assignedVariables['scale']);
        self::assertSame(250, $this->assignedVariables['height']);
        self::assertStringContainsString('max-height: 250px;', $html);
    }

    #[Test]
    public function urlsInTheAssetArgumentsPassThroughUnchanged(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturn(false);

        $this->render(['css' => ['https://cdn.example.org/site.css', '/site/local.css']]);

        self::assertSame(['/_assets/abc/iFramePreview.css', 'https://cdn.example.org/site.css', '/site/local.css'], $this->assignedAssets()['css']);
    }

    #[Test]
    public function invalidScaleFallsBackToTheDefault(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturn(false);
        $this->extensionConfiguration->method('get')->willThrowException(new \RuntimeException('not configured', 1789600002));

        $this->render(['scale' => 0, 'height' => -10]);

        self::assertSame(0.5, $this->assignedVariables['scale']);
        self::assertSame(0, $this->assignedVariables['height']);
    }

    #[Test]
    public function heightZeroDisablesTheConfiguredLimit(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturn(false);
        $this->extensionConfiguration->method('get')->willReturnMap([
            ['look', 'contentPreview/scale', '0.6'],
            ['look', 'contentPreview/height', '400'],
        ]);

        $html = $this->render(['height' => 0]);

        self::assertSame(0, $this->assignedVariables['height']);
        self::assertStringNotContainsString('max-height', $html);
    }

    #[Test]
    public function negativeHeightFallsBackToTheConfiguredLimit(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturn(false);
        $this->extensionConfiguration->method('get')->willReturnMap([
            ['look', 'contentPreview/scale', '0.6'],
            ['look', 'contentPreview/height', '400'],
        ]);

        $this->render(['height' => -10]);

        self::assertSame(400, $this->assignedVariables['height']);
    }

    #[Test]
    public function scaleAndHeightUseBuiltInDefaultsWithoutExtensionConfiguration(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturn(false);
        $this->extensionConfiguration->method('get')->willThrowException(new \RuntimeException('not configured', 1789600001));

        $this->render();

        self::assertSame(0.5, $this->assignedVariables['scale']);
        self::assertSame(0, $this->assignedVariables['height']);
    }

    #[Test]
    public function wrapsTheIframeInTheEditOverlayForEditableRecords(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturnCallback(static fn(string $flag): bool => $flag === ContentPreviewViewHelper::FEATURE_EDIT_OVERLAY);
        $this->variables->add('record', ['uid' => 42, 'pid' => 7]);
        $this->pageAccess->method('read')->with(7, self::anything())->willReturn(['uid' => 7]);
        if (method_exists(BackendUserAuthentication::class, 'checkRecordEditAccess')) {
            $this->backendUser->method('checkRecordEditAccess')->with('tt_content', ['uid' => 42, 'pid' => 7])->willReturn(new AccessCheckResult(true));
        } else {
            $this->backendUser->method('recordEditAccessInternals')->with('tt_content', ['uid' => 42, 'pid' => 7])->willReturn(true);
        }
        $this->pageRenderer->expects($this->once())->method('addCssFile')->with('EXT:look/Resources/Public/Css/Backend/ContentPreview.css');

        $html = $this->render();

        self::assertSame('<!-- EditOverlay -->', $html);
        self::assertSame('tt_content', $this->assignedVariables['table']);
        self::assertSame(42, $this->assignedVariables['uid']);
        self::assertSame('#element-tt_content-42', $this->assignedVariables['returnUrl']);
        self::assertTrue($this->assignedVariables['contextual']);
        self::assertIsString($this->assignedVariables['iframe']);
        self::assertStringStartsWith('<iframe ', $this->assignedVariables['iframe']);
    }

    /**
     * Content Blocks previews pass the record as the "data" object, not as a "record" array.
     * RawRecord implements RecordInterface and returns itself from getRawRecord(), so it is the
     * production object rather than a double; toArray() puts uid and pid in front of the properties.
     */
    #[Test]
    public function wrapsTheIframeInTheEditOverlayForAnEditableRecordObject(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturnCallback(static fn(string $flag): bool => $flag === ContentPreviewViewHelper::FEATURE_EDIT_OVERLAY);
        $record = new RawRecord(42, 7, ['header' => 'Teaser'], new ComputedProperties(), 'tt_content');
        $this->variables->add('data', $record);
        $this->pageAccess->method('read')->with(7, self::anything())->willReturn(['uid' => 7]);
        $row = ['uid' => 42, 'pid' => 7, 'header' => 'Teaser'];
        if (method_exists(BackendUserAuthentication::class, 'checkRecordEditAccess')) {
            $this->backendUser->method('checkRecordEditAccess')->with('tt_content', $row)->willReturn(new AccessCheckResult(true));
        } else {
            $this->backendUser->method('recordEditAccessInternals')->with('tt_content', $row)->willReturn(true);
        }

        $html = $this->render();

        self::assertSame('<!-- EditOverlay -->', $html);
        self::assertSame('tt_content', $this->assignedVariables['table']);
        self::assertSame(42, $this->assignedVariables['uid']);
    }

    #[Test]
    public function rendersNoEditOverlayWhenTheUserMayNotEditTheRecord(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturnCallback(static fn(string $flag): bool => $flag === ContentPreviewViewHelper::FEATURE_EDIT_OVERLAY);
        $this->variables->add('record', ['uid' => 42, 'pid' => 7]);
        $this->pageAccess->method('read')->willReturn(false);
        $this->pageRenderer->expects($this->never())->method('addCssFile');

        self::assertStringStartsWith('<iframe ', $this->render());
    }

    #[Test]
    public function rendersNoEditOverlayWithoutARecordInTheTemplateVariables(): void
    {
        $this->withNonce();
        $this->features->method('isFeatureEnabled')->willReturn(true);
        $this->pageAccess->expects($this->never())->method('read');

        self::assertStringStartsWith('<iframe ', $this->render());
    }
}
