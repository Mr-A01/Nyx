# NYX Security Principles

## Invariants & Review Requirements
1. **Authentication & Authorization**: Verify token validity, admin credentials, and peer authorization on all protected routes.
2. **Session Handling**: Secure session cookies, proper timeout and revocation handling.
3. **Input Validation**: All incoming request payloads must be validated via `InputValidator` or strict type constraints.
4. **Cryptography & E2EE**: Use `ext-sodium` (`SodiumBox`, authenticated encryption). Never invent custom cryptographic protocols or stream ciphers.
5. **Secret Handling**: Never hardcode secrets; use environment variables (`.env`).
6. **Security Headers**: Ensure robust security headers (`SecurityHeaders.php`) are enforced on HTTP responses.
