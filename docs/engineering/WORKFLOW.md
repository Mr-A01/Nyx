# NYX Git & Release Workflow

1. **Reference Branch**: `main` is the official source of truth.
2. **User Development Branch**: `MAHDI` is the integration branch for user features and synchronizations.
3. **Feature Branches**: Create dedicated branches for individual features or bugfixes from `MAHDI` or `main`.
4. **Sync Process**: Regularly fetch `origin`, merge `origin/main` into working branches, resolve conflicts carefully preserving valuable domain logic without compromising security.
5. **Review & PR**: Open Pull Requests for code review before merging into `main`. Never force push to shared branches.
