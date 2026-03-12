# Changelog

All notable changes to Woodev Plugin Framework are documented here.
## [1.4.1] — 2026-03-12
### Bug Fixes
- Enforce WordPress coding standards across framework codebase

- Resolve static analysis and code quality issues

- Resolve all PHPCS errors blocking CI

- Suppress PHPCS warning exit code in CI

- Increase PHPStan memory limit to 1G for WooCommerce stubs

- Disable PHPStan parallel workers to prevent OOM crashes

- Use 2G memory limit for PHPStan with WooCommerce stubs

- Correct PHPStan type error in token editor filter docblock

- Add setAccessible(true) for PHP 7.4/8.0 Reflection compatibility

- Add missing setAccessible(true) in test_singleton_reset_via_reflection


### CI/CD
- Fix failing GitHub Actions workflows


### Documentation
- Expand CLAUDE.md, revise AI agents/skills, and update tooling config


### Features
- **payment-tokens**: Auto-invalidate token cache on WooCommerce token events

- **shipping**: Implement admin notice handlers for countries, debug mode, and plugin configuration


### Refactoring
- Move Woodev_Plugin_Compatibility to compatibility/ directory


### Tests
- Restructure fixtures and improve integration test bootstrap




