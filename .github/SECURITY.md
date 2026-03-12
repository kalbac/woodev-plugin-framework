# Security Policy

## Supported Versions

The following versions of the Woodev plugin are currently being supported with security updates:

| Version | Supported          |
| ------- | ------------------ |
| Latest  | :white_check_mark: |
| < Latest| :x:                |

**We recommend always using the latest version.**

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, please create a private vulnerability report using [GitHub's private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability) feature.

### What to Include

Please include the following information in your report:

- Description of the vulnerability
- Steps to reproduce the issue
- Potential impact of the vulnerability
- Any suggested fixes (if you have them)
- Your contact information for follow-up questions

### Response Time

You can expect a response within **48 hours** acknowledging your report. We will keep you informed of our progress as we investigate and address the issue.

### Disclosure Policy

- We will acknowledge your report within 48 hours
- We will provide a status update within 7 days
- We aim to resolve critical issues within 30 days
- We will coordinate with you on public disclosure timing

## Security Best Practices

When contributing to this project, please follow these security best practices:

1. **Validate all user input** — Never trust user input; always validate and sanitize
2. **Use WordPress functions** — Use `sanitize_text_field()`, `esc_html()`, `wp_nonce_field()`, etc.
3. **Check capabilities** — Always verify user permissions with `current_user_can()`
4. **Use prepared statements** — For database queries, use `$wpdb->prepare()`
5. **Avoid eval()** — Never use `eval()` or similar functions with user input
6. **Keep dependencies updated** — Regularly update all dependencies

## Security Headers

For additional security, consider implementing these headers on your server:

- `Strict-Transport-Security`
- `Content-Security-Policy`
- `X-Content-Type-Options`
- `X-Frame-Options`
- `X-XSS-Protection`

## Credits

We appreciate responsible disclosure and will credit security researchers who report valid vulnerabilities (unless they prefer to remain anonymous).

---

**Thank you for helping keep Woodev and its users safe!**
