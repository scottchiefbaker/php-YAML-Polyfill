<?php
declare(strict_types=1);

/**
 * Basic yaml_emit() and yaml_parse() polyfill (PHP 8.2+).
 *
 * Produces valid, readable YAML; not byte-identical to libyaml.
 * Limitations:
 *  - Empty arrays always emit as [] (map/list is indistinguishable).
 *  - No anchors, tags or callbacks.
 *  - $encoding is ignored; output is always UTF-8.
 */

foreach ([
	'YAML_ANY_ENCODING'     => 0,
	'YAML_UTF8_ENCODING'    => 1,
	'YAML_UTF16LE_ENCODING' => 2,
	'YAML_UTF16BE_ENCODING' => 3,

	'YAML_ANY_BREAK'        => 0,
	'YAML_CR_BREAK'         => 1,
	'YAML_LN_BREAK'         => 2,
	'YAML_CRLN_BREAK'       => 3,
] as $name => $val) {
    if (!defined($name)) {
        define($name, $val);
    }
}

if (!function_exists('yaml_emit')) {
    function _yaml_emit_string(string $s): string
    {
        $needs = $s === '' || $s !== trim($s)
            || preg_match('/[\x00-\x1f\x7f]/', $s)
            || str_contains($s, ': ') || str_contains($s, ' #')
            || str_ends_with($s, ':')
            || preg_match('/^[-?:,\[\]{}#&*!|>\'"%@`]/', $s)
            || preg_match('/^(null|~|true|false|yes|no|on|off|y|n)$/i', $s)
            || is_numeric($s)
            || preg_match('/^[-+]?(\.inf|\.nan)$/i', $s)
            || preg_match('/^0[xo][0-9a-f]+$/i', $s)
            || preg_match('//u', $s) !== 1;
        if (!$needs) {
            return $s;
        }
        $out = '';
        foreach (str_split($s) as $c) {
            $o = ord($c);
            $out .= match (true) {
                $c === '\\' => '\\\\',
                $c === '"' => '\\"',
                $c === "\n" => '\\n',
                $c === "\t" => '\\t',
                $c === "\r" => '\\r',
                $o < 0x20 || $o === 0x7f => sprintf('\\x%02X', $o),
                default => $c,
            };
        }
        return '"' . $out . '"';
    }

    function _yaml_emit_scalar(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string)$v;
        }
        if (is_float($v)) {
            if (is_nan($v)) {
                return '.nan';
            }
            if (is_infinite($v)) {
                return $v > 0 ? '.inf' : '-.inf';
            }
            $s = var_export($v, true);
            return preg_match('/^-?\d+$/', $s) ? $s . '.0' : $s;
        }
        if (is_string($v)) {
            return _yaml_emit_string($v);
        }
        trigger_error('yaml_emit(): Unsupported type ' . get_debug_type($v) . ', emitting null', E_USER_WARNING);
        return 'null';
    }

    function _yaml_emit_normalize(mixed $v): mixed
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format(DATE_ATOM);
        }
        if ($v instanceof JsonSerializable) {
            return $v->jsonSerialize();
        }
        if (is_object($v)) {
            return get_object_vars($v);
        }
        return $v;
    }

    /** Returns lines (without trailing newline) for a collection. */
    function _yaml_emit_lines(array $a, int $depth): array
    {
        if ($depth > 512) {
            throw new RuntimeException('yaml_emit(): maximum nesting depth exceeded');
        }
        $lines = [];
        $isList = array_is_list($a);
        foreach ($a as $k => $v) {
            $v = _yaml_emit_normalize($v);
            $prefix = $isList ? '-' : (is_int($k) ? (string)$k : _yaml_emit_string($k)) . ':';
            if (is_array($v) && $v !== []) {
                $sub = _yaml_emit_lines($v, $depth + 1);
                if ($isList) {
                    // first line joins the dash, the rest align under it
                    $lines[] = '- ' . $sub[0];
                    for ($i = 1; $i < count($sub); $i++) {
                        $lines[] = '  ' . $sub[$i];
                    }
                } else {
                    $subIsList = array_is_list($v);
                    $lines[] = $prefix;
                    foreach ($sub as $l) {
                        $lines[] = ($subIsList ? '' : '  ') . $l;
                    }
                }
            } else {
                $lines[] = $prefix . ' ' . (is_array($v) ? '[]' : _yaml_emit_scalar($v));
            }
        }
        return $lines;
    }

    function yaml_emit(mixed $data, int $encoding = YAML_ANY_ENCODING, int $linebreak = YAML_ANY_BREAK): string
    {
        $data = _yaml_emit_normalize($data);
        if (is_array($data) && $data !== []) {
            $body = implode("\n", _yaml_emit_lines($data, 0)) . "\n";
            $out = "---\n" . $body . "...\n";
        } else {
            $s = is_array($data) ? '[]' : _yaml_emit_scalar($data);
            $out = "--- " . $s . "\n...\n";
        }
        $nl = match ($linebreak) {
            YAML_CR_BREAK => "\r",
            YAML_CRLN_BREAK => "\r\n",
            default => "\n",
        };
        return $nl === "\n" ? $out : str_replace("\n", $nl, $out);
    }
}

if (!function_exists('yaml_parse')) {
    /** Index of the closing quote of a quoted token starting at $t[0], or -1. */
    function _yaml_parse_quote_end(string $t): int
    {
        $q = $t[0];
        for ($i = 1, $n = strlen($t); $i < $n; $i++) {
            if ($q === '"' && $t[$i] === '\\') {
                $i++;
            } elseif ($t[$i] === $q) {
                if ($q === "'" && ($t[$i + 1] ?? '') === "'") {
                    $i++;
                    continue;
                }
                return $i;
            }
        }
        return -1;
    }

    /** Removes a trailing "# comment" (quote-aware) and trailing spaces. */
    function _yaml_parse_strip(string $s): string
    {
        $q = null;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            $prev = $i > 0 ? $s[$i - 1] : ' ';
            if ($q === '"') {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '"') {
                    $q = null;
                }
            } elseif ($q === "'") {
                if ($c === "'") {
                    $q = null;
                }
            } elseif (($c === '"' || $c === "'") && strpos(" \t[{,", $prev) !== false) {
                $q = $c;
            } elseif ($c === '#' && ($prev === ' ' || $prev === "\t")) {
                return rtrim(substr($s, 0, $i));
            }
        }
        return rtrim($s);
    }

    function _yaml_parse_quoted(string $t): string
    {
        $e = _yaml_parse_quote_end($t);
        if ($e < 0) {
            throw new RuntimeException('unterminated quoted string');
        }
        if ($e !== strlen($t) - 1) {
            throw new RuntimeException('unexpected text after quoted string');
        }
        $body = substr($t, 1, -1);
        if ($t[0] === "'") {
            return str_replace("''", "'", $body);
        }
        return preg_replace_callback('/\\\\(x[0-9a-fA-F]{2}|u[0-9a-fA-F]{4}|U[0-9a-fA-F]{8}|.)/su', function ($m) {
            $c = $m[1];
            if (strlen($c) > 1) {
                return mb_chr((int)hexdec(substr($c, 1)), 'UTF-8');
            }
            return match ($c) {
                'n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0", 'e' => "\e",
                'a' => "\x07", 'b' => "\x08", 'f' => "\f", 'v' => "\v",
                '\\', '"', '/', ' ' => $c,
                default => throw new RuntimeException('unknown escape \\' . $c),
            };
        }, $body);
    }

    function _yaml_parse_scalar(string $t): mixed
    {
        if ($t === '' || $t === '~' || strcasecmp($t, 'null') === 0) {
            return null;
        }
        if ($t[0] === '"' || $t[0] === "'") {
            return _yaml_parse_quoted($t);
        }
        if ($t === '[]' || $t === '{}') {
            return [];
        }
        if ($t[0] === '[' || $t[0] === '{') {
            throw new RuntimeException('flow collections are not supported');
        }
        $l = strtolower($t);
        if (in_array($l, ['true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($l, ['false', 'no', 'off'], true)) {
            return false;
        }
        if (preg_match('/^[-+]?(\.inf)$/', $l)) {
            return $l[0] === '-' ? -INF : INF;
        }
        if ($l === '.nan') {
            return NAN;
        }
        if (preg_match('/^[-+]?(0|[1-9][0-9]*)$/', $t)) {
            return abs((float)$t) < 9.2e18 ? (int)$t : (float)$t;
        }
        if (preg_match('/^0x[0-9a-f]+$/', $l)) {
            return hexdec(substr($l, 2));
        }
        if (preg_match('/^0o?[0-7]+$/', $l)) {
            return octdec(ltrim($l, '0o'));
        }
        if (preg_match('/^[-+]?(\d+\.\d*|\.\d+|\d+(?=[eE]))([eE][-+]?\d+)?$/', $t)) {
            return (float)$t;
        }
        return $t;
    }

    /** Splits "key: rest" into [key, rest]; null if the line is not a mapping entry. */
    function _yaml_parse_split(string $t): ?array
    {
        if ($t[0] === '[' || $t[0] === '{') {
            return null;
        }
        if ($t[0] === '"' || $t[0] === "'") {
            $e = _yaml_parse_quote_end($t);
            if ($e < 0) {
                throw new RuntimeException('unterminated quoted string');
            }
            $after = ltrim(substr($t, $e + 1), ' ');
            if ($after !== '' && $after[0] === ':' && ($after === ':' || $after[1] === ' ')) {
                return [substr($t, 0, $e + 1), trim(substr($after, 1))];
            }
            return null;
        }
        $p = strpos($t, ': ');
        if ($p === false) {
            if (!str_ends_with($t, ':')) {
                return null;
            }
            $p = strlen($t) - 1;
        }
        return [rtrim(substr($t, 0, $p)), trim(substr($t, $p + 1))];
    }

    function _yaml_parse_is_dash(string $t): bool
    {
        return $t === '-' || str_starts_with($t, '- ');
    }

    function _yaml_parse_block(array &$L, int &$i, int $depth): mixed
    {
        if ($depth > 512) {
            throw new RuntimeException('maximum nesting depth exceeded');
        }
        [$ind, $t] = $L[$i];
        $n = count($L);
        $out = [];
        if (_yaml_parse_is_dash($t)) {
            while ($i < $n && $L[$i][0] === $ind && _yaml_parse_is_dash($L[$i][1])) {
                $rest = substr($L[$i][1], 1);
                $trim = ltrim($rest, ' ');
                if ($trim === '') {
                    $i++;
                    $out[] = ($i < $n && $L[$i][0] > $ind) ? _yaml_parse_block($L, $i, $depth + 1) : null;
                } else {
                    // re-queue the remainder as its own line at its real column
                    $L[$i] = [$ind + 1 + strlen($rest) - strlen($trim), $trim];
                    $out[] = _yaml_parse_block($L, $i, $depth + 1);
                }
            }
        } elseif (_yaml_parse_split($t) === null) {
            $i++;
            return _yaml_parse_scalar($t);
        } else {
            while ($i < $n && $L[$i][0] === $ind) {
                $kv = _yaml_parse_split($L[$i][1]);
                if ($kv === null) {
                    throw new RuntimeException('expected a "key: value" entry');
                }
                [$k, $rest] = $kv;
                $i++;
                if ($rest !== '') {
                    $v = _yaml_parse_scalar($rest);
                } elseif ($i < $n && ($L[$i][0] > $ind || ($L[$i][0] === $ind && _yaml_parse_is_dash($L[$i][1])))) {
                    $v = _yaml_parse_block($L, $i, $depth + 1);
                } else {
                    $v = null;
                }
                $key = _yaml_parse_scalar($k);
                $out[is_int($key) || is_string($key) ? $key : (string)$key] = $v;
            }
        }
        if ($i < $n && $L[$i][0] > $ind) {
            throw new RuntimeException('bad indentation');
        }
        return $out;
    }

    /**
     * Basic yaml_parse(): block mappings/sequences, plain and quoted scalars,
     * comments. $pos, $callbacks are ignored; only the first document is read.
     * Not supported: flow collections (except [] and {}), block scalars
     * (| and >), anchors, tags, multi-line scalars.
     */
    function yaml_parse(string $input, int $pos = 0, ?int &$ndocs = null, array $callbacks = []): mixed
    {
        try {
            $L = [];
            foreach (preg_split('/\r\n|\n|\r/', $input) as $raw) {
                $line = _yaml_parse_strip($raw);
                $text = ltrim($line, ' ');
                if ($text === '') {
                    continue;
                }
                $ind = strlen($line) - strlen($text);
                if ($text[0] === "\t") {
                    throw new RuntimeException('tabs are not allowed for indentation');
                }
                if ($text === '---' || str_starts_with($text, '--- ')) {
                    if ($L) {
                        break;
                    }
                    $rest = trim(substr($text, 3));
                    if ($rest !== '') {
                        $L[] = [0, $rest];
                    }
                } elseif ($text === '...') {
                    break;
                } elseif ($text[0] === '%' && !$L) {
                    continue;
                } else {
                    $L[] = [$ind, $text];
                }
            }
            $ndocs = 1;
            if (!$L) {
                return null;
            }
            $i = 0;
            $v = _yaml_parse_block($L, $i, 0);
            if ($i < count($L)) {
                throw new RuntimeException('unexpected content');
            }
            return $v;
        } catch (RuntimeException $e) {
            trigger_error('yaml_parse(): ' . $e->getMessage(), E_USER_WARNING);
            return false;
        }
    }
}
