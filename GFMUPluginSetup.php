<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2021
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

class GFMUPluginSetup
{
    public static function Init()
    {
        if (!class_exists('GFForms')) {
            return;
        }

        if (method_exists('GFForms', 'include_addon_framework')) {
            add_action('gform_loaded', ['GFMUPluginSetup', 'boot'], 5);
        }

        self::load_textdomain('gf-multi-uploader');

        //Set Activation/Deactivation hooks
        register_activation_hook(__FILE__, array('GFMUPluginSetup', 'plugin_activation'));
        register_deactivation_hook(__FILE__, array('GFMUPluginSetup', 'plugin_deactivation'));
    }

    public static function boot()
    {
        require_once GFMU_INC_PATH . 'GFMUHandlePluploader.class.php';

        GFAddOn::register('GFMUAddon');
    }

    /**
     * Loads text domain for the plugin.
     *
     * @return bool
     * @action plugins_loaded
     */
    private static function load_textdomain($domain)
    {
        $locale = apply_filters('gfmu_plugin_locale', get_locale(), $domain);

        $mo_file = $domain . '-' . $locale . '.mo';

        if (load_textdomain($domain, WP_LANG_DIR . '/plugins/gf-multi-uploader/' . $mo_file))
            return true;

        return load_textdomain($domain, GFMU_PLUGIN_DIR . 'languages/' . $mo_file);
    }

}