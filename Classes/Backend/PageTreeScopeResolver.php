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
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The single page tree walk in the extension: entry points plus everything below
 * them the user may see. Used for the overview module's page-tree selection and
 * for AiMetadataRecordFinder's web mount scope, so both stay on the same rules.
 */
final class PageTreeScopeResolver
{
    private const MAX_RECURSION_DEPTH = 99;

    public function __construct(private readonly Context $context)
    {
    }

    /**
     * The page selected in the overview module's navigation component plus its
     * subpages, or null for "nothing selected" - which keeps the listing site-wide.
     *
     * @return list<int>|null
     */
    public function resolveSelectedPage(int $pageId, BackendUserAuthentication $backendUser): ?array
    {
        return $pageId > 0 ? $this->resolveSubtrees([$pageId], $backendUser) : null;
    }

    /**
     * @param list<int> $entryPointIds
     * @return list<int>
     */
    public function resolveSubtrees(array $entryPointIds, BackendUserAuthentication $backendUser): array
    {
        if ($entryPointIds === []) {
            return [];
        }

        // Without the workspace the repository's own WorkspaceRestriction drops pages that
        // exist only in this workspace, and with them every record on such a page.
        $pageTreeRepository = GeneralUtility::makeInstance(
            PageTreeRepository::class,
            (int)$this->context->getPropertyFromAspect('workspace', 'id')
        );
        $pageTreeRepository->setAdditionalWhereClause($backendUser->getPagePermsClause(Permission::PAGE_SHOW));

        // The entry points are not carried over unchecked - the walk returns them itself,
        // permission-filtered, so an id the user may not see never widens the scope.
        $pageIds = [];
        foreach ($pageTreeRepository->getFlattenedPages($entryPointIds, self::MAX_RECURSION_DEPTH) as $page) {
            $pageIds[] = (int)$page['uid'];
        }

        return array_values(array_unique($pageIds));
    }
}
