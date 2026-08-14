<?php

declare(strict_types=1);

namespace B13\AiLabel\Service;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Domain\Model\ReviewStatus;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// TYPO3 v14+ only - ComponentFactory/DropDownButton don't exist on v13.
// See Classes/Legacy/Service/AiMetadataBadgeFactory.php for the v13 equivalent.
//
// Builds the small action-column marker shared by the record list and file list
// event listeners: a single "AI" icon that opens a native Bootstrap dropdown
// (data-bs-toggle, no custom JS) showing the record's AI origin (created/modified)
// and whether it still needs review. getReviewStatus() is the single source of
// truth for the label/color of the review state, so
// the dropdown, the plain badge (layout module, form legend, overview module)
// and both TYPO3 versions never drift apart from each other.
//
// #[Autoconfigure(public: true)]: VirtualCheckboxElement can't constructor-inject this
// (FormEngine nodes have a fixed constructor signature on v12, see that class), so it
// fetches this via bare GeneralUtility::makeInstance() instead - which only resolves
// constructor dependencies through the container for classes the container considers
// public; otherwise it falls back to `new AiMetadataBadgeFactory()` with no arguments
// at all, straight past the constructor's required parameters.
#[Autoconfigure(public: true)]
final class AiMetadataBadgeFactory
{
    public function __construct(
        private readonly IconFactory $iconFactory,
        private readonly Typo3Version $typo3Version,
    ) {
        // ComponentFactory is fetched lazily in createButton() instead of being
        // constructor-injected: this class is instantiated by both the v14 and
        // v13/v12 event listeners (Services.yaml autowires the whole Classes/*
        // directory), and ComponentFactory doesn't exist on v13/v12 - a constructor
        // type-hint for it would fail container compilation there. Once v13/v12
        // support is dropped, this can go back to normal constructor injection.
    }

    public function createButton(AiMetadata $metadata, string $href): DropDownButton
    {
        // v14
        $componentFactory = GeneralUtility::makeInstance(ComponentFactory::class);
        $flagLabel = $this->getFlagLabel($metadata);
        $status = $this->getReviewStatus($metadata);

        $dropDown = $componentFactory->createDropDownButton()
            ->setLabel($flagLabel)
            ->setTitle($flagLabel)
            ->setIcon($this->iconFactory->getIcon('ai-label-info', IconSize::SMALL));

        $dropDown->addItem($componentFactory->createDropDownHeader()->setLabel($flagLabel));
        $dropDown->addItem(
            $componentFactory->createDropDownItem()
                ->setTag('a')
                ->setHref($href)
                ->setLabel($status->label)
        );

        return $dropDown;
    }

    public function createButtonHtml(AiMetadata $metadata, string $href): string
    {
        // v12/v13
        $flagLabel = $this->getFlagLabel($metadata);
        $status = $this->getReviewStatus($metadata);

        // IconSize (the enum) doesn't exist before TYPO3 v13. Icon::SIZE_SMALL, the
        // string constant it replaced, isn't a safe substitute either - confirmed
        // removed as of v14, not just deprecated (phpstan under a real v14 install:
        // "Access to undefined constant Icon::SIZE_SMALL"). 'small' is the literal
        // value that constant held on v12/v13, so using it directly here needs no
        // class reference at all - nothing to go stale on either side.
        $iconSize = $this->typo3Version->getMajorVersion() < 13 ? 'small' : IconSize::SMALL;

        return '<div class="btn-group">'
            . '<button type="button" class="btn btn-sm btn-default dropdown-toggle" data-bs-toggle="dropdown"'
            . ' aria-expanded="false" aria-label="' . htmlspecialchars($flagLabel) . '" title="' . htmlspecialchars($flagLabel) . '">'
            . $this->iconFactory->getIcon('ai-label-info', $iconSize)->render()
            . '</button>'
            . '<ul class="dropdown-menu">'
            . '<li><h6 class="dropdown-header">' . htmlspecialchars($flagLabel) . '</h6></li>'
            . '<li><a class="dropdown-item" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($status->label) . '</a></li>'
            . '</ul>'
            . '</div>';
    }

    public function getBadge(AiMetadata $aiMetadata, ?string $href = null): string
    {
        $status = $this->getReviewStatus($aiMetadata);
        $badge = '<span class="badge ' . $status->badgeClass . '">' . htmlspecialchars($status->label) . '</span>';

        if ($href === null) {
            return $badge;
        }

        return '<a href="' . htmlspecialchars($href) . '">' . $badge . '</a>';
    }

    private function getReviewStatus(AiMetadata $metadata): ReviewStatus
    {
        if (!$metadata->isReviewed()) {
            return new ReviewStatus(
                label: $this->getLanguageService()->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:notice.reviewRequired'),
                badgeClass: 'badge-warning',
            );
        }

        $reviewedBy = $metadata->getReviewedBy();
        $beUser = BackendUtility::getRecord('be_users', $reviewedBy);
        $userName = $beUser['username'] ?? ('[' . $reviewedBy . ']');

        return new ReviewStatus(
            label: sprintf(
                $this->getLanguageService()->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:notice.reviewedBy'),
                $userName,
                date('d.m.Y', $metadata->getReviewedTimestamp())
            ),
            badgeClass: 'badge-info',
        );
    }

    private function getFlagLabel(AiMetadata $metadata): string
    {
        $languageService = $this->getLanguageService();

        return match ($metadata->getOrigin()) {
            AiOrigin::Generated => $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_origin.generated'),
            AiOrigin::Manipulated => $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_origin.manipulated'),
            AiOrigin::Human => '',
        };
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
