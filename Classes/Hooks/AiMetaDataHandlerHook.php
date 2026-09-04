<?php

declare(strict_types=1);

namespace B13\AiLabel\Hooks;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Imaging\ProcessedFileInvalidator;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// Folds tx_ailabel_origin / tx_ailabel_reviewed into the tx_ailabel_metadata JSON
// column (a real type=json TCA column added by AddAiMetaFieldsToTca, never part of
// any showitem/palette) as part of DataHandler's own insert/update - no separate
// table, no separate query to write. "tx_ailabel_reviewed" is a plain boolean
// checkbox in the form, but persisted as reviewed_by inside the JSON: the be_users
// uid that reviewed it, or 0 for "review required".
//
// As long as a record is flagged (origin != Human), a save that changes real
// content resets reviewed_by to 0, so the editor has to review again - unless that
// same save also actively ticks "reviewed" from 0/none to 1 (reviewed wins over
// forcing a reset). Reviewed already being set and simply staying set (checkbox
// untouched) does NOT count as "reviewed wins" - content changing after a record
// was already reviewed must still reset it.
//
// processUpdatedRecord() reconciles against what is stored, processNewRecord() has
// nothing to reconcile. Both write into $fieldArray, so the value is part of the
// insert/update DataHandler already performs. AiLabelApi::aiMetadataUpdate() would
// start a second DataHandler inside this one; it stays the entry point for callers
// outside a running DataHandler.
#[Autoconfigure(public: true)]
final class AiMetaDataHandlerHook
{
    // Keyed by "$table:$id". "reviewed" (the submitted checkbox, not yet the actual
    // reviewing user) is stashed via reviewedBy as a 1/0 placeholder - only isReviewed()
    // is ever read back from it here, the real backend user id is resolved later in
    // processDatamap_postProcessFieldArray().
    /** @var array<string, AiMetadata> */
    private array $pendingValues = [];

    // Metadata changes for sys_history, keyed by "$table:$id". Core builds its payload
    // before this hook adds the column, so it would never show up in the history.
    /** @var array<string, array{oldRecord: array<string, string>, newRecord: array<string, string>}> */
    private array $pendingHistory = [];

    public function __construct(
        private readonly Context $context,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly ProcessedFileInvalidator $processedFileInvalidator,
    ) {
    }

    /**
     * Runs before DataHandler's own fillInFieldArray()/checkValue()/
     * compareFieldArrayWithCurrentAndUnset() ever see these fields. That last one
     * reads $currentRecord[$col] for every submitted field to decide whether it is
     * unchanged - since these 2 fields have no real column of their own, that access
     * is an undefined array key, and TYPO3's error handler turns that PHP warning
     * into a thrown exception, aborting the whole save. Stripping them here, before
     * they ever reach that code, avoids it entirely.
     */
    public function processDatamap_preProcessFieldArray(
        array &$incomingFieldArray,
        string $table,
        int|string $id,
        DataHandler $dataHandler
    ): void {
        if (
            !array_key_exists('tx_ailabel_origin', $incomingFieldArray)
            && !array_key_exists('tx_ailabel_reviewed', $incomingFieldArray)
        ) {
            return;
        }

        // Stripping the fields still has to happen for any table, or DataHandler's own
        // compare fatals on them - only the stashing is pointless off the applicable set.
        if (!$this->applicableTablesProvider->isTableApplicable($table)) {
            unset($incomingFieldArray['tx_ailabel_origin'], $incomingFieldArray['tx_ailabel_reviewed']);
            return;
        }

        $origin = AiOrigin::tryFrom((int)($incomingFieldArray['tx_ailabel_origin'] ?? AiOrigin::Human->value)) ?? AiOrigin::Human;
        $this->pendingValues[$this->stateKey($dataHandler, $table, $id)] = (new AiMetadata())
            ->withOrigin($origin)
            ->withReviewedBy(($incomingFieldArray['tx_ailabel_reviewed'] ?? false) ? 1 : 0);
        unset($incomingFieldArray['tx_ailabel_origin'], $incomingFieldArray['tx_ailabel_reviewed']);
    }

    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        int|string $id,
        array &$fieldArray,
        DataHandler $dataHandler
    ): void {
        $pendingAiMetadata = $this->takePendingValue($table, $id, $dataHandler);

        // New records never have an existing/previously-reviewed state to reconcile
        // with - see processNewRecord(). Everything else (the review-reset/"reviewed
        // wins" logic) only makes sense for an update, see processUpdatedRecord().
        if ($status !== 'update') {
            if ($pendingAiMetadata !== null) {
                $this->processNewRecord($pendingAiMetadata, $fieldArray, $dataHandler);
            }
            return;
        }

        $this->processUpdatedRecord($pendingAiMetadata, $table, (int)$id, $fieldArray, $dataHandler);
    }

    /**
     * Core can skip a record between the two hooks (a permission denial, a failed
     * versioning), so its entry would otherwise be picked up by a later save of the same
     * record. Only this run's entries are dropped: the container hands this listener out
     * shared, so a nested DataHandler must not clear what the outer one is still using.
     */
    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $prefix = spl_object_id($dataHandler) . ':';
        foreach (array_keys($this->pendingValues) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->pendingValues[$key]);
            }
        }
        foreach (array_keys($this->pendingHistory) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->pendingHistory[$key]);
            }
        }
    }

    private function stateKey(DataHandler $dataHandler, string $table, int|string $id): string
    {
        return spl_object_id($dataHandler) . ':' . $table . ':' . $id;
    }

    /**
     * In a workspace the pre hook is called with the live uid and the post hook with
     * the version's, so the stashed value is mapped back through t3ver_oid.
     */
    private function takePendingValue(string $table, int|string $id, DataHandler $dataHandler): ?AiMetadata
    {
        $key = $this->stateKey($dataHandler, $table, $id);
        if (!isset($this->pendingValues[$key])) {
            $liveId = $this->resolveLiveId($table, (int)$id);
            if ($liveId === 0) {
                return null;
            }
            $key = $this->stateKey($dataHandler, $table, $liveId);
            if (!isset($this->pendingValues[$key])) {
                return null;
            }
        }

        $pendingAiMetadata = $this->pendingValues[$key];
        unset($this->pendingValues[$key]);

        return $pendingAiMetadata;
    }

    private function resolveLiveId(string $table, int $id): int
    {
        if (!$this->tcaSchemaFactory->get($table)->isWorkspaceAware()) {
            return 0;
        }

        return (int)(BackendUtility::getRecord($table, $id, 't3ver_oid')['t3ver_oid'] ?? 0);
    }

    private function processNewRecord(AiMetadata $pendingAiMetadata, array &$fieldArray, DataHandler $dataHandler): void
    {
        if (!$pendingAiMetadata->isFlagged()) {
            // Nothing to persist - leaves tx_ailabel_metadata at its TCA default (null).
            return;
        }

        $isReviewed = $pendingAiMetadata->isReviewed();
        $finalAiMetadata = $pendingAiMetadata
            ->withReviewedBy($isReviewed ? (int)($dataHandler->BE_USER->user['uid'] ?? 0) : 0)
            ->withReviewedTimestamp($isReviewed ? (int)$this->context->getPropertyFromAspect('date', 'timestamp') : 0);

        // DataHandler/Doctrine already JSON-encode values written to a json-typed
        // column - passing an already-encoded string here would double-encode it.
        $fieldArray['tx_ailabel_metadata'] = $finalAiMetadata->toArray();
    }

    /**
     * @param array<string, int> $old
     * @param array<string, int> $new
     */
    private function rememberForHistory(DataHandler $dataHandler, string $table, int $id, array $old, array $new): void
    {
        $this->pendingHistory[$this->stateKey($dataHandler, $table, $id)] = [
            'oldRecord' => ['tx_ailabel_metadata' => (string)json_encode($old)],
            'newRecord' => ['tx_ailabel_metadata' => (string)json_encode($new)],
        ];
    }

    /**
     * $pendingAiMetadata is null for saves carrying no ai fields, e.g. an import or a
     * scheduler task. Flag and reviewer stay as stored, the review reset still applies.
     */
    private function processUpdatedRecord(?AiMetadata $pendingAiMetadata, string $table, int $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        $aiFieldsSubmitted = $pendingAiMetadata !== null;
        if (!$aiFieldsSubmitted && array_key_exists('tx_ailabel_metadata', $fieldArray)) {
            // An explicit write of the column wins, e.g. from AiLabelApi.
            return;
        }

        $existingAiMetadata = AiMetadata::fromJsonString(BackendUtility::getRecord($table, $id, 'tx_ailabel_metadata')['tx_ailabel_metadata'] ?? null);
        $pendingAiMetadata ??= $existingAiMetadata;

        if (!$pendingAiMetadata->isFlagged()) {
            if (!$aiFieldsSubmitted || (!$existingAiMetadata->isFlagged() && !$existingAiMetadata->isReviewed())) {
                // Not flagged and never was - or nothing in this save says otherwise.
                return;
            }
            // Unflagged now: nothing worth tracking anymore.
            $fieldArray['tx_ailabel_metadata'] = [];
            $this->rememberForHistory($dataHandler, $table, $id, $existingAiMetadata->toArray(), []);
            return;
        }

        // "reviewed wins" only applies if the editor actively ticked reviewed in this
        // very save (0/none -> 1). If it was already reviewed and simply stayed reviewed
        // because the checkbox wasn't touched, that's not a decision made in this save
        // and must not block the reset below - otherwise an already-reviewed record
        // could never be flagged for re-review again once content changes.
        $reviewedJustTicked = $pendingAiMetadata->isReviewed() && !$existingAiMetadata->isReviewed();

        // Content changing on an already-reviewed, still-flagged record means review
        // is needed again - reset reviewed_by, unless this same save also (re-)ticks it.
        // $pendingAiMetadata->isFlagged() is already guaranteed true here (see the early return above).
        $contentChanged = $this->hasRelevantContentChange($table, $fieldArray);
        $needsReviewReset = $contentChanged && $existingAiMetadata->isReviewed() && !$reviewedJustTicked;

        // Only an actual reviewing decision moves the reviewer: a checkbox that merely
        // stayed ticked is not one, so the review keeps the user who gave it.
        $beUserId = (int)($dataHandler->BE_USER->user['uid'] ?? 0);
        $reviewedBy = match (true) {
            $needsReviewReset => 0,
            !$aiFieldsSubmitted => $existingAiMetadata->getReviewedBy(),
            !$pendingAiMetadata->isReviewed() => 0,
            $reviewedJustTicked => $beUserId,
            default => $existingAiMetadata->getReviewedBy(),
        };

        // Derived from the reviewer, never decided separately: the two always describe
        // the same review, so an unreviewed record cannot keep an old timestamp.
        $reviewedTimestamp = match (true) {
            $reviewedBy === 0 => 0,
            $reviewedJustTicked => (int)$this->context->getPropertyFromAspect('date', 'timestamp'),
            default => $existingAiMetadata->getReviewedTimestamp(),
        };

        $finalAiMetadata = $pendingAiMetadata
            ->withReviewedBy($reviewedBy)
            ->withReviewedTimestamp($reviewedTimestamp);

        if ($finalAiMetadata->toArray() === $existingAiMetadata->toArray()) {
            // Nothing moved, so leave the column and the history alone.
            return;
        }

        $fieldArray['tx_ailabel_metadata'] = $finalAiMetadata->toArray();
        $this->rememberForHistory($dataHandler, $table, $id, $existingAiMetadata->toArray(), $finalAiMetadata->toArray());
    }

    /**
     * Writes the sys_history entry for the metadata change and flushes processed files
     * carrying an outdated baked marker. New records need neither.
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        int|string $id,
        array $fieldArray,
        DataHandler $dataHandler
    ): void {
        $key = $this->stateKey($dataHandler, $table, $id);
        $history = $this->pendingHistory[$key] ?? null;
        unset($this->pendingHistory[$key]);
        if ($history === null || $status !== 'update') {
            return;
        }

        $uid = (int)$id;
        GeneralUtility::makeInstance(
            RecordHistoryStore::class,
            RecordHistoryStore::USER_BACKEND,
            (int)($dataHandler->BE_USER->user['uid'] ?? 0),
            $dataHandler->BE_USER->getOriginalUserIdWhenInSwitchUserMode(),
            (int)$this->context->getPropertyFromAspect('date', 'timestamp'),
            (int)$dataHandler->BE_USER->workspace
        )->modifyRecord($table, $uid, $history, $dataHandler->getCorrelationId()?->withAspects('ai_label'));

        if ($table === 'sys_file_metadata') {
            $this->processedFileInvalidator->invalidateForFileMetadata($uid);
        }
    }

    /**
     * $fieldArray here is not a reliable "did anything change" flag on its own.
     * DataHandler's own compareFieldArrayWithCurrentAndUnset() deliberately never
     * strips MM-relation fields even when their value is unchanged ("except the
     * current field holds MM relations", DataHandler::compareFieldArrayWithCurrentAndUnset()),
     * and it only adds tstamp back in once $fieldArray is already non-empty - so tstamp
     * itself is never the cause, but a table with e.g. an MM-related category field
     * would otherwise always look "changed" here. The transOrigDiffSourceField (usually
     * l18n_diffsource) is a re-serialized snapshot of the record used for the
     * translation diff view, not user content, and is rewritten on saves where nothing
     * the editor did actually changed - most noticeably from the second save onwards.
     * All three are filtered out before deciding.
     */
    private function hasRelevantContentChange(string $table, array $fieldArray): bool
    {
        $schema = $this->tcaSchemaFactory->get($table);

        $ignoredFields = array_filter([
            // Our own bookkeeping column, never editorial content.
            'tx_ailabel_metadata',
            // Core puts this in $fieldArray before the compare, so a version parked at
            // any stage but 0 always looks changed.
            't3ver_stage',
            $schema->hasCapability(TcaSchemaCapability::UpdatedAt)
                ? $schema->getCapability(TcaSchemaCapability::UpdatedAt)->getFieldName()
                : null,
            $schema->hasCapability(TcaSchemaCapability::Language)
                ? $schema->getCapability(TcaSchemaCapability::Language)->getDiffSourceField()?->getName()
                : null,
        ]);

        foreach ($fieldArray as $field => $value) {
            if (in_array($field, $ignoredFields, true)) {
                continue;
            }
            $fieldConfig = $schema->hasField($field) ? $schema->getField($field)->getConfiguration() : [];
            if (!empty($fieldConfig['MM'])) {
                continue;
            }
            return true;
        }

        return false;
    }
}
