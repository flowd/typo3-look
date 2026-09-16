<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Backend;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Whether the current backend user may edit a content record, with the rules of the edit button in
 * the page module header: admins always; everybody else needs modify access to the table, content
 * edit permission on the page (readPageAccess with the user's permission clause, also covers web
 * mounts), a page without edit lock, and edit access to the record itself.
 *
 * The record check uses BackendUserAuthentication::checkRecordEditAccess() (TYPO3 14) respectively
 * recordEditAccessInternals() (TYPO3 13). Both are marked @internal by the core; they are the only
 * way to apply the table, language and record edit lock rules the header button applies, and the
 * unit tests pin their signatures.
 *
 * Shared service (DI default, declared explicitly): one instance per request, so the page lookup runs
 * once for all previews of a page.
 */
#[Autoconfigure(shared: true)]
final class RecordEditAccess
{
    /** @var array<int, array<string, mixed>|false> */
    private array $pages = [];

    public function __construct(private readonly PageAccess $pageAccess) {}

    /**
     * @param array<string, mixed> $row raw database row of the record
     */
    public function isEditable(string $table, array $row): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        if (!$backendUser->check('tables_modify', $table)) {
            return false;
        }
        $pid = is_numeric($row['pid'] ?? null) ? (int)$row['pid'] : 0;
        $page = $this->pages[$pid] ??= $this->pageAccess->read($pid, $backendUser->getPagePermsClause(Permission::CONTENT_EDIT));
        if ($page === false) {
            return false;
        }
        $editLock = $page['editlock'] ?? 0;
        if (is_numeric($editLock) && (int)$editLock !== 0) {
            return false;
        }

        return $this->recordEditAccess($backendUser, $table, $row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function recordEditAccess(BackendUserAuthentication $backendUser, string $table, array $row): bool
    {
        if (method_exists($backendUser, 'checkRecordEditAccess')) {
            return $backendUser->checkRecordEditAccess($table, $row)->isAllowed;
        }

        // TYPO3 13
        return $backendUser->recordEditAccessInternals($table, $row);
    }
}
