# NYX Architecture Specification

## Overview
NYX follows a hexagonal modular architecture separating Core infrastructure from domain Modules (`app/Core` and `app/Modules`).
- **Core**: Bootstrap, Http routing, Security, Container, Database/Storage.
- **Modules**: Administration, Attachments, Chain, Conversations, Crypto, Devices, Federation, Gateway, Identity, Ledger, Messaging, Mining, Moderation, Notifications, Presence, Search, Settlement, Social.
- **Shared**: Infrastructure components (Cache, Events, Http, IdGenerator, Messaging, Storage).
- **Public & Routes**: `public/` entry points and `routes/` definitions.
