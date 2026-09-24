<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\EventListener;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\EventListener\RepairMetadataAfterPublish;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class RepairMetadataAfterPublishTest extends FunctionalTestCase
{
    protected ?BackendUserAuthentication $backendUser = null;

    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/RepairMetadataAfterPublish/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/RepairMetadataAfterPublish/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/RepairMetadataAfterPublish/Workspace.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/RepairMetadataAfterPublish/FlaggedRecord.csv');
        $this->backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($this->backendUser);
    }

    // Without the listener the published record holds JSON inside a JSON string.
    #[Test]
    public function publishingAVersionLeavesReadableMetadataOnTheLiveRecord(): void
    {
        $this->backendUser->workspace = 1;
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [1 => ['header' => 'Changed in the workspace']]], [], $this->backendUser);
        $dataHandler->process_datamap();

        $versionUid = (int)$this->getConnectionPool()->getConnectionForTable('tt_content')
            ->fetchOne('SELECT uid FROM tt_content WHERE t3ver_oid = 1 AND t3ver_wsid = 1');
        self::assertGreaterThan(0, $versionUid, 'Expected a workspace version to have been created');

        $publishDataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $publishDataHandler->start([], ['tt_content' => [1 => ['version' => ['action' => 'swap', 'swapWith' => $versionUid]]]], $this->backendUser);
        $publishDataHandler->process_cmdmap();

        $storedValue = $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->fetchOne('SELECT tx_ailabel_metadata FROM tt_content WHERE uid = 1');

        self::assertIsArray(
            json_decode((string)$storedValue, true),
            'The published value must decode to an array, not to a JSON string containing JSON'
        );
        self::assertTrue(AiMetadata::fromJsonString((string)$storedValue)->isAiCreated());
    }

    // The repair runs inside version_swap(), before the version row is dropped and the
    // page cache is flushed, so a failure must not escape and leave a half published
    // workspace. A table registered without a database compare has no such column.
    #[Test]
    public function aFailingRepairDoesNotBreakThePublish(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->executeStatement('ALTER TABLE tt_content DROP COLUMN tx_ailabel_metadata');

        $listener = $this->get(RepairMetadataAfterPublish::class);
        $listener(new AfterRecordPublishedEvent('tt_content', 1, 1));

        self::assertTrue(true, 'The listener must swallow the failure rather than abort the publish');
    }

    protected function getConnectionPool(): ConnectionPool
    {
        return $this->get(ConnectionPool::class);
    }
}
