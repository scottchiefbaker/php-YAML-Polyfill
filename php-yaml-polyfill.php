<?php
declare(strict_types=1);

/**
 * Basic yaml_emit() polyfill (PHP 8.2+).
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

if (!function_exists('_yaml_emit_scalar')) {
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
}

if (!function_exists('yaml_emit')) {
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
