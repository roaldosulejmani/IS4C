<?php
/*******************************************************************************

    HRR Foods Plugin — Settings Loader

    Centralizes the "read version-2 plugin settings" pattern so tasks don't
    each duplicate it. CORRECTION FROM PLAN: $this->config->get('PLUGIN_SETTINGS')
    reads the version-1 config.php array; for version=2 plugins, the right
    way is to instantiate the plugin class and call getSettings().

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

namespace COREPOS\Fannie\Plugin\HRRFoods;

class HRRSettings
{
    /**
     * Load the plugin's settings. Returns an associative array of
     * (un-namespaced) key => value pairs. Falls back to declared defaults
     * for any missing setting so callers can always rely on the keys
     * being present.
     *
     * @return array<string,string>
     */
    public static function load()
    {
        $plugin = new \HRRFoods();
        $settings = $plugin->getSettings();
        $defaults = array();
        foreach ($plugin->plugin_settings as $key => $def) {
            $defaults[$key] = isset($def['default']) ? (string)$def['default'] : '';
        }
        foreach ($defaults as $key => $value) {
            if (!isset($settings[$key]) || $settings[$key] === '') {
                $settings[$key] = $value;
            }
        }
        return $settings;
    }

    /**
     * Return the directory where the plugin writes runtime files.
     * Always the absolute path to <plugin>/noauto/.
     *
     * @return string
     */
    public static function pluginDir()
    {
        return dirname(__DIR__);
    }

    /**
     * Return the absolute path to the token cache file.
     *
     * @return string
     */
    public static function tokenCacheFile()
    {
        return self::pluginDir() . '/noauto/.token-cache.json';
    }
}
