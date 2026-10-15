---
title: Contributing
description: How to contribute code, fixes and documentation to the Woodev Plugin Framework.
---

Contributions are welcome from anyone who builds or maintains WooCommerce plugins: bug reports, fixes, new capabilities and documentation.

Three rules matter most:

1. **Never change data that installed sites already store.** Option keys, method IDs, meta keys, hook names, cron hooks, REST namespaces, AJAX actions and admin slugs stay byte-for-byte identical. Framework internals can change; this data cannot.
2. **Every pull request needs green CI.** A maintainer reviews only pull requests whose checks all pass, and only the maintainer merges.
3. **Use Conventional Commits.** The changelog is generated from commit messages, so never edit it by hand and never change `Woodev_Plugin::VERSION` — raising it publishes a release.

Environment setup, coding standards, the checks to run and the documentation rules are in the full [contributing guide on GitHub](https://github.com/kalbac/woodev-plugin-framework/blob/main/.github/CONTRIBUTING.md). Security problems are reported privately, as described in the [security policy](https://github.com/kalbac/woodev-plugin-framework/blob/main/.github/SECURITY.md), never in a public issue.
