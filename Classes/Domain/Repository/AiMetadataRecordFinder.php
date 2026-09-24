<?php

declare(strict_types=1);

namespace B13\AiLabel\Domain\Repository;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Backend\PageTreeScopeResolver;
use B13\AiLabel\Configuration\ApplicableTablesProvider;
use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Event\AfterRecordIsBuiltEvent;
use B13\AiLabel\Service\AiLabelAccessChecker;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// Collects records across the applicable tables whose tx_ailabel_metadata marks them
// as flagged (AI-created or AI-modified). Not an Extbase repository, no persistence layer in
// use here - just a plain query helper, used by the overview module (site-wide, or
// scoped to a page plus its recursive subpages once a page is selected in its
// navigation component - see PageTreeScopeResolver) and MarkFlaggedPageInLayoutModule
// (single page, tt_content only, never recursive).
//
// Workspace-aware: only ever shows what the current backend user would actually
// see for their active workspace - the live version (or its workspace-overlaid
// draft, if one exists), plus records that only exist as a brand new version in
// this workspace. Never leaks another workspace's drafts. BackendUtility::
// workspaceOL() itself already no-ops entirely if EXT:workspaces isn't loaded or
// the workspace is live (id 0), so this stays a no-op extra query in that case.
//
// Also permission-aware for non-admin backend users, via AiLabelAccessChecker:
// records the user isn't allowed to read (page outside their DB mount / not
// PAGE_SHOW-able, sys_file_metadata whose file sits outside their FS mount or file
// permissions) never make it into the result at all - each remaining record carries
// an 'editable' flag callers use to decide whether to link it for editing.
final class AiMetadataRecordFinder
{
    /** @var list<int>|null Resolved once, the finder walks every applicable table. */
    private ?array $accessiblePageIds = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ApplicableTablesProvider $applicableTablesProvider,
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly Context $context,
        private readonly IconFactory $iconFactory,
        private readonly Typo3Version $typo3Version,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AiLabelAccessChecker $accessChecker,
        private readonly PageTreeScopeResolver $pageTreeScopeResolver,
    ) {
    }

    /**
     * @param list<int>|null $pageIds Restricts the result to a page-tree scope
     * @return list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string, editable: bool}>
     */
    public function findFlaggedRecords(?array $pageIds = null): array
    {
        if ($pageIds === []) {
            // An empty scope is not the same as no scope - nothing can match it.
            return [];
        }

        $records = [];
        foreach ($this->applicableTablesProvider->getApplicableTables() as $table) {
            $records = array_merge($records, $this->findFlaggedRecordsForTable($table, $pageIds));
        }

        return $records;
    }

    /**
     * Only tt_content, only on this one page - used to fold "review required"/
     * "reviewed by X on Y" badges into the Page module's content element headers.
     *
     * @return list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string, editable: bool}>
     */
    public function findFlaggedContentElementsOnPage(int $pageId): array
    {
        if (!$this->applicableTablesProvider->isTableApplicable('tt_content')) {
            return [];
        }

        return $this->findFlaggedRecordsForTable('tt_content', [$pageId]);
    }

    /**
     * @param list<int>|null $pageIds
     * @return list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string, editable: bool}>
     */
    private function findFlaggedRecordsForTable(string $table, ?array $pageIds): array
    {
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id');
        // Raw $GLOBALS['TCA'] access instead of TcaSchemaFactory/TcaSchemaCapability -
        // that API doesn't exist before TYPO3 v13, and this extension must also run on
        // v12. ctrl.versioningWS is the exact same underlying flag that capability wraps.
        $isVersionable = (bool)($GLOBALS['TCA'][$table]['ctrl']['versioningWS'] ?? false);

        $rows = [];

        foreach ($this->findLiveRows($table, $pageIds, $isVersionable) as $row) {
            if ($isVersionable && $workspaceId > 0) {
                BackendUtility::workspaceOL($table, $row, $workspaceId);
                if ($row === false) {
                    // Moved away in this workspace - see workspaceOL()'s $unsetMovePointers.
                    continue;
                }
                // Raw int (2) instead of VersionState::DELETE_PLACEHOLDER - VersionState is a
                // native backed enum on v13/v14 (::DELETE_PLACEHOLDER->value) but TYPO3's older
                // Enumeration-based class on v12 (::DELETE_PLACEHOLDER already a plain int, no
                // ->value, no ::tryFrom()) - same underlying t3ver_state value either way, and
                // this is TYPO3's own stable, long-lived DB schema constant.
                if ((int)($row['t3ver_state'] ?? 0) === 2) {
                    // Deleted in this workspace - would disappear on publish, don't list it.
                    continue;
                }
            }

            $rows[] = $row;
        }

        if ($isVersionable && $workspaceId > 0) {
            foreach ($this->findNewInWorkspace($table, $pageIds, $workspaceId) as $row) {
                $rows[] = $row;
            }
        }

        // One query for the whole table instead of two per record.
        $authors = $this->resolveAuthors($table, $rows);

        $records = [];
        foreach ($rows as $row) {
            $record = $this->buildRecord($table, $row, $authors[(int)$row['uid']] ?? '');
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param list<int>|null $pageIds
     * @return list<array<string, mixed>>
     */
    private function findLiveRows(string $table, ?array $pageIds, bool $isVersionable): array
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->isNotNull('tx_ailabel_metadata'));

        if (!$this->applyPermissionConstraints($queryBuilder, $table)) {
            return [];
        }

        if ($pageIds !== null) {
            $this->constrainToPageScope($queryBuilder, $table, $pageIds);
        }

        if ($isVersionable) {
            // Only the live records here - workspace drafts of them are added back in
            // via workspaceOL() in findFlaggedRecordsForTable(), scoped to the current workspace.
            $queryBuilder->andWhere($queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * Records that only exist as a brand new version inside this workspace - they have
     * no live counterpart yet, so findLiveRows()/workspaceOL() never surfaces them.
     *
     * @param list<int>|null $pageIds
     * @return list<array<string, mixed>>
     */
    private function findNewInWorkspace(string $table, ?array $pageIds, int $workspaceId): array
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->isNotNull('tx_ailabel_metadata'),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                // Raw int (1) instead of VersionState::NEW_PLACEHOLDER->value - see the
                // DELETE_PLACEHOLDER comment above for why.
                $queryBuilder->expr()->eq('t3ver_state', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
            );

        if ($pageIds !== null) {
            $this->constrainToPageScope($queryBuilder, $table, $pageIds);
        }

        if (!$this->applyPermissionConstraints($queryBuilder, $table)) {
            return [];
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param list<int> $pageIds
     */
    private function constrainToPageScope(QueryBuilder $queryBuilder, string $table, array $pageIds): void
    {
        $column = $table === 'pages' ? 'uid' : 'pid';
        $queryBuilder->andWhere($queryBuilder->expr()->in(
            $column,
            $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)
        ));
    }

    /**
     * Restricts the query to what this user may see, and says whether anything is
     * visible at all.
     */
    private function applyPermissionConstraints(QueryBuilder $queryBuilder, string $table): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        if (!$backendUser->check('tables_select', $table)) {
            return false;
        }

        if ($this->livesOnRootLevelOnly($table)) {
            // pid is always 0 here, so there is nothing to scope in SQL. File metadata is
            // checked per row by AiLabelAccessChecker (file mounts and file permissions);
            // any other root-level table has no boundary here and stays admin-only.
            return $table === 'sys_file_metadata';
        }

        $accessiblePageIds = $this->resolveAccessiblePageIds($backendUser);
        if ($accessiblePageIds === []) {
            return false;
        }

        $pageIdParameter = $queryBuilder->createNamedParameter($accessiblePageIds, Connection::PARAM_INT_ARRAY);
        if ($table !== 'pages') {
            $queryBuilder->andWhere($queryBuilder->expr()->in('pid', $pageIdParameter));

            return true;
        }

        // Pages carry access on the record itself. A translation is its own row with its
        // own uid, and the accessible ids only ever contain default language pages, so it
        // has to be matched through its parent as well.
        $pageConstraints = [$queryBuilder->expr()->in('uid', $pageIdParameter)];
        // Same condition as TcaSchemaCapability::Language, which v12 lacks.
        $tcaCtrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        if (isset($tcaCtrl['languageField'], $tcaCtrl['transOrigPointerField'])) {
            $pageConstraints[] = $queryBuilder->expr()->in($tcaCtrl['transOrigPointerField'], $pageIdParameter);
        }
        $queryBuilder->andWhere($queryBuilder->expr()->or(...$pageConstraints));

        return true;
    }

    /**
     * Pages reachable from the user's web mounts, or null for an admin. The permission
     * clause alone is not the same thing: bits can pass on pages outside every mount.
     *
     * @return list<int>|null
     */
    private function resolveAccessiblePageIds(BackendUserAuthentication $backendUser): ?array
    {
        if ($backendUser->isAdmin()) {
            return null;
        }
        if ($this->accessiblePageIds !== null) {
            return $this->accessiblePageIds;
        }

        return $this->accessiblePageIds = $this->pageTreeScopeResolver->resolveSubtrees(
            $backendUser->getWebmounts(),
            $backendUser
        );
    }

    private function livesOnRootLevelOnly(string $table): bool
    {
        // ctrl.rootLevel 1 is RootLevelCapability::TYPE_ONLY_ON_ROOTLEVEL, which v12 lacks.
        return (int)($GLOBALS['TCA'][$table]['ctrl']['rootLevel'] ?? 0) === 1;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }

    /**
     * Filters and sorts an already-fetched record list per $demand. Takes the
     * list rather than fetching it itself so the overview module's stats,
     * distinct-table filter options, and filtered listing can all be derived
     * from a single findFlaggedRecords() call instead of querying three times.
     *
     * Filtering/sorting happens in PHP, not SQL: tx_ailabel_metadata is a JSON
     * blob, and filtering on its decoded content isn't portable across
     * MySQL/SQLite/Postgres without per-database JSON path functions.
     *
     * @param list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string, editable: bool}> $records
     * @return list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string, editable: bool}>
     */
    public function filterAndSort(array $records, AiLabelDemand $demand): array
    {
        $filtered = array_values(array_filter($records, function (array $record) use ($demand): bool {
            if ($demand->hasTable() && $record['table'] !== $demand->getTable()) {
                return false;
            }
            if ($demand->hasOrigin()) {
                $matchesOrigin = $demand->getOrigin() === 'created'
                    ? $record['metadata']->isAiCreated()
                    : $record['metadata']->isAiModified();
                if (!$matchesOrigin) {
                    return false;
                }
            }
            if ($demand->hasReviewStatus()) {
                $isReviewed = $record['metadata']->isReviewed();
                if ($demand->getReviewStatus() === 'reviewed' && !$isReviewed) {
                    return false;
                }
                if ($demand->getReviewStatus() === 'required' && $isReviewed) {
                    return false;
                }
            }
            if ($demand->hasSearch() && stripos($this->searchableLabel($record), $demand->getSearch()) === false) {
                return false;
            }
            return true;
        }));

        usort($filtered, fn (array $a, array $b): int => $this->compareForSort($a, $b, $demand));

        return $filtered;
    }

    private function compareForSort(array $a, array $b, AiLabelDemand $demand): int
    {
        $result = match ($demand->getOrderField()) {
            'title' => strcasecmp($this->searchableLabel($a), $this->searchableLabel($b)),
            'author' => strcasecmp($a['author'], $b['author']),
            'origin' => $a['metadata']->getOrigin()->value <=> $b['metadata']->getOrigin()->value,
            'reviewed' => (int)$a['metadata']->isReviewed() <=> (int)$b['metadata']->isReviewed(),
            // Sorts by the speaking label actually shown in the table, not the
            // raw table name, e.g. "File metadata" vs "sys_file_metadata".
            default => strcasecmp($a['tableLabel'], $b['tableLabel']),
        };
        return $demand->getOrderDirection() === AiLabelDemand::ORDER_DESCENDING ? -$result : $result;
    }

    /**
     * @param array{tableLabel: string, uid: int, title: string} $record
     */
    private function searchableLabel(array $record): string
    {
        return $record['title'] !== '' ? $record['title'] : ($record['tableLabel'] . ' #' . $record['uid']);
    }

    /**
     * Counts across ALL flagged records the caller passes in, ignoring the current
     * demand's filters - site-wide if $records came from findFlaggedRecords(null),
     * or scoped to a page-tree selection if it came from findFlaggedRecords($pageIds).
     *
     * @param list<array{metadata: AiMetadata}> $records
     * @return array{total: int, created: int, modified: int, reviewRequired: int, reviewed: int}
     */
    public function calculateStatistics(array $records): array
    {
        $total = count($records);
        $created = 0;
        $reviewRequired = 0;
        foreach ($records as $record) {
            if ($record['metadata']->isAiCreated()) {
                $created++;
            }
            if (!$record['metadata']->isReviewed()) {
                $reviewRequired++;
            }
        }

        return [
            'total' => $total,
            'created' => $created,
            'modified' => $total - $created,
            'reviewRequired' => $reviewRequired,
            'reviewed' => $total - $reviewRequired,
        ];
    }

    /**
     * Filter-dropdown options: one per raw table actually present in $records,
     * labelled with that table's own speaking title (LLL:ctrl.title), not the
     * per-row/per-type label buildRecord() resolves, since a filter option
     * applies to the whole table, not one record's specific type.
     *
     * @param list<array{table: string}> $records
     * @return list<array{value: string, label: string}>
     */
    public function getDistinctTables(array $records): array
    {
        $tables = array_unique(array_column($records, 'table'));
        $options = array_map(
            fn (string $table): array => ['value' => $table, 'label' => $this->resolveTableTitle($table)],
            $tables,
        );
        usort($options, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        return $options;
    }

    /** @param array<string, mixed> $row */
    private function buildRecord(string $table, array $row, string $author): ?array
    {
        $metadata = AiMetadata::fromJsonString($row['tx_ailabel_metadata'] ?? null);
        if (!$metadata->isFlagged()) {
            return null;
        }
        if (!$this->accessChecker->isReadable($table, $row)) {
            return null;
        }

        $record = [
            'table' => $table,
            'uid' => (int)$row['uid'],
            'pid' => (int)$row['pid'],
            'title' => BackendUtility::getRecordTitle($table, $row),
            'metadata' => $metadata,
            'author' => $author,
            // Resolves per-row overlays (hidden, workspace state, ...), same as
            // any other backend record listing. Built here rather than in the
            // Fluid template since IconFactory needs the actual DB row, which
            // the overview module's demand-filtering layer never carries.
            'icon' => $this->iconFactory->getIconForRecord($table, $row, $this->getSmallIconSize())->render(),
            // Editors see this, not the raw table name. Resolved per record
            // rather than per table since a type-specific title (if the table's
            // "types" configuration for this row's type defines one) takes
            // precedence over the table's own generic title.
            'tableLabel' => $this->resolveRecordTypeTitle($table, $row),
            // Same badge markup as everywhere else (form legend, layout module) -
            // built here instead of the Fluid template so the "review required" vs
            // "reviewed by X on Y" wording/color can't drift apart between the two.
            'reviewBadge' => $this->badgeFactory->getBadge($metadata),
            'editable' => $this->accessChecker->isEditable($table, $row),
        ];

        $event = new AfterRecordIsBuiltEvent($record, $row);
        $this->eventDispatcher->dispatch($event);

        return $event->getRecord();
    }

    /**
     * Creating user per record uid, from the record's insert history entry as
     * RecordHistory::getCreationInformationForRecord() reads it, once per table.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function resolveAuthors(string $table, array $rows): array
    {
        $recordUids = array_map(static fn (array $row): int => (int)$row['uid'], $rows);
        if ($recordUids === []) {
            return [];
        }

        $creatorUidPerRecord = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_history');
        $queryBuilder->getRestrictions()->removeAll();
        // Chunked to stay under the placeholder limit.
        foreach (array_chunk($recordUids, 1000) as $recordUidChunk) {
            $historyEntries = $queryBuilder
                ->select('recuid', 'userid', 'usertype')
                ->from('sys_history')
                ->where(
                    $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                    $queryBuilder->expr()->in('recuid', $queryBuilder->createNamedParameter($recordUidChunk, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('actiontype', $queryBuilder->createNamedParameter(RecordHistoryStore::ACTION_ADD, Connection::PARAM_INT))
                )
                ->orderBy('uid')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($historyEntries as $historyEntry) {
                $recordUid = (int)$historyEntry['recuid'];
                // The first insert entry wins, matching getCreationInformationForRecord()'s
                // own setMaxResults(1).
                if (isset($creatorUidPerRecord[$recordUid]) || ($historyEntry['usertype'] ?? '') !== 'BE') {
                    continue;
                }
                $creatorUidPerRecord[$recordUid] = (int)$historyEntry['userid'];
            }
        }

        $creatorUids = array_values(array_unique(array_filter($creatorUidPerRecord)));
        if ($creatorUids === []) {
            return [];
        }

        $usersQueryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $usersQueryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $users = $usersQueryBuilder
            ->select('uid', 'username', 'realName')
            ->from('be_users')
            ->where($usersQueryBuilder->expr()->in('uid', $usersQueryBuilder->createNamedParameter($creatorUids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();

        $namePerUser = [];
        foreach ($users as $user) {
            $namePerUser[(int)$user['uid']] = ((string)($user['realName'] ?? '')) ?: ((string)($user['username'] ?? ''));
        }

        $authors = [];
        foreach ($creatorUidPerRecord as $recordUid => $creatorUid) {
            $authors[$recordUid] = $namePerUser[$creatorUid] ?? '';
        }

        return $authors;
    }

    // Raw $GLOBALS['TCA'] instead of TcaSchemaFactory (see findFlaggedRecordsForTable()
    // for why): mirrors what TcaSchemaBuilder does - a "types" entry for the row's type
    // is merged over "ctrl", so a type-level "title" wins over the table's own.
    /** @param array<string, mixed> $row */
    private function resolveRecordTypeTitle(string $table, array $row): string
    {
        $tca = $GLOBALS['TCA'][$table] ?? null;
        if (!is_array($tca)) {
            return $table;
        }

        $typeFieldConfiguration = $tca['ctrl']['type'] ?? null;
        $typeConfiguration = [];
        if (is_string($typeFieldConfiguration) && $typeFieldConfiguration !== '') {
            $typeField = explode(':', $typeFieldConfiguration)[0];
            $typeValue = (string)($row[$typeField] ?? '');
            if ($typeValue !== '' && is_array($tca['types'][$typeValue] ?? null)) {
                $typeConfiguration = $tca['types'][$typeValue];
            }
        } elseif (($tca['types'] ?? []) !== []) {
            $typeConfiguration = $tca['types'][array_key_first($tca['types'])];
        }

        return $this->resolveTitle($typeConfiguration['title'] ?? $tca['ctrl']['title'] ?? null, $table);
    }

    private function resolveTableTitle(string $table): string
    {
        return $this->resolveTitle($GLOBALS['TCA'][$table]['ctrl']['title'] ?? null, $table);
    }

    private function resolveTitle(mixed $title, string $fallback): string
    {
        $title = is_string($title) ? $this->getLanguageService()->sL($title) : '';
        return $title !== '' ? $title : $fallback;
    }

    // Same 'small'-literal reasoning as AiMetadataBadgeFactory::createButtonHtml().
    private function getSmallIconSize(): string|IconSize
    {
        return $this->typo3Version->getMajorVersion() < 13 ? 'small' : IconSize::SMALL;
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
