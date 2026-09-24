<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Controller;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Controller\AiLabelOverviewController;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class AiLabelOverviewControllerTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../Repository/Fixtures/AiMetadataRecordFinderPermissions/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Repository/Fixtures/AiMetadataRecordFinderPageTree/Tree.csv');
        $backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function withoutAPageSelectedTheEmptyStateStaysSiteWide(): void
    {
        $this->truncateFlaggedRecords();

        $body = $this->renderModule(0);

        self::assertStringContainsString('No records are currently flagged', $body);
        self::assertStringNotContainsString('Search all pages', $body);
    }

    // "Nothing flagged" must not read as a site-wide statement while a page is selected.
    #[Test]
    public function withAPageSelectedTheEmptyStateNamesTheScope(): void
    {
        $body = $this->renderModule(13);

        self::assertStringContainsString('No records below &quot;Empty child&quot; are flagged', $body);
        self::assertStringContainsString('Search all pages', $body);
        self::assertStringNotContainsString('No records are currently flagged', $body);
    }

    // A page that is gone or unreadable still has to say which scope produced the result.
    #[Test]
    public function anUnresolvablePageFallsBackToItsUid(): void
    {
        $body = $this->renderModule(9999);

        self::assertStringContainsString('No records below &quot;[9999]&quot; are flagged', $body);
    }

    #[Test]
    public function theFilteredEmptyStateNamesTheScopeToo(): void
    {
        $body = $this->renderModule(1, ['demand' => ['search' => 'nothing matches this']]);

        self::assertStringContainsString('No flagged records below &quot;Mounted root&quot; match the current filter', $body);
        self::assertStringContainsString('Search all pages', $body);
    }

    // Proves the de.locallang_mod.xlf overlay is actually picked up, placeholder included.
    #[Test]
    public function theScopedEmptyStateIsTranslated(): void
    {
        $this->switchToGerman();

        $body = $this->renderModule(13);

        self::assertStringContainsString('Unterhalb von „Empty child“ sind keine Datensätze', $body);
        self::assertStringContainsString('Alle Seiten durchsuchen', $body);
    }

    #[Test]
    public function theFilteredEmptyStateStaysPlainWithoutAPageSelected(): void
    {
        $body = $this->renderModule(0, ['demand' => ['search' => 'nothing matches this']]);

        self::assertStringContainsString('No flagged records match the current filter', $body);
        self::assertStringNotContainsString('Search all pages', $body);
    }

    /**
     * "Search all pages" links to id=0, which is "nothing selected", not the page with
     * uid 0 - no editor ever needs access to the root for it. The listing falls back to
     * the web mount scope the finder applies anyway.
     */
    #[Test]
    public function searchAllPagesLeavesAnEditorWithTheirOwnMountOnly(): void
    {
        $this->switchToEditor();

        $body = $this->renderModule(0);

        self::assertStringContainsString('On the root', $body);
        self::assertStringContainsString('On the grandchild', $body);
        self::assertStringNotContainsString('On the unmounted root', $body);
        self::assertStringNotContainsString('Nothing flagged', $body);
    }

    // The button drops the page scope rather than pointing at a page uid the editor
    // would need access to. Only one render per test - DocHeaderComponent is shared,
    // so a second one still sees the first one's ShortcutButton and deprecates.
    #[Test]
    public function searchAllPagesDropsTheScopeRatherThanTargetingARootPage(): void
    {
        $this->switchToEditor();

        $body = $this->renderModule(13);

        self::assertStringContainsString('Search all pages', $body);
        self::assertMatchesRegularExpression('/href="[^"]*(?:\?|&amp;)id=0(?:&amp;|")[^>]*>\s*Search all pages/', $body);
    }

    // The button only exists in the two empty states - a scoped listing that has rows
    // offers no way back other than the page tree itself.
    #[Test]
    public function aScopedListingWithResultsHasNoWayBackButTheTree(): void
    {
        $this->switchToEditor();

        $body = $this->renderModule(11);

        self::assertStringContainsString('On the grandchild', $body);
        self::assertStringNotContainsString('Search all pages', $body);
    }

    private function switchToEditor(): void
    {
        $backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)
            ->createFromUserPreferences($backendUser);
    }

    private function switchToGerman(): void
    {
        $GLOBALS['BE_USER']->user['lang'] = 'de';
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    /** @param array<string, mixed> $queryParams */
    private function renderModule(int $pageId, array $queryParams = []): string
    {
        $request = (new ServerRequest('https://example.com/typo3/module/web/ai-label-overview', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', new NormalizedParams([], [], '', ''))
            ->withAttribute('route', new Route('/module/web/ai-label-overview', ['packageName' => 'b13/ai-label-12']))
            ->withQueryParams(['id' => $pageId] + $queryParams);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return (string)$this->get(AiLabelOverviewController::class)->handleRequest($request)->getBody();
    }

    private function truncateFlaggedRecords(): void
    {
        foreach (['pages', 'tt_content'] as $table) {
            $this->getConnectionPool()->getConnectionForTable($table)
                ->executeStatement('UPDATE ' . $table . ' SET tx_ailabel_metadata = NULL');
        }
    }
}
