<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Repository;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Repository\AiMetadataRecordFinder;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class AiMetadataRecordFinderQueryScopeTest extends FunctionalTestCase
{
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetadataRecordFinderPermissions/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetadataRecordFinderPermissions/Scenario.csv');
    }

    #[Test]
    public function anAdminSeesEveryFlaggedRecord(): void
    {
        $this->authenticate(1);

        self::assertSame([1, 2], $this->findFlaggedUids());
    }

    // Mounted on page 1, no permissions on page 2.
    #[Test]
    public function anEditorOnlySeesRecordsOnPagesTheyMayRead(): void
    {
        $this->authenticate(2);

        self::assertSame([1], $this->findFlaggedUids());
    }

    // Allowed onto the page, but not to select tt_content.
    #[Test]
    public function anEditorWithoutTableAccessSeesNothing(): void
    {
        $this->authenticate(3);

        self::assertSame([], $this->findFlaggedUids());
    }

    private function authenticate(int $userId): void
    {
        $backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser($userId);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /** @return list<int> */
    private function findFlaggedUids(): array
    {
        $uids = array_column($this->get(AiMetadataRecordFinder::class)->findFlaggedRecords(), 'uid');
        sort($uids);

        return $uids;
    }
}
