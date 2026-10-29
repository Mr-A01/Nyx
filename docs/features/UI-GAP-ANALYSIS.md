# UI gap analysis (`client.html` / `status.html` / `identity.html`)

Scanned against Protocol 3 relay routes and feature flags.

## Fully backed by API (usable)

| UI capability | API evidence |
|---------------|--------------|
| Send / sync messages | `/api/v3/messages/send`, `sync` |
| Edit / delete / forward | `edit`, `delete`, `forward` |
| Reactions / pins | `react`, `pin`, `unpin`, `/api/v3/pins` |
| Reply / mentions (client-side + server fields) | message payload + search |
| Read receipts | `messages/read`, `read-state`, `receipts` |
| Typing indicators | `/api/v3/typing` |
| Presence / heartbeat | `presence/heartbeat`, `presence/batch` |
| DM create | `/api/v3/dm` |
| Groups / channels (spaces) | full `/api/v3/spaces/*` set |
| Invites / join / kick / ban / roles | spaces/* |
| Mute / pin / archive conversations | `conversations/*` |
| Block / unblock | `users/block`, `unblock` |
| Report message | `/api/v3/reports` |
| Search users / messages / spaces | `users/search`, `messages/search`, `spaces/search` |
| Profile | `/api/v3/profile` |
| ECDH keys / E2EE web | `/api/v3/keys/ecdh` |
| Devices & session revoke | `auth/devices`, `sessions/revoke*` |
| Push subscribe | `push/subscribe`, `vapid-public` |
| Notifications list | `/api/v3/notifications` |
| Uploads / attachments | `/api/v3/uploads`, `attachments/*` |
| Contacts | `/api/v3/contacts` |
| Account delete | `/api/v3/account/delete` |
| Live updates wait | `/api/v3/updates/wait` |
| Theme / drafts / offline UX | client-local (indexed/local storage) — OK without server |

## Partial / needs care

| UI area | Gap | Recommendation |
|---------|-----|----------------|
| **Voice messages** | UI records audio; server treats as upload/attachment. No dedicated `/voice` route. | Keep as attachment with `mime=audio/*`; document in client. Optional: transcoding worker later. |
| **Service worker / offline outbox** | `sw_client` flag exists; SW file provided as `public/sw.js` (shell cache; API still network-only). | Add `public/sw.js` if not present; queue failed POSTs. |
| **WebSocket live transport** | Client prefers poll; `__NYX_ENABLE_WS__` off on shared hosting. | Keep HTTP poll; enable WS only on VPS. |

## Not in 1.39.3 product (UI stubs or absent)

| Item | Notes |
|------|-------|
| **Wallet / blockchain / payments** | Count 0 in client — not part of NYX relay product. Out of scope unless product expands. |
| **Audio/video calls** | Almost no UI (`call`~3, `video`~1) — not implemented server-side. Future: WebRTC SFU module. |
| **Landing marketing page** | Feature flag `landing`; optional static page. |

## Conclusion

All **product features of 1.39.3** that the official client uses are available through the Pro project via `LegacyRelayKernel`.  
Modular extracts add parallel paths under `/api/v3/modular/*` without removing parity.
