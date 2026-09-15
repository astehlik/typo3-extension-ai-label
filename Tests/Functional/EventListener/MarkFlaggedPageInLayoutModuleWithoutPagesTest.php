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

use B13\AiLabel\Configuration\ApplicableTablesProvider;
use B13\AiLabel\EventListener\MarkFlaggedPageInLayoutModule;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * An integrator may drop "pages" via ApplicableTablesEvent, and then the column
 * tx_ailabel_metadata never gets created on that table at all.
 */
class MarkFlaggedPageInLayoutModuleWithoutPagesTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
        'typo3conf/ext/ai_label/Tests/Functional/Fixtures/Extensions/ai_label_no_pages',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Repository/Fixtures/AiMetadataRecordFinderPermissions/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/PageWithoutAiColumn.csv');
        $backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function thePagesTableReallyHasNoAiColumn(): void
    {
        self::assertFalse($this->get(ApplicableTablesProvider::class)->isTableApplicable('pages'));

        $columns = $this->getConnectionPool()->getConnectionForTable('pages')
            ->createSchemaManager()->listTableColumns('pages');

        self::assertArrayNotHasKey('tx_ailabel_metadata', $columns);
    }

    #[Test]
    public function openingThePageModuleDoesNotBlowUp(): void
    {
        $event = $this->buildEvent();

        $this->get(MarkFlaggedPageInLayoutModule::class)($event);

        self::assertStringNotContainsString('ai-label-page-marker', $event->getHeaderContent());
    }

    // The guard is about "pages" only: tt_content stays applicable, so its badges
    // must still be rendered.
    #[Test]
    public function contentElementBadgesAreStillRendered(): void
    {
        $event = $this->buildEvent();

        $this->get(MarkFlaggedPageInLayoutModule::class)($event);

        $headerContent = $event->getHeaderContent();
        self::assertStringContainsString('id="ai-label-content-badges"', $headerContent);

        self::assertSame(1, preg_match('/id="ai-label-content-badges">(.*?)<\/script>/s', $headerContent, $matches));
        $badges = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        // Only the flagged element, and it carries a real badge.
        self::assertSame([1], array_keys($badges));
        self::assertStringContainsString('Not reviewed', $badges[1]);
    }

    private function buildEvent(): ModifyPageLayoutContentEvent
    {
        $request = (new ServerRequest('https://example.com/typo3/module/web/layout', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', new NormalizedParams([], [], '', ''))
            ->withAttribute('route', new Route('/module/web/layout', ['packageName' => 'typo3/cms-backend']))
            ->withQueryParams(['id' => 1]);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return new ModifyPageLayoutContentEvent(
            $request,
            $this->get(ModuleTemplateFactory::class)->create($request)
        );
    }
}
