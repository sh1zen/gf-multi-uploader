<?php

//Generate wp attachment meta data
if (!function_exists('wp_generate_attachment_metadata'))
    require_once(ABSPATH . 'wp-admin/includes/image.php');

class GFMUHandlePluploader
{
    public static $submit_nonce_key = 'gfmu-submit-nonce';
    private static $upload_tmp_dir_name = 'gfmu-uploads-tmp';

    private static $_instance;

    private $cache;

    private function __construct()
    {
        $this->cache['wp_upload_dir'] = wp_upload_dir();
        $this->cache['upload_dir'] = $this->cache['wp_upload_dir']['basedir'] . '/' . self::$upload_tmp_dir_name . '/';
    }

    public static function getInstance()
    {
        if (self::$_instance == null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function plupload_ajax_delete_file()
    {
        if (!self::verify_nonce()) {
            $this->send_ajax_response('Server error.', 'error');
        }

        $post_id = absint($_POST['file_wpid']);

        $file = substr(sanitize_text_field($_POST['file_id']), 2);

        $tmp_name = sanitize_text_field($_POST['tmp_name']);

        $doing_meta = (isset($_POST['get_by_meta']) and !empty($_POST['get_by_meta']));

        if ($post_id) {

            if ($doing_meta) {
                /* $images = maybe_unserialize(get_metadata('post', $post_id, sanitize_text_field($_POST['get_by_meta']), true));

                 if (($key = array_search($post_id, $images)) !== false) {
                     unset($images[$key]);

                     update_metadata('post', )

                     $this->send_ajax_response($file);
                 }*/
            }
            elseif ($post = wp_delete_attachment($post_id, true)) {
                clean_post_cache($post);
                $this->send_ajax_response($file);
            }
        }
        else {
            if (file_exists($this->cache['upload_dir'] . $tmp_name)) {
                @unlink($this->cache['upload_dir'] . $tmp_name);
                $this->send_ajax_response($file);
            }
            else {
                $this->send_ajax_response('false');
            }
        }

        $this->send_ajax_response('false');
    }

    private static function verify_nonce()
    {
        $nonce_value = isset($_REQUEST['nonce']) ? esc_attr($_REQUEST['nonce']) : null;

        //First check nonce field
        if (!isset($nonce_value) or !wp_verify_nonce($nonce_value, self::$submit_nonce_key)) {
            return false;
        }

        return true;
    }

    private function send_ajax_response($response, $type = false)
    {
        if ($type) {
            $response[$type] = $response;
        }

        header("Content-Type: text/plain");

        echo json_encode($response);

        die();
    }

    public function plupload_ajax_download_file()
    {
        if (!self::verify_nonce()) {
            $this->send_ajax_response('Server error.', 'error');
        }

        $post_id = absint($_POST['post_id']);

        if (!$post_id)
            return;

        $doing_meta = (isset($_POST['get_by_meta']) and !empty($_POST['get_by_meta']));

        $file = tempnam(ABSPATH . "tmp", "zip");
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::OVERWRITE);

        if ($doing_meta) {
            $images = maybe_unserialize(get_metadata('post', $post_id, sanitize_text_field($_POST['get_by_meta']), true));
        }
        else {
            $images = get_attached_media('image', $post_id);
        }

        foreach ($images as $image) {

            $path = get_attached_file($doing_meta ? $image : $image->ID, true);

            if (!$path or !file_exists($path))
                continue;

            $zip->addFile($path, basename($path));
        }

        $zip->close();

        ob_get_clean();

        header('Content-Type: application/zip');

        header('Content-Disposition: attachment; filename=attachment.zip');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');

        header('Content-Length: ' . filesize($file));

        readfile($file);

        unlink($file);

        die();
    }

    /**
     * Handles ajax request from Pluploader script, checks nonce, grabs validation options from gforms form meta
     * then passes the validation options to the main File Uploader php script to process and move to server
     *
     * NOTE: If validation options are not set in gforms for this field the script will default to just images <= 0.5mb
     *        Script will not accept any .js or .php ot .html extensions regardless of validation settings.
     */
    public function plupload_ajax_submit()
    {
        // Include the uploader class
        require_once GFMU_INC_PATH . 'GFMU_FileUploader.php';

        if (!self::verify_nonce()) {
            $this->send_ajax_response('Server error.', 'error');
        }

        $uploader = new GFMU_FileUploader($this->pluploader_server_settings());

        // Call handleUpload() with the name of the folder, relative to PHP's getcwd()
        $result = $uploader->handleUpload($this->cache['wp_upload_dir']['basedir'] . '/' . self::$upload_tmp_dir_name);

        $this->send_ajax_response($result);
    }

    /**
     * pluploader_file_validation_settings
     *
     * Called by $this->pluploader_ajax_submit()
     * Gets validation options for current field from the gform form meta data
     */
    public function pluploader_server_settings()
    {
        //Cache the current form ID
        if (!isset($_REQUEST['currentFormID']) or !isset($_REQUEST['currentFieldID']))
            return [];

        $current_form_id = absint($_REQUEST['currentFormID']);
        $current_field_id = absint($_REQUEST['currentFieldID']);

        $field_obj = RGFormsModel::get_field($current_form_id, $current_field_id);

        if ($field_obj->type !== 'multi-uploader')
            return [];

        $field_options = $field_obj->get_gfmu_field_settings();

        $validation_args = [
            'chunksFolder'      => $this->cache['wp_upload_dir']['basedir'] . '/' . self::$upload_tmp_dir_name . '/chunks',
            'allowedExtensions' => $field_options['filters']['files'],
            'sizeLimit'         => $field_options['max_file_size'],
            'maxFiles'          => $field_options['max_files'],
            'saveToMeta'        => $field_options['save_to_meta'],
            'rename_files'      => (bool)$field_options['rename_file_status'],
            'enable_chunked'    => intval($field_options['chunk_size']) > 0,
            'allowed_mimes'     => get_allowed_mime_types()
        ];

        //Allow devs to hook before we get the form's validation settings
        return apply_filters('gfmu_server_validation_args', $validation_args, $field_obj);
    }

    /**
     * insert_attachment
     *
     * Called by $this->process_uploads().
     * Moves uploaded file out of fine uploads tmp dir and into wp uploads dir
     * Then creates a wp attachment post for the file returning it's attachment post id
     * @returns    int        $attach_id - WP attachment post id for file
     */
    public function maybe_insert_attachment($args = [])
    {
        global $wpdb;
        // $upload_id, $file_base_name, $entry, $attachment_parent_ID, $form, $menu_order = 0
        $args = array_merge([
            'basename'    => '',
            'order'       => 0,
            'ext'         => '',
            'post_parent' => 0,
            'form_id'     => 0,
            'entry_id'    => 0
        ], $args);

        $attach_id = false;
        $wp_upload_dir = $this->cache['wp_upload_dir'];

        $pluploader_tmp_dir = $wp_upload_dir['basedir'] . '/' . self::$upload_tmp_dir_name . '/';

        $args = apply_filters('gfmu_maybe_insert_attachment', $args);

        $uploaded_file_path = $pluploader_tmp_dir . $args['basename'];

        if (!file_exists($uploaded_file_path)) {

            if (is_numeric($args['basename'])) {
                $may_exist = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'attachment';", $args['basename']));
                if ($may_exist)
                    return intval($args['basename']);
            }

            return 0;
        }

        //Cache destination file path
        $wp_dest_file_path = $wp_upload_dir['path'] . '/' . $args['basename'];

        $wp_filetype = wp_check_filetype($uploaded_file_path);

        //First let's move this file into the wp uploads dir structure
        $move_status = GFMUHandlePluploader::move_file($uploaded_file_path, $wp_dest_file_path);

        if ($move_status and $wp_filetype['type']) {

            //Create a unique and descriptive post title - associate with form and entry
            $post_title = 'Form ' . $args['form_id'] . ' Entry ' . $args['entry_id'] . ' Fileupload ' . $args['order'];

            //Create the attachment array required for wp_insert_attachment()
            $attachment_args = array(
                'guid'           => $wp_upload_dir['url'] . '/' . basename($wp_dest_file_path),
                'post_mime_type' => $wp_filetype['type'],
                'post_title'     => $post_title,
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_parent'    => $args['post_parent'],
                'menu_order'     => $args['order']
            );

            $attachment_args = apply_filters('gfmu_insert_attachment_args', $attachment_args);

            //Insert attachment
            $attach_id = wp_insert_attachment($attachment_args, $wp_dest_file_path);

            //Error check
            if (!is_wp_error($attach_id) and $attach_id) {
                wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $wp_dest_file_path));
            }
            else {
                $attach_id = false;

                if (file_exists($uploaded_file_path)) {
                    @unlink($uploaded_file_path);
                }

                if (file_exists($wp_dest_file_path)) {
                    @unlink($wp_dest_file_path);
                }
            }
        }

        return $attach_id;
    }

    /**
     * move_file
     *
     * Helper to move a file from one path to another
     * Paths are full paths to a file including filename and ext
     * @param null $current_path
     * @param null $destination_path
     * @return bool
     */
    private static function move_file($current_path = null, $destination_path = null)
    {
        //Init vars
        $result = false;

        if (isset($current_path) and file_exists($current_path)) {

            //First check if destination dir exists if not make it
            if (!file_exists(dirname($destination_path))) {
                mkdir(dirname($destination_path));
            }

            if (file_exists(dirname($destination_path))) {

                //Move file into dir
                if (copy($current_path, $destination_path)) {
                    unlink($current_path);

                    if (file_exists($destination_path)) {
                        $result = true;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * get existing file uploaded
     *
     * Helper to generate all the hidden field html and javacript local vars
     * requied to place a file already on the tmp folder back into an instance
     * of plupload.
     * @param $field_id
     * @param array $args
     * @return array
     */
    public function get_existing_file_uploaded($field_id, $args = [])
    {
        $file_data = $this->get_post_file_uploaded($field_id, true);

        if (empty($file_data)) {
            $file_data = $this->get_uploaded_media($args);
        }

        return $file_data;
    }

    public function get_post_file_uploaded($field_id, $db_search = false)
    {
        global $wpdb;

        $file_data = [];

        $uplo_dir = $this->cache['wp_upload_dir'];

        $tmp_uploads = $this->get_raw_posted_data($field_id);

        foreach ($tmp_uploads as $file_uid) {

            if (!isset($_POST["{$file_uid}_tname"], $_POST["{$file_uid}_name"]))
                continue;

            $attachment_id = 0;

            $file_name = sanitize_text_field($_POST["{$file_uid}_tname"]);

            $path = $uplo_dir['basedir'] . '/' . self::$upload_tmp_dir_name . '/' . esc_attr($file_name);

            $img_thumb_url = $uplo_dir['baseurl'] . '/' . self::$upload_tmp_dir_name . '/' . esc_attr($file_name);

            if (!file_exists($path) and $db_search) {

                $attachment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", array($file_name)));

                if (empty($attachment))
                    continue;

                $attachment_id = $attachment->ID;
                $img_thumb_url = wp_get_attachment_image_src($attachment->ID, 'thumbnail');

                if ($img_thumb_url)
                    $img_thumb_url = $img_thumb_url[0];
                else
                    $img_thumb_url = $attachment->guid;

                $path = get_attached_file($attachment->ID, true);
            }

            $file_data[] = [
                'id'     => $file_uid,
                'o_name' => sanitize_text_field($_POST["{$file_uid}_name"]),
                't_name' => $file_name,

                'url'           => $img_thumb_url,
                'size'          => $path ? filesize($path) : 0,
                'last_mod_date' => $path ? filesize($path) : time(),
                'wpid'          => $attachment_id,
            ];
        }

        return $file_data;
    }

    public function get_raw_posted_data($field_id)
    {
        if (!isset($_POST["input_{$field_id}"]) or !is_array($_POST["input_{$field_id}"])) {
            return [];
        }

        return array_map('sanitize_file_name', $_POST["input_{$field_id}"]);
    }

    public function get_uploaded_media($args = [])
    {
        $args = array_merge([
            'post_id'     => false,
            'get_by_meta' => '',
        ], $args);

        $post_id = $args['post_id'];

        if (!$post_id) {
            return [];
        }

        $file_data = [];
        $images = [];

        if (empty($args['get_by_meta'])) {
            $images = get_posts(array(
                'post_parent'    => $post_id,
                'post_type'      => 'attachment',
                'post_mime_type' => 'image',
                'posts_per_page' => -1,
                'exclude'        => get_post_thumbnail_id($post_id),
                'orderby'        => 'menu_order',
                'order'          => 'ASC',
            ));
        }
        else {
            $attachments_list = maybe_unserialize(get_metadata('post', $post_id, $args['get_by_meta'], true));

            if (!empty($attachments_list) and is_array($attachments_list)) {

                $images = get_posts(array(
                    'post_type'   => 'attachment',
                    'numberposts' => -1,
                    'post__in'    => $attachments_list
                ));
            }
        }

        $file_upload_number = 0;
        foreach ($images as $image) {

            $img_thumb_url = wp_get_attachment_image_src($image->ID, 'thumbnail');

            if ($img_thumb_url)
                $img_thumb_url = $img_thumb_url[0];
            else
                $img_thumb_url = $image->guid;

            $path = get_attached_file($image->ID, true);

            if (file_exists($path)) {
                $ext = pathinfo($path, PATHINFO_EXTENSION);
                $file_size = (int)filesize($path);
                $last_mod = filemtime($path);
            }
            else {
                $ext = pathinfo($img_thumb_url, PATHINFO_EXTENSION);
                $file_size = 0;
                $last_mod = time();
            }

            $file_name = "file_" . ($file_upload_number + 1) . ".{$ext}";

            $file_data[] = [
                'id'     => "o_" . pathinfo($image->guid, PATHINFO_FILENAME),
                'o_name' => $file_name,
                't_name' => $image->ID,
                'url'           => $img_thumb_url,
                'size'          => $file_size,
                'last_mod_date' => $last_mod,
                'wpid'          => $image->ID,
            ];
            $file_upload_number++;
        }

        return $file_data;
    }

    public function get_raw_posted_details($file_uid)
    {
        if (!isset($_POST["{$file_uid}_tname"]) or !isset($_POST["{$file_uid}_name"])) {
            return false;
        }

        if (empty($_POST["{$file_uid}_tname"]) or empty($_POST["{$file_uid}_name"])) {
            return false;
        }

        return [
            'id'     => $file_uid,
            't_name' => sanitize_text_field($_POST["{$file_uid}_tname"]),
            'o_name' => sanitize_text_field($_POST["{$file_uid}_name"])
        ];
    }
}