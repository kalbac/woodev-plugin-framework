# Woodev Framework AI Agents

Configuration files for AI-assisted development of the Woodev Plugin Framework.

## Agents

| Agent | File | Purpose |
|-------|------|---------|
| Backend Dev | `woodev-framework-backend-agent.md` | PHP development, architecture, naming conventions |
| Code Review | `woodev-framework-code-review-agent.md` | PR review, security audit, quality checks |
| Dev Workflow | `woodev-framework-dev-workflow-agent.md` | Environment, testing, linting, commits |
| Git | `woodev-framework-git-agent.md` | Branching, PRs, releases |
| Docs | `woodev-framework-docs-agent.md` | Documentation, PHPDoc, CLAUDE.md |

## Cross-Tool Compatibility

These agents are designed to work with multiple AI coding tools:

### Claude Code

Agents are supplementary to `CLAUDE.md` (project root), which is automatically loaded. Reference agents manually when needed for specialized tasks.

### Cursor

Add agents as context via `@` mentions in Cursor chat, or configure them in `.cursorrules`.

### Qwen / Windsurf

Agents can be loaded via MCP tools (Serena) or referenced in project configuration files.

## Architecture

```
.ai/
  agents/          # This directory - role-specific agent instructions
  skills/          # Detailed knowledge bases referenced by agents
CLAUDE.md          # Single source of truth for project knowledge (AI tools)
```

### Information Hierarchy

1. **CLAUDE.md** - Project overview, architecture, commands, code style
2. **Agents** (this directory) - Role-specific instructions and checklists
3. **Skills** (`.ai/skills/`) - Detailed patterns, examples, and reference material

Agents reference CLAUDE.md and skills to avoid duplication. Each piece of information exists in ONE place only.

## Adding a New Agent

1. Create `woodev-framework-{name}-agent.md` in this directory
2. Include Role, Version, and Scope in the header
3. Reference shared knowledge via "See CLAUDE.md > Section" instead of duplicating
4. Keep the file under 100 lines
5. Update the agent table above
