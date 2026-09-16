<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Backend;

use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Thin wrapper around BackendUtility::readPageAccess(), so the permission check can be unit tested.
 */
class PageAccess
{
    /**
     * @return array<string, mixed>|false the page record if the current user has the given permission on it
     */
    public function read(int $pageId, string $permissionClause): array|false
    {
        $page = BackendUtility::readPageAccess($pageId, $permissionClause);
        if ($page === false) {
            return false;
        }
        $record = [];
        foreach ($page as $field => $value) {
            $record[(string)$field] = $value;
        }

        return $record;
    }
}
