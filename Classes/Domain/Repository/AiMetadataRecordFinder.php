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

use B13\AiLabel\Configuration\ApplicableTablesProvider;
use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// Collects records across the applicable tables whose tx_ailabel_metadata marks them
// as flagged (AI-created or AI-modified). Not an Extbase repository, no persistence layer in
// use here - just a plain query helper, used by the overview module (site-wide)
// and MarkFlaggedPageInLayoutModule (single page, tt_content only).
//
// Workspace-aware: only ever shows what the current backend user would actually
// see for their active workspace - the live version (or its workspace-overlaid
// draft, if one exists), plus records that only exist as a brand new version in
// this workspace. Never leaks another workspace's drafts. BackendUtility::
// workspaceOL() itself already no-ops entirely if EXT:workspaces isn't loaded or
// the workspace is live (id 0), so this stays a no-op extra query in that case.
final class AiMetadataRecordFinder
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ApplicableTablesProvider $applicableTablesProvider,
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly Context $context,
    ) {
    }

    /** @return list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, reviewBadge: string}> */
    public function findFlaggedRecords(): array
    {
        $records = [];
        foreach ($this->applicableTablesProvider->getApplicableTables() as $table) {
            $records = array_merge($records, $this->findFlaggedRecordsForTable($table, null));
        }

        return $records;
    }

    /**
     * Only tt_content, only on this one page - used to fold "review required"/
     * "reviewed by X on Y" badges into the Page module's content element headers.
     *
     * @return list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, reviewBadge: string}>
     */
    public function findFlaggedContentElementsOnPage(int $pageId): array
    {
        if (!$this->applicableTablesProvider->isTableApplicable('tt_content')) {
            return [];
        }

        return $this->findFlaggedRecordsForTable('tt_content', $pageId);
    }

    /** @return list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, reviewBadge: string}> */
    private function findFlaggedRecordsForTable(string $table, ?int $pid): array
    {
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id');
        // Raw $GLOBALS['TCA'] access instead of TcaSchemaFactory/TcaSchemaCapability -
        // that API doesn't exist before TYPO3 v13, and this extension must also run on
        // v12. ctrl.versioningWS is the exact same underlying flag that capability wraps.
        $isVersionable = (bool)($GLOBALS['TCA'][$table]['ctrl']['versioningWS'] ?? false);

        $records = [];

        foreach ($this->findLiveRows($table, $pid, $isVersionable) as $row) {
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

            $record = $this->buildRecord($table, $row);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        if ($isVersionable && $workspaceId > 0) {
            foreach ($this->findNewInWorkspace($table, $pid, $workspaceId) as $row) {
                $record = $this->buildRecord($table, $row);
                if ($record !== null) {
                    $records[] = $record;
                }
            }
        }

        return $records;
    }

    /** @return list<array<string, mixed>> */
    private function findLiveRows(string $table, ?int $pid, bool $isVersionable): array
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->isNotNull('tx_ailabel_metadata'));

        if ($pid !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)));
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
     * @return list<array<string, mixed>>
     */
    private function findNewInWorkspace(string $table, ?int $pid, int $workspaceId): array
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

        if ($pid !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)));
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /** @param array<string, mixed> $row */
    private function buildRecord(string $table, array $row): ?array
    {
        $metadata = AiMetadata::fromJsonString($row['tx_ailabel_metadata'] ?? null);
        if (!$metadata->isFlagged()) {
            return null;
        }

        return [
            'table' => $table,
            'uid' => (int)$row['uid'],
            'pid' => (int)$row['pid'],
            'title' => BackendUtility::getRecordTitle($table, $row),
            'metadata' => $metadata,
            // Same badge markup as everywhere else (form legend, layout module) -
            // built here instead of the Fluid template so the "review required" vs
            // "reviewed by X on Y" wording/color can't drift apart between the two.
            'reviewBadge' => $this->badgeFactory->getBadge($metadata),
        ];
    }
}
