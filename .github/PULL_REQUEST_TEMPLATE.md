## What this changes

<!-- One paragraph. Link the issue it closes, if any. -->

## Why

<!-- The concrete case on a real project that this fixes or enables. -->

## Checklist

- [ ] `make cs-fix`, `make stan` and `make test-all` pass
- [ ] Tests cover the new behaviour, offsets included where relevant
- [ ] Symfony 5.4 compatible: no `AbstractBundle`, no 6.1+ attributes
- [ ] Works with php-parser 4 and 5
- [ ] `CHANGELOG.md` updated under `[Unreleased]`
- [ ] No fingerprint or baseline schema change outside a major release
