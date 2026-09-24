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
use B13\AiLabel\Service\AiLabelAccessChecker;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

// Non-admin backend user scenario for AiMetadataRecordFinder/AiLabelAccessChecker.
// Fixture: PermissionsScenario.csv - user (uid 2) is DB-mounted at page 10 only, and
// FS-mounted at storage 1 (writable) and storage 2 (read-only), not storage 3 at all.
//
//   pages:        10 (mounted, show+edit+editcontent) -> readable+editable
//                 11 (mounted, subpage of 10, show only) -> readable, not editable
//                 20 (NOT mounted, despite full perms_everybody) -> excluded entirely
//   tt_content:   100 on page 10 -> readable+editable
//                 101 on page 11 -> readable, not editable
//                 102 on page 20 -> excluded entirely
//   sys_file_metadata: 1 on storage 1 (writable mount) -> readable+editable
//                       2 on storage 2 (read-only mount) -> readable, not editable
//                       3 on storage 3 (no mount at all) -> excluded entirely
final class AiMetadataRecordFinderPermissionsTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetadataRecordFinder/PermissionsScenario.csv');

        // StoragePermissionsAspect only wires file mounts/permissions into a storage
        // (ResourceStorage::evaluatePermissions) for a non-admin user in an actual
        // backend request context - see TYPO3\CMS\Core\Resource\Security\
        // StoragePermissionsAspect::addUserPermissionsToStorage(). The request also
        // needs a real 'normalizedParams' attribute - IconFactory::getIconForRecord()
        // (used by AiMetadataRecordFinder::buildRecord()) reads it to build icon URLs
        // and TypeErrors on a bare request without one.
        $request = new ServerRequest('https://example.com/typo3/', 'GET');
        $request = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function nothingIsAccessibleWithoutABackendUser(): void
    {
        unset($GLOBALS['BE_USER']);
        $accessChecker = $this->get(AiLabelAccessChecker::class);
        $row = BackendUtility::getRecord('tt_content', 100) ?? [];

        self::assertFalse($accessChecker->isReadable('tt_content', $row));
        self::assertFalse($accessChecker->isEditable('tt_content', $row));
    }

    #[Test]
    public function ttContentOutsideTheDbMountIsExcludedEntirely(): void
    {
        self::assertNull($this->findRecord('tt_content', 102));
    }

    #[Test]
    public function ttContentOnAFullyPermittedPageIsReadableAndEditable(): void
    {
        $record = $this->findRecord('tt_content', 100);
        self::assertNotNull($record);
        self::assertTrue($record['editable']);
    }

    #[Test]
    public function ttContentOnAShowOnlyPageIsReadableButNotEditable(): void
    {
        $record = $this->findRecord('tt_content', 101);
        self::assertNotNull($record);
        self::assertFalse($record['editable']);
    }

    #[Test]
    public function pageOutsideTheDbMountIsExcludedEntirelyDespiteFullPerms(): void
    {
        self::assertNull($this->findRecord('pages', 20));
    }

    #[Test]
    public function pageWithEditPermissionIsReadableAndEditable(): void
    {
        $record = $this->findRecord('pages', 10);
        self::assertNotNull($record);
        self::assertTrue($record['editable']);
    }

    #[Test]
    public function pageWithOnlyShowPermissionIsReadableButNotEditable(): void
    {
        $record = $this->findRecord('pages', 11);
        self::assertNotNull($record);
        self::assertFalse($record['editable']);
    }

    #[Test]
    public function fileMetadataOnAnUnmountedStorageIsExcludedEntirely(): void
    {
        self::assertNull($this->findRecord('sys_file_metadata', 3));
    }

    #[Test]
    public function fileMetadataOnAWritableMountIsReadableAndEditable(): void
    {
        $record = $this->findRecord('sys_file_metadata', 1);
        self::assertNotNull($record);
        self::assertTrue($record['editable']);
    }

    #[Test]
    public function fileMetadataOnAReadOnlyMountIsReadableButNotEditable(): void
    {
        $record = $this->findRecord('sys_file_metadata', 2);
        self::assertNotNull($record);
        self::assertFalse($record['editable']);
    }

    /** @return array{table: string, uid: int, pid: int, title: string, metadata: \B13\AiLabel\Domain\Model\AiMetadata, reviewBadge: string, editable: bool}|null */
    private function findRecord(string $table, int $uid): ?array
    {
        foreach ($this->get(AiMetadataRecordFinder::class)->findFlaggedRecords() as $record) {
            if ($record['table'] === $table && $record['uid'] === $uid) {
                return $record;
            }
        }
        return null;
    }
}
