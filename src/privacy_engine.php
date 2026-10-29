<?php
declare(strict_types=1);
/**
 * NYX Privacy Engine — modular message sealing for DMs
 * Version: 1.40.0
 *
 * Rules:
 *  - dm_* conversations: never persist plaintext server-side
 *  - store ciphertext + content_hash only
 *  - content_hash = sha256(ciphertext) when client hash missing
 */
if (!defined('DATA_DIR')) {
    return;
}

/** @return string hex sha256 */
function privacy_content_hash(string $ciphertext): string {
    return hash('sha256', $ciphertext);
}

/**
 * Seal a message record for private (DM) conversations in-place.
 * Safe to call for any conversation type — no-ops for non-dm.
 *
 * @param array $msg message record (plaintext/ciphertext/content_hash)
 * @param string $conv conversation_id
 */
function privacy_seal_dm_message(array &$msg, string $conv): void {
    if (!str_starts_with($conv, 'dm_')) {
        return;
    }
    $ct = $msg['ciphertext'] ?? null;
    if (is_string($ct) && $ct !== '') {
        $msg['plaintext'] = null;
        $msg['text'] = null;
        $msg['e2ee'] = true;
        if (empty($msg['content_hash'])) {
            $msg['content_hash'] = privacy_content_hash($ct);
        }
        if (!isset($msg['integrity']) || !is_array($msg['integrity'])) {
            $msg['integrity'] = [
                'alg' => 'sha256',
                'content_hash' => $msg['content_hash'],
                'sealed_at_ms' => function_exists('now_ms') ? now_ms() : (int)round(microtime(true) * 1000),
            ];
        }
    }
}

/**
 * Apply privacy rules to request body fields before persist.
 * @param array $b request body (plaintext, ciphertext, content_hash)
 * @param string $conv conversation_id
 */
function privacy_seal_request_body(array &$b, string $conv): void {
    if (!str_starts_with($conv, 'dm_')) {
        return;
    }
    $ct = $b['ciphertext'] ?? null;
    if (is_string($ct) && $ct !== '') {
        $b['plaintext'] = null;
        if (empty($b['content_hash'])) {
            $b['content_hash'] = privacy_content_hash($ct);
        }
    }
}

/**
 * Module metadata for modular health/features.
 */
function privacy_module_info(): array {
    return [
        'name' => 'privacy_engine',
        'version' => '1.40.0',
        'dm_plaintext' => false,
        'dm_hash' => 'sha256',
        'e2ee_prefix' => ['e2ee1:', 'e2ee2:'],
    ];
}
