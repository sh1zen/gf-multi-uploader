<?php

if (!class_exists('GFForms')) {
    return;
}

class GF_MultiUploader_Field extends GF_Field
{
    /**
     * @var string $type The field type.
     */
    public $type = 'multi-uploader';

    /**
     * Assign the field button to the Advanced Fields group.
     */
    public function get_form_editor_button(): array
    {
        return array(
            'group' => 'advanced_fields',
            'text'  => $this->get_form_editor_field_title(),
        );
    }

    /**
     * Return the field title, for use in the form editor.
     */
    public function get_form_editor_field_title(): string
    {
        return 'Multi Uploader';
    }

    /**
     * The settings which should be available on the field in the form editor.
     */
    function get_form_editor_field_settings(): array
    {
        return array(
            'label_setting',
            'description_setting',
            'rules_setting',
            'input_class_setting',
            'css_class_setting',
            'visibility_setting',
            'conditional_logic_field_setting',
            'gfmu_file_extensions_setting',
            'gfmu_file_size_setting',
            'gfmu_max_files_setting',
            'gfmu_save_to_meta_setting'
        );
    }

    /**
     * Enable this field for use with conditional logic.
     */
    public function is_conditional_logic_supported(): bool
    {
        return true;
    }

    /**
     * The scripts to be included in the form editor.
     */
    public function get_form_editor_inline_script_on_page_render(): string
    {
        $plugin_options = GFMUAddon::get_instance()->get_plupload_settings();

        ob_start();
        // initialize the fields custom settings
        ?>
        jQuery(document).ready(function ($) {

        //Hook into gform load field settings to initialize file extension settings
        $(document).bind("gform_load_field_settings", function (event, field, form) {

        //Populate file extensions with data if set
        field["gfmu_file_extensions"] = field["gfmu_file_extensions"] || '<?php echo esc_js($plugin_options['filters']['files']); ?>';
        $("#gfmu_file_extensions").val(field["gfmu_file_extensions"]);

        field["gfmu_file_size"] = field["gfmu_file_size"] || '<?php echo esc_js($plugin_options['max_file_size']); ?>';
        $("#gfmu_file_size").val(field["gfmu_file_size"]);

        field["gfmu_max_files"] = field["gfmu_max_files"] || '<?php echo esc_js($plugin_options['max_files']); ?>';
        $("#gfmu_max_files").val(field["gfmu_max_files"]);

        field["gfmu_save_to_meta"] = field["gfmu_save_to_meta"] || '';
        $("#gfmu_save_to_meta").val(field["gfmu_save_to_meta"]);

        });});
        <?php

        return ob_get_clean();
    }

    /**
     * Define the fields inner markup.
     *
     * @param array $form The Form Object currently being processed.
     * @param string|array $value The field value. From default/dynamic population, $_POST, or a resumed incomplete submission.
     * @param null|array $entry Null or the Entry Object currently being edited.
     */
    public function get_field_input($form, $value = '', $entry = null): string
    {
        $field_id = absint($this->id);

        if ($this->is_form_editor()) {
            return "<div class='ginput_container mth_plupload'><span class='gform_drop_instructions'>File Uploader</span></div>";
        }

        $input = $this->setup_gfmu_option_var();

        $input .= "<div class='ginput_container mth_plupload'><input name='input_{$field_id}' id='mth_form_pluploader_{$field_id}' type='hidden'/></div>";

        $args = [
            'post_id'     => $_GET['gform_post_id'] ?? 0,
            'get_by_meta' => $this->gfmu_save_to_meta
        ];

        $uploaded_data = GFMUHandlePluploader::getInstance()->get_existing_file_uploaded($field_id, $args);

        $input .= $this->generate_pluploader_field_script($uploaded_data);

        //Cache the div element used by pluploader jquery plugin
        $input .= "<div id='filelist_{$field_id}'>" . __("Your browser doesn't have Flash, Silverlight or HTML5 support.", 'gfmu-locale') . "</div>";

        $input .= "<div id='pluploader_{$field_id}'></div>";

        return $input;
    }

    /**
     * create the GFMU_options used to pass options to plupload
     */
    private function setup_gfmu_option_var(): string
    {
        $field_id = absint($this->id);

        $field_options = $this->get_gfmu_field_settings();

        if (empty($field_options))
            return '';

        return "<script>if(typeof GFMU_options === 'undefined' ) {var GFMU_options = {}} GFMU_options['{$field_id}'] = " . wp_json_encode($field_options) . ";</script>";
    }

    public function get_gfmu_field_settings()
    {
        $field_id = absint($this->id);
        $form_id = absint($this->formId);

        $plupload_settings = GFMUAddon::get_instance()->get_plupload_settings();

        //Cache any validation settings for this field
        if (!empty($this->gfmu_file_extensions)) {
            $plupload_settings['filters']['files'] = preg_replace('/(\s|\.)+/', '', esc_attr($this->gfmu_file_extensions));
        }

        //Cache max file size validation option
        if (!empty($this->gfmu_file_size)) {
            $plupload_settings['max_file_size'] = absint($this->gfmu_file_size) . 'mb';
        }

        if (function_exists('get_user_access_option')) {
            $plupload_settings['max_files'] = get_user_access_option('upload_images', 20);
        }
        elseif (!empty($this->gfmu_max_files)) {
            $plupload_settings['max_files'] = absint($this->gfmu_max_files);
        }

        $plupload_settings['save_to_meta'] = empty($this->gfmu_save_to_meta) ? false : $this->gfmu_save_to_meta;

        $field_options = array_merge([
            'element'         => "pluploader_{$field_id}",
            'wp_ajax_url'     => admin_url('admin-ajax.php'),
            'params'          => [
                'form_id'  => $form_id,
                'field_id' => $field_id,
                'nonce'    => wp_create_nonce(GFMUHandlePluploader::$submit_nonce_key)
            ],
            'flash_url'       => includes_url('js/plupload/plupload.flash.swf'),
            'silverlight_url' => includes_url('js/plupload/plupload.silverlight.xap'),
            'i18n'            => [
                'server_error'     => "Immagine troppo grande.",
                'file_limit_error' => "Hai raggiunto il limite massimo di immagini."
            ]
        ], $plupload_settings);

        return apply_filters('gfmu_field_options', $field_options, $form_id, $field_id);
    }

    private function generate_pluploader_field_script($js_vars = [])
    {
        $field_id = absint($this->id);
        //Add javascript local vars to output
        ob_start();
        ?>
        <script type="text/javascript">
            GFMU_options[<?php echo esc_js($field_id) ?>].setupFiles = {
                <?php
                if (!empty($js_vars)) {
                    foreach ($js_vars as $index => $file_data) {

                        $file_code = $field_id . '-' . $index;

                        $date = new DateTime();
                        $date->setTimestamp($file_data['last_mod_date']);

                        $date = $date->format('Y-m-d\TH:i:s');

                        echo "'" . esc_js($file_code) . "': {";
                        echo "'id': '" . esc_attr($file_data['id']) . "',";
                        echo "'o_name': '" . esc_attr($file_data['o_name']) . "',";
                        echo "'t_name': '" . esc_attr($file_data['t_name']) . "',";
                        echo "'size': '" . esc_js($file_data['size']) . "',";
                        echo "'url': '" . esc_url($file_data['url']) . "',";
                        echo "'lastModified': new Date('" . esc_js($date) . "'),";
                        echo "'wpid': '" . esc_js($file_data['wpid']) . "',";
                        echo "},";
                    }
                }
                ?>
            }
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Forces settings into expected values while saving the form object.
     * No escaping should be done at this stage to prevent double escaping on output.
     */
    public function sanitize_settings()
    {
        parent::sanitize_settings();
        $this->gfmu_file_size = intval($this->gfmu_file_size);
        $this->gfmu_max_files = intval($this->gfmu_max_files);
        $this->gfmu_save_to_meta = sanitize_text_field($this->gfmu_save_to_meta);
        $this->gfmu_file_extensions = preg_replace('/(\s|\.)*/', '', $this->gfmu_file_extensions);
    }


    /**
     * Whether this field expects an array during submission.
     */
    public function is_value_submission_array(): bool
    {
        return true;
    }

    /**
     * Sanitize and format the value before it is saved to the Entry Object.
     *
     * @param string|array $value The value to be saved.
     * @param array $form The Form Object currently being processed.
     * @param string $input_name The input name used when accessing the $_POST.
     * @param int $lead_id The ID of the Entry currently being processed.
     * @param array $lead The Entry Object currently being processed.
     *
     * @return array|string The safe value.
     */
    public function get_value_save_entry($value, $form, $input_name, $lead_id, $lead)
    {
        //trick to fix the two call in a row
        if (!$lead_id)
            return $value;

        $form_id = absint($this->formId);

        $entries = [];

        foreach ((array)$value as $index => $upload_uid) {

            $upload = GFMUHandlePluploader::getInstance()->get_raw_posted_details($upload_uid);

            if (!$upload)
                continue;

            $attachment_id = GFMUHandlePluploader::getInstance()->maybe_insert_attachment([
                'form_id'  => $form_id,
                'entry_id' => $lead_id,
                'basename' => $upload['t_name'],
                'order'    => $index,
            ]);

            if ($attachment_id) {

                $c_entry = [
                    'o_name'        => $upload['o_name'],
                    't_name'        => $upload['t_name'],
                    'url'           => get_attachment_link($attachment_id),
                    'attachment_id' => $attachment_id
                ];

                $entries[] = $c_entry;
            }
        }

        $entries = apply_filters('gfmu_save_entry', $entries, $form_id, $lead_id);

        return maybe_serialize($entries);
    }


    /**
     * Format the entry value for display on the entries list page.
     *
     * Return a value that's safe to display on the page.
     *
     * @param string|array $value The field value.
     * @param array $entry The Entry Object currently being processed.
     * @param string $field_id The field or input ID currently being processed.
     * @param array $columns The properties for the columns being displayed on the entry list page.
     * @param array $form The Form Object currently being processed.
     */
    public function get_value_entry_list($value, $entry, $field_id, $columns, $form): string
    {
        $fields = maybe_unserialize($value);

        if (!is_array($fields))
            $fields = [];

        return sprintf(__("%s uploads", "gfmu-locale"), count($fields));
    }

    /**
     * Format the entry value for display on the entry detail page and for the {all_fields} merge tag.
     *
     * Return a value that's safe to display for the context of the given $format.
     *
     * @param string|array $value The field value.
     * @param string $currency The entry currency code.
     * @param bool|false $use_text When processing choice based fields should the choice text be returned instead of the value.
     * @param string $format The format requested for the location the merge is being used. Possible values: html, text or url.
     * @param string $media The location where the value will be displayed. Possible values: screen or email.
     */
    public function get_value_entry_detail($value, $currency = '', $use_text = false, $format = 'html', $media = 'screen'): string
    {
        $value = maybe_unserialize($value);

        if (!is_array($value)) {
            return parent::get_value_entry_detail($value, $currency, $use_text, $format, $media);
        }

        $str = '';
        foreach ($value as $upload) {
            $str .= "<li><a href='{$upload['url']}' target='_blank'>{$upload['o_name']}</a></li>";
        }

        return "<ul>{$str}</ul>";
    }

    /**
     * Format the entry value before it is used in entry exports and by framework add-ons using GFAddOn::get_field_value().
     *
     * For CSV export return a string or array.
     *
     * @param array $entry The entry currently being processed.
     * @param string $input_id The field or input ID.
     * @param bool|false $use_text When processing choice based fields should the choice text be returned instead of the value.
     * @param bool|false $is_csv Is the value going to be used in the .csv entries export?
     *
     * @return string|array
     */
    public function get_value_export($entry, $input_id = '', $use_text = false, $is_csv = false)
    {
        if (empty($input_id)) {
            $input_id = $this->id;
        }

        return maybe_unserialize(rgar($entry, $input_id));
    }
}
