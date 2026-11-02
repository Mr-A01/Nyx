## Summary
<!-- What does this PR change and why? -->

## Type of change
- [ ] Bug fix
- [ ] New feature
- [ ] Security hardening
- [ ] Documentation / repo polish
- [ ] Refactor (no behavior change)

## Test plan
- [ ] `GET /api/v3/ops/selftest` → ok
- [ ] Relevant API paths exercised (list below)
- [ ] No new public unauthenticated data leaks

## Security checklist (if touching auth / messaging / federation)
- [ ] Identity only from `AuthContext` / session (never body `user_id`)
- [ ] `AccessGuard` on read/write conversation paths
- [ ] No SSRF / path traversal introduced

## Screenshots / notes
<!-- Optional -->

## Related issues
Closes #
