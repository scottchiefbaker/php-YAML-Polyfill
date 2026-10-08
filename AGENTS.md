# AGENTS.md

PHP 8.2+ library: a pure-PHP polyfill for the PECL yaml extension's
`yaml_emit()` / `yaml_parse()` family. Zero dependencies.

## Layout

- `yaml-polyfill.php`: all implementation. Namespaced code lives in
  `YamlPolyfill\` (`emit()`, `parse()`, `emit_file()`, `parse_file()`,
  `parse_url()`). Global `yaml_*()` wrappers at the end are guarded by
  `function_exists`, so PECL wins when loaded.
- `unit_tests.php`: standalone runner, no PHPUnit.
- `composer.json`: autoloads `yaml-polyfill.php` via `files`.
- `.github/workflows/tests.yml`: GitHub Actions on push and PR. Runs
  `composer validate --strict`, `php -l`, and the suite both with `php -n`
  (no PECL yaml) and with yaml loaded, on PHP 8.2 and 8.5.
- `.gitattributes` export-ignores `AGENTS.md`, `unit_tests.php`, and `.github`.

## Commands

- All tests: `php unit_tests.php` (or `composer test`). Currently 134 pass.
- Subset: `php unit_tests.php -f REGEX` (`--filter=`). Case-insensitive,
  matched against check names. Exits 1 if nothing matches.
- Failures only: `--simple` (composes with `-f`). Set `NO_COLOR=1` to
  disable colors.
- Without the PECL extension: `php -n unit_tests.php`. Both modes must pass.
  The `YAML_POLYFILL` check expects the constant only when PECL is absent.
- CI actions are pinned to version tags (`actions/checkout@v7`,
  `shivammathur/setup-php@v2`), not commit SHAs. Keep that unless asked.
- CI cannot be run locally. Check the YAML with `python3` and the commands
  in the workflow by hand. Actual runs: `gh run list`.

## Testing quirks

- Tests `require_once` the polyfill, then call `YamlPolyfill\emit()` and
  `YamlPolyfill\parse()` directly. Do this in new tests too, so they test
  the polyfill even when PECL is loaded.
- `YAML_POLYFILL` is defined only if `yaml_emit` does not already exist when
  the file is included. Include order matters.

## Conventions

- Both files use `declare(strict_types=1)` and tab indentation. Each ends
  with the vim modeline `// vim: tabstop=4 shiftwidth=4 noexpandtab ...`.
  Keep it.
- Errors emit `E_USER_WARNING` and return `false`. Do not throw.
- Prefer simplicity over completeness. Aim for ~90% of real-world use and
  leave niche YAML features out unless asked.

## Intentional limits (don't "fix" without asking)

- Empty arrays always emit `[]`; maps and lists are not distinguished.
- No anchors, tags, or callbacks on emit or parse.
- The `$encoding` argument is ignored; output is always UTF-8.
- Output is not byte-identical to libyaml.

## Unsupported features

`README.md` "Limitations" is the authoritative list. Gaps it omits:
- Folded (`>`) scalars do not special-case more-indented lines.
- Parse errors are generic warnings with no line or column.
