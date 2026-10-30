<?php
/**
 * Scale feature flags (safe defaults for shared hosting).
 * Override via environment variables without code changes.
 */
return [
    // sqlite | file
    "message_db" => getenv("MESSAGE_DB") ?: "sqlite",
    // redis optional — set REDIS_URL or REDIS_HOST to enable
    "redis_url" => getenv("REDIS_URL") ?: "",
    "upload_signing" => true,
    "canonical_public_only" => true,
];
