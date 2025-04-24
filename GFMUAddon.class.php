<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

if (!class_exists('GFForms')) {
    return;
}

GFForms::include_addon_framework();

class GFMUAddon extends GFAddOn
{
    private static ?GFMUAddon $_instance = null;

    protected $_version = GF_MULTI_UPLOADER_VERSION;
    protected $_min_gravityforms_version = '1.9';
    protected $_slug = 'gf_multiuploader';
    protected $_path = 'gf_multi-uploader/gf_multi-uploader.php';
    protected $_full_path = __FILE__;
    protected $_title = 'Gravity Forms Multi Uploader';
    protected $_short_title = 'Multi Uploader';

    private $plugin_options;

    private $pluploaderHandler;

    /**
     * Get an instance of this class.
     *
     * @return GFMUAddon
     */
    public static function get_instance(): GFMUAddon
    {
        if (!self::$_instance) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public static function gform_after_create_post($post_id, $entry, $form): void
    {
        global $wpdb;

        $fields = GFAPI::get_fields_by_type($form, array('multi-uploader'));

        $save_to_meta = [];

        foreach ($fields as $field) {

            $media_entries = false;

            if (isset($entry[$field->id])) {
                $media_entries = maybe_unserialize($entry[$field->id]);
                $media_entries = apply_filters("gfmu_before_attach_uploads", $media_entries, $entry, $field->id);
            }

            if (empty($media_entries)) {
                continue;
            }

            foreach ($media_entries as $file_upload_number => $media) {

                if ($media['attachment_id']) {
                    $attachment_id = $media['attachment_id'];
                }
                else {
                    $attachment_id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE guid LIKE '%s' AND post_type = 'attachment' AND post_parent = '{$post_id}';", '%' . $wpdb->esc_like($media['t_name']) . '%'));
                }

                if (!$attachment_id) {
                    continue;
                }

                if (!empty($field->gfmu_save_to_meta)) {
                    $save_to_meta[] = $attachment_id;
                }
                else {

                    wp_update_post(array('ID' => $attachment_id, 'post_parent' => $post_id));

                    $wpdb->query("UPDATE {$wpdb->posts} SET menu_order = {$file_upload_number} WHERE ID = {$attachment_id};");
                }
            }

            if (!empty($field->gfmu_save_to_meta)) {

                update_post_meta($post_id, $field->gfmu_save_to_meta, $save_to_meta);
            }
        }
    }

    public function init()
    {
        // Get minimum requirements state.
        $meets_requirements = $this->meets_minimum_requirements();

        if (RG_CURRENT_PAGE == 'admin-ajax.php') {

            //If gravity forms is supported, initialize AJAX
            if ($this->is_gravityforms_supported() and $meets_requirements['meets_requirements']) {
                $this->init_ajax();
            }
        }
        elseif (is_admin()) {
            $this->init_admin();
        }
        else {

            if ($this->is_gravityforms_supported() and $meets_requirements['meets_requirements']) {
                $this->init_frontend();
            }
        }

        // Change 1 in gform_after_create_post_1 to your form id number.
        add_filter('gform_after_create_post', array('GFMUAddon', 'gform_after_create_post'), 10, 3);
    }

    public function init_ajax()
    {
        parent::init_ajax();

        //Actions to handle ajax upload requests
        add_action('wp_ajax_nopriv_gfmu-plupload-submit', array($this->pluploaderHandler, 'plupload_ajax_submit'));
        add_action('wp_ajax_gfmu-plupload-submit', array($this->pluploaderHandler, 'plupload_ajax_submit'));

        //Actions to handle ajax delete requests
        add_action('wp_ajax_nopriv_gfmu_delete_file', array($this->pluploaderHandler, 'plupload_ajax_delete_file'));
        add_action('wp_ajax_gfmu_delete_file', array($this->pluploaderHandler, 'plupload_ajax_delete_file'));

        //Actions to handle ajax download requests
        add_action('wp_ajax_nopriv_gfmu_download_file', array($this->pluploaderHandler, 'plupload_ajax_download_file'));
        add_action('wp_ajax_gfmu_download_file', array($this->pluploaderHandler, 'plupload_ajax_download_file'));
    }

    public function init_admin()
    {
        parent::init_admin();

        add_filter('gform_tooltips', array($this, 'tooltips'));
        add_action('gform_field_advanced_settings', array($this, 'field_advanced_settings'), 10, 2);
        add_action('gform_field_standard_settings', array($this, 'field_standard_settings'), 10, 2);
    }

    /**
     * Include the field early, so it is available when entry exports are being performed.
     * @throws \Exception
     */
    public function pre_init()
    {
        parent::pre_init();

        $set_options = wp_parse_args($this->get_plugin_settings(), array(
            'locale'             => 'it',
            'auto_upload'        => true,
            'duplicates_status'  => true,
            'drag_drop_status'   => true,
            'list_view'          => true,
            'thumb_view'         => true,
            'rename_file_status' => true,
            'max_files'          => 10,
            'max_file_size'      => '10mb',
            'ui_view'            => 'thumbs',
            'chunk_size'         => '2mb',
            'files_filters'      => "jpg,png,jpeg,webp,gif"
        ));

        $this->plugin_options = [
            'locale'               => $set_options['locale'],
            'auto_upload'          => boolval($set_options['auto_upload']),
            'duplicates_status'    => boolval($set_options['duplicates_status']),
            'drag_drop_status'     => boolval($set_options['drag_drop_status']),
            'list_view'            => boolval($set_options['list_view']),
            'thumb_view'           => boolval($set_options['thumb_view']),
            'rename_file_status'   => boolval($set_options['rename_file_status']),
            'max_files'            => intval($set_options['max_files']),
            'max_file_size'        => $set_options['max_file_size'],
            'ui_view'              => $set_options['ui_view'],
            'chunk_size'           => $set_options['chunk_size'],
            'browse_button_dom_id' => 'pickfiles',
            'save_to_meta'         => '',
            'filters'              => array(
                'files' => $set_options['files_filters'],
            ),
        ];

        $this->pluploaderHandler = GFMUHandlePluploader::getInstance();

        if ($this->is_gravityforms_supported() and class_exists('GF_Field')) {
            require_once(GFMU_INC_PATH . 'gf_multiuploader_field.class.php');
            GF_Fields::register(new GF_MultiUploader_Field());
        }
    }

    public function get_plupload_settings($setting_path = '', $default = false)
    {
        $settings = $this->plugin_options;

        // remove consecutive dots and add a last one for while loop
        $setting_path = preg_replace('#\.+#', '.', $setting_path . '.');

        while (($pos = strpos($setting_path, '.')) !== false) {

            $slug = substr($setting_path, 0, $pos);

            if (empty($slug)) {
                break;
            }

            if (!isset($settings[$slug])) {
                return $default;
            }

            $settings = $settings[$slug];

            $setting_path = substr($setting_path, $pos + 1);
        }

        if (is_array($settings) or is_object($settings)) {
            $settings = wp_parse_args($settings, $default);
        }

        return $settings;
    }

    /**
     * Add the tooltips for the field.
     *
     * @param array $tooltips An associative array of tooltips where the key is the tooltip name and the value is the tooltip.
     */
    public function tooltips(array $tooltips): array
    {
        $tooltips['gfmu_save_to_meta'] = sprintf('<h6>%s</h6>%s', esc_html__('Save to meta', 'gfmu-locale'), esc_html__('If it is set, will save all the data about uploads into the specified meta.', 'gfmu-locale'));
        $tooltips['gfmu_max_files'] = sprintf('<h6>%s</h6>%s', esc_html__('Max number of files', 'gfmu-locale'), esc_html__('Specify the max number of files the user can upload.', 'gfmu-locale'));
        $tooltips['gfmu_file_size'] = sprintf('<h6>%s</h6>%s', esc_html__('Max file size', 'gfmu-locale'), esc_html__('Specify the max size for each file uploaded.', 'gfmu-locale'));
        $tooltips['gfmu_file_extensions'] = sprintf('<h6>%s</h6>%s', esc_html__('Allowed extensions', 'gfmu-locale'), esc_html__('Specify the allowed extensions.', 'gfmu-locale'));

        return $tooltips;
    }

    /**
     * Add the custom setting for the Simple field to the Appearance tab.
     *
     * @param int $position The position the settings should be located at.
     * @param int $form_id The ID of the form currently being edited.
     */
    public function field_standard_settings(int $position, int $form_id)
    {
        // Add our custom setting just before the 'Custom CSS Class' setting.
        if ($position == 50) {
            ?>
            <li class="gfmu_file_extensions_setting field_setting">
                <label for="gfmu_file_extensions" class="section_label">
                    <?php esc_html_e('Allowed file extensions', 'gfmu-locale'); ?>
                    <?php gform_tooltip('gfmu_file_extensions') ?>
                </label>
                <input type="text" onkeyup="SetFieldProperty('gfmu_file_extensions', this.value);" size="40"
                       id="gfmu_file_extensions">
                <div>
                    <small><?php esc_html_e("Separated with commas (i.e. webp, jpg, jpeg, gif, png, pdf)", 'gfmu-locale'); ?></small>
                </div>
            </li>
            <li class="gfmu_max_files_setting field_setting">
                <label for="gfmu_max_files" class="section_label">
                    <?php esc_html_e('Max number of files', 'gfmu-locale'); ?>
                    <?php gform_tooltip('gfmu_max_files') ?>
                </label>
                <input type="number" onkeyup="SetFieldProperty('gfmu_max_files', this.value);" size="40"
                       id="gfmu_max_files">
                <div>
                    <small><?php esc_html_e("Number of files users can upload.", 'gfmu-locale'); ?></small>
                </div>
            </li>
            <li class="gfmu_file_size_setting field_setting">
                <label for="gfmu_file_size" class="section_label">
                    <?php esc_html_e('Maximum file size (MB)', 'gfmu-locale'); ?>
                    <?php gform_tooltip('gfmu_file_size') ?>
                </label>
                <input type="text" onkeyup="SetFieldProperty('gfmu_file_size', this.value);" size="40"
                       id="gfmu_file_size">
                <div>
                    <small><?php esc_html_e("Max file size, support KB, MB, GB units.", 'gfmu-locale'); ?></small>
                </div>
            </li>
            <?php
        }
    }

    /**
     * Add the custom setting for the Simple field to the Appearance tab.
     *
     * @param int $position The position the settings should be located at.
     * @param int $form_id The ID of the form currently being edited.
     */
    public function field_advanced_settings(int $position, int $form_id)
    {
        // Add our custom setting just before the 'Custom CSS Class' setting.
        if ($position == 50) {
            ?>
            <li class="gfmu_save_to_meta_setting field_setting" style="display: list-item;">
                <label for="gfmu_save_to_meta" class="section_label">
                    <?php esc_html_e('Save to meta', 'gfmu-locale'); ?>
                    <?php gform_tooltip('gfmu_save_to_meta') ?>
                </label>
                <input type="text" onkeyup="SetFieldProperty('gfmu_save_to_meta', this.value);" size="40"
                       id="gfmu_save_to_meta">
                <div>
                    <small><?php esc_html_e("If it's set will save the uploaded data into the specified meta value, comma separated.", 'gfmu-locale'); ?></small>
                </div>
            </li>
            <?php
        }
    }

    /**
     * Configures the settings which should be rendered on the add-on settings tab.
     */
    public function plugin_settings_fields()
    {
        $locales = array_map(function ($file) {
            return [
                'label' => ucfirst(pathinfo($file, PATHINFO_FILENAME)),
                'value' => pathinfo($file, PATHINFO_FILENAME),
            ];
        }, glob(GFMU_PLUGIN_DIR . 'assets/custom-plupload/i18n/*.js', \GLOB_NOSORT));

        return array(
            array(
                'title'  => esc_html__('Multi Uploader Settings', 'gfmu-locale'),
                'fields' => array(
                    array(
                        'label'   => esc_html__('Auto upload', 'gfmu-locale'),
                        'type'    => 'checkbox',
                        'name'    => 'auto_upload',
                        'tooltip' => esc_html__('By selecting this each file will be auto uploaded.', 'gfmu-locale'),
                        'choices' => array(
                            array(
                                'label' => esc_html__('Enabled', 'gfmu-locale'),
                                'name'  => 'auto_upload',
                            ),
                        ),
                    ),
                    array(
                        'label'   => esc_html__('Detect duplicates', 'gfmu-locale'),
                        'type'    => 'checkbox',
                        'name'    => 'duplicates_status',
                        'tooltip' => esc_html__('If enabled, will try to detect if two or more files are the same.', 'gfmu-locale'),
                        'choices' => array(
                            array(
                                'label' => esc_html__('Enabled', 'gfmu-locale'),
                                'name'  => 'duplicates_status',
                            ),
                        ),
                    ),
                    array(
                        'label'   => esc_html__('Drag&Drop', 'gfmu-locale'),
                        'type'    => 'checkbox',
                        'name'    => 'drag_drop_status',
                        'choices' => array(
                            array(
                                'label' => esc_html__('Enabled', 'gfmu-locale'),
                                'name'  => 'drag_drop_status',
                            ),
                        ),
                    ),
                    array(
                        'label'   => esc_html__('Rename files uploaded', 'gfmu-locale'),
                        'type'    => 'checkbox',
                        'name'    => 'rename_file_status',
                        'tooltip' => esc_html__('If enabled will prevent with some security issues.', 'gfmu-locale'),
                        'choices' => array(
                            array(
                                'label' => esc_html__('Enabled', 'gfmu-locale'),
                                'name'  => 'rename_file_status',
                            ),
                        ),
                    ),
                    array(
                        'label'   => esc_html__('Enabled view types', 'gfmu-locale'),
                        'type'    => 'checkbox',
                        'name'    => 'checkboxgroup',
                        'choices' => array(
                            array(
                                'label' => esc_html__('List view', 'gfmu-locale'),
                                'name'  => 'list_view',

                            ),
                            array(
                                'label' => esc_html__('Thumb view', 'gfmu-locale'),
                                'name'  => 'thumb_view',
                            ),
                        ),
                    ),
                    array(
                        'label'   => esc_html__('Default UI view', 'gfmu-locale'),
                        'type'    => 'radio',
                        'name'    => 'ui_view',
                        'choices' => array(
                            array(
                                'label' => esc_html__('Thumb view', 'gfmu-locale'),
                                'name'  => 'thumbs',
                                'value' => 'thumbs',
                            ),
                            array(
                                'label' => esc_html__('List view', 'gfmu-locale'),
                                'name'  => 'list',
                                'value' => 'list',
                            ),
                        ),
                    ),
                    array(
                        'label'   => esc_html__('Plupload locale', 'gfmu-locale'),
                        'type'    => 'select',
                        'name'    => 'locale',
                        'tooltip' => esc_html__('This is the locale of the plupload form.', 'gfmu-locale'),
                        'choices' => $locales,
                    ),
                    array(
                        'label'   => esc_html__('Default max upload files', 'gfmu-locale'),
                        'type'    => 'text',
                        'name'    => 'max_files',
                        'tooltip' => esc_html__('Indicate here the default files upload number.', 'gfmu-locale'),
                        'class'   => 'medium',
                        'value'   => '10',
                    ),
                    array(
                        'label'   => esc_html__('Default max upload size', 'gfmu-locale'),
                        'type'    => 'text',
                        'name'    => 'max_files',
                        'tooltip' => esc_html__('Indicate here the default max upload size, allowed units are KB, MB, GB.', 'gfmu-locale'),
                        'class'   => 'medium',
                        'value'   => '10MB',
                    ),
                    array(
                        'label' => esc_html__('Default allowed extensions filters', 'gfmu-locale'),
                        'type'  => 'text',
                        'name'  => 'files_filters',
                        'class' => 'medium',
                        'value' => 'jpg,jpeg,png,webp,gif',
                    ),
                ),
            ),
        );
    }

    /**
     * Include my_script.js when the form contains a 'simple' type field.
     */
    public function scripts()
    {
        $min = ((defined('SCRIPT_DEBUG') and SCRIPT_DEBUG) or isset($_GET['gform_debug'])) ? '' : '.min';

        $plupload_i18n_script = apply_filters('gfmu_uploader_i18n_script', $this->plugin_options['locale'], 'en');

        if (empty($plupload_i18n_script)) {
            $plupload_i18n_script = 'en';
        }

        if (is_admin()) {
            $scripts = array();
        }
        else {
            $scripts = array(

                array(
                    'handle'  => 'plupload-jquery-ui',
                    'src'     => GFMU_PLUGIN_URL . "assets/custom-plupload/jquery.ui.plupload/jquery.ui.plupload{$min}.js",
                    'version' => $this->_version,
                    'deps'    => array('jquery', 'plupload', 'plupload-all', 'jquery-ui-core', 'jquery-ui-widget', 'jquery-ui-button', 'jquery-ui-progressbar', 'jquery-ui-sortable'),
                    'enqueue' => array(
                        array('field_types' => array('multi-uploader')),
                    ),
                ),

                //Register plupload init script
                array(
                    'handle'  => 'gfmu-pluploader-init',
                    'src'     => GFMU_PLUGIN_URL . "assets/js/init{$min}.js",
                    'version' => $this->_version,
                    'deps'    => array('jquery', 'plupload', 'plupload-all'),
                    'enqueue' => array(
                        array('field_types' => array('multi-uploader')),
                    ),
                ),

                //Register request plupload i18n script if found
                array(
                    'handle'  => 'gfmu-pluploader-locale',
                    'src'     => GFMU_PLUGIN_URL . "assets/custom-plupload/i18n/{$plupload_i18n_script}.js",
                    'version' => $this->_version,
                    'deps'    => ['jquery', 'plupload', 'plupload-all'],
                    'enqueue' => array(
                        array('field_types' => array('multi-uploader')),
                    ),
                ),
            );
        }

        return array_merge(parent::scripts(), $scripts);
    }

    /**
     * Include my_styles.css when the form contains a 'simple' type field.
     *
     * @return array
     */
    public function styles()
    {
        $min = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG || isset($_GET['gform_debug']) ? '' : '.min';

        if (is_admin()) {
            $styles = array(
                array(
                    'handle'  => 'form-display',
                    'src'     => GFMU_PLUGIN_URL . "assets/css/form-display.css",
                    'version' => $this->_version,
                    'enqueue' => array(
                        array('field_types' => array('multi-uploader'))
                    )
                )
            );
        }
        else {
            $styles = array(
                array(
                    'handle'  => 'jquery-ui-css',
                    'src'     => GFMU_PLUGIN_URL . "assets/jquery-ui/jquery-ui{$min}.css",
                    'version' => $this->_version,
                    'enqueue' => array(
                        array('field_types' => array('multi-uploader'))
                    )
                ),
                array(
                    'handle'  => 'plupload-jquery-ui-css',
                    'src'     => GFMU_PLUGIN_URL . "assets/custom-plupload/jquery.ui.plupload/css/jquery.ui.plupload.css",
                    'version' => $this->_version,
                    'deps'    => array('jquery-ui-css'),
                    'enqueue' => array(
                        array('field_types' => array('multi-uploader'))
                    )
                )
            );
        }

        return array_merge(parent::styles(), $styles);
    }
}
