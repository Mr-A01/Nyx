<?php
declare(strict_types=1);
/**
 * Feature catalog: Presence, Notifications, Push
 * Backend: legacy/relay.php + modular PresenceService
 *
 * These Protocol-3 routes are delivered by LegacyRelayKernel
 * (legacy/relay.php) for full behavioral parity with 1.39.3.
 * Modular extracts live under /api/v3/modular/*.
 *
 * Routes:
 *  - POST /api/v3/presence/heartbeat
 *  - GET  /api/v3/presence/batch
 *  - GET  /api/v3/events/poll
 *  - GET  /api/v3/notifications
 *  - POST /api/v3/notifications/read-all
 *  - GET|POST /api/v3/prefs|notif/prefs
 *  - GET  /api/v3/push/vapid-public
 *  - POST /api/v3/push/subscribe|unsubscribe
 */

