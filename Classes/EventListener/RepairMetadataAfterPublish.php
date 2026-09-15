<?php

declare(strict_types=1);

namespace B13\AiLabel\EventListener;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Configuration\ApplicableTablesProvider;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent;

// Publishing encodes tx_ailabel_metadata a second time, leaving JSON inside a JSON
// string that every consumer reads as unflagged. Repaired after the swap, since
// preventing it would have to happen in core. Publishing the same record again
// encodes it once more, hence the loop.
//
// typo3/cms-workspaces stays require-dev: the event is only a parameter type hint.
#[AsEventListener(identifier: 'ai-label/repair-metadata-after-publish')]
final class RepairMetadataAfterPublish
{
    private const MAX_DECODE_DEPTH = 10;

    public function __construct(
        private readonly ApplicableTablesProvider $applicableTablesProvider,
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Guarded as a whole: this runs inside version_swap(), before the version row is
     * dropped and before the page cache is flushed, and nothing wraps a cmdmap in a
     * transaction. Throwing here would leave the workspace half published, which is
     * worse than the value staying broken. A table registered through
     * ApplicableTablesEvent without a database compare has no such column at all.
     */
    public function __invoke(AfterRecordPublishedEvent $event): void
    {
        try {
            $this->repairPublishedRecord($event);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Could not repair tx_ailabel_metadata on {table}:{uid} after publishing. '
                . 'The record reads as unflagged until it is written again.',
                ['table' => $event->getTable(), 'uid' => $event->getRecordId(), 'exception' => $e]
            );
        }
    }

    private function repairPublishedRecord(AfterRecordPublishedEvent $event): void
    {
        $table = $event->getTable();
        if (!$this->applicableTablesProvider->isTableApplicable($table)) {
            return;
        }

        $uid = $event->getRecordId();
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $storedValue = $queryBuilder
            ->select('tx_ailabel_metadata')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        if (!is_string($storedValue) || $storedValue === '') {
            return;
        }

        $decodedValue = json_decode($storedValue, true);
        if (!is_string($decodedValue)) {
            // A healthy record holds a plain object.
            return;
        }

        $depth = 0;
        while (is_string($decodedValue) && $depth++ < self::MAX_DECODE_DEPTH) {
            $decodedValue = json_decode($decodedValue, true);
        }
        if (!is_array($decodedValue)) {
            $this->logger->warning(
                'tx_ailabel_metadata on {table}:{uid} could not be decoded after publishing.',
                ['table' => $table, 'uid' => $uid]
            );
            return;
        }

        // Doctrine encodes the array exactly once.
        $this->connectionPool->getConnectionForTable($table)->update(
            $table,
            ['tx_ailabel_metadata' => $decodedValue],
            ['uid' => $uid]
        );
    }
}
