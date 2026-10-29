# Feature catalog — NYX 1.39.3 → Messenger Pro 1.40

## Delivery model

| Layer | Role |
|-------|------|
| `legacy/relay.php` | Full Protocol 3 (152 routes) — production parity |
| `legacy/mesh_engine.php` | Topology, heal, friends |
| `legacy/update_engine.php` | Signed updates |
| `legacy/identity_api.php` | `/api/v1/*` |
| `app/Modules/*` | Extracted components + `/api/v3/modular/*` |
| `routes/api/v3/catalog/*` | One file per domain (documentation registry) |

## All 103 feature flags (present via LegacyRelayKernel)

presence, receipts, typing, events_poll, receipts_v2, dm_rate_limit, privacy_admin_blind, content_hash_v1, max_text_limit, report_client_helper, message_verify, ecdh_keys, e2ee_dm_preferred, e2ee_dm_required, no_server_signkeys, reports_v1, admin_no_private_read, user_spaces_fed, spaces_mine_federate, pins, reactions, peer_auth, profiles, mute, reply, search, edit, delete, notif_prefs, peer_health, mentions, rate_limit, auto_federate, block, space_settings, conversations, web_client, identity_vault, e2ee_web, landing, relay_discovery, peer_gossip, membership_fanout_v3, membership_remove_fanout, read_proof_acl, signed_payload_lock, private_sync_acl, attachment_metadata, live_broadcast, device_revoke, device_touch, membership_sync_v2, spaces_leave, attachment_upload, membership_repair, cors_hardened, web_push_subscribe, web_push_vapid_deliver, web_push_aes128gcm, sw_client, sqlite_dual_write, sqlite_payload_cutover, sqlite_backfill, cron_tick, attachments, rotate_secret, sqlite_maintenance, fed_queue_stats, prune_messages, anti_entropy, e2ee_required, max_body_limit, version_lock, ops_storage, push_notify_admin, messages_sync_hybrid, conversations_sqlite, conversations_batch_last, fts_rebuild, push_keys_in_queue, route_aliases, safe_verify_sign, ops_selftest, ops_metrics, ops_network, mesh_heal, topology_v2, friend_cap_6, trust_graph, update_apply, update_trust, deploy_identity, anti_entropy_all, sync_parity, cleanup_baks, push_queue_drain, push_on_send, fed_repair_all, fed_repair_conv, safe_device_attest, security_headers, cors_allowlist, peer_limits

## Module ownership

| Module | Owns |
|--------|------|
| Messaging | send, sync, edit, delete, react, pin, receipts, search, verify |
| Conversations | DM, mute/pin/archive, spaces lifecycle |
| Identity | register, sessions, profile, identity_vault, v1 API |
| Devices | register, revoke, touch, attest |
| Crypto | ecdh, prekeys, sodium, e2ee flags |
| Federation | topology, gossip, membership fanout, mesh heal |
| Presence | heartbeat, batch, typing, events poll |
| Notifications | prefs, in-app notifs, web push |
| Attachments | upload, meta, blob |
| Moderation | reports, block/unblock, admin decide |
| Privacy | erase, export, consent, admin blind |
| Search | message search, FTS rebuild |
| Updates | update-check, trust, apply |
| Administration | cron, deploy, prune, sqlite ops, backups |
| Audit | append-only logs |
