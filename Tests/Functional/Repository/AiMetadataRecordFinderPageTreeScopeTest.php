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

use B13\AiLabel\Backend\PageTreeScopeResolver;
use B13\AiLabel\Domain\Repository\AiMetadataRecordFinder;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class AiMetadataRecordFinderPageTreeScopeTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetadataRecordFinderPageTree/Tree.csv');
    }

    #[Test]
    public function noPageSelectedKeepsTheListingSiteWide(): void
    {
        $this->authenticate(1);

        self::assertNull($this->resolve(0));
        self::assertSame(
            ['pages:10', 'pages:2', 'tt_content:100', 'tt_content:110', 'tt_content:111', 'tt_content:120', 'tt_content:200'],
            $this->findFlagged(null)
        );
    }

    #[Test]
    public function selectingAPageScopesTheListingToItsWholeSubtree(): void
    {
        $this->authenticate(1);

        self::assertSame(
            ['pages:10', 'tt_content:100', 'tt_content:110', 'tt_content:111', 'tt_content:120'],
            $this->findFlagged($this->resolve(1))
        );
    }

    // The pages table is matched on uid, so the selected page itself must stay in.
    #[Test]
    public function theSelectedPageItselfIsPartOfTheScope(): void
    {
        $this->authenticate(1);

        self::assertSame(
            ['pages:10', 'tt_content:110', 'tt_content:111'],
            $this->findFlagged($this->resolve(10))
        );
    }

    #[Test]
    public function theWalkDescendsMoreThanOneLevel(): void
    {
        $this->authenticate(1);

        self::assertSame([1, 10, 11, 12], $this->resolve(1));
    }

    #[Test]
    public function aLeafPageScopesToItselfOnly(): void
    {
        $this->authenticate(1);

        self::assertSame(['tt_content:111'], $this->findFlagged($this->resolve(11)));
    }

    // The scope is an additional constraint, never a way around the permission scoping.
    #[Test]
    public function anEditorSelectingAPageOutsideTheirMountSeesNothing(): void
    {
        $this->authenticate(2);

        self::assertSame([], $this->findFlagged($this->resolve(2)));
    }

    #[Test]
    public function anEditorSeesTheirOwnSubtree(): void
    {
        $this->authenticate(2);

        self::assertSame(
            ['pages:10', 'tt_content:110', 'tt_content:111'],
            $this->findFlagged($this->resolve(10))
        );
    }

    #[Test]
    public function theStatisticsFollowTheSelectedScope(): void
    {
        $this->authenticate(1);

        $finder = $this->get(AiMetadataRecordFinder::class);
        $statistics = $finder->calculateStatistics($finder->findFlaggedRecords($this->resolve(10)));

        self::assertSame(3, $statistics['total']);
        self::assertSame(2, $statistics['created']);
        self::assertSame(1, $statistics['modified']);
    }

    private function authenticate(int $userId): void
    {
        $backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser($userId);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /** @return list<int>|null */
    private function resolve(int $pageId): ?array
    {
        $pageIds = $this->get(PageTreeScopeResolver::class)->resolve($pageId, $GLOBALS['BE_USER']);
        if ($pageIds === null) {
            return null;
        }
        $pageIds = array_values(array_unique($pageIds));
        sort($pageIds);

        return $pageIds;
    }

    /**
     * @param list<int>|null $pageIds
     * @return list<string>
     */
    private function findFlagged(?array $pageIds): array
    {
        $identifiers = array_map(
            static fn (array $record): string => $record['table'] . ':' . $record['uid'],
            $this->get(AiMetadataRecordFinder::class)->findFlaggedRecords($pageIds)
        );
        sort($identifiers);

        return $identifiers;
    }
}
