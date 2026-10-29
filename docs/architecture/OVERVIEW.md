# NYX Architecture Overview

NYX Messenger Pro uses a **hexagonal (ports & adapters)** layout with explicit module boundaries.

## Layers

1. **Presentation** — HTTP / WebSocket controllers, form requests, API resources  
2. **Application** — use-cases, DTOs, queries, handlers, validators  
3. **Domain** — entities, value objects, domain events, policies, repository ports  
4. **Infrastructure** — persistence, sodium crypto, mesh transport, queue, push  

## Core vs Modules

- `app/Core` never depends on a module.
- Modules may depend on Core + Shared.
- Modules must not depend on each other’s Infrastructure (only Domain contracts / events).

## Runtime

`public/index.php` → `bootstrap/app.php` → `Application` → Router → Module controller / closure.

## Federation

The **Federation** module owns gossip, membership fan-out, topology, and peer health.  
It replaces the monolithic `mesh_engine.php` while remaining compatible with Protocol 3 peers.
