<?php
declare(strict_types=1);

// Standalone tests for the yaml_emit()/yaml_parse() polyfill.
// Run: php unit_tests.php [-f|--filter=REGEX] [--simple]  (case-insensitive, on check name)

require_once __DIR__ . '/yaml-polyfill.php';

$nativeYamlLoaded = extension_loaded('yaml');

$pass = 0;
$fail = 0;
$skip = 0;

$opts   = getopt('f:', ['filter:', 'simple']);
$filter = $opts['filter'] ?? $opts['f'] ?? null;
$simple = isset($opts['simple']);

echo 'Native PECL YAML: ' . ($nativeYamlLoaded ? 'installed' : 'not installed') . "\n";

if ($filter !== null) {
    $filter = '/' . str_replace('/', '\/', (string)$filter) . '/i';
    if (@preg_match($filter, '') === false) {
        fwrite(STDERR, "Invalid --filter regex\n");
        exit(2);
    }
}

// Colors only when stdout is a TTY and NO_COLOR is not set
$useColor = (function_exists('posix_isatty') ? posix_isatty(STDOUT) : stream_isatty(STDOUT)) && getenv('NO_COLOR') === false;
function color(string $s, string $code): string
{
    global $useColor;
    return $useColor ? "\033[{$code}m$s\033[0m" : $s;
}

function check(string $name, mixed $expected, mixed $actual): void
{
    global $pass, $fail, $skip, $filter, $simple;
    if ($filter !== null && !preg_match($filter, $name)) {
        $skip++;
        return;
    }
    if ($expected === $actual) {
        $pass++;
        if (!$simple) {
            echo color("PASS", "32") . "  $name\n";
        }
    } else {
        $fail++;
        echo color("FAIL", "31") . "  $name\n";
        echo "      expected: " . var_export($expected, true) . "\n";
        echo "      actual:   " . var_export($actual, true) . "\n";
    }
}

function e(mixed $v): string
{
    return \YamlPolyfill\emit($v);
}

// Top-level scalars
check('scalar string'      , "--- hi\n...\n"   , e('hi'));
check('scalar null'        , "--- null\n...\n" , e(null));
check('scalar true'        , "--- true\n...\n" , e(true));
check('scalar false'       , "--- false\n...\n", e(false));
check('scalar int'         , "--- 42\n...\n"   , e(42));
check('scalar negative int', "--- -7\n...\n"   , e(-7));

// Collections
check('empty array'             , "--- []\n...\n"                     , e([]));
check('simple map'              , "---\na: 1\nb: 2\n...\n"            , e(['a' => 1, 'b' => 2]));
check('simple list'             , "---\n- 1\n- 2\n- 3\n...\n"         , e([1, 2, 3]));
check('nested maps'             , "---\na:\n  b:\n    c: 1\n...\n"    , e(['a' => ['b' => ['c' => 1]]]));
check('list of maps'            , "---\n- x: 1\n  z: 2\n- x: 3\n...\n", e([['x' => 1, 'z' => 2], ['x' => 3]]));
check('list of lists'           , "---\n- - 1\n  - 2\n- - 3\n...\n"   , e([[1, 2], [3]]));
check('map with list'           , "---\na:\n- 1\n- 2\nb: 3\n...\n"    , e(['a' => [1, 2], 'b' => 3]));
check('empty array as map value', "---\na: []\n...\n"                 , e(['a' => []]));
check('empty array in list'     , "---\n- []\n...\n"                  , e([[]]));

// Floats
check('float 1.0' , "--- 1.0\n...\n"  , e(1.0));
check('float 0.1' , "--- 0.1\n...\n"  , e(0.1));
check('float INF' , "--- .inf\n...\n" , e(INF));
check('float -INF', "--- -.inf\n...\n", e(-INF));
check('float NAN' , "--- .nan\n...\n" , e(NAN));

// String quoting
$quoted = [
    'empty string'      => ['', '""'],
    'string "true"'     => ['true', '"true"'],
    'string "null"'     => ['null', '"null"'],
    'string "y"'        => ['y', '"y"'],
    'string "~"'        => ['~', '"~"'],
    'numeric string'    => ['12', '"12"'],
    'float string'      => ['1.5', '"1.5"'],
    'hex string'        => ['0x1F', '"0x1F"'],
    'colon-space'       => ['a: b', '"a: b"'],
    'trailing colon'    => ['a:', '"a:"'],
    'space-hash'        => ['a #b', '"a #b"'],
    'leading space'     => [' a', '" a"'],
    'trailing space'    => ['a ', '"a "'],
    'leading dash'      => ['-a', '"-a"'],
    'leading bracket'   => ['[a', '"[a"'],
    'leading ampersand' => ['&a', '"&a"'],
    'leading asterisk'  => ['*a', '"*a"'],
    'embedded quote'    => ["a\"b", 'a"b'],
    'newline'           => ["a\nb", '"a\nb"'],
    'tab'               => ["a\tb", '"a\tb"'],
    'control char'      => ["a\x01b", '"a\x01b"'],
    'backslash newline' => ["a\\\nb", '"a\\\\\nb"'],
];
foreach ($quoted as $name => [$in, $out]) {
    check("quote: $name", "--- $out\n...\n", e($in));
}
check('plain string unquoted', "--- hello world\n...\n", e('hello world'));
check('unicode unquoted'     , "--- héllo\n...\n"      , e('héllo'));
check('emoji unquoted'       , "--- 😀\n...\n"         , e('😀'));

// Keys
check('key "y" quoted'       , "---\n\"y\": 1\n...\n"   , e(['y' => 1]));
check('key with colon quoted', "---\n\"a: b\": 1\n...\n", e(['a: b' => 1]));
check('integer key unquoted' , "---\n5: a\nx: b\n...\n" , e([5 => 'a', 'x' => 'b']));

// Line breaks
check('linebreak CRLN', "---\r\na: 1\r\n...\r\n", \YamlPolyfill\emit(['a' => 1], YAML_ANY_ENCODING, YAML_CRLN_BREAK));
check('linebreak CR'  , "---\ra: 1\r...\r"      , \YamlPolyfill\emit(['a' => 1], YAML_ANY_ENCODING, YAML_CR_BREAK));
check('linebreak LN'  , "---\na: 1\n...\n"      , \YamlPolyfill\emit(['a' => 1], YAML_ANY_ENCODING, YAML_LN_BREAK));

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
check('JsonSerializable' , "---\nk: v\n...\n"                    , e(new JS()));
check('plain object'     , "---\na: 1\nb: x\n...\n"              , e(new Plain()));
check('stdClass'         , "---\na: 1\n...\n"                    , e((object)['a' => 1]));
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
check('resource warns'     , true             , $warned);

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

// yaml_parse
function p(string $y): mixed
{
    return @\YamlPolyfill\parse($y);
}
check('parse scalar'                          , 'hi'                                        , p("--- hi\n...\n"));
check('parse empty'                           , null                                        , p(''));
check('parse map'                             , ['a' => 1, 'b' => 'x']                      , p("a: 1\nb: x\n"));
check('parse list'                            , [1, 2, 3]                                   , p("- 1\n- 2\n- 3\n"));
check('parse nested'                          , ['a' => ['b' => ['c' => 1]]]                , p("a:\n  b:\n    c: 1\n"));
check('parse list of maps'                    , [['x' => 1, 'z' => 2], ['x' => 3]]          , p("- x: 1\n  z: 2\n- x: 3\n"));
check('parse list under key, same indent'     , ['a' => [1, 2], 'b' => 3]                   , p("a:\n- 1\n- 2\nb: 3\n"));
check('parse nested list'                     , [[1, 2], [3]]                               , p("- - 1\n  - 2\n- - 3\n"));
check('parse types',
      [null, null, true, false, 3, -4, 1.5, 0.0, 255, 8, INF, -INF],
      p("- ~\n- null\n- true\n- no\n- 3\n- -4\n- 1.5\n- 0.0\n- 0xff\n- 010\n- .inf\n- -.inf\n"));
check('parse nan'                             , true                                        , is_nan(p("--- .nan\n")));
check('parse empty value is null'             , ['a' => null, 'b' => 1]                     , p("a:\nb: 1\n"));
check('parse empty collections'               , ['a' => [], 'b' => []]                      , p("a: []\nb: {}\n"));
check('parse double quoted'                   , "a\nb\"\\ \u{e9}A"                          , p('--- "a\\nb\\"\\\\ \\u00e9\\x41"'));
check('parse quoted keeps type as string'     , ['1', 'true', '']                           , p("- \"1\"\n- 'true'\n- ''\n"));
check('parse single quoted'                   , "it's"                                      , p("--- 'it''s'"));
check('parse quoted key'                      , ['a: b' => 1]                               , p("\"a: b\": 1\n"));
check('parse comments'                        , ['a' => 'x # y', 'b' => 2]                  , p("# top\na: \"x # y\" # c\n\nb: 2 # d\n"));
check('parse plain with colon'                , ['u' => 'http://x.y/z']                     , p("u: http://x.y/z\n"));
check('parse CRLF'                            , ['a' => 1, 'b' => 2]                        , p("a: 1\r\nb: 2\r\n"));
check('parse big int becomes float'           , 1.0E+25                                     , p("--- 10000000000000000000000000"));
check('parse flow list'                       , [1, 2]                                      , p("--- [1, 2]"));
check('parse flow map'                        , ['a' => 1, 'b' => 2]                        , p("{a: 1, b: 2}"));
check('parse flow types'                      , [1, 2.5, true, null, 'x']                   , p("[1, 2.5, true, null, x]"));
check('parse flow nested'                     , ['a' => [1, ['b' => 2]]]                    , p("{a: [1, {b: 2}]}"));
check('parse flow quoted'                     , ['a, b', 'c]', "d'"]                        , p("[\"a, b\", 'c]', \"d'\"]"));
check('parse flow json style'                 , ['a' => 1, 'b' => 'x']                      , p('{"a":1, "b":"x"}'));
check('parse flow empty and trailing comma'   , [[], [1, 2]]                                , p("[[ ], [1, 2,]]"));
check('parse flow as map value'               , ['p' => [80, 443], 'q' => 1]                , p("p: [80, 443]\nq: 1\n"));
check('parse flow as list item'               , [[1, 2], ['a' => 1]]                        , p("- [1, 2]\n- {a: 1}\n"));
check('parse flow with comment'               , ['a' => [1, 'x # y']]                       , p("a: [1, \"x # y\"] # note\n"));
check('parse flow plain colon'                , ['a:b']                                     , p("[a:b]"));
check('parse flow unterminated'               , false                                       , p("a: [1,\n"));
check('parse flow trailing text'              , false                                       , p("a: [1] x\n"));
check('parse flow key without value'          , false                                       , p("{a}"));
check('parse flow complex key'                , false                                       , p("{[a]: 1}"));
check('parse flow empty entry'                , false                                       , p("[a,,b]"));
check('parse block literal'                   , ['a' => "x\n  y\n\nz\n", 'b' => 1]            , p("a: |\n  x\n    y\n\n  z\nb: 1\n"));
check('parse block literal strip'             , ['a' => "x\ny"]                             , p("a: |-\n  x\n  y\n"));
check('parse block folded'                    , ['a' => "x y\nz\n"]                          , p("a: >\n  x\n  y\n\n  z\n"));
check('parse block folded strip'              , ['a' => 'x y']                              , p("a: >-\n  x\n  y\n"));
check('parse block explicit indent'           , ['a' => "  x\n"]                            , p("a: |2\n    x\n"));
check('parse block in list'                   , ["x\n", ['k' => "y\n", 'm' => 2]]           , p("- |\n  x\n- k: |\n    y\n  m: 2\n"));
check('parse block top level'                 , "x\n# y\n"                                  , p("--- |\n  x\n  # y\n"));
check('parse block keeps content literal'     , ['a' => "k: v # c \"q\" \\\n"]              , p("a: |\n  k: v # c \"q\" \\\n"));
check('parse block stays string'              , ['a' => "123\n"]                            , p("a: |\n  123\n"));
check('parse block header comment'            , ['a' => "x\n"]                              , p("a: | # note\n  x\n"));
check('parse block empty'                     , ['a' => '', 'b' => 1]                       , p("a: |\nb: 1\n"));
check('parse block CRLF'                      , ['a' => "x\ny\n"]                           , p("a: |\r\n  x\r\n  y\r\n"));
check('parse block keep chomping rejected'    , false                                       , p("a: |+\n  x\n"));
check('parse block bad indent'                , false                                       , p("a: |\n    x\n  y\n"));
check('parse block not a header'              , ['a' => 'x |', 'b' => '|x']                 , p("a: x |\nb: '|x'\n"));
check('parse bad indent returns false'        , false                                       , p("a:\n    b: 1\n  c: 2\n"));
check('parse unterminated quote returns false', false                                       , p("a: \"x\n"));
check('parse warns on error', true, (function () {
    $w = false;
    set_error_handler(function () use (&$w) { $w = true; return true; });
    \YamlPolyfill\parse("a: 'x\n");
    restore_error_handler();
    return $w;
})());
foreach ([
    'scalars'       => ['a', '', ' x', 'true', '1', '1.5', 'a: b', '#x', "l1\nl2", "q\"'\\", 'null', 'é', "\x01"],
    'mixed'         => ['k' => [1, 2.5, null, true, ['n' => ['x', 'y']], [[1], [2, 3]]], '5' => 'five', 'a b' => 'c: d'],
    'list of lists' => [[1, 2], [[3], []], ['a' => []]],
    'floats'        => [1.0, -0.5, 1.0E+25, INF, -INF],
] as $name => $data) {
    check("round trip $name", $data, p(e($data)));
}

// File functions
$tmp = tempnam(sys_get_temp_dir(), 'yml');

check('emit_file returns true', true                      , \YamlPolyfill\emit_file($tmp, ['a' => [1, 2]]));
check('emit_file content'     , "---\na:\n- 1\n- 2\n...\n", file_get_contents($tmp));
check('parse_file reads back' , ['a' => [1, 2]]           , \YamlPolyfill\parse_file($tmp, 0, $nd));
check('parse_file ndocs'      , 1                         , $nd);

check('parse_url file:// reads', ['a' => [1, 2]], \YamlPolyfill\parse_url('file://' . $tmp, 0, $nd2));
check('parse_url ndocs', 1, $nd2);
unlink($tmp);
check('parse_url missing returns false', false, @\YamlPolyfill\parse_url('file://' . $tmp));
check('parse_file missing returns false', false, @\YamlPolyfill\parse_file($tmp));
check('emit_file bad path returns false', false, @\YamlPolyfill\emit_file('/nonexistent-dir/x.yml', 1));

// Constants
foreach (['YAML_ANY_ENCODING', 'YAML_UTF8_ENCODING', 'YAML_UTF16LE_ENCODING', 'YAML_UTF16BE_ENCODING',
          'YAML_ANY_BREAK', 'YAML_CR_BREAK', 'YAML_LN_BREAK', 'YAML_CRLN_BREAK'] as $c) {
    check("constant $c defined", true, defined($c));
}
check('constant YAML_POLYFILL defined', !extension_loaded('yaml'), defined('YAML_POLYFILL'));

echo "\n" . color("$pass passed", "32") . ", " . color("$fail failed", $fail > 0 ? "31" : "32")
    . ($filter !== null ? ", $skip skipped" : '') . "\n";
if ($filter !== null && $pass + $fail === 0) {
    fwrite(STDERR, "No tests matched --filter\n");
    exit(1);
}
exit($fail > 0 ? 1 : 0);
