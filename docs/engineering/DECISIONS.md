# NYX Architectural Decisions (ADR)

## ADR-001: Hexagonal Modular PHP Architecture
- **Status**: Accepted
- **Context**: NYX requires clean separation between domain logic, application use cases, and infrastructure adapters.
- **Decision**: Organize application code into `app/Core/` and modular domain bounded contexts under `app/Modules/`.

## ADR-002: SQLite Storage with Docker Volumes
- **Status**: Accepted
- **Context**: Lightweight, file-backed persistence suitable for relay and federated messaging nodes.
- **Decision**: Use SQLite with persistent Docker volume `nyx-data`.
