<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
/** Focused, isolated checks for public upload and attachment promotion. */

define('SECURE_AUTH_SALT', 'test-only-salt');
define('ABSPATH', __DIR__ . '/');
define('GFMU_INC_PATH', dirname(__DIR__) . '/inc/');

function __($message, $domain = '') { return $message; }
function absint($value) { return abs((int)$value); }
function wp_unslash($value) { return stripslashes($value); }
function wp_is_stream($path) { return false; }
function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
function trailingslashit($path) { return rtrim($path, '/\\') . '/'; }
function sanitize_file_name($name) {
    $name = trim(preg_replace('/[^A-Za-z0-9._-]/', '-', $name), '-');
    $parts = explode('.', $name);
    for ($i = 1; $i < count($parts) - 1; $i++) {
        if (strlen($parts[$i]) >= 2 && strlen($parts[$i]) <= 5 && !in_array(strtolower($parts[$i]), array('png', 'pdf'), true)) {
            $parts[$i] .= '_';
        }
    }
    return implode('.', $parts);
}
function sanitize_text_field($value) { return is_string($value) ? trim($value) : ''; }
function get_allowed_mime_types() { return array('png' => 'image/png', 'pdf' => 'application/pdf'); }
function apply_filters($name, $value) { return $value; }
function wp_generate_attachment_metadata($id, $path) { return array(); }
function wp_update_attachment_metadata($id, $metadata) { return true; }
function wp_insert_attachment($args, $path) { return 77; }
function is_wp_error($value) { return false; }
function get_transient($key) { global $test_transients; return $test_transients[$key] ?? false; }
function delete_transient($key) { global $test_transients; unset($test_transients[$key]); }
function is_user_logged_in() { return false; }
function get_post($id) { return null; }
function current_user_can($capability, $id = 0) { return false; }
function wp_unique_filename($directory, $name) {
    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $base = pathinfo($name, PATHINFO_FILENAME);
    $candidate = $name;
    for ($counter = 1; file_exists($directory . '/' . $candidate); $counter++) {
        $candidate = $base . '-' . $counter . '.' . $extension;
    }
    return $candidate;
}

class RGFormsModel
{
    public static function get_field($form_id, $field_id)
    {
        if ($form_id !== 1 || $field_id !== 2) {
            return null;
        }
        return new class {
            public $type = 'multi-uploader';
            public function get_gfmu_field_settings() {
                return array('filters' => array('files' => 'png'), 'max_file_size' => '1mb', 'max_files' => 2,
                    'save_to_meta' => '', 'rename_file_status' => false, 'chunk_size' => 1024);
            }
        };
    }
}

class GFForms {}
class GF_Field
{
    public $failed_validation = false;
    public $validation_message = '';
    public function validate($value, $form) {}
}

$test_root = sys_get_temp_dir() . '/gfmu-security-' . bin2hex(random_bytes(8));
mkdir($test_root);
mkdir($test_root . '/public');
mkdir($test_root . '/public/gfmu-uploads-tmp');
mkdir($test_root . '/media');

function wp_upload_dir()
{
    global $test_root;
    return array('basedir' => $test_root . '/public', 'baseurl' => 'https://example.test/public', 'path' => $test_root . '/media', 'url' => 'https://example.test/media');
}

function wp_check_filetype_and_ext($path, $name, $mimes)
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    foreach ($mimes as $extensions => $mime) {
        if (in_array($extension, explode('|', $extensions), true)) {
            if ($extension === 'png' && @getimagesize($path) === false) {
                return array('ext' => false, 'type' => false);
            }
            return array('ext' => $extension, 'type' => $mime);
        }
    }
    return array('ext' => false, 'type' => false);
}

function assert_check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function remove_test_tree($path)
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

require_once dirname(__DIR__) . '/inc/GFMU_FileUploader.php';
require_once dirname(__DIR__) . '/inc/GFMUHandlePluploader.class.php';
require_once dirname(__DIR__) . '/inc/GF_MultiUploader_Field.class.php';

class TestField extends GF_MultiUploader_Field
{
    public function get_gfmu_field_settings($form = null) { return array('max_files' => 1); }
}

class TestUploader extends GFMU_FileUploader
{
    public $mutate_chunk_path;

    protected function validateUploadedFile(string $name = '', string $file_path = '')
    {
        $result = parent::validateUploadedFile($name, $file_path);
        if ($result === true && $this->mutate_chunk_path) {
            file_put_contents($this->mutate_chunk_path, '<?php echo "late write";', FILE_APPEND);
        }
        return $result;
    }
}

function send_chunk($uploader, $name, $index, $contents, $test_root, $upload_id = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $stage_dir = null)
{
    $source = tempnam($test_root, 'input-');
    file_put_contents($source, $contents);
    $_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
    $_REQUEST = array('filename' => $name, 'chunks' => 2, 'chunk' => $index, 'upload_id' => $upload_id);
    $_FILES = array('file' => array('name' => $name, 'tmp_name' => $source, 'size' => strlen($contents)));
    try {
        return $uploader->handleUpload($stage_dir ?: $test_root . '/public/gfmu-uploads-tmp');
    } finally {
        unlink($source);
    }
}

try {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $options = array('chunksFolder' => $test_root . '/private-chunks', 'allowedExtensions' => '', 'sizeLimit' => '1mb', 'enable_chunked' => true);
    $uploader = new TestUploader($options);

    send_chunk($uploader, 'shell.php', 0, '<?php ', $test_root);
    $result = send_chunk($uploader, 'shell.php', 1, 'echo "bad";', $test_root);
    assert_check($result['result'] === 'error', 'PHP upload with an empty field allowlist was accepted');
    assert_check(!file_exists($test_root . '/public/gfmu-uploads-tmp/shell.php'), 'PHP reached public uploads');

    $result = send_chunk($uploader, 'shell.php.png', 0, "\x89PNG\r\n\x1a\n", $test_root);
    assert_check($result['result'] === 'error', 'Executable double extension was accepted');
    $result = send_chunk($uploader, 'shell.php_.png', 0, "\x89PNG\r\n\x1a\n", $test_root);
    assert_check($result['result'] === 'error', 'Sanitized executable double extension was accepted');
    $result = send_chunk($uploader, 'shell.pht_.png', 0, "\x89PNG\r\n\x1a\n", $test_root);
    assert_check($result['result'] === 'error', 'PHP handler alias was accepted');

    $small = new TestUploader(array_merge($options, array('sizeLimit' => '15')));
    send_chunk($small, 'large.png', 0, str_repeat('A', 10), $test_root);
    $result = send_chunk($small, 'large.png', 1, str_repeat('B', 10), $test_root);
    assert_check($result['result'] === 'error', 'Cumulative chunk size limit was bypassed');

    $php_allowed = new TestUploader(array_merge($options, array('allowedExtensions' => 'php,png')));
    $result = send_chunk($php_allowed, 'image.php', 0, "\x89PNG\r\n\x1a\n", $test_root);
    $result = send_chunk($php_allowed, 'image.php', 1, 'image bytes', $test_root);
    assert_check($result['result'] === 'error', 'An explicitly configured PHP suffix was accepted');

    $result = send_chunk($uploader, '../escape.png', 0, 'data', $test_root);
    assert_check($result['result'] === 'error', 'Traversal filename was accepted');
    assert_check(!file_exists($test_root . '/escape.png'), 'Traversal wrote outside uploads');

    $normal = new TestUploader($options);
    $normal->mutate_chunk_path = $options['chunksFolder'] . '/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/upload.part';
    send_chunk($normal, 'photo.png', 0, substr($png, 0, 8), $test_root);
    $result = send_chunk($normal, 'photo.png', 1, substr($png, 8), $test_root);
    assert_check(($result['result'] ?? '') === 'success', 'A permitted chunked image failed: ' . json_encode($result));
    $promoted = $test_root . '/public/gfmu-uploads-tmp/' . $result['success']['file_id'];
    assert_check(file_get_contents($promoted) === $png, 'Promoted bytes differed from validated snapshot');

    $other_id = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    send_chunk($uploader, 'same.png', 0, substr($png, 0, 8), $test_root);
    send_chunk($uploader, 'same.png', 0, substr($png, 0, 8), $test_root, $other_id);
    $first = send_chunk($uploader, 'same.png', 1, substr($png, 8) . 'A', $test_root);
    $second = send_chunk($uploader, 'same.png', 1, substr($png, 8) . 'B', $test_root, $other_id);
    assert_check(($first['result'] ?? '') === 'success' && ($second['result'] ?? '') === 'success', 'Concurrent filenames failed');
    assert_check($first['success']['file_id'] !== $second['success']['file_id'], 'Concurrent uploads shared a staged name');
    assert_check(file_get_contents($test_root . '/public/gfmu-uploads-tmp/' . $first['success']['file_id']) === $png . 'A', 'First upload buffer was mixed');
    assert_check(file_get_contents($test_root . '/public/gfmu-uploads-tmp/' . $second['success']['file_id']) === $png . 'B', 'Second upload buffer was mixed');

    $existing_path = $test_root . '/public/gfmu-uploads-tmp/already.png';
    $candidate_path = $test_root . '/candidate.png';
    file_put_contents($existing_path, 'existing bytes');
    file_put_contents($candidate_path, $png);
    $move = new ReflectionMethod(GFMU_FileUploader::class, 'move_file');
    $move->setAccessible(true);
    assert_check($move->invoke($uploader, $candidate_path, $existing_path) === false, 'Concurrent staging replaced an existing file');
    assert_check(file_get_contents($existing_path) === 'existing bytes', 'Exclusive staging write altered existing bytes');

    $limited = new TestUploader(array_merge($options, array('chunksFolder' => $test_root . '/limited-chunks')));
    $limited->maxActiveUploads = 1;
    send_chunk($limited, 'limited.png', 0, substr($png, 0, 8), $test_root);
    $blocked = send_chunk($limited, 'limited.png', 0, substr($png, 0, 8), $test_root, $other_id);
    assert_check($blocked['result'] === 'error', 'Active chunk buffer limit was bypassed');
    $finished = send_chunk($limited, 'limited.png', 1, substr($png, 8), $test_root);
    assert_check($finished['result'] === 'success', 'Limited upload failed to complete');
    $resumed = send_chunk($limited, 'limited.png', 0, substr($png, 0, 8), $test_root, $other_id);
    assert_check(isset($resumed['success']), 'Slot was not released after completed upload');

    $private_budget = new TestUploader(array_merge($options, array('chunksFolder' => $test_root . '/budget-chunks')));
    $private_budget->maxStagedBytes = 12;
    $first_buffer = send_chunk($private_budget, 'budget-one.png', 0, substr($png, 0, 8), $test_root, 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
    $second_buffer = send_chunk($private_budget, 'budget-two.png', 0, substr($png, 0, 8), $test_root, 'ffffffffffffffffffffffffffffffff');
    assert_check(isset($first_buffer['success']) && $second_buffer['result'] === 'error', 'Private buffer byte quota was bypassed');

    $quota_stage = $test_root . '/quota-stage';
    $quota = new TestUploader(array_merge($options, array('chunksFolder' => $test_root . '/quota-chunks')));
    $quota->maxStagedFiles = 1;
    send_chunk($quota, 'quota-one.png', 0, substr($png, 0, 8), $test_root, 'cccccccccccccccccccccccccccccccc', $quota_stage);
    $first_stage = send_chunk($quota, 'quota-one.png', 1, substr($png, 8), $test_root, 'cccccccccccccccccccccccccccccccc', $quota_stage);
    send_chunk($quota, 'quota-two.png', 0, substr($png, 0, 8), $test_root, 'dddddddddddddddddddddddddddddddd', $quota_stage);
    $denied_stage = send_chunk($quota, 'quota-two.png', 1, substr($png, 8), $test_root, 'dddddddddddddddddddddddddddddddd', $quota_stage);
    assert_check($first_stage['result'] === 'success' && $denied_stage['result'] === 'error', 'Completed staging quota was bypassed');

    file_put_contents($test_root . '/public/gfmu-uploads-tmp/index.php', '<?php echo "staged";');
    $handler = GFMUHandlePluploader::getInstance();
    $_REQUEST = array('currentFormID' => 1, 'currentFieldID' => 2);
    $server_settings = $handler->pluploader_server_settings();
    assert_check(strpos(wp_normalize_path($server_settings['chunksFolder']), wp_normalize_path($test_root . '/public')) !== 0,
        'Chunk buffers were configured inside public uploads');
    $result = $handler->maybe_insert_attachment(array('basename' => 'index.php'));
    assert_check($result === false, 'Staged PHP was promoted as an attachment');
    assert_check(!file_exists($test_root . '/media/index.php'), 'Staged PHP reached media uploads');

    $stage_name = basename($promoted);
    $token = 'test-upload-secret';
    $test_transients['gfmu_file_' . hash('sha256', $stage_name)] = array(
        'token_hash' => hash('sha256', $token), 'form_id' => 1, 'field_id' => 2,
    );
    $result = $handler->maybe_insert_attachment(array('basename' => $stage_name, 'form_id' => 1, 'field_id' => 2, 'upload_token' => 'wrong'));
    assert_check($result === false && file_exists($promoted), 'Another user claimed a staged file');
    $result = $handler->maybe_insert_attachment(array('basename' => $stage_name, 'form_id' => 1, 'field_id' => 3, 'upload_token' => $token));
    assert_check($result === false && file_exists($promoted), 'A staged file was claimed by another field');
    file_put_contents($test_root . '/media/' . $stage_name, 'preexisting media');
    $result = $handler->maybe_insert_attachment(array('basename' => $stage_name, 'form_id' => 1, 'field_id' => 2, 'upload_token' => $token));
    assert_check($result === 77, 'Permitted staged image was not attached');
    assert_check(file_get_contents($test_root . '/media/' . $stage_name) === 'preexisting media', 'Existing media was overwritten');
    assert_check(file_exists($test_root . '/media/photo-1.png'), 'Attachment file was not moved to a unique path');

    $result = $handler->maybe_insert_attachment(array('basename' => '99'));
    assert_check($result === 0, 'Guest attached an existing media item');
    $field = new TestField();
    $field->validate(array('first', 'second'), array());
    assert_check($field->failed_validation, 'Field accepted more files than its configured maximum');
    assert_check($handler->get_uploaded_media(array('post_id' => 99)) === array(), 'Guest accessed a post media listing');
    $_POST = array('input_2' => array('file99'), 'file99_tname' => '99', 'file99_name' => 'private.png');
    assert_check($handler->get_post_file_uploaded(2, true) === array(), 'Guest disclosed an existing attachment through posted data');
    $_POST = array('file77_tname' => 'safe.png', 'file77_name' => 'safe.png', 'file77_token' => $token);
    assert_check($handler->get_raw_posted_details('file77')['upload_token'] === $token, 'Upload token was lost at form submission');

    echo "upload security checks passed\n";
} finally {
    remove_test_tree($test_root);
}
