# NYX Testing and Verification

## Verification Procedures
1. **PHP Syntax Check**: `php -l <file>` for syntax validation.
2. **Unit & Feature Tests**: Run test suite via PHPUnit (if configured) or custom self-tests.
3. **Runtime Checks**: Verify health endpoints and application bootstrap via `php -f public/index.php` or container logs.
4. **Observed Results**: Never report test success without direct observation of execution output.
