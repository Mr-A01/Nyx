<?php
declare(strict_types=1);
/**
 * Feature catalog: Messaging
 * Backend: legacy/relay.php (Protocol 3) + modular SendMessageHandler
 *
 * These Protocol-3 routes are delivered by LegacyRelayKernel
 * (legacy/relay.php) for full behavioral parity with 1.39.3.
 * Modular extracts live under /api/v3/modular/*.
 *
 * Routes:
 *  - POST /api/v3/messages/send
 *  - GET  /api/v3/messages/sync
 *  - POST /api/v3/messages/edit
 *  - POST /api/v3/messages/delete
 *  - POST /api/v3/messages/forward
 *  - POST /api/v3/messages/react
 *  - POST /api/v3/messages/pin
 *  - POST /api/v3/messages/unpin
 *  - POST /api/v3/messages/read
 *  - GET  /api/v3/messages/read-state
 *  - POST /api/v3/messages/receipt
 *  - GET  /api/v3/messages/search
 *  - POST /api/v3/messages/verify
 *  - POST /api/v3/messages/ack
 *  - POST /api/v3/typing
 *  - GET  /api/v3/typing
 *  - GET  /api/v3/pins
 */

