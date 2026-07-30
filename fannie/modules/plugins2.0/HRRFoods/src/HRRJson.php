<?php
/*******************************************************************************

    HRR Foods Plugin — Tiny JSON helper

    Wraps json_encode / json_decode with the two behaviours we rely on everywhere:
    - always-associative-array decode
    - encode failures throw HRRJsonException instead of returning false

    We don't pull in a JSON library; PHP's built-in is sufficient.

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

namespace COREPOS\Fannie\Plugin\HRRFoods;

class HRRJsonException extends \RuntimeException {}

class HRRJson
{
    /**
     * Encode a value to JSON. Throws on failure.
     *
     * @param mixed $value
     * @return string
     * @throws HRRJsonException
     */
    public static function encode($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new HRRJsonException('json_encode failed: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Decode a JSON string to an associative array. Throws on failure.
     *
     * @param string $json
     * @param bool $assoc when true (default) returns array; false returns stdClass
     * @return mixed
     * @throws HRRJsonException
     */
    public static function decode($json, $assoc = true)
    {
        $value = json_decode((string)$json, $assoc);
        if ($value === null && strtolower(trim((string)$json)) !== 'null') {
            throw new HRRJsonException('json_decode failed: ' . json_last_error_msg());
        }
        return $value;
    }

    /**
     * Decode if non-empty, else return the default. Never throws on empty input.
     *
     * @param string $json
     * @param mixed $default
     * @return mixed
     */
    public static function decodeOr($json, $default = array())
    {
        if (!is_string($json) || trim($json) === '') {
            return $default;
        }
        try {
            return self::decode($json, true);
        } catch (HRRJsonException $e) {
            return $default;
        }
    }
}
