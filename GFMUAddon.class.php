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
    protected $_path = 'gf-multi-uploader/gf-multi-uploader.php';
    protected $_full_path = __FILE__;
    protected $_title = 'Gravity Forms Multi Uploader';
    protected $_short_title = 'Multi Uploader';

    private array $plugin_options = [];

    private $pluploaderHandler;

    public static function get_instance(): GFMUAddon
    {
        if (!self::$_instance) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public static function normalize_extension_filters(string $extensions): string
    {
        $extensions = array_map(
            static function ($extension) {
                return strtolower(ltrim(trim($extension), '.'));
            },
            explode(',', $extensions)
        );

        $extensions = array_values(array_unique(array_filter($extensions)));

        return implode(',', $extensions);
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
                $media_entries = apply_filters('gfmu_before_attach_uploads', $media_entries, $entry, $field->id);
            }

            if (empty($media_entries)) {
                continue;
            }

            foreach ($media_entries as $file_upload_number => $media) {
                if ($media['attachment_id']) {
                    $attachment_id = $media['attachment_id'];
                } else {
                    $attachment_id = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts} WHERE guid LIKE '%s' AND post_type = 'attachment' AND post_parent = '{$post_id}';",
                            '%' . $wpdb->esc_like($media['t_name']) . '%'
                        )
                    );
                }

                if (!$attachment_id) {
                    continue;
                }

                if (!empty($field->gfmu_save_to_meta)) {
                    $save_to_meta[] = $attachment_id;
                } else {
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
        $meets_requirements = $this->meets_minimum_requirements();

        if (RG_CURRENT_PAGE == 'admin-ajax.php') {
            if ($this->is_gravityforms_supported() && $meets_requirements['meets_requirements']) {
                $this->init_ajax();
            }
        } elseif (is_admin()) {
            $this->init_admin();
        } else {
            if ($this->is_gravityforms_supported() && $meets_requirements['meets_requirements']) {
                $this->init_frontend();
            }
        }

        add_filter('gform_after_create_post', array('GFMUAddon', 'gform_after_create_post'), 10, 3);
    }

    public function init_ajax()
    {
        parent::init_ajax();

        add_action('wp_ajax_nopriv_gfmu-plupload-submit', array($this->pluploaderHandler, 'plupload_ajax_submit'));
        add_action('wp_ajax_gfmu-plupload-submit', array($this->pluploaderHandler, 'plupload_ajax_submit'));
        add_action('wp_ajax_nopriv_gfmu_delete_temp_file', array($this->pluploaderHandler, 'plupload_ajax_delete_temp_file'));
        add_action('wp_ajax_gfmu_delete_temp_file', array($this->pluploaderHandler, 'plupload_ajax_delete_temp_file'));
        add_action('wp_ajax_gfmu_delete_file', array($this->pluploaderHandler, 'plupload_ajax_delete_file'));
        add_action('wp_ajax_gfmu_download_file', array($this->pluploaderHandler, 'plupload_ajax_download_file'));
    }

    public function init_admin()
    {
        parent::init_admin();

        add_filter('gform_tooltips', array($this, 'tooltips'));
        add_action('gform_field_advanced_settings', array($this, 'field_advanced_settings'), 10, 2);
        add_action('gform_field_standard_settings', array($this, 'field_standard_settings'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    public function pre_init()
    {
        parent::pre_init();

        $this->plugin_options = array_merge(
            $this->get_default_behavior_settings(),
            array(
                'save_to_meta' => '',
                'style'        => $this->get_global_style_settings(),
            )
        );

        $this->pluploaderHandler = GFMUHandlePluploader::getInstance();

        if ($this->is_gravityforms_supported() && class_exists('GF_Field')) {
            require_once(GFMU_INC_PATH . 'GF_MultiUploader_Field.class.php');
            GF_Fields::register(new GF_MultiUploader_Field());
        }
    }

    public function get_default_behavior_settings(): array
    {
        return array(
            'locale'             => $this->get_default_locale(),
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
            'filters'            => array(
                'files' => 'jpg,png,jpeg,webp,gif',
            ),
        );
    }

    public function get_default_style_settings(): array
    {
        return array(
            'header_title'       => esc_html__('Select files', 'gfmu-locale'),
            'header_text'        => esc_html__('Add files to the upload queue and click the start button.', 'gfmu-locale'),
            'primary_color'      => '#1e7a3a',
            'primary_text_color' => '#ffffff',
            'surface_color'      => '#f4faf4',
            'border_color'       => '#d9e4da',
            'border_radius'      => 16,
            'panel_min_height'   => 420,
        );
    }

    public function get_global_style_settings(): array
    {
        $plugin_settings = (array)$this->get_plugin_settings();
        $style_settings = isset($plugin_settings['style']) ? (array)$plugin_settings['style'] : [];

        return $this->sanitize_style_settings($style_settings);
    }

    public function get_form_uploader_settings($form = null): array
    {
        $defaults = $this->get_default_behavior_settings();

        if (is_numeric($form) && (int)$form > 0) {
            $form = GFAPI::get_form((int)$form);
        }

        $saved_settings = [];

        if (is_array($form) && isset($form[$this->get_slug()]) && is_array($form[$this->get_slug()])) {
            $saved_settings = $form[$this->get_slug()];
        }

        $settings = array_merge($defaults, $this->sanitize_behavior_settings($saved_settings));
        $settings['style'] = $this->get_global_style_settings();

        return $settings;
    }

    public function get_plupload_settings($setting_path = '', $default = false)
    {
        $settings = $this->plugin_options;
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

        if (is_array($settings) || is_object($settings)) {
            $settings = wp_parse_args($settings, $default);
        }

        return $settings;
    }

    public function form_settings_page_title()
    {
        return esc_html__('Multi Uploader Defaults', 'gfmu-locale');
    }

    public function form_settings_fields($form): array
    {
        $settings = $this->get_form_uploader_settings($form);

        return array(
            array(
                'title'       => esc_html__('Uploader defaults for this form', 'gfmu-locale'),
                'description' => esc_html__('These values apply to Multi Uploader fields in the current form unless a field overrides them in the editor.', 'gfmu-locale'),
                'fields'      => array(
                    array(
                        'label'   => esc_html__('Auto upload', 'gfmu-locale'),
                        'type'    => 'checkbox',
                        'name'    => 'auto_upload',
                        'choices' => array(
                            array(
                                'label' => esc_html__('Upload files immediately after selection', 'gfmu-locale'),
                                'name'  => 'auto_upload',
                            ),
                        ),
                    ),
                    array(
                        'label'   => esc_html__('Detect duplicates', 'gfmu-locale'),
                        'type'    => 'checkbox',
                        'name'    => 'duplicates_status',
                        'choices' => array(
                            array(
                                'label' => esc_html__('Prevent duplicate uploads', 'gfmu-locale'),
                                'name'  => 'duplicates_status',
                            ),
                        ),
                    ),
                    array(
                        'label'   => esc_html__('Enable drag and drop', 'gfmu-locale'),
                        'type'    => 'checkbox',
                        'name'    => 'drag_drop_status',
                        'choices' => array(
                            array(
                                'label' => esc_html__('Allow drag and drop area', 'gfmu-locale'),
                                'name'  => 'drag_drop_status',
                            ),
                        ),
                    ),
                    array(
                        'label'   => esc_html__('Rename uploaded files', 'gfmu-locale'),
                        'type'    => 'checkbox',
                        'name'    => 'rename_file_status',
                        'choices' => array(
                            array(
                                'label' => esc_html__('Use generated target names for uploads', 'gfmu-locale'),
                                'name'  => 'rename_file_status',
                            ),
                        ),
                    ),
                    array(
                        'label'   => esc_html__('Available view types', 'gfmu-locale'),
                        'type'    => 'checkbox',
                        'name'    => 'gfmu_views',
                        'choices' => array(
                            array(
                                'label' => esc_html__('List view', 'gfmu-locale'),
                                'name'  => 'list_view',
                            ),
                            array(
                                'label' => esc_html__('Thumbnail view', 'gfmu-locale'),
                                'name'  => 'thumb_view',
                            ),
                        ),
                    ),
                    array(
                        'label'   => esc_html__('Default view', 'gfmu-locale'),
                        'type'    => 'radio',
                        'name'    => 'ui_view',
                        'choices' => array(
                            array(
                                'label' => esc_html__('Thumbnail view', 'gfmu-locale'),
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
                        'label' => esc_html__('Maximum number of files', 'gfmu-locale'),
                        'type'  => 'text',
                        'name'  => 'max_files',
                        'class' => 'small',
                        'value' => (string)$settings['max_files'],
                    ),
                    array(
                        'label'       => esc_html__('Maximum file size', 'gfmu-locale'),
                        'description' => esc_html__('Allowed units: KB, MB, GB. Example: 10mb', 'gfmu-locale'),
                        'type'        => 'text',
                        'name'        => 'max_file_size',
                        'class'       => 'small',
                        'value'       => $settings['max_file_size'],
                    ),
                    array(
                        'label'       => esc_html__('Chunk size', 'gfmu-locale'),
                        'description' => esc_html__('Chunk size used by Plupload. Example: 2mb', 'gfmu-locale'),
                        'type'        => 'text',
                        'name'        => 'chunk_size',
                        'class'       => 'small',
                        'value'       => $settings['chunk_size'],
                    ),
                    array(
                        'label'       => esc_html__('Allowed extensions', 'gfmu-locale'),
                        'description' => esc_html__('Comma separated list, for example: jpg,jpeg,png,webp,pdf', 'gfmu-locale'),
                        'type'        => 'text',
                        'name'        => 'files_filters',
                        'class'       => 'medium',
                        'value'       => $settings['filters']['files'],
                    ),
                ),
            ),
        );
    }

    public function enqueue_admin_assets($hook_suffix): void
    {
        if (!$this->is_style_settings_page()) {
            return;
        }

        wp_enqueue_style(
            'gfmu-admin-settings',
            GFMU_PLUGIN_URL . 'assets/css/gfmu-admin.css',
            array(),
            $this->_version
        );

        wp_enqueue_script(
            'gfmu-admin-preview',
            GFMU_PLUGIN_URL . 'assets/js/gfmu-admin.js',
            array(),
            $this->_version,
            true
        );
    }

    public function plugin_settings()
    {
        if (!GFCommon::current_user_can_any('gravityforms_edit_settings')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'gfmu-locale'));
        }

        $style_settings = $this->get_global_style_settings();
        $is_saved = false;
        $is_reset = false;

        if ('POST' === strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) && isset($_POST['gfmu_style_settings_nonce'])) {
            check_admin_referer('gfmu_save_style_settings', 'gfmu_style_settings_nonce');

            $plugin_settings = (array)$this->get_plugin_settings();
            $action = isset($_POST['gfmu_style_action']) ? sanitize_key(wp_unslash($_POST['gfmu_style_action'])) : 'save';

            if ($action === 'reset') {
                unset($plugin_settings['style']);
                $style_settings = $this->get_default_style_settings();
                $is_reset = true;
            } else {
                $submitted_style = isset($_POST['gfmu_style']) ? (array)wp_unslash($_POST['gfmu_style']) : array();
                $plugin_settings['style'] = $this->sanitize_style_settings($submitted_style);
                $style_settings = $plugin_settings['style'];
                $is_saved = true;
            }

            $this->update_plugin_settings($plugin_settings);

            $this->plugin_options['style'] = $style_settings;
        }
        ?>
        <div class="wrap gfmu-admin-page">
            <div class="gfmu-admin-hero">
                <div class="gfmu-admin-hero__content">
                    <span class="gfmu-admin-kicker"><?php esc_html_e('Gravity Forms add-on', 'gfmu-locale'); ?></span>
                    <h1><?php esc_html_e('Multi Uploader Style', 'gfmu-locale'); ?></h1>
                    <p class="gfmu-admin-intro">
                        <?php esc_html_e('Set the global visual language of the uploader. Behaviour defaults stay inside each form settings page, while this screen controls the shared look and feel.', 'gfmu-locale'); ?>
                    </p>
                </div>
                <div class="gfmu-admin-hero__meta">
                    <span class="gfmu-admin-pill"><?php esc_html_e('Global style', 'gfmu-locale'); ?></span>
                    <span class="gfmu-admin-pill gfmu-admin-pill--ghost"><?php esc_html_e('Live preview', 'gfmu-locale'); ?></span>
                </div>
            </div>

            <?php if ($is_saved) : ?>
                <div class="notice notice-success is-dismissible gfmu-admin-notice">
                    <p><?php esc_html_e('Style settings saved.', 'gfmu-locale'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($is_reset) : ?>
                <div class="notice notice-warning is-dismissible gfmu-admin-notice">
                    <p><?php esc_html_e('Custom style reset to default values.', 'gfmu-locale'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" class="gfmu-admin-card">
                <?php wp_nonce_field('gfmu_save_style_settings', 'gfmu_style_settings_nonce'); ?>

                <div class="gfmu-admin-layout">
                    <div class="gfmu-admin-config">
                        <section class="gfmu-admin-section">
                            <div class="gfmu-admin-section__header">
                                <h2><?php esc_html_e('Header copy', 'gfmu-locale'); ?></h2>
                                <p><?php esc_html_e('Text shown at the top of every uploader instance.', 'gfmu-locale'); ?></p>
                            </div>
                            <div class="gfmu-admin-grid gfmu-admin-grid--copy">
                                <label class="gfmu-admin-field gfmu-admin-field--filled">
                                    <span><?php esc_html_e('Header title', 'gfmu-locale'); ?></span>
                                    <small><?php esc_html_e('Short and prominent label.', 'gfmu-locale'); ?></small>
                                    <input type="text" name="gfmu_style[header_title]" value="<?php echo esc_attr($style_settings['header_title']); ?>" class="regular-text">
                                </label>

                                <label class="gfmu-admin-field gfmu-admin-field--filled">
                                    <span><?php esc_html_e('Header text', 'gfmu-locale'); ?></span>
                                    <small><?php esc_html_e('Secondary helper text below the title.', 'gfmu-locale'); ?></small>
                                    <textarea name="gfmu_style[header_text]" rows="4" class="large-text"><?php echo esc_textarea($style_settings['header_text']); ?></textarea>
                                </label>
                            </div>
                        </section>

                        <section class="gfmu-admin-section">
                            <div class="gfmu-admin-section__header">
                                <h2><?php esc_html_e('Theme palette', 'gfmu-locale'); ?></h2>
                                <p><?php esc_html_e('Adjust the main accent, surface and border colors used across the component.', 'gfmu-locale'); ?></p>
                            </div>
                            <div class="gfmu-admin-grid gfmu-admin-grid--colors">
                                <label class="gfmu-admin-field gfmu-admin-field--color">
                                    <span><?php esc_html_e('Primary color', 'gfmu-locale'); ?></span>
                                    <small><?php esc_html_e('Buttons and active states.', 'gfmu-locale'); ?></small>
                                    <span class="gfmu-admin-color-control">
                                        <span class="gfmu-admin-color-swatch" style="background-color: <?php echo esc_attr($style_settings['primary_color']); ?>;"></span>
                                        <input type="color" name="gfmu_style[primary_color]" value="<?php echo esc_attr($style_settings['primary_color']); ?>">
                                        <em><?php echo esc_html(strtoupper($style_settings['primary_color'])); ?></em>
                                    </span>
                                </label>

                                <label class="gfmu-admin-field gfmu-admin-field--color">
                                    <span><?php esc_html_e('Primary text color', 'gfmu-locale'); ?></span>
                                    <small><?php esc_html_e('Text shown on primary actions.', 'gfmu-locale'); ?></small>
                                    <span class="gfmu-admin-color-control">
                                        <span class="gfmu-admin-color-swatch" style="background-color: <?php echo esc_attr($style_settings['primary_text_color']); ?>;"></span>
                                        <input type="color" name="gfmu_style[primary_text_color]" value="<?php echo esc_attr($style_settings['primary_text_color']); ?>">
                                        <em><?php echo esc_html(strtoupper($style_settings['primary_text_color'])); ?></em>
                                    </span>
                                </label>

                                <label class="gfmu-admin-field gfmu-admin-field--color">
                                    <span><?php esc_html_e('Surface color', 'gfmu-locale'); ?></span>
                                    <small><?php esc_html_e('Panel backgrounds and soft areas.', 'gfmu-locale'); ?></small>
                                    <span class="gfmu-admin-color-control">
                                        <span class="gfmu-admin-color-swatch" style="background-color: <?php echo esc_attr($style_settings['surface_color']); ?>;"></span>
                                        <input type="color" name="gfmu_style[surface_color]" value="<?php echo esc_attr($style_settings['surface_color']); ?>">
                                        <em><?php echo esc_html(strtoupper($style_settings['surface_color'])); ?></em>
                                    </span>
                                </label>

                                <label class="gfmu-admin-field gfmu-admin-field--color">
                                    <span><?php esc_html_e('Border color', 'gfmu-locale'); ?></span>
                                    <small><?php esc_html_e('Lines, frames and separators.', 'gfmu-locale'); ?></small>
                                    <span class="gfmu-admin-color-control">
                                        <span class="gfmu-admin-color-swatch" style="background-color: <?php echo esc_attr($style_settings['border_color']); ?>;"></span>
                                        <input type="color" name="gfmu_style[border_color]" value="<?php echo esc_attr($style_settings['border_color']); ?>">
                                        <em><?php echo esc_html(strtoupper($style_settings['border_color'])); ?></em>
                                    </span>
                                </label>
                            </div>
                        </section>

                        <section class="gfmu-admin-section">
                            <div class="gfmu-admin-section__header">
                                <h2><?php esc_html_e('Frame', 'gfmu-locale'); ?></h2>
                                <p><?php esc_html_e('Tune the overall softness and vertical presence of the component.', 'gfmu-locale'); ?></p>
                            </div>
                            <div class="gfmu-admin-grid gfmu-admin-grid--metrics">
                                <label class="gfmu-admin-field gfmu-admin-field--filled">
                                    <span><?php esc_html_e('Border radius (px)', 'gfmu-locale'); ?></span>
                                    <small><?php esc_html_e('Corner roundness for the uploader shell.', 'gfmu-locale'); ?></small>
                                    <input type="number" min="0" max="40" name="gfmu_style[border_radius]" value="<?php echo esc_attr((string)$style_settings['border_radius']); ?>" class="small-text">
                                </label>

                                <label class="gfmu-admin-field gfmu-admin-field--filled">
                                    <span><?php esc_html_e('Minimum panel height (px)', 'gfmu-locale'); ?></span>
                                    <small><?php esc_html_e('Default vertical size before content grows.', 'gfmu-locale'); ?></small>
                                    <input type="number" min="320" max="960" step="10" name="gfmu_style[panel_min_height]" value="<?php echo esc_attr((string)$style_settings['panel_min_height']); ?>" class="small-text">
                                </label>
                            </div>
                        </section>
                    </div>

                    <aside class="gfmu-admin-sidebar">
                        <div class="gfmu-admin-preview-shell">
                            <div class="gfmu-admin-preview-head">
                                <span class="gfmu-admin-preview-tag"><?php esc_html_e('Live preview', 'gfmu-locale'); ?></span>
                                <strong><?php esc_html_e('Uploader appearance', 'gfmu-locale'); ?></strong>
                                <p><?php esc_html_e('This preview reflects the styling saved for all instances.', 'gfmu-locale'); ?></p>
                            </div>

                            <div class="gfmu-admin-preview" style="
                                --gfmu-primary-color: <?php echo esc_attr($style_settings['primary_color']); ?>;
                                --gfmu-primary-text-color: <?php echo esc_attr($style_settings['primary_text_color']); ?>;
                                --gfmu-surface-color: <?php echo esc_attr($style_settings['surface_color']); ?>;
                                --gfmu-border-color: <?php echo esc_attr($style_settings['border_color']); ?>;
                                --gfmu-border-radius: <?php echo esc_attr((string)$style_settings['border_radius']); ?>px;
                                --gfmu-panel-min-height: <?php echo esc_attr((string)$style_settings['panel_min_height']); ?>px;
                            ">
                                <div class="gfmu-admin-preview-panel">
                                    <div class="gfmu-admin-preview-header">
                                        <div class="gfmu-admin-preview-switch">
                                            <span></span>
                                            <span></span>
                                        </div>
                                        <strong class="gfmu-admin-preview-title"><?php echo esc_html($style_settings['header_title']); ?></strong>
                                        <p class="gfmu-admin-preview-text"><?php echo esc_html($style_settings['header_text']); ?></p>
                                    </div>
                                    <div class="gfmu-admin-preview-body">
                                        <div class="gfmu-admin-preview-dropzone">
                                            <span><?php esc_html_e('Drop area preview', 'gfmu-locale'); ?></span>
                                            <small><?php esc_html_e('Files, thumbnails and states inherit this theme.', 'gfmu-locale'); ?></small>
                                        </div>
                                    </div>
                                    <div class="gfmu-admin-preview-footer">
                                        <div class="gfmu-admin-preview-actions">
                                            <button type="button" class="button button-primary"><?php esc_html_e('Add files', 'gfmu-locale'); ?></button>
                                            <button type="button" class="button"><?php esc_html_e('Start upload', 'gfmu-locale'); ?></button>
                                        </div>
                                        <div class="gfmu-admin-preview-status">
                                            <span>0%</span>
                                            <span>0 kb</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>

                <div class="submit gfmu-admin-actions">
                    <button type="submit" name="gfmu_style_action" value="save" class="button button-primary">
                        <?php esc_html_e('Save style', 'gfmu-locale'); ?>
                    </button>
                    <button
                        type="submit"
                        name="gfmu_style_action"
                        value="reset"
                        class="button button-secondary gfmu-admin-reset"
                        onclick="return window.confirm('<?php echo esc_js(__('Reset the custom style and restore the default look?', 'gfmu-locale')); ?>');"
                    >
                        <?php esc_html_e('Reset custom style', 'gfmu-locale'); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    public function tooltips(array $tooltips): array
    {
        $tooltips['gfmu_save_to_meta'] = sprintf('<h6>%s</h6>%s', esc_html__('Save to meta', 'gfmu-locale'), esc_html__('If it is set, will save all the data about uploads into the specified meta.', 'gfmu-locale'));
        $tooltips['gfmu_max_files'] = sprintf('<h6>%s</h6>%s', esc_html__('Max number of files', 'gfmu-locale'), esc_html__('Specify the max number of files the user can upload.', 'gfmu-locale'));
        $tooltips['gfmu_file_size'] = sprintf('<h6>%s</h6>%s', esc_html__('Max file size', 'gfmu-locale'), esc_html__('Specify the max size for each file uploaded.', 'gfmu-locale'));
        $tooltips['gfmu_file_extensions'] = sprintf('<h6>%s</h6>%s', esc_html__('Allowed extensions', 'gfmu-locale'), esc_html__('Specify the allowed extensions.', 'gfmu-locale'));

        return $tooltips;
    }

    public function field_standard_settings(int $position, int $form_id)
    {
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
                    <small><?php esc_html_e('Separated with commas (i.e. webp, jpg, jpeg, gif, png, pdf)', 'gfmu-locale'); ?></small>
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
                    <small><?php esc_html_e('Number of files users can upload for this field.', 'gfmu-locale'); ?></small>
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
                    <small><?php esc_html_e('Value in MB for this field override.', 'gfmu-locale'); ?></small>
                </div>
            </li>
            <?php
        }
    }

    public function field_advanced_settings(int $position, int $form_id)
    {
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

    public function scripts()
    {
        $plupload_i18n_script = apply_filters('gfmu_uploader_i18n_script', $this->plugin_options['locale'], 'en');

        if (empty($plupload_i18n_script)) {
            $plupload_i18n_script = 'en';
        }

        if (is_admin()) {
            $scripts = array();
        } else {
            $scripts = array(
                array(
                    'handle'  => 'plupload-jquery-ui',
                    'src'     => GFMU_PLUGIN_URL . 'assets/custom-plupload/jquery.ui.plupload/jquery.ui.plupload.js',
                    'version' => $this->_version,
                    'deps'    => array('jquery', 'plupload', 'plupload-all', 'jquery-ui-core', 'jquery-ui-widget', 'jquery-ui-button', 'jquery-ui-progressbar', 'jquery-ui-sortable'),
                    'enqueue' => array(
                        array('field_types' => array('multi-uploader')),
                    ),
                ),
                array(
                    'handle'  => 'gfmu-pluploader-init',
                    'src'     => GFMU_PLUGIN_URL . 'assets/js/init.js',
                    'version' => $this->_version,
                    'deps'    => array('jquery', 'plupload', 'plupload-all'),
                    'enqueue' => array(
                        array('field_types' => array('multi-uploader')),
                    ),
                ),
                array(
                    'handle'  => 'gfmu-pluploader-locale',
                    'src'     => GFMU_PLUGIN_URL . "assets/custom-plupload/i18n/{$plupload_i18n_script}.js",
                    'version' => $this->_version,
                    'deps'    => array('jquery', 'plupload', 'plupload-all'),
                    'enqueue' => array(
                        array('field_types' => array('multi-uploader')),
                    ),
                ),
            );
        }

        return array_merge(parent::scripts(), $scripts);
    }

    public function styles()
    {
        if (is_admin()) {
            $styles = array(
                array(
                    'handle'  => 'gfmu-form-display',
                    'src'     => GFMU_PLUGIN_URL . 'assets/css/form-display.css',
                    'version' => $this->_version,
                    'enqueue' => array(
                        array('field_types' => array('multi-uploader')),
                    ),
                ),
            );
        } else {
            $styles = array(
                array(
                    'handle'  => 'plupload-jquery-ui-css',
                    'src'     => GFMU_PLUGIN_URL . 'assets/custom-plupload/jquery.ui.plupload/css/jquery.ui.plupload.css',
                    'version' => $this->_version,
                    'enqueue' => array(
                        array('field_types' => array('multi-uploader')),
                    ),
                ),
                array(
                    'handle'  => 'gfmu-theme-css',
                    'src'     => GFMU_PLUGIN_URL . 'assets/css/gfmu-theme.css',
                    'version' => $this->_version,
                    'enqueue' => array(
                        array('field_types' => array('multi-uploader')),
                    ),
                ),
            );
        }

        return array_merge(parent::styles(), $styles);
    }

    public function sanitize_behavior_settings(array $settings): array
    {
        $defaults = $this->get_default_behavior_settings();
        $ui_view = $settings['ui_view'] ?? $defaults['ui_view'];

        return array(
            'locale'             => $this->sanitize_locale($settings['locale'] ?? $defaults['locale']),
            'auto_upload'        => array_key_exists('auto_upload', $settings) ? !empty($settings['auto_upload']) : $defaults['auto_upload'],
            'duplicates_status'  => array_key_exists('duplicates_status', $settings) ? !empty($settings['duplicates_status']) : $defaults['duplicates_status'],
            'drag_drop_status'   => array_key_exists('drag_drop_status', $settings) ? !empty($settings['drag_drop_status']) : $defaults['drag_drop_status'],
            'list_view'          => array_key_exists('list_view', $settings) ? !empty($settings['list_view']) : $defaults['list_view'],
            'thumb_view'         => array_key_exists('thumb_view', $settings) ? !empty($settings['thumb_view']) : $defaults['thumb_view'],
            'rename_file_status' => array_key_exists('rename_file_status', $settings) ? !empty($settings['rename_file_status']) : $defaults['rename_file_status'],
            'max_files'          => $this->sanitize_positive_int($settings['max_files'] ?? $defaults['max_files'], (int)$defaults['max_files']),
            'max_file_size'      => $this->sanitize_size_setting($settings['max_file_size'] ?? $defaults['max_file_size'], $defaults['max_file_size']),
            'ui_view'            => in_array($ui_view, array('thumbs', 'list'), true) ? $ui_view : $defaults['ui_view'],
            'chunk_size'         => $this->sanitize_size_setting($settings['chunk_size'] ?? $defaults['chunk_size'], $defaults['chunk_size']),
            'filters'            => array(
                'files' => self::normalize_extension_filters((string)($settings['files_filters'] ?? ($settings['filters']['files'] ?? $defaults['filters']['files']))),
            ),
        );
    }

    public function sanitize_style_settings(array $settings): array
    {
        $defaults = $this->get_default_style_settings();

        return array(
            'header_title'       => sanitize_text_field($settings['header_title'] ?? $defaults['header_title']),
            'header_text'        => sanitize_textarea_field($settings['header_text'] ?? $defaults['header_text']),
            'primary_color'      => $this->sanitize_hex_color_setting($settings['primary_color'] ?? $defaults['primary_color'], $defaults['primary_color']),
            'primary_text_color' => $this->sanitize_hex_color_setting($settings['primary_text_color'] ?? $defaults['primary_text_color'], $defaults['primary_text_color']),
            'surface_color'      => $this->sanitize_hex_color_setting($settings['surface_color'] ?? $defaults['surface_color'], $defaults['surface_color']),
            'border_color'       => $this->sanitize_hex_color_setting($settings['border_color'] ?? $defaults['border_color'], $defaults['border_color']),
            'border_radius'      => min(40, max(0, $this->sanitize_positive_int($settings['border_radius'] ?? $defaults['border_radius'], (int)$defaults['border_radius']))),
            'panel_min_height'   => min(960, max(320, $this->sanitize_positive_int($settings['panel_min_height'] ?? $defaults['panel_min_height'], (int)$defaults['panel_min_height']))),
        );
    }

    private function get_available_locale_slugs(): array
    {
        $files = glob(GFMU_PLUGIN_DIR . 'assets/custom-plupload/i18n/*.js', GLOB_NOSORT);

        return array_map(
            static function ($file) {
                return pathinfo($file, PATHINFO_FILENAME);
            },
            $files ?: array()
        );
    }

    private function get_default_locale(): string
    {
        $available_locales = $this->get_available_locale_slugs();
        $locale = strtolower((string)apply_filters('gfmu_uploader_i18n_script', substr(determine_locale(), 0, 2), 'en'));

        return in_array($locale, $available_locales, true) ? $locale : 'en';
    }

    private function sanitize_locale(string $locale): string
    {
        $locale = sanitize_key($locale);
        $available_locales = $this->get_available_locale_slugs();

        return in_array($locale, $available_locales, true) ? $locale : $this->get_default_locale();
    }

    private function sanitize_positive_int($value, int $fallback): int
    {
        $value = absint($value);

        return $value > 0 ? $value : $fallback;
    }

    private function sanitize_size_setting($value, string $fallback): string
    {
        $value = strtolower(trim((string)$value));

        if ($value === '') {
            return $fallback;
        }

        if (preg_match('/^\d+$/', $value)) {
            return absint($value) . 'mb';
        }

        if (preg_match('/^\d+\s*(kb|mb|gb)$/', $value, $matches)) {
            return absint($value) . strtolower($matches[1]);
        }

        return $fallback;
    }

    private function sanitize_hex_color_setting($value, string $fallback): string
    {
        $value = sanitize_hex_color((string)$value);

        return $value ?: $fallback;
    }

    private function is_style_settings_page(): bool
    {
        if (!is_admin()) {
            return false;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $subview = isset($_GET['subview']) ? sanitize_key(wp_unslash($_GET['subview'])) : '';

        return $page === 'gf_settings' && $subview === $this->get_slug();
    }
}
