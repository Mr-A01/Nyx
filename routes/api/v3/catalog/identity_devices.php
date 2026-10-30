<?php
declare(strict_types=1);
/**
 * Feature catalog: Identity, Auth, Devices, Sessions
 * Backend: legacy/relay.php + legacy/identity_api.php
 *
 * These Protocol-3 routes are delivered by LegacyRelayKernel
 * (legacy/relay.php) for full behavioral parity with 1.39.3.
 * Modular extracts live under /api/v3/modular/*.
 *
 * Routes:
 *  - POST /api/v3/auth/register
 *  - POST /api/v3/auth/challenge
 *  - GET|POST /api/v3/auth/devices
 *  - POST /api/v3/auth/devices/revoke|touch
 *  - GET|POST /api/v3/devices
 *  - GET|POST /api/v3/sessions
 *  - POST /api/v3/sessions/revoke|revoke-others
 *  - POST /api/v3/account/delete
 *  - GET|POST /api/v1/* (identity_api)
 *  - GET|POST /api/v3/keys/ecdh|prekey|device
 *  - GET /api/v3/crypto/pubkey
 *  - GET|POST /api/v3/profile
 */

