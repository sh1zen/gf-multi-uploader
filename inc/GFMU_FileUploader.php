<?php

/**
 *  Class - GFMU_FileUploader
 *
 *  Handles file upload from Plupload.
 *
 *  1. Creates plupload tmp folder in uploads if not created
 *  2. Adds index.php
 *  3. Validates file extension and mime type against wordpress allowed mimes
 *  4. Handles single or chunked upload of files
 *  5. Also runs a tmp folder cleanup after 1 week or every 1000 requests
 */
class GFMU_FileUploader
{
    public $allowedExtensions = array();

    public $inputName = 'file';
    public $maxFileAge = 5 * 3600;
    public $chunksCleanupProbability = 1000;
    public $chunksExpireIn = 604800; // Once in 1000 requests on avg

    protected $uploadName;
    private $options;
    private $uuid = null;

    public function __construct($args = [])
    {
        $this->options = array_merge([
            'chunksFolder'      => './chunks',
            'allowedExtensions' => 'jpg,jpeg,png',
            'sizeLimit'         => '10mb',
            'maxFiles'          => 1,
            'saveToMeta'        => false,
            'rename_files'      => false,
            'enable_chunked'    => false,
            'allowed_mimes'     => get_allowed_mime_types()

        ], $args);

        $this->options['allowedExtensions'] = array_map('trim', explode(',', $this->options['allowedExtensions']));
        $this->options['sizeLimit'] = $this->toBytes($this->options['sizeLimit']);
    }

    /**
     * Converts a given size with units to bytes.
     * @param string $str
     */
    protected function toBytes($str)
    {
        $str = preg_replace('/[^0-9kmgtb]/', '', strtolower($str));

        if (!preg_match("/\b(\d+(?:\.\d+)?)\s*([kmgt]?b)\b/", trim($str), $matches))
            return absint($str);

        $val = absint($matches[1]);

        switch ($matches[2]) {
            case 'gb':
                $val *= 1024;
            case 'mb':
                $val *= 1024;
            case 'kb':
                $val *= 1024;
        }

        return $val;
    }

    /**
     * Get the name of the uploaded file
     */
    public function getUploadName()
    {
        return $this->uploadName;
    }

    /**
     * Process the upload.
     * @param string $uploadDirectory Target directory.
     * @param string $name Overwrites the name of the file.
     * @return array|bool|bool[]|mixed
     */
    public function handleUpload($uploadDirectory, $name = null)
    {
        //Cache upload id for current file
        $this->uuid = current(explode('.', $this->getName()));

        if (is_writable($this->options['chunksFolder']) and (rand(1, $this->chunksCleanupProbability) === 1)) {

            // Run garbage collection
            $this->cleanupChunks();
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
                        'message' => __("Server Error. Max file size too high, try activate chunking.", "gfmu-locale")
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
                    'message' => __("Server error. Uploads directory isn't writable or executable.", "gfmu-locale")
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
                    'message' => __("Server error. Not a multipart request. Please set forceMultipart to default value (true).", "gfmu-locale")
                )
            );
        }

        // Get size and name
        $size = $_FILES[$this->inputName]['size'];

        if ($name === null) {
            $name = $this->getName();
        }

        // Validate name

        if ($name === null || $name === '') {
            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array(
                    'code'    => 100,
                    'message' => __("File name is empty.", "gfmu-locale")
                )
            );
        }

        // Validate file size

        if ($size == 0) {
            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array(
                    'code'    => 100,
                    'message' => __("File is empty.", "gfmu-locale")
                )
            );
        }

        if ($size > $this->options['sizeLimit']) {
            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array(
                    'code'    => 100,
                    'message' => sprintf(__("File is too large. Max %s M", "gfmu-locale"), $this->options['sizeLimit'])
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

        //Handle chunked uploads
        if ($totalParts > 1) {

            $chunksFolder = $this->options['chunksFolder'];

            //First check to see if requested uploads dir exists, if not make it
            if (!file_exists($chunksFolder)) {
                mkdir($chunksFolder);
                chmod($chunksFolder, 0744);
            }

            $partIndex = (int)$_REQUEST['chunk'];

            if (!is_writable($chunksFolder)) {
                return array(
                    'result'   => 'error',
                    'file_uid' => $this->uuid,
                    'error'    => array(
                        'code'    => 100,
                        'message' => __("Server error. Chunks directory isn't writable or executable.", "gfmu-locale")
                    )
                );
            }

            $targetFolder = $this->options['chunksFolder'] . DIRECTORY_SEPARATOR . $this->uuid;

            if (!file_exists($targetFolder)) {
                mkdir($targetFolder);
            }

            //Cache a unique tmp file path in chunks dir to buffer the file chunks
            $tmp_chunk_file_path = $targetFolder . "/{$name}.part";

            //Open the temp file
            $out = @fopen($tmp_chunk_file_path, $partIndex == 0 ? "wb" : "ab");

            //If tmp file has been opened successfully start to write the stream to it
            if ($out) {

                // Read binary input stream and append it to temp file
                $chunked_input_data_stream = esc_attr($_FILES[$this->inputName]['tmp_name']);
                $in = @fopen($chunked_input_data_stream, "rb");

                //If stream file has been opened then start to write the tmp file to the desintaion file
                if ($in) {

                    //Note we are reading in small sections of 4096 bytes
                    while ($buff = fread($in, 4096))
                        fwrite($out, $buff);

                }
                else {
                    return array(
                        'result'   => 'error',
                        'file_uid' => $this->uuid,
                        'error'    => array(
                            'code'    => 100,
                            'message' => __("Failed to open input stream", "gfmu-locale")
                        )
                    );
                }

                @fclose($in);
                @fclose($out);
            }
            else {
                return array(
                    'result'   => 'error',
                    'file_uid' => $this->uuid,
                    'error'    => array(
                        'code'    => 100,
                        'message' => __("Failed to open chunk destination file", "gfmu-locale")
                    )
                );
            }

            //So we have buffered the last chunk of the stream lets move the file into the main dir
            if ($totalParts - 1 == $partIndex) {

                $file_info = $this->getUniqueTargetPath($uploadDirectory, $name);

                if (isset($file_info['file_path'])) {

                    $target = esc_attr($file_info['file_path']);

                    if ($this->move_file($tmp_chunk_file_path, $target)) {

                        //Validate whole tmp file
                        if (($validate_result = $this->validateUploadedFile($name, $target)) !== true) {

                            //Delete files
                            @unlink($tmp_chunk_file_path);
                            @unlink($target);

                            //Return result to user
                            return $validate_result;

                        }

                        //Remove the chunk tmp folder for this file
                        rmdir($targetFolder);

                        //Return that all is ok
                        return array(
                            'result'   => 'success',
                            'file_uid' => $this->uuid,
                            'success'  => array(
                                "file_id" => $file_info['file_name']
                            )
                        );

                    }
                    else {
                        return array(
                            'result'   => 'error',
                            'file_uid' => $this->uuid,
                            'error'    => array(
                                'code'    => 100,
                                'message' => __("Failed to move final buffer file", "gfmu-locale")
                            )
                        );
                    }

                }
                else {
                    return array(
                        'result'   => 'error',
                        'file_uid' => $this->uuid,
                        'error'    => array(
                            'code'    => 100,
                            'message' => __("Error generating final file path", "gfmu-locale")
                        )
                    );
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

            $file_info = $this->getUniqueTargetPath($uploadDirectory, $name);
            $this->uuid = current(explode('.', $name));

            if (isset($file_info['file_name'], $file_info['file_path'], $_FILES[$this->inputName]['tmp_name'])) {

                $target = $file_info['file_path'];

                if ($target) {
                    $this->uploadName = basename($target);

                    if (move_uploaded_file($_FILES[$this->inputName]['tmp_name'], $target)) {
                        return array(
                            'result'   => 'success',
                            'file_uid' => $this->uuid,
                            'success'  => array(
                                "file_id" => $file_info['file_name']
                            )
                        );
                    }
                }

            }

            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array(
                    'code'    => 100,
                    'message' => __("The upload was cancelled, or server error encountered", "gfmu-locale")
                )
            );
        }
    }

    /**
     * Get the original filename
     */
    public function getName()
    {
        if (isset($_REQUEST['name']))
            return esc_attr($_REQUEST['name']);

        if (isset($_FILES[$this->inputName]))
            return esc_attr($_FILES[$this->inputName]['name']);

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
     * @param string $dir
     */
    protected function removeDir($dir)
    {
        foreach (scandir($dir) as $item) {
            if ($item == "." || $item == "..")
                continue;

            unlink($dir . DIRECTORY_SEPARATOR . $item);
        }
        rmdir($dir);
    }

    /**
     * Returns a path to use with this upload. Check that the name does not exist,
     * and appends a suffix otherwise.
     * @param string $uploadDirectory Target directory
     * @param string $filename The name of the file to use.
     */
    protected function getUniqueTargetPath($uploadDirectory, $filename)
    {
        // Allow only one process at the time to get a unique file name, otherwise
        // if multiple people would upload a file with the same name at the same time
        // only the latest would be saved.

        if (function_exists('sem_acquire')) {
            $lock = sem_get(ftok(__FILE__, 'u'));
            sem_acquire($lock);
        }

        $pathinfo = pathinfo($filename);
        $base = $pathinfo['filename'];
        $ext = isset($pathinfo['extension']) ? $pathinfo['extension'] : '';
        $ext = $ext == '' ? $ext : '.' . $ext;

        $unique = $base;
        $suffix = 0;

        //Create a new random name for file - security reasons
        if (isset($filename) and $this->options['rename_files']) {

            $unique = md5($filename);

            $result['file_path'] = $uploadDirectory . DIRECTORY_SEPARATOR . $unique . $ext;

        }
        elseif ($filename and !$this->options['rename_files']) {

            //Cache destination full file path
            $_file_path = $uploadDirectory . DIRECTORY_SEPARATOR . $unique . $ext;

            //Check if there is a file with the same name in tmp folder
            if (file_exists($_file_path)) {

                //Put file in unique dir
                $suffix += rand(1, 999);
                $unique = $unique . '_' . $suffix;
            }

            $result['file_path'] = $uploadDirectory . DIRECTORY_SEPARATOR . $unique . $ext;
        }

        //Cache filename
        $result['file_name'] = $unique . $ext;

        // Create an empty target file
        if (!touch($result['file_path'])) {
            // Failed
            $result = false;
        }

        if (function_exists('sem_release')) {
            sem_release($lock);
        }

        return $result;
    }

    /**
     * move_file
     *
     * Helper to move a file from one path to another
     * Paths are full paths to a file including filename and ext
     */
    private function move_file($current_path = null, $destination_path = null)
    {
        //Init vars
        $result = false;

        if (isset($current_path) && file_exists($current_path)) {

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
     * validateUploadedFile
     *
     * Validates both a files extension and then the mime type
     * Mime type is compared to the wordpress allowed mime types array
     *
     * Note that the method prefers to use finfo to check the mime type but falls
     * back to mime_content_type() and then no mime validation if neither function is available
     *
     * @param string $name
     * @param string $file_path - defaults to $_FILES[$this->inputName]['tmp_name']
     * @return array|bool
     */
    protected function validateUploadedFile($name = null, $file_path = null)
    {
        //Init vars
        $mime_type = null;

        if (!isset($file_path)) {
            $file_path = $_FILES[$this->inputName]['tmp_name'];
        }

        // Validate file extension
        $pathinfo = pathinfo($name);
        $ext = isset($pathinfo['extension']) ? $pathinfo['extension'] : '';

        if ($this->options['allowedExtensions'] and !in_array(strtolower($ext), array_map("strtolower", $this->options['allowedExtensions']))) {
            $these = implode(', ', $this->options['allowedExtensions']);

            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array(
                    'code'    => 100,
                    'message' => sprintf(__("File has an invalid extension, it should be one of %s.", "gfmu-locale"), $these)
                )
            );
        }

        //First check which php tools we have
        if (function_exists('finfo_open')) {

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file_path);
            finfo_close($finfo);

        }
        elseif (function_exists('mime_content_type')) {
            $mime_type = mime_content_type($file_path);
        }

        //Stop nasty mime types
        if (!empty($mime_type) and !in_array($mime_type, array_values($this->options['allowed_mimes']))) {

            return array(
                'result'   => 'error',
                'file_uid' => $this->uuid,
                'error'    => array(
                    'code'    => 100,
                    'message' => sprintf(__("File Type Error: %s.", "gfmu-locale"), $mime_type)
                )
            );

        }
        return true;
    }
}