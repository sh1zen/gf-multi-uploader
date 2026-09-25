<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

/**
 *  Class - GFMU_FileUploader
 *
 *  Handles file upload from Plupload.
 *
 *  Stages validated uploads, assembles private chunk buffers, and limits
 *  temporary storage before files are promoted to the media library.
 */
class GFMU_FileUploader
{
    public array $allowedExtensions = array();

    public string $inputName = 'file';
    public int $maxFileAge = 18000;
    public int $chunksExpireIn = 3600;
    public int $maxActiveUploads = 200;
    public int $maxStagedFiles = 200;
    public int $maxStagedBytes = 1073741824;
    protected $uploadName;
    private $options;
    private $uuid = null;

    public function __construct($args = [])
    {
        $this->options = array_merge([
            'chunksFolder'      => sys_get_temp_dir() . '/gfmu-chunks',
            'allowedExtensions' => 'jpg,jpeg,png,webp',
            'sizeLimit'         => '10mb',
            'maxFiles'          => 1,
            'saveToMeta'        => false,
            'rename_files'      => false,
            'enable_chunked'    => false,
            'allowed_mimes'     => get_allowed_mime_types()

        ], $args);

        $this->options['allowedExtensions'] = $this->normalizeExtensions($this->options['allowedExtensions']);
        $this->options['sizeLimit'] = $this->toBytes($this->options['sizeLimit']);
        $this->maxStagedBytes = (int)min(21474836480, max($this->maxStagedBytes, $this->options['sizeLimit'] * 20));
    }

    private function normalizeExtensions($extensions): array
    {
        if (is_array($extensions)) {
            $extensions = implode(',', $extensions);
        }

        $extensions = array_map(
            static function ($extension) {
                return strtolower(ltrim(trim((string)$extension), '.'));
            },
            explode(',', (string)$extensions)
        );

        $extensions = array_values(array_unique(array_filter($extensions)));

        return $extensions;
    }

    /**
     * Converts a given size with units to bytes.
     */
    protected function toBytes($str): int
    {
        if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*([kmgt]b?|b)?\s*$/i', (string)$str, $matches)) {
            return 0;
        }

        $powers = ['' => 0, 'b' => 0, 'k' => 1, 'kb' => 1, 'm' => 2, 'mb' => 2, 'g' => 3, 'gb' => 3, 't' => 4, 'tb' => 4];
        return (int)floor((float)$matches[1] * (1024 ** $powers[strtolower($matches[2] ?? '')]));
    }

    /**
     * Process the upload.
     * @param string $uploadDirectory Target directory.
     * @param string|null $name Overwrites the name of the file.
     * @return array|bool|bool[]
     */
    public function handleUpload(string $uploadDirectory, string $name = '')
    {
        // Keep every upload path below its intended directory.
        $name = $name !== '' ? $name : $this->getName();
        if (preg_match('~[/\\\\\x00-\x1f]~', $name)) {
            return array('result' => 'error', 'error' => array('code' => 100, 'message' => __('Invalid file name.', 'gf-multi-uploader')));
        }

        if ($this->hasExecutableExtension($name)) {
            return array('result' => 'error', 'error' => array('code' => 100, 'message' => __('Invalid file extension.', 'gf-multi-uploader')));
        }

        $name = sanitize_file_name($name);
        if ($name === '' || $name[0] === '.' || strpos($name, '.') === false) {
            return array('result' => 'error', 'error' => array('code' => 100, 'message' => __('Invalid file name.', 'gf-multi-uploader')));
        }

        $this->uuid = explode('.', $name)[0];

        // Reject executable and disallowed extensions before writing even a chunk.
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($this->hasExecutableExtension($name) || !$this->extensionIsAllowed($extension)) {
            return array('result' => 'error', 'error' => array('code' => 100, 'message' => __('File has an invalid extension.', 'gf-multi-uploader')));
        }

        // Check that the max upload size specified in class configuration does not
        // exceed size allowed by server config
        if (!$this->options['enable_chunked']) {

            if ($this->toBytes(ini_get('post_max_size')) < $this->options['sizeLimit'] or $this->toBytes(ini_get('upload_max_filesize')) < $this->options['sizeLimit']) {
                return array(
                    'result'   => 'error',
                    'file_uid' => $this->uuid,
                    'error'    => array(
                        'code'    => 100,
                        'message' => __("Server Error. Max file size too high, try activate chunking.", "gf-multi-uploader")
                    )
                );
            }
        }

        //First check to see if requested uploads dir exists, if not make it
        if (!file_exists($uploadDirectory)) {
            mkdir($uploadDirectory);
            chmod($uploadDirectory, 0744);

            //Add index.php to folder to stop direct access via browser
            $index_content = '<?php //Nothing to see here';
            $index_file = fopen($uploadDirectory . '/index.php', 'w');
            if ($index_file !== false) {
                fwrite($index_file, $index_content);
                fclose($index_file);
            }
        }

        if (!is_writable($uploadDirectory)) {
            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array(
                    'code'    => 100,
                    'message' => __("Server error. Uploads directory isn't writable or executable.", "gf-multi-uploader")
                )
            );
        }

        if (!isset($_SERVER['CONTENT_TYPE'])) {

            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array(
                    'code'    => 100,
                    'message' => "No files were uploaded."
                )
            );

        }
        else if (strpos(strtolower($_SERVER['CONTENT_TYPE']), 'multipart/') !== 0) {
            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array(
                    'code'    => 100,
                    'message' => __("Server error. Not a multipart request. Please set forceMultipart to default value (true).", "gf-multi-uploader")
                )
            );
        }

        // Get size and name
        $size = $_FILES[$this->inputName]['size'];

        // Validate file size

        if ($size == 0) {
            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array(
                    'code'    => 100,
                    'message' => __("File is empty.", "gf-multi-uploader")
                )
            );
        }

        if ($size > $this->options['sizeLimit']) {
            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array(
                    'code'    => 100,
                    'message' => sprintf(__("File is too large. Max %s Mb", "gf-multi-uploader"), $this->options['sizeLimit'])
                )
            );
        }

        // Remove old temp files
        if (is_dir($uploadDirectory) and ($dir = opendir($uploadDirectory))) {
            while (($file = readdir($dir)) !== false) {
                $tmpfilePath = $uploadDirectory . DIRECTORY_SEPARATOR . $file;

                // Remove temp file if it is older than the max age and is not the current file
                if ((filemtime($tmpfilePath) < time() - $this->maxFileAge) && ($tmpfilePath != "{$name}.part")) {
                    @unlink($tmpfilePath);
                }
            }

            closedir($dir);
        }

        //Check for chunked uploads
        $totalParts = isset($_REQUEST['chunks']) ? (int)$_REQUEST['chunks'] : 1;
        $partIndex = isset($_REQUEST['chunk']) ? (int)$_REQUEST['chunk'] : 0;
        if ($totalParts < 1 || $totalParts > 10000 || $partIndex < 0 || $partIndex >= $totalParts) {
            return array('result' => 'error', 'error' => array('code' => 100, 'message' => __('Invalid chunk index.', 'gf-multi-uploader')));
        }

        //Handle chunked uploads
        if ($totalParts > 1) {

            $upload_id = isset($_REQUEST['upload_id']) && is_string($_REQUEST['upload_id']) ? $_REQUEST['upload_id'] : '';
            if (!preg_match('/^[a-f0-9]{32}$/', $upload_id)) {
                return array('result' => 'error', 'error' => array('code' => 100, 'message' => __('Invalid upload identifier.', 'gf-multi-uploader')));
            }

            $chunksFolder = $this->options['chunksFolder'];

            //First check to see if requested uploads dir exists, if not make it
            if (!is_dir($chunksFolder) && !mkdir($chunksFolder, 0700, true)) {
                return array('result' => 'error', 'error' => array('code' => 100, 'message' => __('Failed to create chunks directory.', 'gf-multi-uploader')));
            }

            if (!is_writable($chunksFolder)) {
                return array(
                    'result'   => 'error',
                    'file_uid' => $this->uuid,
                    'error'    => array(
                        'code'    => 100,
                        'message' => __("Server error. Chunks directory isn't writable or executable.", "gf-multi-uploader")
                    )
                );
            }

            $targetFolder = $this->options['chunksFolder'] . DIRECTORY_SEPARATOR . $upload_id;
            $private_lock = $this->lockChunkQuota($targetFolder, $partIndex, (int)$size);
            if (!$private_lock) {
                return array('result' => 'error', 'error' => array('code' => 100, 'message' => __('Temporary chunk quota reached.', 'gf-multi-uploader')));
            }
            try {
            if (!is_dir($targetFolder) && $partIndex !== 0) {
                return array('result' => 'error', 'error' => array('code' => 100, 'message' => __('Missing first chunk.', 'gf-multi-uploader')));
            }

            if (!is_dir($targetFolder) && !mkdir($targetFolder, 0700)) {
                return array('result' => 'error', 'error' => array('code' => 100, 'message' => __('Failed to create chunk buffer.', 'gf-multi-uploader')));
            }

            //Cache a unique tmp file path in chunks dir to buffer the file chunks
            $tmp_chunk_file_path = $targetFolder . '/upload.part';
            if ($partIndex !== 0 && !is_file($tmp_chunk_file_path)) {
                return array('result' => 'error', 'error' => array('code' => 100, 'message' => __('Missing earlier chunk.', 'gf-multi-uploader')));
            }

            //Open the temp file
            $out = @fopen($tmp_chunk_file_path, 'c+b');

            //If tmp file has been opened successfully start to write the stream to it
            if ($out && flock($out, LOCK_EX)) {

                if ($partIndex === 0) {
                    ftruncate($out, 0);
                    rewind($out);
                }
                $current_size = fstat($out)['size'];
                if ($current_size + $size > $this->options['sizeLimit']) {
                    flock($out, LOCK_UN);
                    fclose($out);
                    return array('result' => 'error', 'error' => array('code' => 100, 'message' => __('File is too large.', 'gf-multi-uploader')));
                }
                fseek($out, 0, SEEK_END);

                // Read binary input stream and append it to temp file
                $chunked_input_data_stream = $_FILES[$this->inputName]['tmp_name'];
                $in = @fopen($chunked_input_data_stream, "rb");

                //If stream file has been opened then start to write the tmp file to the destination file
                if ($in) {

                    //Note we are reading in small sections of 4096 bytes
                    while ($buff = fread($in, 4096)) {
                        fwrite($out, $buff);
                    }
                }
                else {
                    flock($out, LOCK_UN);
                    fclose($out);
                    return array(
                        'result'   => 'error',
                        'file_uid' => $this->uuid,
                        'error'    => array(
                            'code'    => 100,
                            'message' => __("Failed to open input stream", "gf-multi-uploader")
                        )
                    );
                }

                @fclose($in);
                flock($out, LOCK_UN);
                @fclose($out);
                @touch($targetFolder);
            }
            else {
                if ($out) {
                    fclose($out);
                }
                return array(
                    'result'   => 'error',
                    'file_uid' => $this->uuid,
                    'error'    => array(
                        'code'    => 100,
                        'message' => __("Failed to open chunk destination file", "gf-multi-uploader")
                    )
                );
            }
            } finally {
                flock($private_lock, LOCK_UN);
                fclose($private_lock);
            }

            //So we have buffered the last chunk of the stream lets move the file into the main dir
            if ($totalParts - 1 == $partIndex) {
                // Other requests can still append to the chunk buffer. Validate a private
                // snapshot so the bytes checked are the same bytes later promoted.
                $complete_file = tempnam(sys_get_temp_dir(), 'gfmu-');
                if (!$complete_file || !copy($tmp_chunk_file_path, $complete_file)) {
                    if ($complete_file) {
                        @unlink($complete_file);
                    }
                    return array('result' => 'error', 'file_uid' => $this->uuid, 'error' => array('code' => 100, 'message' => __('Failed to prepare final buffer file.', 'gf-multi-uploader')));
                }

                try {
                    $validate_result = $this->validateUploadedFile($name, $complete_file);
                    if ($validate_result !== true) {
                        @unlink($tmp_chunk_file_path);
                        @rmdir($targetFolder);
                        return $validate_result;
                    }

                    clearstatcache(true, $complete_file);
                    $complete_size = filesize($complete_file);
                    if ($complete_size === false || $complete_size > $this->options['sizeLimit']) {
                        @unlink($tmp_chunk_file_path);
                        @rmdir($targetFolder);
                        return array('result' => 'error', 'file_uid' => $this->uuid, 'error' => array('code' => 100, 'message' => __('File is too large.', 'gf-multi-uploader')));
                    }

                    $quota_lock = $this->lockStagingQuota($uploadDirectory, $complete_size);
                    if (!$quota_lock) {
                        @unlink($tmp_chunk_file_path);
                        @rmdir($targetFolder);
                        return array('result' => 'error', 'file_uid' => $this->uuid, 'error' => array('code' => 100, 'message' => __('Temporary upload quota reached.', 'gf-multi-uploader')));
                    }
                    try {
                        $file_info = $this->getUniqueTargetPath($uploadDirectory, $name);
                        if (!isset($file_info['file_path'])) {
                            return array('result' => 'error', 'file_uid' => $this->uuid, 'error' => array('code' => 100, 'message' => __('Error generating final file path.', 'gf-multi-uploader')));
                        }

                        if (!$this->move_file($complete_file, $file_info['file_path'])) {
                            return array('result' => 'error', 'file_uid' => $this->uuid, 'error' => array('code' => 100, 'message' => __('Failed to move final buffer file.', 'gf-multi-uploader')));
                        }

                        @unlink($tmp_chunk_file_path);
                        @rmdir($targetFolder);

                        return array(
                            'result'   => 'success',
                            'file_uid' => $this->uuid,
                            'success'  => array('file_id' => $file_info['file_name'])
                        );
                    } finally {
                        flock($quota_lock, LOCK_UN);
                        fclose($quota_lock);
                    }
                } finally {
                    @unlink($complete_file);
                }

            }

            return array("success" => true);
        }
        else {

            //Validate file for NON-Chunked uploads
            if (($validate_result = $this->validateUploadedFile($name)) !== true) {
                //Delete files
                @unlink($_FILES[$this->inputName]['tmp_name']);

                return $validate_result;
            }

            $quota_lock = $this->lockStagingQuota($uploadDirectory, (int)$size);
            if (!$quota_lock) {
                return array('result' => 'error', 'file_uid' => $this->uuid, 'error' => array('code' => 100, 'message' => __('Temporary upload quota reached.', 'gf-multi-uploader')));
            }
            try {
                $file_info = $this->getUniqueTargetPath($uploadDirectory, $name);

                if (isset($file_info['file_name'], $file_info['file_path'], $_FILES[$this->inputName]['tmp_name'])) {

                    $target = $file_info['file_path'];

                    if ($target) {
                        $this->uploadName = basename($target);
                        $reservation = @fopen($target, 'xb');
                        if ($reservation) {
                            fclose($reservation);
                        }

                        if ($reservation && move_uploaded_file($_FILES[$this->inputName]['tmp_name'], $target)) {
                            return array(
                                'result'   => 'success',
                                'file_uid' => $this->uuid,
                                'success'  => array(
                                    "file_id" => $file_info['file_name']
                                )
                            );
                        }
                        if ($reservation) {
                            @unlink($target);
                        }
                    }

                }
            } finally {
                flock($quota_lock, LOCK_UN);
                fclose($quota_lock);
            }

            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array(
                    'code'    => 100,
                    'message' => __("The upload was cancelled, or server error encountered", "gf-multi-uploader")
                )
            );
        }
    }

    /**
     * Get the original filename
     */
    public function getName(): ?string
    {
        if (isset($_REQUEST['filename']))
            return is_string($_REQUEST['filename']) ? wp_unslash($_REQUEST['filename']) : '';

        if (isset($_REQUEST['name']))
            return is_string($_REQUEST['name']) ? wp_unslash($_REQUEST['name']) : '';

        if (isset($_FILES[$this->inputName]))
            return is_string($_FILES[$this->inputName]['name']) ? $_FILES[$this->inputName]['name'] : '';

        return '';
    }

    /**
     * Deletes all file parts in the chunks folder for files uploaded
     * more than chunksExpireIn seconds ago
     */
    protected function cleanupChunks()
    {
        foreach (scandir($this->options['chunksFolder']) as $item) {
            if ($item == "." or $item == "..")
                continue;

            $path = $this->options['chunksFolder'] . DIRECTORY_SEPARATOR . $item;

            if (!is_dir($path))
                continue;

            if (time() - filemtime($path) > $this->chunksExpireIn) {
                $this->removeDir($path);
            }
        }
    }

    /**
     * Removes a directory and all files contained inside
     */
    protected function removeDir(string $dir)
    {
        foreach (scandir($dir) as $item) {
            if ($item == "." || $item == "..")
                continue;

            @unlink($dir . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($dir);
    }

    /**
     * Returns a path to use with this upload. Check that the name does not exist,
     * and appends a suffix otherwise.
     * @param string $uploadDirectory Target directory
     * @param string $filename The name of the file to use.
     */
    protected function getUniqueTargetPath(string $uploadDirectory, string $filename)
    {
        $result = [];

        list($result['file_path'], $result['file_name']) = $this->unique_filename($this->normalize_path($uploadDirectory . DIRECTORY_SEPARATOR . $filename), $this->options['rename_files']);

        if (!$result['file_path']) {
            $result = false;
        }

        return $result;
    }

    private function unique_filename($filename, $obfuscation = false): array
    {
        $iter = 0;

        $path_parts = pathinfo($filename);

        $filename = $obfuscation ? bin2hex(random_bytes(16)) : $path_parts['filename'];

        $path = $path_parts['dirname'] === '.' ? '' : "{$path_parts['dirname']}/";

        do {

            $out_name = $iter > 0 ? "{$filename}-{$iter}" : $filename;

            $iter++;

        } while (file_exists("{$path}{$out_name}.{$path_parts['extension']}"));

        return ["{$path}{$out_name}.{$path_parts['extension']}", "{$out_name}.{$path_parts['extension']}"];
    }

    private function normalize_path($path, $trailing_slash = false)
    {
        $wrapper = '';

        // Remove the trailing slash
        if (!$trailing_slash) {
            $path = rtrim($path, '/');
        }
        else {
            $path .= '/';
        }

        if (wp_is_stream($path)) {
            list($wrapper, $path) = explode('://', $path, 2);

            $wrapper .= '://';
        }
        else {
            // Windows paths should uppercase the drive letter.
            if (':' === substr($path, 1, 1)) {
                $path = ucfirst($path);
            }
        }

        // Standardise all paths to use '/' and replace multiple slashes down to a singular.
        $path = preg_replace('#(?<=.)[/\\\]+#', '/', $path);

        return $wrapper . $path;
    }

    /** Serialize chunk writes and bound the number and total size of private buffers. */
    private function lockChunkQuota(string $targetFolder, int $partIndex, int $incoming_size)
    {
        $root = $this->options['chunksFolder'];
        $lock = @fopen($root . '/.stage.lock', 'c+b');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) {
                fclose($lock);
            }
            return false;
        }

        if ($partIndex === 0) {
            $this->cleanupChunks();
        }
        $items = scandir($root);
        if ($items === false) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return false;
        }
        $active = 0;
        $bytes = 0;
        foreach ($items as $item) {
            $directory = $root . DIRECTORY_SEPARATOR . $item;
            if ($item === '.' || $item === '..' || !is_dir($directory)) {
                continue;
            }
            $active++;
            $buffer = $directory . '/upload.part';
            if (is_file($buffer)) {
                $bytes += (int)filesize($buffer);
            }
        }
        $existing = is_file($targetFolder . '/upload.part') ? (int)filesize($targetFolder . '/upload.part') : 0;
        if ((!is_dir($targetFolder) && $active >= $this->maxActiveUploads)
            || $bytes - ($partIndex === 0 ? $existing : 0) + $incoming_size > $this->maxStagedBytes) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return false;
        }
        return $lock;
    }

    /** Hold a private lock while checking the total public staging budget. */
    private function lockStagingQuota(string $uploadDirectory, int $incoming_size)
    {
        $private_directory = $this->options['chunksFolder'];
        if (!is_dir($private_directory) && !mkdir($private_directory, 0700, true)) {
            return false;
        }
        $lock = @fopen($private_directory . '/.stage.lock', 'c+b');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) {
                fclose($lock);
            }
            return false;
        }

        $files = scandir($uploadDirectory);
        if ($files === false) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return false;
        }
        $count = 0;
        $bytes = 0;
        foreach ($files as $file) {
            $path = $uploadDirectory . DIRECTORY_SEPARATOR . $file;
            if ($file === '.' || $file === '..' || $file === 'index.php' || !is_file($path)) {
                continue;
            }
            $count++;
            $bytes += (int)filesize($path);
        }
        if ($count >= $this->maxStagedFiles || $bytes + $incoming_size > $this->maxStagedBytes) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return false;
        }
        return $lock;
    }

    /**
     * move_file
     *
     * Helper to move a file from one path to another
     * Paths are full paths to a file including filename and ext
     */
    private function move_file($current_path = null, $destination_path = null): bool
    {
        if (!is_file($current_path) || !is_dir(dirname($destination_path))) {
            return false;
        }

        $source = @fopen($current_path, 'rb');
        $destination = $source ? @fopen($destination_path, 'xb') : false;
        if (!$source || !$destination) {
            if ($source) {
                fclose($source);
            }
            return false;
        }

        $expected_size = fstat($source)['size'];
        $copied_size = stream_copy_to_stream($source, $destination);
        fclose($destination);
        fclose($source);
        if ($copied_size === false || $copied_size !== $expected_size) {
            @unlink($destination_path);
            return false;
        }

        @unlink($current_path);
        return true;
    }

    /**
     * validateUploadedFile
     *
     * Validates the filename and contents against the field and WordPress MIME maps.
     *
     * @param string|null $name
     * @param string|null $file_path - defaults to $_FILES[$this->inputName]['tmp_name']
     * @return array|bool
     */
    protected function validateUploadedFile(string $name = '', string $file_path = '')
    {
        if (empty($file_path)) {
            $file_path = $_FILES[$this->inputName]['tmp_name'];
        }

        // Validate file extension
        $pathinfo = pathinfo($name);
        $ext = strtolower($pathinfo['extension'] ?? '');

        if ($this->hasExecutableExtension($name) || !$this->extensionIsAllowed($ext)) {
            $these = implode(', ', $this->options['allowedExtensions']);

            @unlink($file_path);

            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array(
                    'code'    => 100,
                    'message' => sprintf(__("File has an invalid extension, it should be one of %s.", "gf-multi-uploader"), $these)
                )
            );
        }

        $wp_filetype = wp_check_filetype_and_ext($file_path, $name, $this->options['allowed_mimes']);

        $allowed_mimes_for_extension = $this->getAllowedMimesForExtension($ext);

        if (empty($allowed_mimes_for_extension)
            || empty($wp_filetype['ext'])
            || strtolower($wp_filetype['ext']) !== $ext
            || empty($wp_filetype['type'])
            || !$this->mimeMatchesAllowed($wp_filetype['type'], $allowed_mimes_for_extension)) {
            @unlink($file_path);

            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array('code' => 100, 'message' => __('File has an invalid type or extension.', 'gf-multi-uploader'))
            );
        }

        return true;
    }

    /** Recheck a staged file against the destination field before creating media. */
    public function validateStagedFile(string $name, string $path): bool
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        return !$this->hasExecutableExtension($name) && $size !== false && $size > 0 && $size <= $this->options['sizeLimit']
            && $this->validateUploadedFile($name, $path) === true;
    }

    /** Reject executable suffixes in every filename segment, including double extensions. */
    private function hasExecutableExtension(string $name): bool
    {
        foreach (array_slice(explode('.', strtolower($name)), 1) as $segment) {
            if (preg_match('/^(?:php|pht|phar|(?:shtml|cgi|pl|py|rb|asp|aspx|jsp|sh|bash|exe|dll|bat|cmd|ps1)(?:$|[_-]))/', $segment)) {
                return true;
            }
        }
        return false;
    }

    /** Check the filename against the configured field and WordPress MIME map. */
    private function extensionIsAllowed(string $extension): bool
    {
        return $extension !== ''
            && !$this->hasExecutableExtension('file.' . $extension)
            && (!$this->options['allowedExtensions'] || in_array($extension, $this->options['allowedExtensions'], true))
            && !empty($this->getAllowedMimesForExtension($extension));
    }

    private function getAllowedMimesForExtension(string $extension): array
    {
        if (empty($extension)) {
            return array_values($this->options['allowed_mimes']);
        }

        $matches = [];

        foreach ($this->options['allowed_mimes'] as $extensions => $mime) {
            $extension_group = array_map('trim', explode('|', strtolower((string)$extensions)));

            if (in_array($extension, $extension_group, true)) {
                $matches[] = strtolower((string)$mime);
            }
        }

        return array_values(array_unique(array_filter($matches)));
    }

    private function mimeMatchesAllowed(string $mime_type, array $allowed_mimes): bool
    {
        $mime_type = strtolower(trim($mime_type));
        $allowed_mimes = array_map('strtolower', array_filter($allowed_mimes));

        if (empty($mime_type) || empty($allowed_mimes)) {
            return false;
        }

        if (in_array($mime_type, $allowed_mimes, true)) {
            return true;
        }

        $mime_aliases = [
            'image/x-png'                   => 'image/png',
            'image/pjpeg'                  => 'image/jpeg',
            'image/jpg'                    => 'image/jpeg',
            'application/x-zip-compressed' => 'application/zip',
            'application/x-pdf'            => 'application/pdf'
        ];

        if (isset($mime_aliases[$mime_type]) && in_array($mime_aliases[$mime_type], $allowed_mimes, true)) {
            return true;
        }

        return false;
    }
}
