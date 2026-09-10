# PHPSpellcheckBundle — spell check Symfony translations and PHP code

[![CI](https://github.com/php-spellcheck/spellcheck-symfony-bundle/actions/workflows/ci.yaml/badge.svg)](https://github.com/php-spellcheck/spellcheck-symfony-bundle/actions/workflows/ci.yaml)
[![PHP](https://img.shields.io/badge/php-8.1%20%7C%208.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-777bb4?logo=php&logoColor=white)](https://www.php.net/supported-versions.php)
[![Symfony](https://img.shields.io/badge/symfony-5.4%20%7C%206.4%20%7C%207.x%20%7C%208.x-000000?logo=symfony&logoColor=white)](https://symfony.com/releases)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

A Symfony bundle that spell checks **every translation catalogue with the
dictionary of its own locale**, and the **PHP code** of the project (class
names, methods, properties, parameters, constants, docblocks, comments) — from
the console, or as a failing build in CI.

It is the Symfony integration of
[`php-spellcheck/spellcheck-core`](https://github.com/php-spellcheck/spellcheck-core),
the framework-agnostic engine.

- Symfony 5.4 LTS, 6.4 LTS, 7.x and 8.x — PHP 8.1 up to 8.5 (Symfony 8.x
  itself needs PHP ≥ 8.4)
- Backends: **hunspell**, **aspell**, **ext-pspell**, or the pure PHP
  `wordlist` backend, which needs no system binary
- **ICU aware**: plural and select messages are expanded, only the textual
  branches are checked
- Placeholders, HTML, markdown code, URLs, e-mails and paths are stripped
  **without losing the column** in the original message
- **Baseline** for legacy projects: only new typos fail the build
- Report formats for humans, **GitHub Actions annotations**, **GitLab Code
  Quality**, JSON and JUnit
- PSR-6 cached, deterministic output, lazy pipeline

## Table of contents

- [Install](#install)
- [Minimal configuration](#minimal-configuration)
- [Commands](#commands)
- [Adopting on an existing project](#adopting-on-an-existing-project)
- [Translation sources](#translation-sources)
- [What is checked, and what is not](#what-is-checked-and-what-is-not)
- [Suppressing](#suppressing)
- [CI](#ci)
- [Extending](#extending)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)
- [Related projects](#related-projects)
- [Contributing](#contributing)

## Install

```bash
composer require --dev php-spellcheck/spellcheck-symfony-bundle
```

Without Flex, register the bundle in dev and test only:

```php
// config/bundles.php
return [
    // ...
    PHPSpellcheck\SpellcheckBundle\PHPSpellcheckBundle::class => ['dev' => true, 'test' => true],
];
```

Install a backend (recommended: hunspell plus the dictionaries of your locales):

```bash
sudo apt-get install hunspell hunspell-it hunspell-en-us
```

Then check the environment:

```bash
bin/console spellcheck:doctor
```

## Minimal configuration

```yaml
# config/packages/php_spellcheck.yaml
php_spellcheck:
    dictionaries:
        - '%kernel.project_dir%/.spellcheck/project.txt'

    cache:
        pool: cache.php_spellcheck

    translations:
        paths: ['%kernel.project_dir%/translations']

    code:
        paths: ['%kernel.project_dir%/src']
```

### Glob patterns in the paths

`translations.paths` and `code.paths` accept glob patterns, with the Finder
syntax: a single `*` stops at the directory separator, `**` crosses it.

```yaml
php_spellcheck:
    translations:
        paths:
            - '%kernel.project_dir%/translations'
            # Every module that ships its own catalogues.
            - '%kernel.project_dir%/src/*/translations'
            # Every catalogue under src, at any depth.
            - '%kernel.project_dir%/src/**/*.yml'

    code:
        paths: ['%kernel.project_dir%/src/**/Entity']
```

Patterns are matched against the file system at every run, not when the
container is built, so a new directory does not need a cache clear. The walk
starts at the last segment without a wildcard, so keep that prefix as deep as
possible: `%kernel.project_dir%/**/translations` scans `vendor/` and `var/` too.

A pattern that matches nothing is dropped; if no path is left, the run reports a
diagnostic instead of failing. `dictionaries` takes plain files only.

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            cache.php_spellcheck:
                adapter: cache.adapter.filesystem
```

A filesystem pool is preferable to a shared Redis one: the results depend on the
dictionaries installed on that particular machine.

## Commands

| Command | Purpose |
|---|---|
| `spellcheck` | Every enabled source |
| `spellcheck:translations` | Catalogues only, with `--locale` and `--domain` |
| `spellcheck:code` | PHP only, accepts explicit paths |
| `spellcheck:baseline` | Records the current issues, `--merge`, `--prune`, `--dry-run` |
| `spellcheck:doctor` | Diagnoses backends, dictionaries, locale mapping |
| `spellcheck:dictionary:add` | Adds words, keeping the file sorted |
| `spellcheck:debug:fragments` | Shows what the pipeline produces |

Shared options: `--format`, `--no-suggestions`, `--no-baseline`, `--no-cache`,
`--fail-on-warning`, `--ignore-warnings`, `--report-outdated`, `--config-profile`.

Exit codes: `0` clean, `1` new issues, `2` configuration or environment error,
`3` warnings only.

## Adopting on an existing project

The first run on a real project reports a lot. That is expected, and the answer
is not to weaken the rules:

```bash
bin/console spellcheck:baseline
git add .spellcheck/baseline.json && git commit -m "Spellcheck baseline"
```

From then on only regressions fail the build. Shrink the baseline over time by
moving legitimate terms into the project dictionary and fixing the rest. Add
`--report-outdated` in CI so the baseline cannot quietly grow forever.

## Translation sources

Two mutually exclusive modes, `translations.source`:

**`files`** (default) reads the translation files of the project directly. It
knows which file and line every message comes from, and never sees vendor
catalogues.

**`translator`** reads every catalogue known to the Translator, vendor messages
included. Useful to audit everything the user can actually see, but
`MessageCatalogue` does not record which file a message came from, so lines are
best effort. This is also why `exclude_domains` defaults to
`['validators', 'security']` in this mode.

Note on Symfony 5.4: catalogues are loaded explicitly, locale by locale.
`getCatalogues()` only returns what happens to be loaded already, and it is not
part of `TranslatorBagInterface` before 6.1.

## What is checked, and what is not

Placeholders (`%name%`, `{{ var }}`, `{name}`, `:param`, `%s`), HTML, markdown
code, URLs, e-mails and paths are removed before checking, without losing the
position: the reported column points at the offset in the **original** message.

ICU messages are expanded and only the textual branches are checked:
`{count, plural, one {Hai una mela} other {Hai # mele}}` checks the two
sentences, not `count`, `plural`, `one`, `other` or `#`. Legacy pipe plurals are
split and their intervals stripped.

In PHP, `variable` and `string_literal` are **not** checked by default: local
variables are often deliberate abbreviations, and string literals contain SQL,
regexes and service ids. Enable them with `code.check` if you want the noise.

## Suppressing

In code:

```php
// @spellcheck-ignore-file
// @spellcheck-ignore-next-line
$x = 1; // @spellcheck-ignore-line
/* @spellcheck-disable */ ... /* @spellcheck-enable */
// @spellcheck-words Kbps Mbps idempotency
```

In catalogues there is no inline suppression on purpose: translation files are
read by translators, not by developers. Use the project dictionary or the
baseline.

## CI

```yaml
# GitHub Actions
- run: php bin/console spellcheck --format=github --no-interaction
```

```yaml
# GitLab CI
spellcheck:
  script:
    - php bin/console spellcheck --format=gitlab > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

The GitLab fingerprint is deliberately the same one the baseline uses, so
GitLab deduplicates across pipelines exactly as the baseline does across runs.

## Extending

Six extension points, autoconfigured when the container has autoconfiguration
enabled:

| Tag | Interface |
|---|---|
| `php_spellcheck.source` | `SourceInterface` |
| `php_spellcheck.processor` | `TextProcessorInterface` |
| `php_spellcheck.tokenizer` | `TokenizerInterface` |
| `php_spellcheck.speller` | `SpellerInterface` |
| `php_spellcheck.reporter` | `ReporterInterface` |
| `php_spellcheck.filter` | `MisspellingFilterInterface` |

A custom reporter's `getName()` becomes a valid `--format` value.

## Troubleshooting

**The run times out with hunspell.** Set
`backend_options.terse_mode: false`. Some builds do not emit the terminating
blank line in terse mode.

**No line number on translation issues.** You are in `translator` mode; switch
to `files`, or accept the logical location.

**It is slow.** Check the `cache ... % hit` line in the summary. Use
`--no-suggestions` in CI, where nobody reads them.

**A word is not reported and I don't know why.** `spellcheck:debug:fragments
--grep=<word>` shows the text after the pipeline and the extracted tokens.

## FAQ

**How do I spell check Symfony translation files?** Point
`translations.paths` at the catalogue directories and run
`bin/console spellcheck:translations`. Each catalogue is checked against the
dictionary of its own locale, so `messages.it.yaml` is read as Italian and
`messages.en.yaml` as English, in the same run.

**Does it work without hunspell or aspell installed?** Yes. Set
`backend: wordlist` and give it a word list: the pure PHP backend needs no
system binary, which is the usual choice on a locked down CI image.

**Can it check PHP identifiers and comments, not only strings?** Yes, that is
what `spellcheck:code` does. `variable` and `string_literal` are off by default
because local variables are often deliberate abbreviations and string literals
carry SQL, regexes and service ids; enable them with `code.check`.

**How do I adopt it on a legacy project with thousands of typos?** Record a
baseline with `bin/console spellcheck:baseline` and commit it. From then on
only regressions fail, and `--report-outdated` keeps the baseline from growing
silently.

**Does it fail my pull request?** Exit code `1` on new issues, `3` on warnings
only, `2` on a configuration or environment error. With
`--format=github` the issues show up as inline annotations on the diff.

**Which locales are supported?** Any locale for which a dictionary is
installed. `spellcheck:doctor` prints the mapping between your configured
locales and the dictionaries actually found on the machine.

## Related projects

- [`php-spellcheck/spellcheck-core`](https://github.com/php-spellcheck/spellcheck-core)
  — the engine: sources, processors, tokenizers, spellers, reporters, usable in
  any PHP project or framework.

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md)
for the local setup and the rules that are not negotiable, and
[SECURITY.md](SECURITY.md) to report a vulnerability privately.

## License

[MIT](LICENSE) © Raffaele Carelle.
