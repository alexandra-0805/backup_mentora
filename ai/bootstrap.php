<?php

/**
 * Load every Mentora AI feature. Pages include this one file:
 *   require_once __DIR__ . '/../ai/bootstrap.php';
 */

declare(strict_types=1);

require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/text_extract.php';
require_once __DIR__ . '/skills.php';
require_once __DIR__ . '/resume_review.php';
require_once __DIR__ . '/intern_assessment.php';
require_once __DIR__ . '/agent_report_summary.php';
