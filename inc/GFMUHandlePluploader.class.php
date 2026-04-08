<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

//Generate wp attachment meta data

if (!function_exists('wp_generate_attachment_metadata')) {
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
}

class GFMUHandlePluploader
{
    public static string $upload_nonce_key = 'gfmu-upload-nonce';
    public static string $media_nonce_key = 'gfmu-media-nonce';
    private static string $upload_tmp_dir_name = 'gfmu-uploads-tmp';

    private static ?GFMUHandlePluploader $_instance = null;

    private $cache;

    private function __construct()
    {
        $this->cache['wp_upload_dir'] = wp_upload_dir();
        $this->cache['upload_dir'] = $this->cache['wp_upload_dir']['basedir'] . '/' . self::$upload_tmp_dir_name . '/';
    }

    public static function getInstance(): GFMUHandlePluploader
    {
        if (!self::$_instance) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function plupload_ajax_delete_file(): void
    {
        if (!self::verify_nonce(self::$media_nonce_key)) {
            $this->send_ajax_response('Unauthorized.', 'error');
        }

        $attachment_id = isset($_POST['file_wpid']) ? absint(wp_unslash($_POST['file_wpid'])) : 0;
        $file = substr(sanitize_file_name(wp_unslash($_POST['file_id'] ?? '')), 2);
        $context_post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;

        if (!$attachment_id) {
            $this->send_ajax_response('false');
        }

        if (!$this->current_user_can_manage_attachment($attachment_id, $context_post_id)) {
            $this->send_ajax_response('Unauthorized.', 'error');
        }

        $attachment = get_post($attachment_id);

        if (!$attachment || $attachment->post_type !== 'attachment') {
            $this->send_ajax_response('false');
        }

        if ($post = wp_delete_attachment($attachment_id, true)) {
            clean_post_cache($post);
            $this->send_ajax_response($file);
        }

        $this->send_ajax_response('false');
    }

    public function plupload_ajax_delete_temp_file(): void
    {
        if (!self::verify_nonce(self::$upload_nonce_key)) {
            $this->send_ajax_response('Unauthorized.', 'error');
        }

        if (!empty($_POST['file_wpid'])) {
            $this->send_ajax_response('Unauthorized.', 'error');
        }

        $file = substr(sanitize_file_name(wp_unslash($_POST['file_id'] ?? '')), 2);
        $tmp_name = sanitize_file_name(wp_unslash($_POST['tmp_name'] ?? ''));
        $tmp_path = $this->get_temp_upload_path($tmp_name);

        if (!$tmp_path || !file_exists($tmp_path)) {
            $this->send_ajax_response('false');
        }

        if (@unlink($tmp_path)) {
            $this->send_ajax_response($file);
        }

        $this->send_ajax_response('false');
    }

    private static function verify_nonce(string $nonce_key): bool
    {
        $nonce_value = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : null;

        if (!isset($nonce_value) || !wp_verify_nonce($nonce_value, $nonce_key)) {
            return false;
        }

        return true;
    }

    private function send_ajax_response($response, $type = false)
    {
        if ($type) {
            if (!is_array($response)) {
                $response = [
                    'message' => strval($response),
                ];
            }

            $response = [
                'result' => $type,
                $type    => $response,
            ];
        }

        header("Content-Type: text/plain");

        echo wp_json_encode($response);

        die();
    }

    public function plupload_ajax_download_file(): void
    {
        if (!self::verify_nonce(self::$media_nonce_key)) {
            $this->send_ajax_response('Unauthorized.', 'error');
        }

        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;

        if (!$post_id) {
            $this->send_ajax_response('Unauthorized.', 'error');
        }

        if (!$this->current_user_can_access_media_post($post_id)) {
            $this->send_ajax_response('Unauthorized.', 'error');
        }

        $meta_key = isset($_POST['get_by_meta']) ? sanitize_text_field(wp_unslash($_POST['get_by_meta'])) : '';
        $attachment_ids = $this->get_media_attachment_ids($post_id, $meta_key);

        if (empty($attachment_ids)) {
            $this->send_ajax_response('false');
        }

        $files_to_zip = [];

        foreach ($attachment_ids as $attachment_id) {
            if (!$this->current_user_can_download_attachment($attachment_id, $post_id)) {
                $this->send_ajax_response('Unauthorized.', 'error');
            }

            $path = get_attached_file($attachment_id, true);

            if (!$path || !file_exists($path)) {
                continue;
            }

            $files_to_zip[] = $path;
        }

        if (empty($files_to_zip)) {
            $this->send_ajax_response('false');
        }

        $file = $this->get_temp_archive_path();

        if (!$file || !$this->create_download_archive($files_to_zip, $file)) {
            if ($file && file_exists($file)) {
                unlink($file);
            }

            $this->send_ajax_response('Server error.', 'error');
        }

        ob_get_clean();

        header('Content-Type: application/zip');

        header('Content-Disposition: attachment; filename=attachment.zip');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');

        header('Content-Length: ' . self::filesize($file));

        readfile($file);

        unlink($file);

        die();
    }

    private static function filesize($path)
    {
        $size = 0;
        if (file_exists($path)) {
            $size = @filesize($path) ?: 0;
        }

        return $size;
    }

    private function get_temp_archive_path()
    {
        if (function_exists('wp_tempnam')) {
            return wp_tempnam('gfmu-attachment.zip');
        }

        return tempnam(sys_get_temp_dir(), 'gfmu-zip-');
    }

    private function create_download_archive(array $files_to_zip, string $archive_path): bool
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $result = $zip->open($archive_path, ZipArchive::OVERWRITE);

            if ($result !== true) {
                return false;
            }

            foreach ($files_to_zip as $path) {
                $zip->addFile($path, basename($path));
            }

            return $zip->close();
        }

        require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

        if (!class_exists('PclZip')) {
            return false;
        }

        $zip = new PclZip($archive_path);
        $result = $zip->create($files_to_zip, PCLZIP_OPT_REMOVE_ALL_PATH);

        return !empty($result);
    }

    /**
     * Handles ajax request from Pluploader script, checks nonce, grabs validation options from gforms form meta
     * then passes the validation options to the main File Uploader php script to process and move to server
     *
     * NOTE: If validation options are not set in gforms for this field the script will default to just images <= 0.5mb
     *        Script will not accept any .js or .php ot .html extensions regardless of validation settings.
     */
    public function plupload_ajax_submit(): void
    {
        // Include the uploader class
        require_once GFMU_INC_PATH . 'GFMU_FileUploader.php';

        if (!self::verify_nonce(self::$upload_nonce_key)) {
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
     * Gets validation options for current field from the gform form metadata
     */
    public function pluploader_server_settings()
    {
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

        return apply_filters('gfmu_server_validation_args', $validation_args, $field_obj);
    }

    public function current_user_can_access_media_post(int $post_id): bool
    {
        if (!$post_id || !is_user_logged_in()) {
            return false;
        }

        $post = get_post($post_id);

        if (!$post) {
            return false;
        }

        return current_user_can('edit_post', $post_id);
    }

    public function current_user_can_manage_attachment(int $attachment_id, int $context_post_id = 0): bool
    {
        if (!$attachment_id || !is_user_logged_in()) {
            return false;
        }

        $attachment = get_post($attachment_id);

        if (!$attachment || $attachment->post_type !== 'attachment') {
            return false;
        }

        if (!current_user_can('delete_post', $attachment_id)) {
            return false;
        }

        if ($context_post_id && !$this->current_user_can_access_media_post($context_post_id)) {
            return false;
        }

        if ($attachment->post_parent) {
            return current_user_can('edit_post', $attachment->post_parent);
        }

        return current_user_can('edit_post', $attachment_id);
    }

    public function current_user_can_download_attachment(int $attachment_id, int $context_post_id = 0): bool
    {
        if (!$attachment_id || !is_user_logged_in()) {
            return false;
        }

        $attachment = get_post($attachment_id);

        if (!$attachment || $attachment->post_type !== 'attachment') {
            return false;
        }

        if ($context_post_id && !$this->current_user_can_access_media_post($context_post_id)) {
            return false;
        }

        if ($attachment->post_parent) {
            return current_user_can('edit_post', $attachment->post_parent);
        }

        return current_user_can('edit_post', $attachment_id);
    }

    private function get_media_attachment_ids(int $post_id, string $meta_key = ''): array
    {
        if (!$post_id) {
            return [];
        }

        $attachment_ids = [];

        if (empty($meta_key)) {
            $images = get_attached_media('image', $post_id);
            foreach ($images as $image) {
                if ($image instanceof WP_Post) {
                    $attachment_ids[] = intval($image->ID);
                }
            }
        }
        else {
            $images = maybe_unserialize(get_metadata('post', $post_id, $meta_key, true));
            if (is_array($images)) {
                $attachment_ids = array_map('intval', $images);
            }
        }

        $attachment_ids = array_filter(array_unique($attachment_ids));

        return array_values($attachment_ids);
    }

    private function get_temp_upload_path(string $tmp_name): ?string
    {
        $tmp_name = sanitize_file_name($tmp_name);

        if (empty($tmp_name)) {
            return null;
        }

        return $this->cache['upload_dir'] . $tmp_name;
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
     */
    private static function move_file($current_path = null, $destination_path = null): bool
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
     * Helper to generate all the hidden field html and javascript local vars
     * required to place a file already on the tmp folder back into an instance
     * of plupload.
     */
    public function get_existing_file_uploaded($field_id, array $args = []): array
    {
        $file_data = $this->get_post_file_uploaded($field_id, true);

        if (empty($file_data)) {
            $file_data = $this->get_uploaded_media($args);
        }

        return $file_data;
    }

    public function get_post_file_uploaded($field_id, $db_search = false): array
    {
        global $wpdb;

        $file_data = [];

        $uplo_dir = $this->cache['wp_upload_dir'];

        $tmp_uploads = $this->get_raw_posted_data($field_id);

        foreach ($tmp_uploads as $file_uid) {

            if (!isset($_POST["{$file_uid}_tname"], $_POST["{$file_uid}_name"]))
                continue;

            $attachment_id = 0;

            $file_name = sanitize_file_name($_POST["{$file_uid}_tname"]);

            $path = $uplo_dir['basedir'] . '/' . self::$upload_tmp_dir_name . '/' . esc_attr($file_name);

            $img_thumb_url = $uplo_dir['baseurl'] . '/' . self::$upload_tmp_dir_name . '/' . esc_attr($file_name);
            $preview_url = $img_thumb_url;

            if (!file_exists($path) and $db_search) {

                $attachment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", array($file_name)));

                if (empty($attachment))
                    continue;

                $attachment_id = $attachment->ID;
                $img_thumb_url = wp_get_attachment_image_src($attachment->ID, 'thumbnail');
                $preview_url = wp_get_attachment_url($attachment->ID);

                if ($img_thumb_url)
                    $img_thumb_url = $img_thumb_url[0];
                else
                    $img_thumb_url = $attachment->guid;

                if (!$preview_url)
                    $preview_url = $attachment->guid;

                $path = get_attached_file($attachment->ID, true);
            }

            $file_data[] = [
                'id'            => $file_uid,
                'o_name'        => sanitize_file_name($_POST["{$file_uid}_name"]),
                't_name'        => $file_name,
                'url'           => $img_thumb_url,
                'preview_url'   => $preview_url,
                'size'          => $path ? self::filesize($path) : 0,
                'last_mod_date' => $path ? @filemtime($path) : time(),
                'wpid'          => $attachment_id,
            ];
        }

        return $file_data;
    }

    public function get_raw_posted_data($field_id): array
    {
        if (!isset($_POST["input_{$field_id}"]) or !is_array($_POST["input_{$field_id}"])) {
            return [];
        }

        return array_map('sanitize_file_name', $_POST["input_{$field_id}"]);
    }

    public function get_uploaded_media($args = []): array
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
            $preview_url = wp_get_attachment_url($image->ID);

            if ($img_thumb_url)
                $img_thumb_url = $img_thumb_url[0];
            else
                $img_thumb_url = $image->guid;

            if (!$preview_url)
                $preview_url = $image->guid;

            $path = get_attached_file($image->ID, true);

            if (file_exists($path)) {
                $ext = pathinfo($path, PATHINFO_EXTENSION);
                $file_size = self::filesize($path);
                $last_mod = filemtime($path);
            }
            else {
                $ext = pathinfo($img_thumb_url, PATHINFO_EXTENSION);
                $file_size = 0;
                $last_mod = time();
            }

            $file_name = "file_" . ($file_upload_number + 1) . ".{$ext}";

            $file_data[] = [
                'id'            => "o_" . pathinfo($image->guid, PATHINFO_FILENAME),
                'o_name'        => $file_name,
                't_name'        => $image->ID,
                'url'           => $img_thumb_url,
                'preview_url'   => $preview_url,
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
            't_name' => sanitize_file_name($_POST["{$file_uid}_tname"]),
            'o_name' => sanitize_file_name($_POST["{$file_uid}_name"])
        ];
    }
}
