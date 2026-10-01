# AGENTS.md

Two-file PHP 8.2+ project (no manifest, no CI): `php-yaml-polyfill.php` is a
`yaml_emit()` and `yaml_parse()` polyfill; `unit_tests.php` tests it.

- Purpose: a small, lightweight polyfill for the PECL yaml extension's
  `yaml_emit()`. Not a bit-for-bit replacement; aim to cover ~90% of real-world
  use cases and leave niche features to the full PECL library. Prefer
  simplicity over completeness; don't add complexity for rare edge cases.
- Run tests: `php unit_tests.php` (set `NO_COLOR=1` to disable colors).
  Run a subset: `php unit_tests.php --filter=REGEX` (case-insensitive, matched
  against check names; exits 1 if nothing matches).
- Tests do not `include` the polyfill. They read `php-yaml-polyfill.php`,
  regex/str_replace its source (strips `declare(strict_types=1)`, renames
  `yaml_emit`/`yaml_parse` -> `*_polyfill`, forces both `function_exists`
  guards to true) and `eval` it. If you change a guard line, the `declare`
  header, or a function name/signature text in `php-yaml-polyfill.php`, update
  those replacements in `unit_tests.php` or tests will silently break. Tests
  call `yaml_emit_polyfill()` / `yaml_parse_polyfill()`.
- Both files use `declare(strict_types=1)`.
- Known intentional limits (don't "fix" without asking): empty arrays always
  emit `[]`, no anchors/tags/callbacks, encoding arg ignored (UTF-8), output
  not byte-identical to libyaml.
- `yaml_parse()` is a block-style subset: flow collections (single-line only)
  are supported; no block scalars (`|`, `>`), anchors, tags, multi-line scalars,
  multi-document, callbacks. Errors warn (`E_USER_WARNING`) and return false.
  `yes/no/on/off` parse as booleans (YAML 1.1, like PECL).
