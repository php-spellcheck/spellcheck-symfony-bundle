# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `AcmeSpellcheckBundle` targeting Symfony 5.4 LTS: classic
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

### Notes

- No service in this bundle uses autowiring: every dependency is declared
  explicitly, so the bundle also works in applications with
  `_defaults: autowire: false`.
