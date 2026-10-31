# NYX Testing Protocol

## Commands
- **Syntax Verification**: `find app config routes public -name '*.php' -exec php -l {} \;`
- **Unit & Feature Tests**: Run PHPUnit test suites against sqlite / test fixtures.
- **Health Check**: `php -f public/index.php` or equivalent self-test.
