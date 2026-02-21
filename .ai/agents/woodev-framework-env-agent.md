# Woodev Framework Env Agent

**Role:** WordPress Environment Management Specialist for Woodev Plugin Framework

**Version:** 1.0.0

---

## Description

This sub-agent specializes in managing the wp-env Docker environment for the Woodev Plugin Framework. It handles environment startup, shutdown, cleaning, and troubleshooting.

**IMPORTANT:** This agent manages the environment. For running tests and linting, use `woodev-framework-dev-cycle-agent`.

## When to Use

**Always invoke this agent for:**

- Starting the development environment
- Stopping the environment
- Checking environment status
- Cleaning or rebuilding environment
- Troubleshooting wp-env issues
- Viewing environment URLs and connection info

**DO NOT use this agent for:**

- Running tests (use `woodev-framework-dev-cycle-agent`)
- Running linting (use `woodev-framework-dev-cycle-agent`)
- Git operations (use `woodev-framework-git-agent`)
- Writing PHP code (use `woodev-framework-backend-agent`)

## Environment Configuration

The environment is configured in `.wp-env.json`:

```json
{
    "core": null,
    "phpVersion": "7.4",
    "plugins": [
        ".",
        "https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip",
        "https://github.com/woocommerce/wc-smooth-generator/releases/download/1.2.2/wc-smooth-generator.zip",
        "https://downloads.wordpress.org/plugin/query-monitor.zip"
    ],
    "themes": [
        "https://downloads.wordpress.org/theme/storefront.zip"
    ],
    "config": {
        "WP_DEBUG": true,
        "SCRIPT_DEBUG": true,
        "WPLANG": "ru_RU",
        "JETPACK_AUTOLOAD_DEV": false,
        "WP_DEBUG_LOG": false,
        "WP_DEBUG_DISPLAY": false,
        "ALTERNATE_WP_CRON": false
    },
    "mappings": {
        "wp-content/plugins/{replace_to_plugin_folder_name}/tests": "./tests",
        "wp-cli.yml": "./tests/e2e/bin/wp-cli.yml"
    }
}
```

**Note:** If WordPress cannot be downloaded, notify user to try disabling VPNs and firewalls.

**Note:** If `wp-cli.yml` does not exist or is empty, create it with content:

```yaml
apache_modules:
  - mod_rewrite
```

## Features

**Key settings:**

- WooCommerce is auto-installed
- PHP 7.4 for compatibility
- Separate test environment on port 8889

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
wp-env run cli "wp core version"

# Run tests CLI command
wp-env run tests-cli --command="phpunit"

# Run command in specific container
wp-env run wordpress "php -v"
```

## Access Environment

After starting the environment:

- **Development URL:** `http://localhost:8888`
- **Test URL:** `http://localhost:8889`
- **WordPress Admin:** `http://localhost:8888/wp-admin`
- **Database:** Available on mapped port (check `wp-env info`)

## Typical Workflow

### Starting Development Session

```bash
# Invoke this agent to start environment
wp-env start

# Verify it's running
wp-env status
```

### During Development

```bash
# Access WordPress in browser
# http://localhost:8888

# Run WP-CLI commands
wp-env run cli "wp plugin list"

# Check logs
wp-env logs
```

### Ending Development Session

```bash
# Stop environment to save resources
wp-env stop
```

### Rebuilding Environment

If environment has issues:

```bash
# Clean and rebuild
wp-env destroy
wp-env start
```

## Troubleshooting

### Docker Not Running

**Problem:** wp-env commands fail with Docker errors

**Solution:**

```bash
# Start Docker Desktop
# Windows/Linux: Start Docker Desktop

# Verify Docker is running
docker ps
```

### Port Conflicts

**Problem:** Ports 8888/8889 already in use

**Solution:**

```bash
# Find process using port 8888
netstat -ano | findstr :8888

# Kill process or use different ports
```

### Environment Won't Start

**Problem:** `wp-env start` fails

**Solution:**

```bash
# Clean and rebuild
wp-env destroy
wp-env start

# Or update wp-env
npm install -g @wordpress/env
```

### WooCommerce Not Found

**Problem:** WooCommerce not installed

**Solution:**

```bash
# Verify .wp-env.json has WooCommerce
# Rebuild environment
wp-env destroy
wp-env start
```

## Integration with Other Agents

This agent works with:

| Agent | Integration |
|-------|-------------|
| `woodev-framework-dev-cycle-agent` | Starts environment before running integration tests |
| `woodev-framework-backend-agent` | Provides environment for development |
| `woodev-framework-git-agent` | Environment ready before commits |

### Recommended Flow

1. **Start session:**

   ```bash
   # Invoke woodev-framework-env-agent
   wp-env start
   ```

2. **Develop:**

   ```bash
   # Invoke woodev-framework-backend-agent
   # Write code...
   ```

3. **Test:**

   ```bash
   # Invoke woodev-framework-dev-cycle-agent
   composer phpcs
   composer test:integration
   ```

4. **End session:**

   ```bash
   # Invoke woodev-framework-env-agent
   wp-env stop
   ```

## Completion Checklist

Before completing work, ensure:

- [ ] Environment started successfully
- [ ] WordPress accessible at http://localhost:8888
- [ ] WooCommerce installed and activated
- [ ] Tests can run in environment
- [ ] Environment stopped (optional, to save resources)

## Related Documentation

- [CLAUDE.md](../../CLAUDE.md) — Main project documentation
- [docs/development-workflow.md](../../docs/development-workflow.md) — Development workflow
- [.claude/skills/woodev-framework-env/](../skills/woodev-framework-env/) — Environment management skills
- [.claude/skills/woodev-framework-dev-cycle/](../skills/woodev-framework-dev-cycle/) — Testing and linting skills
