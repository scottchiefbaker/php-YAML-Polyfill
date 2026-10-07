# AGENTS.md

Two-file PHP 8.2+ project (no manifest, no CI): `yaml-polyfill.php` is a
`yaml_emit()` and `yaml_parse()` polyfill; `unit_tests.php` tests it.

- Purpose: a small, lightweight polyfill for the PECL yaml extension's
  `yaml_emit()`. Not a bit-for-bit replacement; aim to cover ~90% of real-world
  use cases and leave niche features to the full PECL library. Prefer
  simplicity over completeness; don't add complexity for rare edge cases.
- Run tests: `php unit_tests.php` (set `NO_COLOR=1` to disable colors).
  Run a subset: `php unit_tests.php --filter=REGEX` (also `-f`; case-insensitive, matched
  against check names; exits 1 if nothing matches).
  Quiet output: `--simple` only prints failing checks (with expected/actual
  diffs) and the summary line; composes with `--filter`.
- Tests `require_once` the polyfill normally, then call the namespaced
  implementations (for example, `YamlPolyfill\emit()` and
  `YamlPolyfill\parse()`) directly so they test the polyfill even when PECL
  YAML functions are already installed. Global PECL-compatible functions are
  conditional wrappers around those implementations.
- Both files use `declare(strict_types=1)`.
- Known intentional limits (don't "fix" without asking): empty arrays always
  emit `[]`, no anchors/tags/callbacks, encoding arg ignored (UTF-8), output
  not byte-identical to libyaml.
- `yaml_parse()` is a block-style subset: flow collections (single-line only)
  and block scalars (`|`, `>`; clip/strip chomping only, simple folding) are
  supported; no keep chomping (`+`), anchors, tags, multi-line plain/quoted
  scalars, multi-document, callbacks. Errors warn (`E_USER_WARNING`) and return false.
  `yes/no/on/off` parse as booleans (YAML 1.1, like PECL).

## YAML spec features NOT supported

Known gaps; don't add without asking (see Purpose: simplicity over completeness).

`yaml_parse()` / `yaml_parse_file()` / `yaml_parse_url()`:
- Anchors and aliases (`&a`, `*a`), merge keys (`<<`).
- Tags (`!!str`, `!!binary`, `!!timestamp`, `!!set`, `!!omap`, `!php/object`);
  also no YAML 1.1 timestamps or sexagesimal numbers.
- Multi-document streams: only the first document is read, `$ndocs` is always
  1, `$pos` is ignored.
- Multi-line flow collections (flow must be on one line); complex flow keys.
- Multi-line plain and quoted scalars (continuation lines).
- Block scalar keep chomping (`+`); folded (`>`) scalars do not special-case
  more-indented lines.
- Complex mapping keys (`? key`), explicit `:` value indicators.
- Tabs for indentation; `%` directives beyond skipping them.
- Callbacks (`$callbacks` is ignored).
- Errors are generic warnings without line/column information.

`yaml_emit()` / `yaml_emit_file()`:
- Anchors, aliases, tags, callbacks (objects become arrays via
  `get_object_vars()`, `JsonSerializable` or `DateTimeInterface`).
- Distinguishing empty maps from empty lists (always `[]`).
- Flow style, block scalars, and control over indent or line width.
- UTF-16 output (`$encoding` ignored); only LF, CR, CRLF line breaks.

Not applicable: PECL ini settings (`yaml.decode_*`) and HTTP options (timeouts,
contexts) for `yaml_parse_url()`.
