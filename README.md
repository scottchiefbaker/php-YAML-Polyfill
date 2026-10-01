# yaml-polyfill

A pure-PHP polyfill for the PECL php-yaml extension, written for PHP 8.2+.

This library provides the same core API — `yaml_emit()`, `yaml_parse()`, and
friends — as a single, dependency-free PHP file, so projects that need simple,
reliable YAML input/output don't have to install a C extension.

It aims to cover roughly 90% of real-world YAML usage. Output is valid,
readable YAML, but it is **not** guaranteed to be byte-identical to libyaml.
If you need byte-exact fidelity to libyaml, use the PECL extension.

## Features

- `yaml_emit($data, $encoding, $linebreak)` — serialize nested arrays,
  scalars, and simple objects to block-style YAML. Also
  `yaml_emit_file($filename, $data, ...)`.
- `yaml_parse($input, $pos, &$ndocs, $callbacks)` — parse plain, quoted,
  and block scalars, block mappings and sequences, single-line flow
  collections, comments, and multi-document streams (first document).
  Also `yaml_parse_file($filename, ...)` and `yaml_parse_url($url, ...)`.
- Handles string quoting, integers, floats (including `.inf` / `.nan`),
  booleans, and `null`.
- `DateTimeInterface` values are serialized as ISO-8601 strings; objects
  are serialized via `get_object_vars()`; `JsonSerializable` objects are
  serialized via `jsonSerialize()`.
- The `YAML_ANY_ENCODING`, `YAML_UTF8_ENCODING`, `YAML_UTF16LE_ENCODING`,
  `YAML_UTF16BE_ENCODING`, `YAML_ANY_BREAK`, `YAML_CR_BREAK`,
  `YAML_LN_BREAK`, and `YAML_CRLN_BREAK` constants are defined when the
  PECL extension isn't loaded, for API parity.
- `YAML_POLYFILL` is defined (as `true`) when this polyfill's
  implementations are the ones in use; it is never defined when the PECL
  extension is active, so `defined('YAML_POLYFILL')` detects at runtime
  which library is providing the `yaml_*()` functions.
- The `$encoding` and `$linebreak` parameters are accepted for API parity;
  output is always UTF-8 with LF, CR, or CRLF line breaks.
- Errors are intentional and well-behaved: parse/emit failures raise an
  `E_USER_WARNING` and return `false`, matching PECL behavior rather than
  throwing exceptions.
- **Zero dependencies** — PHP 8.2+ core only, no extensions required.

## Requirements

- PHP 8.2 or newer
- Nothing else.

## Installation

Drop the file somewhere your autoloader (or `require` path) can find it, and
require it once:

```php
require_once('/path/to/yaml-polyfill.php');
```

Every public function is wrapped in a `function_exists()` guard, so it is
safe to load even when the real PECL yaml extension is installed.

## Usage

### Emitting YAML

```php
require 'yaml-polyfill.php';

$data = [
    'name'    => 'example',
    'version' => 1.0,
    'tags'    => ['a', 'b', 'c'],
    'nested'  => [
        'enabled' => true,
        'retries' => 3,
    ],
];

echo yaml_emit($data);
```

Output:

```yaml
---
name: example
version: 1.0
tags:
- a
- b
- c
nested:
  enabled: true
  retries: 3
...
```

### Writing to and reading from files

```php
yaml_emit_file('config.yml', $data);            // returns bool

$config = yaml_parse_file('config.yml');        // returns array
$remote = yaml_parse_url('https://example.com/config.yml');
```

### Parsing YAML

```php
$yaml = "---
# A sample document
name: example
enabled: yes
port: 8080
servers:
  - one.example.com
  - two.example.com
description: >
  Multiple lines are
  folded into one.
...";

$data = yaml_parse($yaml);
print_r($data);
```

Output:

```
Array
(
    [name]    => example
    [enabled] => 1
    [port]    => 8080
    [servers] => Array
        (
            [0] => one.example.com
            [1] => two.example.com
        )

    [description] => Multiple lines are folded into one.

)
```

### Multi-document streams

Only the first document in a stream is parsed, matching the PECL
extension's behavior when `$pos` is 0:

```php
$yaml = "---\nfoo: 1\n...\n---\nfoo: 2\n...\n";

$data = yaml_parse($yaml, 0, $ndocs);
var_dump($ndocs);   // int(1)
var_dump($data);    // ['foo' => 1]
```

### Error handling

Parse errors do not throw; they raise an `E_USER_WARNING` and return
`false` (PECL behavior):

```php
$result = yaml_parse("[unclosed");
if ($result === false) {
    // a warning has already been issued
}
```

## Limitations

This polyfill favors simplicity over full spec compliance. Not supported:

- Anchors, aliases, and merge keys (`&anchor`, `*alias`, `<<`).
- Tags (`!!str`, `!!binary`, `!!timestamp`, `!php/object`, etc.),
  YAML 1.1 timestamps, and sexagesimal numbers.
- Callbacks (the `$callbacks` argument is ignored).
- Multi-line plain and quoted scalars (continuation lines); multi-line
  flow collections (flow must be on a single line).
- Block scalar keep-chomping (`+|`, `+>`).
- Complex mapping keys (`? key`) and explicit key indicators.
- Tab indentation.
- Empty arrays always emit as `[]`; empty maps and empty lists are not
  distinguished.
- No anchors/tags/callbacks on emit; no flow style; no control over
  indent unit or line width.
- Byte-exact output parity with libyaml is not a goal.
- `$pos` is ignored; `$ndocs` is always 1; only the first document is read.
- UTF-16 output is not produced; `$encoding` is ignored.

## Testing

Run the test suite with PHP from the repository root:

```
php unit_tests.php
```

Use `--filter=REGEX` to run a subset (case-insensitive, matched against
check names), and `NO_COLOR=1` to disable colored output:

```
php unit_tests.php --filter=parse
NO_COLOR=1 php unit_tests.php
```

## License

GPL-2.0.
