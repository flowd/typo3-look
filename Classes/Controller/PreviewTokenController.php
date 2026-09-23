<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Controller;

use Flowd\Typo3Look\Backend\PageAccess;
use Flowd\Typo3Look\Preview\InvalidPreviewTokenException;
use Flowd\Typo3Look\Preview\PreviewDescriptor;
use Flowd\Typo3Look\Preview\PreviewTokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * AJAX route of the backend (authenticated, CSRF token checked by the core): exchanges a signed
 * preview descriptor for a short-lived preview token and returns the URL of the preview request.
 *
 * The descriptor is only accepted for the backend user it was issued to, and only while that user
 * may still see the record: same workspace, read access to the table and the page, access to the
 * language, and the record still on the page it was issued for. A descriptor kept from an earlier
 * page module view therefore stops working when the user's rights change or the record moves.
 */
#[AsController]
final readonly class PreviewTokenController
{
    public function __construct(
        private PreviewTokenService $tokenService,
        private UriBuilder $uriBuilder,
        private PageAccess $pageAccess,
    ) {}

    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $signedDescriptor = is_array($body) ? ($body['descriptor'] ?? null) : null;
        if (!is_string($signedDescriptor) || $signedDescriptor === '') {
            return $this->error('Missing preview descriptor', 400);
        }
        try {
            $descriptor = $this->tokenService->verifyDescriptor($signedDescriptor);
        } catch (InvalidPreviewTokenException $e) {
            return $this->error($e->expired ? 'The preview descriptor has expired, reload the page module' : 'Invalid preview descriptor', $e->expired ? 410 : 400);
        }
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !$this->mayPreview($backendUser, $descriptor)) {
            return $this->error('The preview is not available for this user', 403);
        }

        $url = (string)$this->uriBuilder->buildUriFromRoute('look_preview', ['previewToken' => $this->tokenService->issueToken($descriptor)]);

        return (new JsonResponse(['url' => $url]))->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The rules of the page module for showing the record, re-applied at the time of the exchange.
     */
    private function mayPreview(BackendUserAuthentication $backendUser, PreviewDescriptor $descriptor): bool
    {
        $userId = $backendUser->getUserId();
        if ($userId === null || $userId <= 0 || $userId !== $descriptor->backendUserId) {
            return false;
        }
        if ($descriptor->workspace !== $backendUser->workspace) {
            return false;
        }
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca) || !isset($tca[$descriptor->table]) || !$backendUser->check('tables_select', $descriptor->table)) {
            return false;
        }
        if (!$backendUser->checkLanguageAccess($descriptor->language)) {
            return false;
        }
        $row = BackendUtility::getRecord($descriptor->table, $descriptor->uid);
        if (!is_array($row)) {
            return false;
        }
        if ($descriptor->workspace > 0) {
            BackendUtility::workspaceOL($descriptor->table, $row, $descriptor->workspace);
            if (!is_array($row)) {
                return false;
            }
        }
        $pid = $row['pid'] ?? null;
        if (!is_numeric($pid) || (int)$pid !== $descriptor->pid) {
            return false;
        }

        return $this->pageAccess->read($descriptor->pid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
    }

    private function error(string $message, int $status): ResponseInterface
    {
        return (new JsonResponse(['error' => $message], $status))->withHeader('Cache-Control', 'no-store');
    }
}
