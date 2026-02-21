---
name: woodev-framework-env
description: Manage wp-env Docker environment for Woodev Framework local development. Use when starting, stopping, or troubleshooting the WordPress test environment.
---

# Woodev Framework Environment Management

## When to Use This Skill

**ALWAYS invoke this skill when:**

- Starting local development environment
- Running tests that require WordPress/WooCommerce
- Troubleshooting wp-env issues
- Cleaning or rebuilding the environment
- Checking environment status

**DO NOT use this skill for:**

- Running linting (use `woodev-framework-dev-cycle`)
- Writing PHP code (use `woodev-framework-backend-dev`)
- Git operations (use `woodev-framework-git`)

---

## Overview

This skill provides guidance for managing the wp-env Docker environment used for local development and testing. The environment includes:

- **WordPress** (latest compatible version)
- **WooCommerce** (auto-installed via `.wp-env.json`)
- **PHP 7.4** (configured in `.wp-env.json`)
- **MySQL/MariaDB** database

---

## Environment Configuration

The environment is configured in `.wp-env.json`:

```json
{
    "core": null,
    "phpVersion": "7.4",
    "plugins": [
        "woocommerce",
        "."
    ],
    "config": {
        "WP_DEBUG": true,
        "SCRIPT_DEBUG": true
    },
    "env": {
        "tests": {
            "plugins": [
                "woocommerce",
                "."
            ]
        }
    }
}
```

**Key settings:**

- `core: null` — uses WordPress from WordPress.org (latest stable)
- `phpVersion: "7.4"` — PHP version for CLI and web
- `plugins` — auto-installs WooCommerce and current plugin
- `env.tests` — separate test environment configuration

---

## Commands

### Start Environment

```bash
# Start development environment
wp-env start

# Start with verbose output
wp-env start --debug

# Start and update to latest versions
wp-env start --update
```

### Stop Environment

```bash
# Stop all containers
wp-env stop

# Stop and destroy containers
wp-env destroy
```

### Check Status

```bash
# Check environment status
wp-env status

# View environment URLs
wp-env info
```

### Clean Environment

```bash
# Clean all containers and volumes
wp-env clean all

# Clean only database
wp-env clean database

# Clean only WordPress files
wp-env clean wordpress
```

### Run Commands in Environment

```bash
# Run WP-CLI command
wp-env run cli wp core version

# Run tests CLI command
wp-env run tests-cli --command="phpunit"

# Run command in specific container
wp-env run wordpress "php -v"
```

### Access Environment

```bash
# Open WordPress in browser (from info output)
# Usually: http://localhost:8888

# Access test environment
# Usually: http://localhost:8889

# Database connection (from info output)
# Host: localhost:8888 (mapped port)
# Database: wordpress
# User: wordpress
# Password: wordpress
```

---

## Development Workflow

### Standard Workflow

1. **Before coding:**

   ```bash
   # Ensure environment is running
   wp-env status

   # Start if needed
   wp-env start
   ```

2. **During development:**

   ```bash
   # Access WordPress admin
   # URL: http://localhost:8888/wp-admin

   # Run WP-CLI commands
   wp-env run cli "wp plugin list"
   ```

3. **Before running integration tests:**

   ```bash
   # Ensure test environment is running
   wp-env status

   # Start if needed
   wp-env start
   ```

4. **After testing:**

   ```bash
   # Stop to save resources
   wp-env stop
   ```

---

## Testing Environment

### Running Integration Tests

```bash
# Start environment (REQUIRED)
wp-env start

# Run all integration tests
composer test:integration

# Or using wp-env directly
wp-env run tests-cli --command="phpunit"

# Run specific test class
wp-env run tests-cli --command="phpunit --filter TestClassName"

# Run tests with coverage
wp-env run tests-cli --command="phpunit --coverage-html ./coverage"
```

### Test Environment Setup

The test environment is separate from development:

- **Development:** `http://localhost:8888`
- **Tests:** `http://localhost:8889`

Both environments have WooCommerce installed.

---

## Troubleshooting

### Environment Won't Start

**Problem:** `wp-env start` fails

**Solutions:**

1. **Check Docker is running:**

   ```bash
   docker ps
   ```

2. **Clean and rebuild:**

   ```bash
   wp-env destroy
   wp-env start
   ```

3. **Check for port conflicts:**

   ```bash
   # Ports 8888, 8889 should be free
   netstat -ano | findstr :8888
   netstat -ano | findstr :8889
   ```

### Tests Fail Unexpectedly

**Problem:** Tests pass locally but fail in environment

**Solutions:**

1. **Ensure clean database:**

   ```bash
   wp-env clean database
   wp-env start
   ```

2. **Rebuild test environment:**

   ```bash
   wp-env destroy
   wp-env start --update
   ```

3. **Check plugin is loaded:**

   ```bash
   wp-env run cli "wp plugin list"
   ```

### WooCommerce Not Found

**Problem:** WooCommerce not installed in environment

**Solutions:**

1. **Verify `.wp-env.json`:**

   ```json
   {
       "plugins": [
           "woocommerce",
           "."
       ]
   }
   ```

2. **Rebuild environment:**

   ```bash
   wp-env destroy
   wp-env start
   ```

3. **Manually install WooCommerce:**

   ```bash
   wp-env run cli "wp plugin install woocommerce --activate"
   ```

### PHP Version Mismatch

**Problem:** Wrong PHP version in environment

**Solutions:**

1. **Check `.wp-env.json`:**

   ```json
   {
       "phpVersion": "7.4"
   }
   ```

2. **Rebuild with correct version:**

   ```bash
   wp-env destroy
   wp-env start
   ```

3. **Verify PHP version:**

   ```bash
   wp-env run cli "php -v"
   ```

---

## Quick Command Reference

### Most Common Commands

```bash
# Start environment
wp-env start

# Stop environment
wp-env stop

# Check status
wp-env status

# View URLs and connection info
wp-env info

# Clean everything
wp-env clean all

# Run WP-CLI
wp-env run cli "wp core version"

# Run integration tests
composer test:integration

# Destroy and rebuild
wp-env destroy && wp-env start
```

---

## Integration with Dev Cycle

This skill integrates with `woodev-framework-dev-cycle`:

1. **Before running integration tests** — ensure environment is running
2. **Before linting** — environment not required (uses local PHP)
3. **After testing** — can stop environment to save resources

### Automated Workflow

```bash
# Full dev cycle with environment management
wp-env start
composer check
composer test:integration
wp-env stop
```

---

## Notes

- **Docker required** — ensure Docker Desktop is installed and running
- **Ports 8888/8889** — must be available for wp-env
- **WooCommerce auto-installed** — via `.wp-env.json` configuration
- **Separate environments** — development (8888) and tests (8889)
- **PHP 7.4** — configured for compatibility requirements
