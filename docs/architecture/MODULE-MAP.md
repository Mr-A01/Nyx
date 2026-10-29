# Module map

| Module | Responsibility |
|--------|----------------|
| Administration | Ops health, info, metrics, network probe |
| Messaging | Send/sync/receipts/events |
| Conversations | Spaces, DM, members, roles |
| Identity | Registration, sessions, keys |
| Devices | Device list / revoke |
| Presence | Online, typing |
| Federation | Peer mesh, gossip fanout |
| Crypto | Prekeys, E2EE helpers |
| Attachments | Upload/download |
| Moderation | Block, report |
| Search | Users / messages |
| Notifications | Push prefs |
| Privacy | Preferences |
| Updates | Soft update channel |
| Audit | Admin audit trail |

Core (`app/Core`) owns HTTP kernel, router, container, validation, security headers.
