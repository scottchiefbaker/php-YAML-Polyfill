<?php
declare(strict_types=1);

/**
 * Basic yaml_emit(), yaml_parse(), yaml_emit_file(), yaml_parse_file()
 * and yaml_parse_url() polyfill (PHP 8.2+).
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

    /** Parses one single-line flow value ([..], {..}, quoted or plain) at $t[$p]. */
    function _yaml_parse_flow(string $t, int &$p, int $depth): mixed
    {
        if ($depth > 512) {
            throw new RuntimeException('maximum nesting depth exceeded');
        }
        $n = strlen($t);
        $skip = function () use ($t, $n, &$p): void {
            while ($p < $n && ($t[$p] === ' ' || $t[$p] === "\t")) {
                $p++;
            }
        };
        $skip();
        $c = $t[$p] ?? '';
        if ($c === '[' || $c === '{') {
            $close = $c === '[' ? ']' : '}';
            $out = [];
            $p++;
            while (true) {
                $skip();
                if ($p >= $n) {
                    throw new RuntimeException('unterminated flow collection');
                }
                if ($t[$p] === $close) {
                    $p++;
                    return $out;
                }
                if ($close === ']') {
                    $out[] = _yaml_parse_flow($t, $p, $depth + 1);
                } else {
                    $k = $t[$p];
                    if ($k === '[' || $k === '{') {
                        throw new RuntimeException('complex mapping keys are not supported');
                    }
                    if ($k === '"' || $k === "'") {
                        $e = _yaml_parse_quote_end(substr($t, $p));
                        if ($e < 0) {
                            throw new RuntimeException('unterminated quoted string');
                        }
                        $key = _yaml_parse_quoted(substr($t, $p, $e + 1));
                        $p += $e + 1;
                    } else {
                        $s = $p;
                        while ($p < $n && !in_array($t[$p], [',', '}'], true)
                            && !($t[$p] === ':' && ($p + 1 >= $n || in_array($t[$p + 1], [' ', ',', '}'], true)))) {
                            $p++;
                        }
                        $key = _yaml_parse_scalar(trim(substr($t, $s, $p - $s)));
                    }
                    $skip();
                    if ($p >= $n || $t[$p] !== ':') {
                        throw new RuntimeException('flow mapping entry needs a "key: value"');
                    }
                    $p++;
                    $v = _yaml_parse_flow($t, $p, $depth + 1);
                    $out[is_int($key) || is_string($key) ? $key : (string)$key] = $v;
                }
                $skip();
                if ($p < $n && $t[$p] === ',') {
                    $p++;
                } elseif ($p >= $n || $t[$p] !== $close) {
                    throw new RuntimeException($p >= $n ? 'unterminated flow collection' : 'expected "," or "' . $close . '"');
                }
            }
        }
        if ($c === '"' || $c === "'") {
            $e = _yaml_parse_quote_end(substr($t, $p));
            if ($e < 0) {
                throw new RuntimeException('unterminated quoted string');
            }
            $q = _yaml_parse_quoted(substr($t, $p, $e + 1));
            $p += $e + 1;
            return $q;
        }
        $s = $p;
        while ($p < $n && !in_array($t[$p], [',', ']', '}'], true)) {
            $p++;
        }
        $tok = trim(substr($t, $s, $p - $s));
        if ($tok === '') {
            throw new RuntimeException('empty flow entry');
        }
        return _yaml_parse_scalar($tok);
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
            $p = 0;
            $v = _yaml_parse_flow($t, $p, 0);
            if (trim(substr($t, $p)) !== '') {
                throw new RuntimeException('unexpected text after flow collection');
            }
            return $v;
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
     * If $text is a block scalar header ("key: |", "- >-", "|2" after "---"),
     * consumes the content lines that follow $raws[$r] (advancing $r) and returns
     * the header re-written with the content as a double-quoted scalar.
     */
    function _yaml_parse_block_scalar(array $raws, int &$r, string $text, int $ind, bool $isDoc): ?string
    {
        if (!preg_match('/^((?:-[ ]+)*)(.+?:[ ]+)?([|>])([-+1-9]{0,2})$/', $text, $m)
            || !preg_match('/^(?:[1-9][-+]?|[-+][1-9]?)?$/', $m[4])
            || (!$isDoc && $m[1] === '' && $m[2] === '')) {
            return null;
        }
        [, $dashes, $key, $style, $mods] = $m;
        if (str_contains($mods, '+')) {
            throw new RuntimeException('keep chomping (+) is not supported');
        }
        if ($isDoc) {
            $parent = -1;
        } elseif ($key !== '') {
            $parent = $ind + strlen($dashes);
        } else {
            $parent = $ind + strlen($dashes) - 2;
        }
        $explicit = (int)preg_replace('/\D/', '', $mods);
        $ci = $explicit > 0 ? max($parent, 0) + $explicit : 0;
        $lines = [];
        for ($j = $r + 1, $n = count($raws); $j < $n; $j++) {
            $raw = $raws[$j];
            if (trim($raw) === '') {
                $lines[] = '';
                continue;
            }
            $lead = strlen($raw) - strlen(ltrim($raw, ' '));
            if ($lead <= $parent || ($isDoc && ($raw === '---' || $raw === '...' || str_starts_with($raw, '--- ')))) {
                break;
            }
            if ($ci === 0) {
                $ci = $lead;
            } elseif ($lead < $ci) {
                throw new RuntimeException('bad indentation in block scalar');
            }
            $lines[] = substr($raw, $ci);
        }
        $r = $j - 1;
        while ($lines && end($lines) === '') {
            array_pop($lines);
        }
        if ($style === '|') {
            $str = implode("\n", $lines);
        } else {
            $str = '';
            $prevText = false;
            foreach ($lines as $l) {
                if ($l === '') {
                    $str .= "\n";
                    $prevText = false;
                } else {
                    $str .= ($prevText ? ' ' : '') . $l;
                    $prevText = true;
                }
            }
        }
        if ($lines && !str_contains($mods, '-')) {
            $str .= "\n";
        }
        $json = json_encode($str, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('invalid UTF-8 in block scalar');
        }
        return $dashes . $key . $json;
    }

    /**
     * Basic yaml_parse(): block mappings/sequences, plain and quoted scalars,
     * comments. $pos, $callbacks are ignored; only the first document is read.
     * Flow collections are supported on a single line only. Block scalars
     * (| and >) are supported with clip/strip chomping (no +). Not supported:
     * anchors, tags, multi-line plain/quoted scalars.
     */
    function yaml_parse(string $input, int $pos = 0, ?int &$ndocs = null, array $callbacks = []): mixed
    {
        try {
            $L = [];
            $raws = preg_split('/\r\n|\n|\r/', $input);
            for ($r = 0; $r < count($raws); $r++) {
                $line = _yaml_parse_strip($raws[$r]);
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
                        $L[] = [0, _yaml_parse_block_scalar($raws, $r, $rest, 0, true) ?? $rest];
                    }
                } elseif ($text === '...') {
                    break;
                } elseif ($text[0] === '%' && !$L) {
                    continue;
                } else {
                    $L[] = [$ind, _yaml_parse_block_scalar($raws, $r, $text, $ind, false) ?? $text];
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

if (!function_exists('yaml_emit_file')) {
    function yaml_emit_file(string $filename, mixed $data, int $encoding = YAML_ANY_ENCODING, int $linebreak = YAML_ANY_BREAK): bool
    {
        return file_put_contents($filename, yaml_emit($data, $encoding, $linebreak)) !== false;
    }
}

if (!function_exists('yaml_parse_file')) {
    function yaml_parse_file(string $filename, int $pos = 0, ?int &$ndocs = null, array $callbacks = []): mixed
    {
        $input = file_get_contents($filename);
        if ($input === false) {
            return false;
        }
        return yaml_parse($input, $pos, $ndocs, $callbacks);
    }
}

if (!function_exists('yaml_parse_url')) {
    function yaml_parse_url(string $url, int $pos = 0, ?int &$ndocs = null, array $callbacks = []): mixed
    {
        $input = file_get_contents($url);
        if ($input === false) {
            return false;
        }
        return yaml_parse($input, $pos, $ndocs, $callbacks);
    }
}
