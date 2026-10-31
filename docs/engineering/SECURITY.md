# NYX Security Specification

## Authentication & Authorization
- Token-based session authentication via `SessionAuthenticator` using cryptographically secure tokens.
- Password hashing using PHP standard `password_hash` (bcrypt/argon2).
- Rate limiting on sensitive endpoints (login, registration).

## E2EE & Cryptography
- Sodium-based end-to-end encryption for message payloads.
- Strict validation of message integrity and signatures.
- No plaintext storage of sensitive cryptographic materials or master keys.
- No hardcoded secrets or default test tokens in production code.
