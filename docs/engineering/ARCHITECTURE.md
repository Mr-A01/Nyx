# NYX Architecture

## Overview
NYX is a Federated E2EE Messenger Relay built with a hexagonal modular PHP architecture.

## Core Structure
- **Public Entry**: `public/index.php` serves as front controller routing requests via `app/Core/Routing/`.
- **Core Modules**: `app/Modules/` houses bounded contexts (Administration, Attachments, Audit, Conversations, Crypto, Devices, Federation, Identity, Messaging, Moderation, Notifications, Presence, Privacy, Search, Updates).
- **Core Infrastructure**: `app/Core/` contains Bootstrap, Container, Database, Http, Security, Routing, and Validation.
- **Storage**: SQLite databases stored under `storage/data/` or `data/`, backed by persistent Docker volume `nyx-data`.
- **Runtime**: PHP 8.3 FPM + Nginx web server via FastCGI.
