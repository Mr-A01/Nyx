<?php
declare(strict_types=1);
/**
 * Feature catalog: Conversations & Spaces
 * Backend: legacy/relay.php
 *
 * These Protocol-3 routes are delivered by LegacyRelayKernel
 * (legacy/relay.php) for full behavioral parity with 1.39.3.
 * Modular extracts live under /api/v3/modular/*.
 *
 * Routes:
 *  - GET  /api/v3/conversations
 *  - POST /api/v3/conversations/mute
 *  - POST /api/v3/conversations/pin
 *  - POST /api/v3/conversations/archive
 *  - POST /api/v3/conversations/prefs
 *  - POST /api/v3/conversations/read
 *  - POST /api/v3/conversations/repair
 *  - POST /api/v3/dm
 *  - POST /api/v3/spaces
 *  - GET  /api/v3/spaces/mine
 *  - GET  /api/v3/spaces/discover
 *  - GET  /api/v3/spaces/search
 *  - GET  /api/v3/spaces/get|preview|lookup|members
 *  - POST /api/v3/spaces/join|join-by-id|join-invite|leave
 *  - POST /api/v3/spaces/invite|kick|ban|unban|set-role|settings|transfer
 *  - GET  /api/v3/spaces/join-requests
 *  - POST /api/v3/spaces/join-request/decide
 */

