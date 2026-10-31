<?php
declare(strict_types=1);
/**
 * Feature catalog: Ops, Admin, Updates
 * Backend: legacy/update_engine.php + modular SystemHealth/SelfTest
 *
 * These Protocol-3 routes are delivered by LegacyRelayKernel
 * (legacy/relay.php) for full behavioral parity with 1.39.3.
 * Modular extracts live under /api/v3/modular/*.
 *
 * Routes:
 *  - GET  /api/v3/ops/info|metrics|network|storage|selftest|update-check|health-dashboard
 *  - GET  /api/v3/updates/wait|stream
 *  - POST /api/v3/admin/* (deploy, backup, prune, sqlite, push-queue, rotate-secret, ...)
 *  - GET  /api/v3/modular/health|selftest
 */

