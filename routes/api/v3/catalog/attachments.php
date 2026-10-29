<?php
declare(strict_types=1);
/**
 * Feature catalog: Attachments & Uploads
 * Backend: legacy/relay.php + modular StoreAttachmentMeta
 *
 * These Protocol-3 routes are delivered by LegacyRelayKernel
 * (legacy/relay.php) for full behavioral parity with 1.39.3.
 * Modular extracts live under /api/v3/modular/*.
 *
 * Routes:
 *  - POST /api/v3/uploads
 *  - POST /api/v3/uploads/raw
 *  - POST /api/v3/media/upload|upload-raw
 *  - POST /api/v3/attachments/upload
 *  - GET  /api/v3/attachments/meta|blob
 */

