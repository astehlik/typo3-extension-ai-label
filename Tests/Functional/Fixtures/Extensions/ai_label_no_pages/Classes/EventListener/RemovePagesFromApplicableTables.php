<?php

declare(strict_types=1);

namespace B13\AiLabelNoPages\EventListener;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Event\ApplicableTablesEvent;

final class RemovePagesFromApplicableTables
{
    public function __invoke(ApplicableTablesEvent $event): void
    {
        $event->removeApplicableTable('pages');
    }
}
