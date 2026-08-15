<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Fluid\View\StandaloneView;

// Shared by every functional test that needs a bare Fluid view outside a full
// request/site context (ViewHelper/partial tests). No single API covers all three
// supported TYPO3 versions: ViewFactoryInterface/ViewFactoryData don't exist before
// v13, and StandaloneView - while still present on v13/v14 - is fully removed as of
// v13 despite still being merely "@deprecated" in its own v12 docblock (v12 is the
// only version it can actually be used on). Both branches reference a class that
// doesn't exist on the other version(s), but that's safe: neither is executed unless
// the Typo3Version check actually selects it.
trait CreatesFluidViewTrait
{
    /** @param list<string> $templateRootPaths @param list<string> $partialRootPaths */
    private function createFluidView(array $templateRootPaths, array $partialRootPaths = []): object
    {
        if ((new Typo3Version())->getMajorVersion() < 13) {
            $view = GeneralUtility::makeInstance(StandaloneView::class);
            $view->getTemplatePaths()->setTemplateRootPaths($templateRootPaths);
            if ($partialRootPaths !== []) {
                $view->getTemplatePaths()->setPartialRootPaths($partialRootPaths);
            }
            return $view;
        }

        return $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: $templateRootPaths,
            partialRootPaths: $partialRootPaths,
        ));
    }
}
