<?php
/*******************************************************************************

    HRR Foods Plugin — Per-store Account Resolution

    A single JSON object in PluginSettings maps FANNIE storeID -> HRR account code.
    This class loads, validates, and queries that map. Static API so tasks and
    admin pages can call it without a long-lived instance.

    Storage:
        PluginSettings.name  = "HRRFoods.HRRStoreMap"
        PluginSettings.setting = '{"0":"CHICAGO-HQ","1":"CHICAGO-001",...}'

    The map is namespaced under the plugin (HRRFoods.*) so it never collides.

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

namespace COREPOS\Fannie\Plugin\HRRFoods;

class HRRStoreMap
{
    /** @var array<int,string> */
    private $map = array();

    /** @var bool */
    private $loaded = false;

    /**
     * Load the map from a settings array. The settings array is the full
     * PluginSettings key/value dict as returned by FanniePlugin::getSettings()
     * — i.e. NOT namespaced. We look up the 'HRRStoreMap' key directly.
     *
     * @param array<string,mixed> $settings
     * @return void
     */
    public function load(array $settings)
    {
        $this->map = array();
        $raw = isset($settings['HRRStoreMap']) ? (string)$settings['HRRStoreMap'] : '';
        $decoded = HRRJson::decodeOr($raw, array());
        if (!is_array($decoded)) {
            $this->loaded = true;
            return;
        }
        foreach ($decoded as $storeId => $accountCode) {
            $this->map[(int)$storeId] = (string)$accountCode;
        }
        $this->loaded = true;
    }

    /**
     * Return the HRR account code for a given FANNIE storeID, or null.
     *
     * @param int $storeId
     * @return string|null
     */
    public function resolve($storeId)
    {
        $storeId = (int)$storeId;
        if (isset($this->map[$storeId]) && $this->map[$storeId] !== '') {
            return $this->map[$storeId];
        }
        return null;
    }

    /**
     * Return the full map. Keys are int, values are string account codes.
     *
     * @return array<int,string>
     */
    public function all()
    {
        return $this->map;
    }

    /**
     * Return the account code for the configured default store, or null.
     *
     * @param int $defaultStoreId
     * @return string|null
     */
    public function accountCodeForDefault($defaultStoreId)
    {
        return $this->resolve((int)$defaultStoreId);
    }

    /**
     * Validate the raw JSON string. Returns a list of human-readable errors
     * (empty list = valid). Call this from the admin page on save.
     *
     * Rules:
     *  - Must be valid JSON (or empty).
     *  - Must be a JSON object (associative array), not a list.
     *  - Each key must parse as an integer.
     *  - Each value must be a non-empty string of [A-Za-z0-9._-]{1,64}.
     *
     * @param string $raw
     * @return array<int,string>
     */
    public function validate($raw)
    {
        $errors = array();
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return $errors; // empty is allowed — operator can fill in later
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null && strtolower($raw) !== 'null') {
            $errors[] = 'HRRStoreMap is not valid JSON: ' . json_last_error_msg();
            return $errors;
        }
        if (!is_array($decoded)) {
            $errors[] = 'HRRStoreMap must be a JSON object (e.g. {"1":"CHICAGO-001"}).';
            return $errors;
        }
        // Reject list-style ([...])
        if (array_keys($decoded) !== array_filter(array_keys($decoded), 'is_string')
            && $decoded !== array_values($decoded)) {
            // pure list
            $errors[] = 'HRRStoreMap must be a JSON object, not an array.';
            return $errors;
        }
        foreach ($decoded as $storeId => $accountCode) {
            if (!is_int($storeId) && !(is_string($storeId) && ctype_digit($storeId))) {
                $errors[] = "Store key '$storeId' is not a valid integer.";
                continue;
            }
            if (!is_string($accountCode) || $accountCode === '') {
                $errors[] = "Account code for store $storeId is missing.";
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $accountCode)) {
                $errors[] = "Account code '$accountCode' contains invalid characters. Allowed: A-Z a-z 0-9 . _ - (max 64 chars).";
            }
        }
        return $errors;
    }

    /**
     * Convenience: encode a map for storage in a settings field.
     *
     * @param array<int|string,string> $map
     * @return string JSON object
     */
    public static function encode(array $map)
    {
        $out = array();
        foreach ($map as $storeId => $accountCode) {
            $out[(int)$storeId] = (string)$accountCode;
        }
        return HRRJson::encode((object)$out);
    }
}
