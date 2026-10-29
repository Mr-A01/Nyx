<?php
declare(strict_types=1);
/**
 * Feature catalog: Moderation, Block, Reports, Privacy
 * Backend: legacy + modular ReportMessage + EraseUserData
 *
 * These Protocol-3 routes are delivered by LegacyRelayKernel
 * (legacy/relay.php) for full behavioral parity with 1.39.3.
 * Modular extracts live under /api/v3/modular/*.
 *
 * Routes:
 *  - POST /api/v3/users/block|unblock
 *  - POST /api/v3/block
 *  - POST /api/v3/reports|report
 *  - GET  /api/v3/admin/reports
 *  - POST /api/v3/admin/report/decide
 *  - POST /api/v3/modular/privacy/erase
 */

