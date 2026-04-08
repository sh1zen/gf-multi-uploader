/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

let GFMU_loaders = {};

function downloadFromAjaxPost_XHR(url, params, headers) {

    function decodeResponse(buffer) {
        try {
            return new TextDecoder('utf-8').decode(new Uint8Array(buffer));
        } catch (error) {
            return '';
        }
    }

    let xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.responseType = 'arraybuffer';

    xhr.onload = function () {
        if (this.status === 200) {
            let filename = "";
            let disposition = xhr.getResponseHeader('Content-Disposition');

            if (!disposition || disposition.indexOf('attachment') === -1) {
                let responseText = decodeResponse(this.response).trim();

                if (responseText && responseText !== 'false') {
                    console.warn('GFMU download request did not return an attachment.', responseText);
                }

                return;
            }

            if (disposition && disposition.indexOf('attachment') !== -1) {
                let filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
                let matches = filenameRegex.exec(disposition);
                if (matches != null && matches[1]) filename = matches[1].replace(/['"]/g, '');
            }

            let blob = new Blob([this.response], {type: xhr.getResponseHeader('Content-Type')});
            if (typeof window.navigator.msSaveBlob !== 'undefined') {
                window.navigator.msSaveBlob(blob, filename);
            } else {
                let URL = window.URL || window.webkitURL;
                let downloadUrl = URL.createObjectURL(blob);

                if (filename) {
                    let a = document.createElement("a");
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
                }, 100);
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

        $(document).bind('gform_page_loaded', function () {
            init_pluploader();
        });

        function gform_plupload_field(id, name, value, fieldId) {
            return '<input type="hidden" name="' + name + '_tname" value="' + value + '" data-gfmu-field="' + fieldId + '" data-gfmu-role="tname" data-gfmu-upload-id="' + id + '"/>';
        }

        function escapeSelector(value) {
            return value.replace(/([ #;?%&,.+*~\':"!^$[\]()=>|/@])/g, '\\$1');
        }

        function getFileRow(file) {
            return $('#' + escapeSelector(file.id));
        }

        function isExistingMedia(file) {
            return parseInt(file.wpid, 10) > 0;
        }

        function removeHiddenField(file) {
            let fieldName = file.id + '_tname';

            $('input').filter(function () {
                return this.name === fieldName;
            }).remove();
        }

        function getAjaxResponse(response) {
            if (typeof response !== 'string') {
                return response;
            }

            try {
                return $.parseJSON(response);
            } catch (error) {
                return response;
            }
        }

        function getCurrentPostId(option) {
            let postId = option.params.post_id || 0;

            if (!postId) {
                postId = (new URLSearchParams(window.location.search)).get('gform_post_id') || 0;
            }

            return postId;
        }

        function resolveUploader(up, option) {
            if (up && Array.isArray(up.files)) {
                return up;
            }

            if (up && typeof up.plupload === 'function') {
                try {
                    up = up.plupload('getUploader');
                } catch (error) {
                    up = null;
                }
            }

            if (up && Array.isArray(up.files)) {
                return up;
            }

            if (!option || !option.element) {
                return null;
            }

            let element = $('#' + escapeSelector(option.element));

            if (!element.length || typeof element.plupload !== 'function') {
                return null;
            }

            try {
                up = element.plupload('getUploader');
            } catch (error) {
                return null;
            }

            return up && Array.isArray(up.files) ? up : null;
        }

        function hasDownloadableFiles(up, option) {
            if (!option || option.can_download_existing_media !== true || !option.params.media_nonce) {
                return false;
            }

            up = resolveUploader(up, option);

            if (!up) {
                return false;
            }

            if (!parseInt(getCurrentPostId(option), 10)) {
                return false;
            }

            return up.files.some(function (file) {
                return isExistingMedia(file);
            });
        }

        function getStorageKey(option) {
            return [
                'gfmu',
                'queue',
                option.params.form_id,
                option.params.field_id,
                window.location.pathname,
                window.location.search
            ].join(':');
        }

        function shouldRestorePersistedFiles() {
            let navigationEntries = window.performance && window.performance.getEntriesByType
                ? window.performance.getEntriesByType('navigation')
                : [];

            if (navigationEntries.length && navigationEntries[0].type) {
                return navigationEntries[0].type === 'reload' || navigationEntries[0].type === 'back_forward';
            }

            return false;
        }

        function getTempFileUrl(option, targetName) {
            if (!option.temp_uploads_url || !targetName) {
                return '';
            }

            return option.temp_uploads_url + encodeURIComponent(targetName);
        }

        function loadPersistedFiles(option) {
            if (!window.sessionStorage) {
                return [];
            }

            try {
                let data = window.sessionStorage.getItem(getStorageKey(option));
                return data ? JSON.parse(data) : [];
            } catch (error) {
                return [];
            }
        }

        function clearPersistedFiles(option) {
            if (!window.sessionStorage) {
                return;
            }

            try {
                window.sessionStorage.removeItem(getStorageKey(option));
            } catch (error) {
            }
        }

        function savePersistedFiles(option, files) {
            if (!window.sessionStorage) {
                return;
            }

            try {
                if (!files.length) {
                    clearPersistedFiles(option);
                    return;
                }

                window.sessionStorage.setItem(getStorageKey(option), JSON.stringify(files));
            } catch (error) {
            }
        }

        function getStoredFileSnapshot(file, option) {
            if (!file || !file.target_name || isExistingMedia(file)) {
                return null;
            }

            return {
                id: file.id,
                o_name: file.name,
                t_name: file.target_name,
                size: file.size || file.origSize || 0,
                url: file.url || getTempFileUrl(option, file.target_name),
                preview_url: file.preview_url || file.url || getTempFileUrl(option, file.target_name),
                lastModified: file.lastModified || new Date(),
                mime_type: file.type || '',
                wpid: 0
            };
        }

        function persistUploaderFiles(up, option) {
            let files = [];

            up.files.forEach(function (file) {
                let snapshot = getStoredFileSnapshot(file, option);

                if (snapshot) {
                    files.push(snapshot);
                }
            });

            savePersistedFiles(option, files);
        }

        function syncAutoUploadUi(up, option) {
            if (option.auto_upload !== true) {
                return;
            }

            let headerText = $(up.getOption('container')).find('.plupload_header_text');

            if (!headerText.length) {
                return;
            }

            headerText.text(
                $.trim(
                    headerText.text().replace(
                        /\s*(and click the start button\.?|e clicca il pulsante di avvio\.?)\s*$/i,
                        ''
                    )
                )
            );
        }

        function applyGlobalTheme(option) {
            if (!option || !option.style) {
                return;
            }

            let container = $('#' + escapeSelector(option.element + '_container'));

            if (!container.length) {
                return;
            }

            let style = option.style;
            let borderRadius = parseInt(style.border_radius, 10);
            let panelMinHeight = parseInt(style.panel_min_height, 10);

            container.css({
                '--gfmu-primary-color': style.primary_color || '',
                '--gfmu-primary-text-color': style.primary_text_color || '',
                '--gfmu-surface-color': style.surface_color || '',
                '--gfmu-border-color': style.border_color || '',
                '--gfmu-border-radius': (isNaN(borderRadius) ? 16 : borderRadius) + 'px',
                '--gfmu-panel-min-height': (isNaN(panelMinHeight) ? 420 : panelMinHeight) + 'px'
            });

            if (style.header_title) {
                container.find('.plupload_header_title').text(style.header_title);
            }

            if (style.header_text) {
                container.find('.plupload_header_text').text(style.header_text);
            }
        }

        function buildSetupFiles(option) {
            let setupFiles = $.extend({}, option.setupFiles || {});
            let persistedFiles = shouldRestorePersistedFiles() ? loadPersistedFiles(option) : [];

            persistedFiles.forEach(function (file, index) {
                let dedupeKey = file.t_name || file.id;
                let exists = false;

                $.each(setupFiles, function (_, current) {
                    let currentKey = current.t_name || current.id;
                    if (currentKey === dedupeKey) {
                        exists = true;
                        return false;
                    }
                });

                if (!exists) {
                    setupFiles['session_' + index] = file;
                }
            });

            return setupFiles;
        }

        function syncHiddenFields(up, option) {
            let form = $('#gform_' + option.params.form_id);
            let fieldName = 'input_' + option.params.field_id;

            if (!form.length) {
                return;
            }

            form.find('input[data-gfmu-field="' + option.params.field_id + '"][data-gfmu-role="tname"]').remove();

            $('.plupload_file_fields', $('#' + escapeSelector(option.element + '_container'))).html('');

            up.files.forEach(function (file) {
                let row = getFileRow(file);

                if (!row.length) {
                    return;
                }

                if (!file.target_name && !isExistingMedia(file)) {
                    row.find('.plupload_file_fields').html('');
                    return;
                }

                row.find('.plupload_file_fields').html(
                    '<input type="hidden" name="' + fieldName + '[]" value="' + file.id + '" />' +
                    '<input type="hidden" name="' + file.id + '_name" value="' + $('<div>').text(file.name).html() + '" />'
                );

                if (file.target_name) {
                    form.append(gform_plupload_field(file.id, file.id, file.target_name, option.params.field_id));
                }
            });
        }

        function applyDownloadPermissions(up, option) {
            let container = $('#' + escapeSelector(option.element + '_container'));
            if (!container.length) {
                return;
            }

            let enabled = hasDownloadableFiles(up, option);

            container.find('.plupload_download')
                .toggleClass('is-disabled', !enabled)
                .attr('aria-disabled', enabled ? 'false' : 'true')
                .attr('tabindex', enabled ? '0' : '-1');
        }

        function applyExistingMediaPermissions(file, option) {
            if (!isExistingMedia(file) || option.can_manage_existing_media === true) {
                return;
            }

            let row = getFileRow(file);

            if (!row.length) {
                return;
            }

            row.addClass('gfmu-existing-media-readonly');
            row.find('.plupload_action_icon')
                .removeClass('plupload_action_icon')
                .addClass('gfmu-disabled-action')
                .attr('title', option.i18n.existing_media_readonly);
        }

        function handleFileRemoval(option, file) {
            let requestData = null;
            let removeFieldOnSuccess = true;

            if (isExistingMedia(file)) {
                if (option.can_manage_existing_media !== true || !option.params.media_nonce) {
                    return;
                }

                requestData = {
                    action: 'gfmu_delete_file',
                    nonce: option.params.media_nonce,
                    file_id: file.id,
                    file_wpid: file.wpid,
                    tmp_name: file.target_name || '',
                    post_id: getCurrentPostId(option)
                };
                removeFieldOnSuccess = false;
            } else if (file.target_name) {
                requestData = {
                    action: 'gfmu_delete_temp_file',
                    nonce: option.params.upload_nonce,
                    file_id: file.id,
                    tmp_name: file.target_name
                };
            } else {
                removeHiddenField(file);
                return;
            }

            $.ajax({
                type: "POST",
                url: option.wp_ajax_url,
                data: requestData
            }).done(function (response) {
                let responseData = getAjaxResponse(response);

                if (responseData && responseData.result === 'error') {
                    return;
                }

                removeHiddenField(file);
            }).fail(function () {
                if (removeFieldOnSuccess) {
                    removeHiddenField(file);
                }
            });
        }

        function init_pluploader() {
            $.each(GFMU_options, function (key, option) {

                GFMU_loaders[key] = $("#" + option.element).plupload({
                    runtimes: option.runtimes,
                    url: option.wp_ajax_url,
                    max_file_size: option.max_file_size,
                    chunk_size: option.chunk_size,
                    unique_names: option.rename_file_status,
                    prevent_duplicates: option.duplicates_status,
                    multiple_queues: true,
                    multipart_params: {
                        'action': 'gfmu-plupload-submit',
                        'currentFormID': option.params.form_id,
                        'currentFieldID': option.params.field_id,
                        'nonce': option.params.upload_nonce,
                    },
                    filters: {
                        max_file_size: option.max_file_size,
                        mime_types: [
                            {title: "files", extensions: option.filters.files}
                        ],
                        prevent_duplicates: true
                    },
                    resize: {
                        width: 3840,
                        height: 2160,
                        quality: 80,
                        crop: false,
                        preserve_headers: true
                    },
                    rename: false,
                    thumb_width: 136,
                    thumb_height: 96,
                    thumb_crop: true,
                    sortable: true,
                    dragdrop: option.drag_drop_status,
                    buttons: {
                        browse: true,
                        start: option.auto_upload !== true,
                        stop: true
                    },
                    views: {
                        list: option.list_view,
                        thumbs: option.thumb_view,
                        active: option.ui_view
                    },
                    flash_swf_url: option.flash_url,
                    silverlight_xap_url: option.silverlight_url,
                    init: {
                        Error: function (up, response) {

                        },
                        PostInit: function (up) {

                            document.getElementById('filelist_' + key).innerHTML = '';
                            syncAutoUploadUi(up, option);
                            applyGlobalTheme(option);

                            option.setupFiles = buildSetupFiles(option);

                            if (typeof option.setupFiles !== 'undefined') {

                                let name, file;

                                $.each(option.setupFiles, function (index, value) {

                                    if (typeof value.o_name !== 'undefined')
                                        name = value.o_name;

                                    let file_size = parseInt(value.size, 10);

                                    if (file_size <= 0)
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
                                    file.lastModified = value.lastModified ? new Date(value.lastModified) : null;
                                    file.type = value.mime_type || 'image/jpeg';
                                    file.url = value.url || getTempFileUrl(option, value.t_name);
                                    file.preview_url = value.preview_url || value.url || getTempFileUrl(option, value.t_name);
                                    file.wpid = value.wpid;

                                    up.addFile(file);
                                });
                            }

                            window.setTimeout(function () {
                                syncHiddenFields(up, option);
                                persistUploaderFiles(up, option);
                                up.files.forEach(function (existingFile) {
                                    applyExistingMediaPermissions(existingFile, option);
                                });
                                applyDownloadPermissions(up, option);
                            }, 0);

                            $(document).trigger('mthPluploadInit', option.params.field_id);
                        },
                        FileUploaded: function (up, file, response) {

                            let obj = $.parseJSON(response.response);

                            if (obj.result === 'error') {
                                up.trigger('Error', {
                                    code: (obj.error && obj.error.code) || 0,
                                    message: (obj.error && obj.error.message) || option.i18n.server_error,
                                    file: file
                                });
                            } else if (obj.result === 'success') {
                                file.target_name = obj.success.file_id;
                                file.wpid = 0;
                                file.url = getTempFileUrl(option, obj.success.file_id);
                                file.preview_url = file.url;

                                syncHiddenFields(up, option);
                                persistUploaderFiles(up, option);
                                applyDownloadPermissions(up, option);

                                $(document).trigger('mthPluploadFileUploaded', up, file, response);
                            } else {
                                up.trigger('Error', {
                                    code: 300,
                                    message: option.i18n.server_error,
                                    file: file
                                });
                            }
                        },
                        FilesAdded: function (up, selectedFiles) {

                            let file_added_result = false;

                            plupload.each(selectedFiles, function (file) {
                                file_added_result = false;

                                if (up.files.length > option.max_files) {
                                    $('#' + file.id).toggle("highlight", function () {
                                        this.remove();
                                    });
                                    up.removeFile(file);
                                    up.trigger('Error', {
                                        message: option.i18n.file_limit_error
                                    });
                                    file_added_result = false;
                                } else {
                                    file_added_result = true;
                                }
                            });

                            if (file_added_result === true && option.auto_upload === true) {
                                up.start();
                            }
                        },
                        FilesRemoved: function (up, files) {

                            $(document).trigger('mthPluploadFileRemoved', up, files);

                            files.forEach(function (file) {
                                handleFileRemoval(option, file);
                            });

                            syncHiddenFields(up, option);
                            persistUploaderFiles(up, option);
                            applyDownloadPermissions(up, option);
                        },
                        UploadComplete: function (up, files) {

                        }
                    }
                });
            });

            $('.plupload_download_hook').off('click.gfmu').on('click.gfmu', function (e) {

                e.preventDefault();

                let key = $(this).data('id').split("_").pop();
                let option = GFMU_options[key];

                if (!option || option.can_download_existing_media !== true || !option.params.media_nonce) {
                    return;
                }

                if (!hasDownloadableFiles(GFMU_loaders[key], option)) {
                    return;
                }

                const data = {
                    nonce: option.params.media_nonce,
                    action: 'gfmu_download_file',
                    post_id: getCurrentPostId(option)
                };

                if (option.save_to_meta) {
                    data.get_by_meta = option.save_to_meta;
                }

                downloadFromAjaxPost_XHR(option.wp_ajax_url, data, Array('Content-type', 'application/zip'));
            });
        }

        init_pluploader();

        $(document).bind('gform_confirmation_loaded', function (event, formId) {
            $.each(GFMU_options, function (_, option) {
                if (String(option.params.form_id) === String(formId)) {
                    clearPersistedFiles(option);
                }
            });
        });
    });
})(jQuery);
