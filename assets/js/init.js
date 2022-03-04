let GFMU_loaders = {};

function downloadFromAjaxPost_XHR(url, params, headers) {

    let xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.responseType = 'arraybuffer';

    xhr.onload = function () {
        if (this.status === 200) {
            let filename = "";
            let disposition = xhr.getResponseHeader('Content-Disposition');
            if (disposition && disposition.indexOf('attachment') !== -1) {
                let filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
                let matches = filenameRegex.exec(disposition);
                if (matches != null && matches[1]) filename = matches[1].replace(/['"]/g, '');
            }

            let blob = new Blob([this.response], {type: xhr.getResponseHeader('Content-Type')});
            if (typeof window.navigator.msSaveBlob !== 'undefined') {
                // IE workaround for "HTML7007: One or more blob URLs were revoked by closing the blob for which they were created. These URLs will no longer resolve as the data backing the URL has been freed."
                window.navigator.msSaveBlob(blob, filename);
            } else {
                let URL = window.URL || window.webkitURL;
                let downloadUrl = URL.createObjectURL(blob);

                if (filename) {
                    // use HTML5 a[download] attribute to specify filename
                    let a = document.createElement("a");
                    // safari doesn't support this yet
                    if (typeof a.download === 'undefined') {
                        window.location = downloadUrl;
                    } else {
                        a.href = downloadUrl;
                        a.download = filename;
                        document.body.appendChild(a);
                        a.click();
                    }
                } else {
                    window.location = downloadUrl;
                }

                setTimeout(function () {
                    URL.revokeObjectURL(downloadUrl);
                }, 100); // cleanup
            }
        }
    };
    xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
    jQuery.each(headers, function (key, value) {
        xhr.setRequestHeader(key, value);
    });

    xhr.send(jQuery.param(params));
}

(function ($) {

    $(document).ready(function ($) {

        if (typeof GFMU_options === 'undefined')
            return;

        // ajax support when using page ajax Gforms
        $(document).bind('gform_page_loaded', function () {
            init_pluploader();
        });


        function gform_plupload_field(id, name, value) {
            return '<input type="hidden" name="' + name + '_tname" value="' + value + '"/>';
        }

        //Loop the plupload field init vars from wordpress and init plupload for each one
        function init_pluploader() {
            $.each(GFMU_options, function (key, option) {

                //Init Plupload
                GFMU_loaders[key] = $("#" + option.element).plupload({

                    // General settings
                    runtimes: option.runtimes,

                    url: option.wp_ajax_url,

                    //Max file size
                    max_file_size: option.max_file_size,

                    chunk_size: option.chunk_size,

                    unique_names: option.rename_file_status,

                    prevent_duplicates: option.duplicates_status,

                    multiple_queues: true,

                    multipart_params: {
                        'action': 'gfmu-plupload-submit',
                        'currentFormID': option.params.form_id,
                        'currentFieldID': option.params.field_id,
                        'nonce': option.params.nonce,
                    },

                    // Specify what files to browse for
                    filters: {
                        //Max file size
                        max_file_size: option.max_file_size,
                        //Specifiy files to browse for
                        mime_types: [
                            {title: "files", extensions: option.filters.files}
                        ],
                        prevent_duplicates: true
                    },

                    resize: {
                        width: 1920,
                        height: 1080,
                        quality: 80,
                        crop: false,
                        preserve_headers: true
                    },

                    // Rename files by clicking on their titles
                    rename: false,

                    thumb_width: 100,
                    thumb_height: 60,
                    thumb_crop: true,

                    // Sort files
                    sortable: true,

                    // Enable ability to drag'n'drop files onto the widget (currently only HTML5 supports that)
                    dragdrop: option.drag_drop_status,

                    // Views to activate
                    views: {
                        list: option.list_view,
                        thumbs: option.thumb_view, // Show thumbs
                        active: option.ui_view
                    },

                    // Flash settings
                    flash_swf_url: option.flash_url,

                    // Silverlight settings
                    silverlight_xap_url: option.silverlight_url,

                    //Post init events
                    init: {
                        Error: function (up, response) {

                        },
                        PostInit: function (up) {

                            //Hide browser detection message
                            document.getElementById('filelist_' + key).innerHTML = '';

                            //Add any active files to plupload
                            if (typeof option.setupFiles !== 'undefined') {

                                let name, uploadedFiles = 0, file;

                                $.each(option.setupFiles, function (index, value) {

                                    if (typeof value.o_name !== 'undefined')
                                        name = value.o_name;

                                    let file_size = parseInt(value.size, 10);

                                    if(file_size <= 0)
                                        file_size = 178542;

                                    file = new plupload.File({'name': name});
                                    file.id = value.id;
                                    file.target_name = value.t_name;
                                    file.percent = 100;
                                    file.status = plupload.DONE;
                                    file.size = file_size;
                                    file.loaded = file_size;
                                    file.origSize = file_size;
                                    file.completeTimestamp = Date.now();
                                    file.lastModified = value.lastModified;
                                    file.type = value.mime_type || 'image/jpeg';
                                    file.url = value.url;
                                    file.wpid = value.wpid;

                                    uploadedFiles++;
                                    up.addFile(file);

                                    $('#gform_' + option.params.form_id).append(gform_plupload_field(file.id, file.id, file.target_name));
                                });
                            }
                            //Trigger uploader init complete
                            $(document).trigger('mthPluploadInit', option.params.field_id);

                        },
                        FileUploaded: function (up, file, response) {

                            //Called when a file finishes uploading
                            let obj = $.parseJSON(response.response);

                            //Detect error
                            if (obj.result === 'error') {

                                //Alert user of error
                                up.trigger('Error', {
                                    code: obj.error.code,
                                    message: obj.error.message,
                                    file: file
                                });

                            } else if (obj.result === 'success') {

                                $('#gform_' + option.params.form_id).append(gform_plupload_field(file.id, file.id, obj.success.file_id));

                                //Trigger uploader file uploaded
                                $(document).trigger('mthPluploadFileUploaded', up, file, response);

                            } else {

                                //General error
                                up.trigger('Error', {
                                    code: 300,
                                    message: option.i18n.server_error,
                                    file: file
                                });
                            }
                        },
                        FilesAdded: function (up, selectedFiles) {

                            let file_added_result = false;

                            //Remove files if max limit reached
                            plupload.each(selectedFiles, function (file) {

                                //File added result
                                file_added_result = false;

                                if (up.files.length > option.max_files) {
                                    $('#' + file.id).toggle("highlight", function () {
                                        this.remove();
                                    });
                                    up.removeFile(file);
                                    //Error
                                    up.trigger('Error', {
                                        message: option.i18n.file_limit_error
                                    });
                                    file_added_result = false;
                                } else {
                                    file_added_result = true;
                                }
                            });

                            //If file added then check if auto upload isset
                            if (file_added_result === true && option.auto_upload === true) {
                                up.start();
                            }

                        },
                        FilesRemoved: function (up, files) {

                            //Trigger uploader is empty
                            $(document).trigger('mthPluploadFileRemoved', up, files);

                            files.forEach(function (file) {

                                if (file.wpid) {

                                    $.ajax({
                                        type: "POST",
                                        url: option.wp_ajax_url,
                                        data: {
                                            action: 'gfmu_delete_file',
                                            nonce: option.params.nonce,
                                            file_id: file.id,
                                            tmp_name: file.target_name,
                                            file_wpid: file.wpid
                                        }
                                    }).done(function (response) {
                                        //Remove hidden gforms input for this file
                                        $("input[name=" + file.id + "_tname]").remove();
                                    });
                                }
                            });
                        },
                        UploadComplete: function (up, files) {

                        }
                    }
                });
            });

            $('.plupload_download_hook').on('click', function (e) {

                e.preventDefault();

                let key = $(this).data('id').split("_").pop();

                const data = {
                    nonce: GFMU_options[key].params.nonce,
                    action: 'gfmu_download_file',
                    post_id: (new URLSearchParams(window.location.search)).get('gform_post_id')
                };

                downloadFromAjaxPost_XHR(GFMU_options[key].wp_ajax_url, data, Array('Content-type', 'application/zip'));
            });
        }

        init_pluploader();
    });
})(jQuery);
