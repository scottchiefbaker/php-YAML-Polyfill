<?php
declare(strict_types=1);

// Standalone tests for the yaml_emit() polyfill. Run: php unit_tests.php

// Load the polyfill under another name so it is tested even if the PECL
// extension is installed.
$src = file_get_contents(__DIR__ . '/php-yaml-polyfill.php');
$src = preg_replace('/^<\?php\s*declare\(strict_types=1\);/', '', $src);
$src = str_replace(
    ["if (!function_exists('yaml_emit')) {", 'function yaml_emit('],
    ['if (true) {', 'function yaml_emit_polyfill('],
    $src
);
$src = str_replace("if (!function_exists('_yaml_emit_scalar')) {", 'if (!function_exists("_yaml_emit_scalar")) {', $src);
eval($src);

$pass = 0;
$fail = 0;

// Colors only when stdout is a TTY and NO_COLOR is not set
$useColor = (function_exists('posix_isatty') ? posix_isatty(STDOUT) : stream_isatty(STDOUT)) && getenv('NO_COLOR') === false;
function color(string $s, string $code): string
{
    global $useColor;
    return $useColor ? "\033[{$code}m$s\033[0m" : $s;
}

function check(string $name, mixed $expected, mixed $actual): void
{
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo color("PASS", "32") . "  $name\n";
    } else {
        $fail++;
        echo color("FAIL", "31") . "  $name\n";
        echo "      expected: " . var_export($expected, true) . "\n";
        echo "      actual:   " . var_export($actual, true) . "\n";
    }
}

function e(mixed $v): string
{
    return yaml_emit_polyfill($v);
}

// Top-level scalars
check('scalar string', "--- hi\n...\n", e('hi'));
check('scalar null', "--- null\n...\n", e(null));
check('scalar true', "--- true\n...\n", e(true));
check('scalar false', "--- false\n...\n", e(false));
check('scalar int', "--- 42\n...\n", e(42));
check('scalar negative int', "--- -7\n...\n", e(-7));

// Collections
check('empty array', "--- []\n...\n", e([]));
check('simple map', "---\na: 1\nb: 2\n...\n", e(['a' => 1, 'b' => 2]));
check('simple list', "---\n- 1\n- 2\n- 3\n...\n", e([1, 2, 3]));
check('nested maps', "---\na:\n  b:\n    c: 1\n...\n", e(['a' => ['b' => ['c' => 1]]]));
check('list of maps', "---\n- x: 1\n  z: 2\n- x: 3\n...\n", e([['x' => 1, 'z' => 2], ['x' => 3]]));
check('list of lists', "---\n- - 1\n  - 2\n- - 3\n...\n", e([[1, 2], [3]]));
check('map with list', "---\na:\n- 1\n- 2\nb: 3\n...\n", e(['a' => [1, 2], 'b' => 3]));
check('empty array as map value', "---\na: []\n...\n", e(['a' => []]));
check('empty array in list', "---\n- []\n...\n", e([[]]));

// Floats
check('float 1.0', "--- 1.0\n...\n", e(1.0));
check('float 0.1', "--- 0.1\n...\n", e(0.1));
check('float INF', "--- .inf\n...\n", e(INF));
check('float -INF', "--- -.inf\n...\n", e(-INF));
check('float NAN', "--- .nan\n...\n", e(NAN));

// String quoting
$quoted = [
    'empty string' => ['', '""'],
    'string "true"' => ['true', '"true"'],
    'string "null"' => ['null', '"null"'],
    'string "y"' => ['y', '"y"'],
    'string "~"' => ['~', '"~"'],
    'numeric string' => ['12', '"12"'],
    'float string' => ['1.5', '"1.5"'],
    'hex string' => ['0x1F', '"0x1F"'],
    'colon-space' => ['a: b', '"a: b"'],
    'trailing colon' => ['a:', '"a:"'],
    'space-hash' => ['a #b', '"a #b"'],
    'leading space' => [' a', '" a"'],
    'trailing space' => ['a ', '"a "'],
    'leading dash' => ['-a', '"-a"'],
    'leading bracket' => ['[a', '"[a"'],
    'leading ampersand' => ['&a', '"&a"'],
    'leading asterisk' => ['*a', '"*a"'],
    'embedded quote' => ["a\"b", 'a"b'],
    'newline' => ["a\nb", '"a\nb"'],
    'tab' => ["a\tb", '"a\tb"'],
    'control char' => ["a\x01b", '"a\x01b"'],
    'backslash newline' => ["a\\\nb", '"a\\\\\nb"'],
];
foreach ($quoted as $name => [$in, $out]) {
    check("quote: $name", "--- $out\n...\n", e($in));
}
check('plain string unquoted', "--- hello world\n...\n", e('hello world'));
check('unicode unquoted', "--- héllo\n...\n", e('héllo'));
check('emoji unquoted', "--- 😀\n...\n", e('😀'));

// Keys
check('key "y" quoted', "---\n\"y\": 1\n...\n", e(['y' => 1]));
check('key with colon quoted', "---\n\"a: b\": 1\n...\n", e(['a: b' => 1]));
check('integer key unquoted', "---\n5: a\nx: b\n...\n", e([5 => 'a', 'x' => 'b']));

// Line breaks
check('linebreak CRLN', "---\r\na: 1\r\n...\r\n", yaml_emit_polyfill(['a' => 1], YAML_ANY_ENCODING, YAML_CRLN_BREAK));
check('linebreak CR', "---\ra: 1\r...\r", yaml_emit_polyfill(['a' => 1], YAML_ANY_ENCODING, YAML_CR_BREAK));
check('linebreak LN', "---\na: 1\n...\n", yaml_emit_polyfill(['a' => 1], YAML_ANY_ENCODING, YAML_LN_BREAK));

// Objects
class JS implements JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return ['k' => 'v'];
    }
}
class Plain
{
    public $a = 1;
    public $b = 'x';
}
check('JsonSerializable', "---\nk: v\n...\n", e(new JS()));
check('plain object', "---\na: 1\nb: x\n...\n", e(new Plain()));
check('stdClass', "---\na: 1\n...\n", e((object)['a' => 1]));
check('DateTimeImmutable', "--- 2024-01-02T03:04:05+00:00\n...\n", e(new DateTimeImmutable('2024-01-02T03:04:05+00:00')));

// Unsupported types
$warned = false;
set_error_handler(function () use (&$warned) {
    $warned = true;
    return true;
});
$fh = fopen('php://memory', 'r');
$res = e($fh);
fclose($fh);
restore_error_handler();
check('resource emits null', "--- null\n...\n", $res);
check('resource warns', true, $warned);

// Depth guard
$deep = 1;
for ($i = 0; $i < 600; $i++) {
    $deep = [$deep];
}
$threw = false;
try {
    e($deep);
} catch (RuntimeException $ex) {
    $threw = true;
}
check('depth guard throws', true, $threw);

// Constants
foreach (['YAML_ANY_ENCODING', 'YAML_UTF8_ENCODING', 'YAML_UTF16LE_ENCODING', 'YAML_UTF16BE_ENCODING',
          'YAML_ANY_BREAK', 'YAML_CR_BREAK', 'YAML_LN_BREAK', 'YAML_CRLN_BREAK'] as $c) {
    check("constant $c defined", true, defined($c));
}

echo "\n" . color("$pass passed", "32") . ", " . color("$fail failed", $fail > 0 ? "31" : "32") . "\n";
exit($fail > 0 ? 1 : 0);
