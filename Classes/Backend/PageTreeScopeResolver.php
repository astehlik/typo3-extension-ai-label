<?php

declare(strict_types=1);

namespace B13\AiLabel\Backend;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use TYPO3\CMS\Backend\Tree\Repository\PageTreeRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the page selected in the overview module's page-tree navigation
 * component into the page-id scope AiMetadataRecordFinder needs: that page
 * plus all of its subpages, recursively.
 */
final class PageTreeScopeResolver
{
    private const MAX_RECURSION_DEPTH = 99;

    /**
     * @return list<int>|null
     */
    public function resolve(int $pageId, BackendUserAuthentication $backendUser): ?array
    {
        if ($pageId <= 0) {
            return null;
        }
        $pageTreeRepository = GeneralUtility::makeInstance(
            PageTreeRepository::class,
            $backendUser->workspace
        );
        $pageTreeRepository->setAdditionalWhereClause($backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        $pageIds = [$pageId];
        foreach ($pageTreeRepository->getFlattenedPages($pageIds, self::MAX_RECURSION_DEPTH) as $page) {
            $pageIds[] = (int)$page['uid'];
        }

        return $pageIds;
    }
}
