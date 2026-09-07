# Security policy

## Supported versions

| Version | Supported |
|---|---|
| `1.x` | yes |
| `dev-main` | yes, best effort |

## Reporting a vulnerability

Do not open a public issue for a security problem.

Use GitHub's private reporting form on
[the Security tab](https://github.com/php-spellcheck/spellcheck-symfony-bundle/security/advisories/new),
or write to <raffaele.carelle@gmail.com>.

Please include the version, the PHP and Symfony versions, the backend in use
and the smallest input that reproduces the problem. You will get a first answer
within seven days, and a fix or a public advisory once the cause is confirmed.

## Scope

This bundle runs on developer machines and in CI, and it reads project files
and spawns spell checking binaries. Reports that matter most are therefore:

- command injection through configuration, dictionary paths or file names;
- path traversal that reads files outside the configured paths;
- a backend that sends project text to a remote service without a diagnostic;
- code execution while parsing an untrusted PHP file or translation catalogue.
