<?php

declare(strict_types=1);

namespace B13\AiLabel\Service;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

final class AiLabelAccessChecker
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly Typo3Version $typo3Version,
    ) {
    }

    /** @param array<string, mixed> $row */
    public function isReadable(string $table, array $row): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null || $backendUser->isAdmin()) {
            return true;
        }
        if ($table === 'sys_file_metadata') {
            return $this->fileActionAllowed($row, 'read');
        }

        return $this->pageReadAccessAllowed($table, $row);
    }

    /** @param array<string, mixed> $row */
    public function isEditable(string $table, array $row): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null || $backendUser->isAdmin()) {
            return true;
        }
        if ($table === 'sys_file_metadata') {
            return $this->fileActionAllowed($row, 'editMeta');
        }

        return $this->pageEditAccessAllowed($table, $row);
    }

    /** @param array<string, mixed> $row */
    private function pageReadAccessAllowed(string $table, array $row): bool
    {
        $pageRow = $this->resolvePageRow($table, $row);
        if ($pageRow === null) {
            return false;
        }

        $permission = new Permission($this->getBackendUser()->calcPerms($pageRow));
        return $permission->showPagePermissionIsGranted();
    }

    /** @param array<string, mixed> $row */
    private function pageEditAccessAllowed(string $table, array $row): bool
    {
        $pageRow = $this->resolvePageRow($table, $row);
        if ($pageRow === null) {
            return false;
        }

        $permission = new Permission($this->getBackendUser()->calcPerms($pageRow));
        if ($table === 'pages') {
            return $permission->editPagePermissionIsGranted();
        }

        return $permission->editContentPermissionIsGranted() && $this->recordEditAccessAllowed($table, $row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function resolvePageRow(string $table, array $row): ?array
    {
        if ($table === 'pages') {
            return $row;
        }

        $pid = (int)($row['pid'] ?? 0);
        if ($pid <= 0) {
            return null;
        }

        $pageRow = BackendUtility::getRecord('pages', $pid);
        return is_array($pageRow) ? $pageRow : null;
    }

    /** @param array<string, mixed> $row */
    private function recordEditAccessAllowed(string $table, array $row): bool
    {
        $backendUser = $this->getBackendUser();
        if ($this->typo3Version->getMajorVersion() >= 14) {
            return $backendUser->checkRecordEditAccess($table, $row)->isAllowed;
        }

        return $backendUser->recordEditAccessInternals($table, $row);
    }

    /** @param array<string, mixed> $row */
    private function fileActionAllowed(array $row, string $action): bool
    {
        $fileUid = (int)($row['file'] ?? 0);
        if ($fileUid <= 0) {
            return false;
        }

        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (FileDoesNotExistException) {
            return false;
        }

        return $file->checkActionPermission($action);
    }

    protected function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
