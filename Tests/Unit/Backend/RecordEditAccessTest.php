<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Tests\Unit\Backend;

use Flowd\Typo3Look\Backend\PageAccess;
use Flowd\Typo3Look\Backend\RecordEditAccess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Authentication\AccessCheckResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RecordEditAccessTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private PageAccess&MockObject $pageAccess;
    private BackendUserAuthentication&MockObject $backendUser;
    private RecordEditAccess $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pageAccess = $this->createMock(PageAccess::class);
        $this->backendUser = $this->createMock(BackendUserAuthentication::class);
        $this->backendUser->method('getPagePermsClause')->willReturn('perms-clause');
        $this->backendUser->method('check')->with('tables_modify', 'tt_content')->willReturn(true);
        $GLOBALS['BE_USER'] = $this->backendUser;
        $this->subject = new RecordEditAccess($this->pageAccess);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * TYPO3 14 answers with checkRecordEditAccess(), TYPO3 13 with recordEditAccessInternals().
     */
    private function recordEditAccessIs(bool $allowed): void
    {
        if (method_exists(BackendUserAuthentication::class, 'checkRecordEditAccess')) {
            $this->backendUser->method('checkRecordEditAccess')->with('tt_content', self::anything())->willReturn(new AccessCheckResult($allowed));
        } else {
            $this->backendUser->method('recordEditAccessInternals')->with('tt_content', self::anything())->willReturn($allowed);
        }
    }

    private function recordEditAccessIsNeverChecked(): void
    {
        $method = method_exists(BackendUserAuthentication::class, 'checkRecordEditAccess') ? 'checkRecordEditAccess' : 'recordEditAccessInternals';
        $this->backendUser->expects($this->never())->method($method);
    }

    #[Test]
    public function isNotEditableWithoutBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);
        $this->pageAccess->expects($this->never())->method('read');

        self::assertFalse($this->subject->isEditable('tt_content', ['uid' => 1, 'pid' => 2]));
    }

    #[Test]
    public function adminsMayAlwaysEdit(): void
    {
        $this->backendUser->method('isAdmin')->willReturn(true);
        $this->pageAccess->expects($this->never())->method('read');

        self::assertTrue($this->subject->isEditable('tt_content', ['uid' => 1, 'pid' => 2]));
    }

    #[Test]
    public function isNotEditableWithoutModifyAccessToTheTable(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('check')->with('tables_modify', 'tt_content')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;
        $this->pageAccess->expects($this->never())->method('read');

        self::assertFalse($this->subject->isEditable('tt_content', ['uid' => 1, 'pid' => 2]));
    }

    #[Test]
    public function isNotEditableWithoutContentEditPermissionOnThePage(): void
    {
        $this->pageAccess->method('read')->with(2, 'perms-clause')->willReturn(false);
        $this->recordEditAccessIsNeverChecked();

        self::assertFalse($this->subject->isEditable('tt_content', ['uid' => 1, 'pid' => 2]));
    }

    #[Test]
    public function isNotEditableOnAnEditLockedPage(): void
    {
        $this->pageAccess->method('read')->willReturn(['uid' => 2, 'editlock' => 1]);
        $this->recordEditAccessIsNeverChecked();

        self::assertFalse($this->subject->isEditable('tt_content', ['uid' => 1, 'pid' => 2]));
    }

    #[Test]
    public function isNotEditableWithoutEditAccessToTheRecord(): void
    {
        $this->pageAccess->method('read')->willReturn(['uid' => 2, 'editlock' => 0]);
        $this->recordEditAccessIs(false);

        self::assertFalse($this->subject->isEditable('tt_content', ['uid' => 1, 'pid' => 2]));
    }

    #[Test]
    public function isEditableWhenPageAndRecordChecksPass(): void
    {
        $row = ['uid' => 1, 'pid' => 2];
        $this->pageAccess->method('read')->willReturn(['uid' => 2, 'editlock' => 0]);
        $this->recordEditAccessIs(true);

        self::assertTrue($this->subject->isEditable('tt_content', $row));
    }

    #[Test]
    public function looksUpEachPageOnlyOnce(): void
    {
        $this->pageAccess->expects($this->once())->method('read')->with(2, 'perms-clause')->willReturn(['uid' => 2]);
        $this->recordEditAccessIs(true);

        self::assertTrue($this->subject->isEditable('tt_content', ['uid' => 1, 'pid' => 2]));
        self::assertTrue($this->subject->isEditable('tt_content', ['uid' => 3, 'pid' => 2]));
    }
}
