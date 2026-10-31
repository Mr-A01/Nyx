<?php
declare(strict_types=1);

namespace Nyx\Core\Support;

/**
 * Canonical feature list aligned with NYX 1.39.3-testnet / 1.40 modular.
 * All of these are served via LegacyRelayKernel for Protocol parity.
 */
final class FeatureRegistry
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            // Messaging core
            'presence', 'receipts', 'typing', 'events_poll', 'receipts_v2',
            'dm_rate_limit', 'max_text_limit', 'report_client_helper', 'message_verify',
            'pins', 'reactions', 'mute', 'reply', 'search', 'edit', 'delete',
            'mentions', 'conversations', 'messages_sync_hybrid',
            // E2EE / crypto
            'ecdh_keys', 'e2ee_dm_preferred', 'e2ee_dm_required', 'e2ee_required',
            'no_server_signkeys', 'e2ee_web', 'identity_vault', 'safe_verify_sign',
            'content_hash_v1', 'signed_payload_lock', 'private_sync_acl', 'read_proof_acl',
            // Spaces / social
            'user_spaces_fed', 'spaces_mine_federate', 'space_settings', 'profiles',
            'block', 'contacts',
            // Devices / push
            'device_revoke', 'device_touch', 'safe_device_attest',
            'web_push_subscribe', 'web_push_vapid_deliver', 'web_push_aes128gcm',
            'push_keys_in_queue', 'push_on_send', 'push_queue_drain', 'push_notify_admin', 'sw_client',
            // Federation / mesh
            'auto_federate', 'peer_auth', 'peer_health', 'peer_gossip', 'relay_discovery',
            'membership_fanout_v3', 'membership_remove_fanout', 'membership_sync_v2',
            'membership_repair', 'live_broadcast', 'mesh_heal', 'topology_v2',
            'friend_cap_6', 'trust_graph', 'anti_entropy', 'anti_entropy_all',
            'fed_queue_stats', 'fed_repair_all', 'fed_repair_conv', 'peer_limits',
            // Attachments
            'attachment_metadata', 'attachment_upload', 'attachments',
            // Ops / admin
            'rate_limit', 'cors_hardened', 'cors_allowlist', 'security_headers',
            'privacy_admin_blind', 'admin_no_private_read', 'reports_v1',
            'sqlite_dual_write', 'sqlite_payload_cutover', 'sqlite_backfill', 'sqlite_maintenance',
            'conversations_sqlite', 'conversations_batch_last', 'fts_rebuild',
            'cron_tick', 'rotate_secret', 'prune_messages', 'ops_storage',
            'ops_selftest', 'ops_metrics', 'ops_network', 'version_lock', 'max_body_limit',
            'update_apply', 'update_trust', 'deploy_identity', 'sync_parity', 'cleanup_baks',
            'route_aliases', 'web_client', 'landing', 'notif_prefs',
        ];
    }

    public static function has(string $feature): bool
    {
        return in_array($feature, self::all(), true);
    }

    /** @return array{version:string,protocol:int,count:int,features:list<string>} */
    public static function manifest(): array
    {
        $all = self::all();
        return [
            'version' => defined('NYX_VERSION') ? NYX_VERSION : '1.40.0',
            'protocol' => defined('NYX_PROTOCOL') ? NYX_PROTOCOL : 3,
            'count' => count($all),
            'features' => $all,
            'delivery' => 'legacy-relay-kernel + modular-extracts',
        ];
    }
}
