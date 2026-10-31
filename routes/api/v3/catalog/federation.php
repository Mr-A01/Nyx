<?php
declare(strict_types=1);
/**
 * Feature catalog: Federation & Mesh
 * Backend: legacy/relay.php + legacy/mesh_engine.php
 *
 * These Protocol-3 routes are delivered by LegacyRelayKernel
 * (legacy/relay.php) for full behavioral parity with 1.39.3.
 * Modular extracts live under /api/v3/modular/*.
 *
 * Routes:
 *  - GET  /api/v3/federation/topology
 *  - GET  /api/v3/federation/peers|mesh|identity
 *  - POST /api/v3/federation/gossip|broadcast|membership|relay
 *  - GET  /api/v3/federation/peers/health
 *  - POST /api/v3/admin/mesh-heal
 *  - POST /api/v3/admin/topo-recompute
 *  - POST /api/v3/admin/anti-entropy-all
 *  - GET  /api/v3/discovery/relays
 *  - POST /api/v3/discovery/ping
 */

