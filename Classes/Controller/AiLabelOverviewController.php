<?php

declare(strict_types=1);

namespace B13\AiLabel\Controller;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Backend\PageTreeScopeResolver;
use B13\AiLabel\Backend\SortUrlBuilder;
use B13\AiLabel\Domain\Repository\AiLabelDemand;
use B13\AiLabel\Domain\Repository\AiMetadataRecordFinder;
use B13\AiLabel\Pagination\DemandedArrayPaginator;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\Action\ShortcutButton;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
final class AiLabelOverviewController
{
    private const MODULE_IDENTIFIER = 'web_ai_label_overview';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly AiMetadataRecordFinder $recordFinder,
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly SortUrlBuilder $sortUrlBuilder,
        private readonly PageTreeScopeResolver $pageTreeScopeResolver,
        private readonly Typo3Version $typo3Version,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $languageService = $this->getLanguageService();
        $view->setTitle($languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'));

        // "id" - the page selected in the navigation component
        $pageId = (int)($request->getQueryParams()['id'] ?? $request->getParsedBody()['id'] ?? 0);
        $backendUser = $this->getBackendUser();
        $pageIds = $backendUser !== null
            ? $this->pageTreeScopeResolver->resolveSelectedPage($pageId, $backendUser)
            : [];

        $pageRow = $pageId > 0 ? BackendUtility::getRecord('pages', $pageId) : null;
        $pageTitle = $pageRow !== null ? BackendUtility::getRecordTitle('pages', $pageRow) : '';

        $this->addShortcut($view, $pageId, $pageTitle);

        $demand = AiLabelDemand::fromRequest($request);
        $allRecords = $this->recordFinder->findFlaggedRecords($pageIds);
        $statistics = $this->recordFinder->calculateStatistics($allRecords);
        $tables = $this->recordFinder->getDistinctTables($allRecords);

        $matchingRecords = $this->recordFinder->filterAndSort($allRecords, $demand);
        $totalCount = count($matchingRecords);
        $pageItems = array_slice($matchingRecords, ($demand->getPage() - 1) * $demand->getLimit(), $demand->getLimit());

        $returnUrl = $this->buildOverviewUrl($demand, $pageId);
        $pageItems = array_map(
            fn (array $record): array => [
                ...$record,
                'reviewBadge' => $this->badgeFactory->getBadge(
                    $record['metadata'],
                    $record['editable'] ? $this->buildEditUrl($record['table'], $record['uid'], $returnUrl) : null,
                ),
            ],
            $pageItems,
        );

        $paginator = new DemandedArrayPaginator($pageItems, $demand->getPage(), $demand->getLimit(), $totalCount);
        $pagination = new SimplePagination($paginator);
        $paginationBaseUrl = (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER, $this->defaultRouteParams($demand, $pageId));

        $view->assignMultiple([
            'demand' => $demand,
            // The page currently selected in the tree
            'pageId' => $pageId,
            // Names the page-tree scope in the empty states, so "nothing flagged" can't
            // read as a site-wide statement. The uid stands in for a page that is gone.
            'scopeLabel' => $pageTitle !== '' ? $pageTitle : '[' . $pageId . ']',
            // Same listing without the page-tree scope, filters kept.
            'unscopedUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER, $this->defaultRouteParams($demand, 0)),
            // Filters are submitted via POST (Overview/Filters.html), so they never
            // show up in the request's own URI. It must be rebuilt from the parsed
            // demand instead, the same way $paginationBaseUrl is.
            'returnUrl' => $returnUrl,
            'paginationBaseUrl' => $paginationBaseUrl,
            'sortUrls' => $this->sortUrlBuilder->build($demand, self::MODULE_IDENTIFIER, $pageId),
            'paginator' => $paginator,
            'pagination' => $pagination,
            'statistics' => $statistics,
            'tables' => $tables,
        ]);

        return $view->renderResponse('Overview/Index');
    }

    /**
     * @return array<string, string|int>
     */
    private function defaultRouteParams(AiLabelDemand $demand, int $pageId): array
    {
        $params = [
            'id' => $pageId,
            'orderField' => $demand->getOrderField(),
            'orderDirection' => $demand->getOrderDirection(),
        ];
        foreach ($demand->getParameters() as $key => $value) {
            $params['demand[' . $key . ']'] = $value;
        }
        return $params;
    }

    private function buildOverviewUrl(AiLabelDemand $demand, int $pageId): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(
            self::MODULE_IDENTIFIER,
            array_merge($this->defaultRouteParams($demand, $pageId), ['page' => $demand->getPage()]),
        );
    }

    private function addShortcut(ModuleTemplate $view, int $pageId, string $pageTitle): void
    {
        $displayName = $moduleTitle = $this->getLanguageService()->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab');
        $arguments = [];

        if ($pageId > 0) {
            $arguments = ['id' => $pageId];
            if ($pageTitle !== '') {
                $displayName = $moduleTitle . ': ' . $pageTitle . ' [' . $pageId . ']';
            }
        }

        $docHeader = $view->getDocHeaderComponent();
        if ($this->typo3Version->getMajorVersion() >= 14) {
            // Adding the button by hand is deprecated there and goes away in v15.
            $docHeader->setShortcutContext(self::MODULE_IDENTIFIER, $displayName, $arguments);

            return;
        }

        $docHeader->getButtonBar()->addButton(
            GeneralUtility::makeInstance(ShortcutButton::class)
                ->setRouteIdentifier(self::MODULE_IDENTIFIER)
                ->setDisplayName($displayName)
                ->setArguments($arguments),
            ButtonBar::BUTTON_POSITION_RIGHT
        );
    }

    private function buildEditUrl(string $table, int $uid, string $returnUrl): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$table => [$uid => 'edit']],
            'returnUrl' => $returnUrl,
        ]);
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    protected function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
