<?php
/**
 * Plugin Name: Multi Uploader for Gravity Forms
 * Plugin URI: https://github.com/sh1zen/gf-multi-uploader
 * Description: Multiple file uploader and editor with advanced options for Gravity Forms plugin.
 * Version: 1.0.2
 * Author: sh1zen
 * Author URI: https://sh1zen.github.io/
 * Text Domain: gfmu-locale
 * Domain Path: /languages
 */

const GF_MULTI_UPLOADER_VERSION = '1.0.2';

define('GFMU_PLUGIN_DIR', dirname(__FILE__) . '/');
define('GFMU_PLUGIN_URL', plugin_dir_url(__FILE__));

const GFMU_INC_PATH = GFMU_PLUGIN_DIR . 'inc/';

require_once(GFMU_PLUGIN_DIR . 'GFMUPluginSetup.php');

require_once(GFMU_PLUGIN_DIR . 'GFMUAddon.class.php');

GFMUPluginSetup::Init();