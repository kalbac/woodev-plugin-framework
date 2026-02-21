# Woodev Framework Sub-Agents

**Version:** 1.0.0

---

## Description

This directory contains sub-agents for the Woodev Plugin Framework. Each sub-agent specializes in a specific development area and provides specialized knowledge for their domain.

**IMPORTANT:** This is a **framework** used by 10+ dependent plugins. Backward compatibility is critical.

## Available Sub-Agents

| Sub-Agent | File | Role | When to Use |
|-----------|------|------|-------------|
| `woodev-framework-env-agent` | `woodev-framework-env-agent.md` | Environment Management | Starting/stopping wp-env, checking status, cleaning |
| `woodev-framework-backend-agent` | `woodev-framework-backend-agent.md` | Backend PHP Development | Creating/modifying PHP code, classes, methods, hooks |
| `woodev-framework-dev-cycle-agent` | `woodev-framework-dev-cycle-agent.md` | Testing & Linting | Running tests, linting, code quality checks, Conventional Commits |
| `woodev-framework-git-agent` | `woodev-framework-git-agent.md` | Git Operations | Creating branches, commits (Conventional Commits), PRs, releases |
| `woodev-framework-code-review-agent` | `woodev-framework-code-review-agent.md` | Code Review | Code review, standards checking, **backward compatibility** |
| `woodev-framework-docs-agent` | `woodev-framework-docs-agent.md` | Documentation | Writing documentation, README, CLAUDE.md, markdown files |

## How Sub-Agents Work

Sub-agents are invoked **automatically** when the task matches their specialization. Simply describe the task clearly and specifically:

### Usage Examples

```
# Environment management
"Start the development environment"
→ woodev-framework-env-agent

# Backend development
"Create a new framework class with backward compatibility"
→ woodev-framework-backend-agent

# Dev Cycle
"Run linting on PHP files"
→ woodev-framework-dev-cycle-agent

# Git operations
"Create a new branch for bug fix"
→ woodev-framework-git-agent

# Code Review
"Review code for backward compatibility"
→ woodev-framework-code-review-agent

# Documentation
"Update README with new features description"
→ woodev-framework-docs-agent
```

## Development Workflow with Sub-Agents

```
1. Start task
   └─> woodev-framework-git-agent (create branch)

2. Start environment
   └─> woodev-framework-env-agent (wp-env start)

3. Write code
   └─> woodev-framework-backend-agent (follow standards, maintain BC)

4. Write tests
   └─> woodev-framework-backend-agent (BEFORE writing tests)

5. Check code
   └─> woodev-framework-dev-cycle-agent (composer phpcs)
   └─> woodev-framework-dev-cycle-agent (composer test:unit)
   └─> woodev-framework-dev-cycle-agent (composer test:integration)

6. Documentation
   └─> woodev-framework-docs-agent (README, CLAUDE.md)

7. Commit
   └─> woodev-framework-dev-cycle-agent (Conventional Commits format)

8. Push and PR
   └─> woodev-framework-git-agent (create PR)

9. Review
   └─> woodev-framework-code-review-agent (check standards + BC)

10. Stop environment (optional)
    └─> woodev-framework-env-agent (wp-env stop)
```

## Key Differences from Plugin Agents

| Aspect | Plugin | Framework |
|--------|--------|-----------|
| **Version** | `$version` property in main file | `VERSION` constant in `woodev/class-plugin.php` |
| **Release** | Manual tagging + release script | **Fully automatic** via GitHub Actions |
| **Changelog** | `pnpm changelog add` | **Auto-generated** by git-cliff from Conventional Commits |
| **Commands** | `pnpm lint:php`, `pnpm test:php` | `composer phpcs`, `composer test:unit`, `composer test:integration` |
| **Backward Compatibility** | Important | **CRITICAL** (10+ dependent plugins) |
| **Breaking Changes** | Avoid | **Require deprecation cycle + major version bump** |

## AI Agent Integration

Sub-agents are integrated with the following AI agents:

### Claude Code

- Configuration: `.claude/settings.json`
- Sub-agents available via `agents` section
- Automatic switching based on task description

### Cursor

- Configuration: `.cursor/rules/skills.mdc`
- Sub-agents described in `Woodev Framework Sub-Agents` section
- Used together with project skills

### Qwen Code

- Configuration: `.qwen/QWEN.md`
- Sub-agents described in available agents table
- Automatic activation by task context

## Sub-Agent File Structure

Each sub-agent file follows a unified structure:

```markdown
# {Agent Name}

**Role:** {Brief role description}

**Version:** {Version}

---

## Description

Detailed description of agent specialization.

## When to Use

List of tasks the agent is designed for.

## Working Principles

Key principles and standards the agent follows.

## Completion Checklist

Quality control checklist.

## Related Documentation

Links to relevant project documentation.
```

## Updating Sub-Agents

When updating sub-agents:

1. Update version in agent file
2. Update this index file if new agent added
3. Ensure all AI agents have up-to-date links
4. Document changes in commit messages (Conventional Commits)

## Related Documentation

- [CLAUDE.md](../../CLAUDE.md) — Main project documentation
- [.claude/skills/](../skills/) — Project skills
- [docs/README.md](../../docs/README.md) — Project documentation
- [.claude/settings.json](../../.claude/settings.json) — Claude Code settings
- [.cursor/rules/](../../.cursor/rules/) — Cursor rules
- [.qwen/QWEN.md](../../.qwen/QWEN.md) — Qwen Code settings
