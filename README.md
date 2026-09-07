# AcmeSpellcheckBundle

Symfony integration for [`php-spellcheck/spellcheck-core`](https://github.com/php-spellcheck/spellcheck-core):
spell checks **every translation catalogue with the dictionary of its own
locale**, and the **PHP code** of the project (class names, methods,
properties, parameters, constants, docblocks, comments).

- Symfony **5.4 LTS**, 6.4, 7.x
- PHP ≥ 8.1
- Works without any system binary through the pure PHP `wordlist` backend

## Install

```bash
composer require --dev php-spellcheck/spellcheck-symfony-bundle
```

Without Flex, register the bundle in dev and test only:

```php
// config/bundles.php
return [
    // ...
    PHPSpellcheck\SpellcheckBundle\AcmeSpellcheckBundle::class => ['dev' => true, 'test' => true],
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
# config/packages/acme_spellcheck.yaml
acme_spellcheck:
    dictionaries:
        - '%kernel.project_dir%/.spellcheck/project.txt'

    cache:
        pool: cache.acme_spellcheck

    translations:
        paths: ['%kernel.project_dir%/translations']

    code:
        paths: ['%kernel.project_dir%/src']
```

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            cache.acme_spellcheck:
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
| `acme_spellcheck.source` | `SourceInterface` |
| `acme_spellcheck.processor` | `TextProcessorInterface` |
| `acme_spellcheck.tokenizer` | `TokenizerInterface` |
| `acme_spellcheck.speller` | `SpellerInterface` |
| `acme_spellcheck.reporter` | `ReporterInterface` |
| `acme_spellcheck.filter` | `MisspellingFilterInterface` |

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

## License

MIT.
