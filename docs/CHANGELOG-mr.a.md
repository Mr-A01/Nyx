# NYX mr.a Stabilization Changelog

## v1.45.1-mr.a — Production live fixes (acebet90)

### Messaging
- Server-side `display_name` / `username` enrichment on send + sync
- Always attach compact reactions on sync (username preferred)
- Collaborative edit for §POLL§ / §QUIZ§ / §TODO§ / §EVENT§ by space members
- Dual identity matching (username + user_id hash) for edit/react auth
- `reply_to` / `reply_to_id` dual support; preserve `forwarded_from`

### Client UI
- `specialPreviewText` — sidebar shows 📊 Poll / ❓ Quiz instead of raw JSON
- Reaction Who modal with resolved display names
- `window.closeModal` / `window.openModal` export (inline onclick safe)
- Poll vote persists via `editMessage` with `conv_id`
- Live mode default (no silent DEMO ADAPTER fallback)
- `isMyMessage` + dual user index for correct self/peer labels

### Conversations / DM
- Canonical DM id hashing; `peer_display` resolution
- Membership rebuild for accurate member_count
- add-member route aliases

### Security / Crypto / Search
- SecurityController (sessions, recovery, freeze)
- Crypto status / safety-number endpoints
- Global search routes

### Identity
- Register via `/auth/register`; mintLiveSession without fake tokens
- Syntax fix (double `async`); Rocket Loader guards
