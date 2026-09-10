# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-10

### Added

- `PHPSpellcheckBundle` targeting Symfony 5.4 LTS: classic
  Bundle + Extension + Configuration, since `AbstractBundle` is 6.1+.
- Full configuration tree with strict validation: unknown backends, invalid
  `ignore_patterns` and missing dictionary files fail at compile time.
- `TranslationFilesSource` (default) and `TranslatorCatalogueSource`, selected by
  `translations.source`. Catalogues are loaded locale by locale rather than
  through `getCatalogues()`.
- `LocaleResolver`: configured locales, then `%kernel.enabled_locales%`, then the
  translator with a warning.
- Seven commands with `#[AsCommand]` (available since Symfony 5.3).
- `TranslatorOptionalPass`: the bundle works with the translator disabled.
- `RegisterTranslationLoadersPass`: builds the loader locator by reading the
  `translation.loader` tag aliases by hand, since `#[AsTaggedItem]` is 6.1+.
- Autoconfiguration for the six extension points.
- Glob patterns in `translations.paths` and `code.paths`, resolved by
  `PathExpander` with the Finder syntax (`*` stops at the separator, `**` does
  not), matched at run time so a new directory needs no cache clear.

### Changed

- Symfony 8.x and PHP 8.5 are supported and covered by CI. The Symfony
  constraints accept `^8.0`; Symfony 8 itself requires PHP >= 8.4.
- The test suite runs on PHPUnit 10.5, 11.5 or 12; the development dependencies
  `matthiasnoback/symfony-config-test` and
  `matthiasnoback/symfony-dependency-injection-test` moved to `^6.2`, the first
  series that supports Symfony 8. PHPUnit 13 is excluded: its
  `ExceptionMessageIsOrContains` constraint takes a string instead of an
  exception, which breaks `assertConfigurationIsInvalid()` with a message.
- The development image installs `ext-pspell` from PECL on PHP >= 8.4, where the
  extension is no longer part of php-src.

### Notes

- No service in this bundle uses autowiring: every dependency is declared
  explicitly, so the bundle also works in applications with
  `_defaults: autowire: false`.

[1.0.0]: https://github.com/php-spellcheck/spellcheck-symfony-bundle/releases/tag/v1.0.0
