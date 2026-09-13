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

use B13\AiLabel\EventListener\MarkFlaggedPageInLayoutModule;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MarkFlaggedPageInLayoutModuleTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function flaggedPageGetsABadgeInTheHeader(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/FlaggedPage.csv');

        $request = (new ServerRequest('https://example.com/typo3/module/web/layout'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withQueryParams(['id' => 1]);
        // The listener never touches the module template, and building a real one
        // needs a full backend request (NormalizedParams, route, LanguageService).
        $moduleTemplate = (new \ReflectionClass(ModuleTemplate::class))->newInstanceWithoutConstructor();
        $event = new ModifyPageLayoutContentEvent($request, $moduleTemplate);
        $this->get(MarkFlaggedPageInLayoutModule::class)->__invoke($event);

        self::assertStringContainsString('ai-label-page-marker', $event->getHeaderContent());
    }
}
