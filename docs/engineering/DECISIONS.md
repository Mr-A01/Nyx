# Architectural Decision Records (ADR)

1. **Source of Truth**: `main` branch is the canonical reference.
2. **Modular Hexagonal Architecture**: Core and Modules separation for maintainability and testability.
3. **Security First**: Elimination of all mock authentication bypasses, fixed verification codes, and debug flags in production configurations.
4. **Local Work-Chain v2**: Each relay maintains its own local verification chain; global consensus relies on settlement batches rather than heavy global BFT/PoW.
