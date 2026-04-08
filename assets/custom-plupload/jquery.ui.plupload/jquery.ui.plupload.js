/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */


;(function (window, document, plupload, o, $) {

    let uploaders = {};

    function polyfill(plupload, o) {
        plupload.sprintf = o.core.utils.Basic.sprintf;
        plupload.ua = o.core.utils.Env;
    }

    polyfill(plupload, o);

    function _(str) {
        return plupload.translate(str) || str;
    }

    function renderUI(obj) {
        obj.id = obj.attr('id');

        obj.html(
            '<div class="plupload_wrapper">' +
            '<div class="ui-widget-content plupload_container">' +
            '<div class="ui-state-default ui-widget-header plupload_header">' +
            '<div class="plupload_header_content">' +
            '<div class="plupload_header_main">' +
            '<div class="plupload_header_title">' + _("Select files") + '</div>' +
            '<div class="plupload_header_text">' + _("Add files to the upload queue and click the start button.") + '</div>' +
            '</div>' +
            '<div class="plupload_view_switch">' +
            '<button type="button" class="plupload_view_toggle" data-view="thumbs" title="' + _('Thumbnails') + '" aria-label="' + _('Thumbnails') + '">' +
            '<span class="plupload_view_toggle_icon ui-icon ui-icon-image" aria-hidden="true"></span>' +
            '<span class="plupload_view_toggle_label">' + _('Thumbnails') + '</span>' +
            '</button>' +
            '</div>' +
            '</div>' +
            '</div>' +

            '<div class="plupload_content">' +
            '<div class="plupload_droptext">' + _("Drag files here.") + '</div>' +
            '<ul class="plupload_filelist_content plupload_scroll"> </ul>' +
            '</div>' +

            '<div class="plupload_footer ui-widget-header">' +
            '<div class="plupload_footer_primary">' +
            '<div class="plupload_buttons">' +
            '<a class="plupload_button plupload_add">' + _("Add Files") + '</a>' +
            '<a class="plupload_button plupload_start">' + _("Start Upload") + '</a>' +
            '<a class="plupload_button plupload_stop plupload_hidden">' + _("Stop Upload") + '</a>' +
            '</div>' +
            '<div class="plupload_started plupload_hidden">' +
            '<div class="plupload_progress" aria-hidden="true">' +
            '<div class="plupload_progress_container"></div>' +
            '</div>' +
            '<div class="plupload_upload_status" aria-live="polite"></div>' +
            '</div>' +
            '</div>' +
            '<div class="plupload_footer_secondary">' +
            '<div class="plupload_footer_metric plupload_file_status"><span class="plupload_total_status">0%</span></div>' +
            '<div class="plupload_footer_metric plupload_file_size"><span class="plupload_total_file_size">0 kb</span></div>' +
            '<div class="plupload_download"><a class="plupload_button plupload_download plupload_download_hook" data-id="' + obj.id + '">' + _("Download") + '</a></div>' +
            '</div>' +
            '</div>' +
            '<div class="plupload_preview_overlay" hidden>' +
            '<div class="plupload_preview_dialog" role="dialog" aria-modal="true">' +
            '<button type="button" class="plupload_preview_close" aria-label="' + _('Close') + '">&times;</button>' +
            '<img class="plupload_preview_image" alt="">' +
            '</div>' +
            '</div>' +

            '</div>' +
            '<input class="plupload_count" value="0" type="hidden">' +
            '</div>'
        );
    }

    $.widget("ui.plupload", {

        widgetEventPrefix: '',

        contents_bak: '',

        options: {
            browse_button_hover: 'ui-state-hover',
            browse_button_active: 'ui-state-active',

            filters: {},

            // widget specific
            buttons: {
                browse: true,
                start: true,
                stop: true
            },

            views: {
                list: true,
                thumbs: true,
                active: 'thumbs',
                remember: true // requires: https://github.com/carhartl/jquery-cookie, otherwise disabled even if set to true
            },

            thumb_width: 136,
            thumb_height: 96,
            thumb_crop: true,

            multiple_queues: true, // re-use widget by default
            dragdrop: true,
            autostart: false,
            sortable: false,
            rename: false
        },

        FILE_COUNT_ERROR: -9001,

        _create: function () {

            let id = this.element.attr('id');
            if (!id) {
                id = plupload.guid();
                this.element.attr('id', id);
            }
            this.id = id;

            // backup the elements initial state
            this.contents_bak = this.element.html();
            renderUI(this.element);

            // container, just in case
            this.container = $('.plupload_container', this.element).attr('id', id + '_container');

            this.content = $('.plupload_content', this.element);

            if ($.fn.resizable) {
                this.container.resizable({
                    handles: 's',
                    minHeight: 300
                });
            }

            // list of files, may become sortable
            this.filelist = $('.plupload_filelist_content', this.container)
                .attr({
                    id: id + '_filelist',
                    unselectable: 'on'
                });


            // buttons
            this.browse_button = $('.plupload_add', this.container).attr('id', id + '_browse');
            this.start_button = $('.plupload_start', this.container).attr('id', id + '_start');
            this.stop_button = $('.plupload_stop', this.container).attr('id', id + '_stop');

            if ($.ui.button) {
                this.browse_button.button({
                    icon: 'ui-icon-circle-plus',
                    disabled: true
                });

                this.start_button.button({
                    icon: 'ui-icon-circle-arrow-e',
                    disabled: true
                });

                this.stop_button.button({
                    icon: 'ui-icon-circle-close'
                });
            }

            // progressbar
            this.progressbar = $('.plupload_progress_container', this.container);

            if ($.ui.progressbar) {
                this.progressbar.progressbar();
            }

            this._setVisualState('idle');

            // counter
            this.counter = $('.plupload_count', this.element)
                .attr({
                    id: id + '_count',
                    name: id + '_count'
                });

            // initialize uploader instance
            this._initUploader();
        },

        _initUploader: function () {
            let self = this
                , id = this.id
                , uploader
                , options = {
                    container: id + '_buttons',
                    browse_button: id + '_browse'
                }
            ;

            $('.plupload_buttons', this.element).attr('id', id + '_buttons');

            if (self.options.dragdrop) {
                this.filelist.parent().attr('id', this.id + '_dropbox');
                options.drop_element = this.id + '_dropbox';
            }

            this.filelist.on('click', function (e) {
                if ($(e.target).hasClass('plupload_action_icon')) {
                    self.removeFile($(e.target).closest('.plupload_file').attr('id'));
                    e.preventDefault();
                }
            });

            this._initPreview();

            uploader = this.uploader = uploaders[id] = new plupload.Uploader($.extend(this.options, options));

            if (self.options.views.thumbs) {
                uploader.settings.required_features.display_media = true;
            }

            // for backward compatibility
            if (self.options.max_file_count) {
                plupload.extend(uploader.getOption('filters'), {
                    max_file_count: self.options.max_file_count
                });
            }

            plupload.addFileFilter('max_file_count', function (maxCount, file, cb) {
                if (maxCount <= this.files.length - (this.total.uploaded + this.total.failed)) {
                    self.browse_button.button('disable');
                    this.disableBrowse();

                    this.trigger('Error', {
                        code: self.FILE_COUNT_ERROR,
                        message: _("File count error."),
                        file: file
                    });
                    cb(false);
                } else {
                    cb(true);
                }
            });


            uploader.bind('Error', function (up, err) {
                let message = (err.message || '').replace(/\s*:\s*\.$/, '.').trim();
                let details = "";

                switch (err.code) {
                    case plupload.FILE_EXTENSION_ERROR:
                        if (!message || /^File Type Error/i.test(message)) {
                            message = _("File extension error.");
                        }
                        details = err.file && err.file.name ? plupload.sprintf(_("File: %s"), err.file.name) : _("The selected file type is not allowed.");
                        break;

                    case plupload.FILE_SIZE_ERROR:
                        if (!message) {
                            message = _("File size error.");
                        }
                        details = plupload.sprintf(_("File: %s, size: %d, max file size: %d"), err.file.name, plupload.formatSize(err.file.size), plupload.formatSize(plupload.parseSize(up.getOption('filters').max_file_size)));
                        break;

                    case plupload.FILE_DUPLICATE_ERROR:
                        if (!message) {
                            message = _("Duplicate file error.");
                        }
                        details = plupload.sprintf(_("%s already present in the queue."), err.file.name);
                        break;

                    case self.FILE_COUNT_ERROR:
                        if (!message) {
                            message = _("File count error.");
                        }
                        details = plupload.sprintf(_("Upload element accepts only %d file(s) at a time. Extra files were stripped."), up.getOption('filters').max_file_count || 0);
                        break;

                    case plupload.IMAGE_FORMAT_ERROR :
                        if (!message) {
                            message = _("Image format error.");
                        }
                        details = _("Image format either wrong or not supported.");
                        break;

                    case plupload.IMAGE_MEMORY_ERROR :
                        if (!message) {
                            message = _("Image memory error.");
                        }
                        details = _("Runtime ran out of available memory.");
                        break;

                    /* // This needs a review
                    case plupload.IMAGE_DIMENSIONS_ERROR :
                        details = plupload.sprintf(_('Resoultion out of boundaries! <b>%s</b> runtime supports images only up to %wx%hpx.'), up.runtime, up.features.maxWidth, up.features.maxHeight);
                        break;	*/

                    case plupload.HTTP_ERROR:
                        if (!message) {
                            message = _("HTTP Error.");
                        }
                        details = _("Upload URL might be wrong or doesn't exist.");
                        break;
                }

                if (!message) {
                    message = _("Upload error.");
                }

                message = '<strong>' + message + '</strong>';

                if (details) {
                    message += " <br /><i>" + details + "</i>";
                }

                self._trigger('error', null, {up: up, error: err});

                // do not show UI if no runtime can be initialized
                if (err.code === plupload.INIT_ERROR) {
                    setTimeout(function () {
                        self.destroy();
                    }, 1);
                } else {
                    self.notify('error', message);
                }
            });

            uploader.bind('PostInit', function (up) {
                // all buttons are optional, so they can be disabled and hidden
                if (!self.options.buttons.browse) {
                    self.browse_button.button('disable').hide();
                    up.disableBrowse(true);
                } else {
                    self.browse_button.button('enable');
                }

                if (!self.options.buttons.start) {
                    self.start_button.button('disable').hide();
                }

                if (!self.options.buttons.stop) {
                    self.stop_button.button('disable').hide();
                }

                if (!self.options.unique_names && self.options.rename) {
                    self._enableRenaming();
                }

                if (self.options.dragdrop && up.features.dragdrop) {
                    self.filelist.parent().addClass('plupload_dropbox');
                }

                self._enableViewSwitcher();

                self.start_button.click(function (e) {
                    if (!$(this).button('option', 'disabled')) {
                        self.start();
                    }
                    e.preventDefault();
                });

                self.stop_button.click(function (e) {
                    self.stop();
                    e.preventDefault();
                });

                self._trigger('ready', null, {up: up});
            });

            // uploader internal events must run first
            uploader.init();

            uploader.bind('FileFiltered', function (up, file) {
                self._addFiles(file);
            });

            uploader.bind('FilesAdded', function (up, files) {
                self._trigger('selected', null, {up: up, files: files});

                // re-enable sortable
                if (self.options.sortable && $.ui.sortable) {
                    self._enableSortingList();
                }

                self._trigger('updatelist', null, {filelist: self.filelist});

                if (self.options.autostart) {
                    // set a little delay to make sure that QueueChanged triggered by the core has time to complete
                    setTimeout(function () {
                        self.start();
                    }, 10);
                }
            });

            uploader.bind('FilesRemoved', function (up, files) {
                // destroy sortable if enabled
                if ($.ui.sortable && self.options.sortable) {
                    $('tbody', self.filelist).sortable('destroy');
                }

                $.each(files, function (i, file) {
                    $('#' + file.id).toggle("highlight", function () {
                        $(this).remove();
                    });
                });

                if (up.files.length) {
                    // re-initialize sortable
                    if (self.options.sortable && $.ui.sortable) {
                        self._enableSortingList();
                    }
                }

                self._trigger('updatelist', null, {filelist: self.filelist});
                self._trigger('removed', null, {up: up, files: files});
            });

            uploader.bind('QueueChanged StateChanged', function () {
                self._handleState();
            });

            uploader.bind('UploadFile', function (up, file) {
                self._handleFileStatus(file);
            });

            uploader.bind('FileUploaded', function (up, file) {
                self._handleFileStatus(file);
                self._trigger('uploaded', null, {up: up, file: file});
            });

            uploader.bind('UploadProgress', function (up, file) {
                self._handleFileStatus(file);
                self._updateTotalProgress();
                self._trigger('progress', null, {up: up, file: file});
            });

            uploader.bind('UploadComplete', function (up, files) {
                self._addFormFields();
                self._trigger('complete', null, {up: up, files: files});
            });
        },

        _setOption: function (key, value) {
            let self = this;

            if (key === 'buttons' && typeof (value) == 'object') {
                value = $.extend(self.options.buttons, value);

                if (!value.browse) {
                    self.browse_button.button('disable').hide();
                    self.uploader.disableBrowse(true);
                } else {
                    self.browse_button.button('enable').show();
                    self.uploader.disableBrowse(false);
                }

                if (!value.start) {
                    self.start_button.button('disable').hide();
                } else {
                    self.start_button.button('enable').show();
                }

                if (!value.stop) {
                    self.stop_button.button('disable').hide();
                } else {
                    self.start_button.button('enable').show();
                }
            }

            self.uploader.settings[key] = value;
        },


        /**
         Start upload. Triggers `start` event.

         @method start
         */
        start: function () {
            this.uploader.start();
            this._trigger('start', null, {up: this.uploader});
        },


        /**
         Stop upload. Triggers `stop` event.

         @method stop
         */
        stop: function () {
            this.uploader.stop();
            this._trigger('stop', null, {up: this.uploader});
        },


        /**
         Enable browse button.

         @method enable
         */
        enable: function () {
            this.browse_button.button('enable');
            this.uploader.disableBrowse(false);
        },


        /**
         Disable browse button.

         @method disable
         */
        disable: function () {
            this.browse_button.button('disable');
            this.uploader.disableBrowse(true);
        },


        /**
         Retrieve file by it's unique id.

         @method getFile
         @param {String} id Unique id of the file
         @return {plupload.File}
         */
        getFile: function (id) {
            let file;

            if (typeof id === 'number') {
                file = this.uploader.files[id];
            } else {
                file = this.uploader.getFile(id);
            }
            return file;
        },

        /**
         Return array of files currently in the queue.

         @method getFiles
         @return {Array} Array of files in the queue represented by plupload.File objects
         */
        getFiles: function () {
            return this.uploader.files;
        },


        /**
         Remove the file from the queue.

         @method removeFile
         @param {plupload.File|String} file File to remove, might be specified directly or by it's unique id
         */
        removeFile: function (file) {
            if (plupload.typeOf(file) === 'string') {
                file = this.getFile(file);
            }
            this.uploader.removeFile(file);
        },


        /**
         Clear the file queue.

         @method clearQueue
         */
        clearQueue: function () {
            this.uploader.splice();
        },


        /**
         Retrieve internal plupload.Uploader object (usually not required).

         @method getUploader
         @return {plupload.Uploader}
         */
        getUploader: function () {
            return this.uploader;
        },

        _setVisualState: function (state) {
            let states = 'plupload-state-idle plupload-state-uploading plupload-state-complete';

            this.container
                .removeClass(states)
                .addClass('plupload-state-' + state);
        },

        _initPreview: function () {
            let self = this
                , overlay = $('.plupload_preview_overlay', this.element)
            ;

            if (!overlay.length) {
                return;
            }

            this.previewOverlay = overlay;
            this.previewImage = $('.plupload_preview_image', overlay);
            this.previewClose = $('.plupload_preview_close', overlay);

            this.filelist
                .off('click.pluploadPreview')
                .on('click.pluploadPreview', '.plupload_file_thumb', function (e) {
                    let fileId = $(this).closest('.plupload_file').attr('id');

                    if (!fileId) {
                        return;
                    }

                    if (self.openPreview(fileId, this)) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                });

            overlay
                .off('click.pluploadPreview')
                .on('click.pluploadPreview', function (e) {
                    if ($(e.target).is('.plupload_preview_overlay, .plupload_preview_close')) {
                        self.closePreview();
                    }
                });

            this.document
                .off('keydown.pluploadPreview-' + this.id)
                .on('keydown.pluploadPreview-' + this.id, function (e) {
                    if (e.key === 'Escape' && self.previewOverlay && self.previewOverlay.hasClass('is-open')) {
                        self.closePreview();
                    }
                });
        },

        resolvePreviewSource: function (file, thumb) {
            let $thumb = thumb ? $(thumb) : $('#' + file.id + ' .plupload_file_thumb', this.filelist)
                , image = $thumb.find('img').first()
                , canvas = $thumb.find('canvas').first().get(0)
            ;

            if (file.preview_url) {
                return file.preview_url;
            }

            if (file.url) {
                return file.url;
            }

            if (image.length && image.attr('src')) {
                return image.attr('src');
            }

            if (canvas && canvas.toDataURL) {
                try {
                    return canvas.toDataURL();
                } catch (ignore) {
                }
            }

            return '';
        },

        isPreviewableImage: function (file, thumb) {
            let fileType = ((file && file.type) || '').toLowerCase()
                , src = this.resolvePreviewSource(file, thumb)
            ;

            if (fileType.indexOf('image/') === 0) {
                return true;
            }

            return /(\.)(avif|bmp|gif|ico|jpe?g|png|svg|webp)([?#].*)?$/i.test(src) || /^data:image\//i.test(src);
        },

        openPreview: function (file, thumb) {
            let currentFile = this.getFile(file)
                , src
            ;

            if (!currentFile || !this.previewOverlay || !this.previewImage) {
                return false;
            }

            if (!this.isPreviewableImage(currentFile, thumb)) {
                return false;
            }

            src = this.resolvePreviewSource(currentFile, thumb);

            if (!src) {
                return false;
            }

            this.previewImage.attr({
                src: src,
                alt: currentFile.name || ''
            });

            this.previewOverlay
                .addClass('is-open')
                .attr('aria-hidden', 'false')
                .removeAttr('hidden');

            $('body').addClass('plupload_preview_open');

            if (this.previewClose && this.previewClose.length) {
                this.previewClose.trigger('focus');
            }

            return true;
        },

        closePreview: function () {
            if (!this.previewOverlay || !this.previewImage) {
                return;
            }

            this.previewOverlay
                .removeClass('is-open')
                .attr('aria-hidden', 'true')
                .attr('hidden', 'hidden');

            this.previewImage.attr({
                src: '',
                alt: ''
            });

            $('body').removeClass('plupload_preview_open');
        },


        /**
         Trigger refresh procedure, specifically browse_button re-measure and re-position operations.
         Might get handy, when UI Widget is placed within the popup, that is constantly hidden and shown
         again - without calling this method after each show operation, dialog trigger might get displaced
         and disfunctional.

         @method refresh
         */
        refresh: function () {
            this.uploader.refresh();
        },


        /**
         Display a message in notification area.

         @method notify
         @param {Enum} type Type of the message, either `error` or `info`
         @param {String} message The text message to display.
         */
        notify: function (type, message) {
            let popup = $(
                '<div class="plupload_message">' +
                '<span class="plupload_message_close ui-icon ui-icon-circle-close" title="' + _('Close') + '"></span>' +
                '<p><span class="ui-icon"></span>' + message + '</p>' +
                '</div>'
            )
                , toastContainer = $('#plupload_toasts')
                , closePopup
                , dismissDelay = type === 'error' ? 7000 : 4500
            ;

            if (!toastContainer.length) {
                toastContainer = $('<div id="plupload_toasts" class="plupload_toasts" aria-live="polite" aria-atomic="false"></div>').appendTo('body');
            }

            popup.addClass('ui-state-' + (type === 'error' ? 'error' : 'highlight'))
                .attr('role', type === 'error' ? 'alert' : 'status')
                .find('p .ui-icon')
                .addClass('ui-icon-' + (type === 'error' ? 'alert' : 'info'))
                .end()
                .find('.plupload_message_close')
                .click(function () {
                    closePopup();
                })
                .end();

            closePopup = function () {
                let timerId = popup.data('dismissTimer');

                if (timerId) {
                    clearTimeout(timerId);
                }

                popup.stop(true, true).fadeOut(180, function () {
                    $(this).remove();

                    if (!toastContainer.children().length) {
                        toastContainer.remove();
                    }
                });
            };

            popup.on('mouseenter', function () {
                let timerId = popup.data('dismissTimer');

                if (timerId) {
                    clearTimeout(timerId);
                }
            }).on('mouseleave', function () {
                popup.data('dismissTimer', setTimeout(closePopup, dismissDelay));
            });

            toastContainer.append(popup);
            popup.data('dismissTimer', setTimeout(closePopup, dismissDelay));
        },


        /**
         Destroy the widget, the uploader, free associated resources and bring back original html.

         @method destroy
         */
        destroy: function () {
            // destroy uploader instance
            this.uploader.destroy();

            // unbind all button events
            $('.plupload_button', this.element).unbind();

            // destroy buttons
            if ($.ui.button) {
                $('.plupload_add, .plupload_start, .plupload_stop', this.container)
                    .button('destroy');
            }

            // destroy progressbar
            if ($.ui.progressbar) {
                this.progressbar.progressbar('destroy');
            }

            // destroy sortable behavior
            if ($.ui.sortable && this.options.sortable) {
                $('tbody', this.filelist).sortable('destroy');
            }

            // restore the elements initial state
            this.element
                .empty()
                .html(this.contents_bak);
            this.contents_bak = '';

            $.Widget.prototype.destroy.apply(this);
        },


        _handleState: function () {
            let up = this.uploader
                , filesPending = up.files.length - (up.total.uploaded + up.total.failed)
                , maxCount = up.getOption('filters').max_file_count || 0
            ;

            if (plupload.STARTED === up.state) {
                $([])
                    .add(this.stop_button, this.element)
                    .add('.plupload_started', this.element)
                    .removeClass('plupload_hidden');

                this._setVisualState('uploading');

                this.start_button.button('disable');

                if (!this.options.multiple_queues) {
                    this.browse_button.button('disable');
                    up.disableBrowse();
                }

                $('.plupload_upload_status', this.element).html(plupload.sprintf(_('Uploaded %d/%d files'), up.total.uploaded, up.files.length));
                $('.plupload_header_content', this.element).addClass('plupload_header_content_bw');
            } else if (plupload.STOPPED === up.state) {
                $([])
                    .add(this.stop_button, this.element)
                    .add('.plupload_started', this.element)
                    .addClass('plupload_hidden');

                if (filesPending) {
                    this.start_button.button('enable');
                } else {
                    this.start_button.button('disable');
                }

                if (this.options.multiple_queues) {
                    $('.plupload_header_content', this.element).removeClass('plupload_header_content_bw');
                }

                // if max_file_count defined, only that many files can be queued at once
                if (this.options.multiple_queues && maxCount && maxCount > filesPending) {
                    this.browse_button.button('enable');
                    up.disableBrowse(false);
                }

                this._setVisualState(up.total.uploaded && !filesPending ? 'complete' : 'idle');
                this._updateTotalProgress();
            }

            if (up.total.queued === 0) {
                $('.ui-button-text', this.browse_button).html(_('Add Files'));
            } else {
                $('.ui-button-text', this.browse_button).html(plupload.sprintf(_('%d files queued'), up.total.queued));
            }

            up.refresh();
        },


        _handleFileStatus: function (file) {
            let $file = $('#' + file.id), actionClass, iconClass;

            // since this method might be called asynchronously, file row might not yet be rendered
            if (!$file.length) {
                return;
            }

            switch (file.status) {
                case plupload.DONE:
                    actionClass = 'plupload_done';
                    iconClass = 'plupload_action_icon ui-icon ui-icon-circle-check';
                    break;

                case plupload.FAILED:
                    actionClass = 'ui-state-error plupload_failed';
                    iconClass = 'plupload_action_icon ui-icon ui-icon-alert';
                    break;

                case plupload.QUEUED:
                    actionClass = 'plupload_delete';
                    iconClass = 'plupload_action_icon ui-icon ui-icon-circle-minus';
                    break;

                case plupload.UPLOADING:
                    actionClass = 'ui-state-highlight plupload_uploading';
                    iconClass = 'plupload_action_icon ui-icon ui-icon-circle-arrow-w';

                    // scroll uploading file into the view if its bottom boundary is out of it
                    let scroller = $('.plupload_scroll', this.container)
                        , scrollTop = scroller.scrollTop()
                        , scrollerHeight = scroller.height()
                        , rowOffset = $file.position().top + $file.height()
                    ;

                    if (scrollerHeight < rowOffset) {
                        scroller.scrollTop(scrollTop + rowOffset - scrollerHeight);
                    }

                    // Set file specific progress
                    $file
                        .find('.plupload_file_percent')
                        .html(file.percent + '%')
                        .end()
                        .find('.plupload_file_progress')
                        .css('width', file.percent + '%')
                        .end()
                        .find('.plupload_file_size')
                        .html(plupload.formatSize(file.size));
                    break;
            }
            actionClass += ' ui-state-default plupload_file';

            $file
                .attr('class', actionClass)
                .find('.plupload_action_icon')
                .attr('class', iconClass);
        },


        _updateTotalProgress: function () {
            let up = this.uploader;

            this.progressbar.progressbar('value', up.total.percent);

            this.element
                .find('.plupload_total_status')
                .html(up.total.percent + '%')
                .end()
                .find('.plupload_total_file_size')
                .html(plupload.formatSize(up.total.size))
                .end()
                .find('.plupload_upload_status')
                .html(plupload.sprintf(_('Uploaded %d/%d files'), up.total.uploaded, up.files.length));
        },


        _displayThumbs: function () {
            let self = this
                , tw, th // thumb width/height
                , cols
                , num = 0 // number of simultaneously visible thumbs
                , thumbs = [] // array of thumbs to preload at any given moment
                , loading = false
            ;

            if (!this.options.views.thumbs) {
                return;
            }


            function onLast(el, eventName, cb) {
                let timer;

                el.on(eventName, function () {
                    clearTimeout(timer);
                    timer = setTimeout(function () {
                        clearTimeout(timer);
                        cb();
                    }, 300);
                });
            }


            // calculate number of simultaneously visible thumbs
            function measure() {
                if (!tw || !th) {
                    let wrapper = $('.plupload_file:eq(0)', self.filelist);
                    if (!wrapper.length) {
                        return false;
                    }
                    tw = wrapper.outerWidth(true);
                    th = wrapper.outerHeight(true);
                }

                let aw = self.filelist.innerWidth(), ah = self.filelist.innerHeight();
                cols = Math.max(1, Math.floor(aw / tw));
                num = cols * (Math.ceil(ah / th) + 1);
                return true;
            }


            function pickThumbsToLoad() {
                // calculate index of virst visible thumb
                let startIdx = Math.floor(self.filelist.scrollTop() / th) * cols;
                // get potentially visible thumbs that are not yet visible
                thumbs = $('.plupload_file', self.filelist)
                    .slice(startIdx, startIdx + num)
                    .filter('.plupload_file_loading')
                    .get();
            }


            function init() {
                function mpl() { // measure, pick, load
                    if (self.view_mode !== 'thumbs') {
                        return;
                    }
                    if (!measure()) {
                        return;
                    }
                    pickThumbsToLoad();
                    lazyLoad();
                }

                if ($.fn.resizable) {
                    onLast(self.container, 'resize', mpl);
                }

                onLast(self.window, 'resize', mpl);
                onLast(self.filelist, 'scroll', mpl);

                self.element.on('viewchanged selected', mpl);

                mpl();
            }


            function preloadThumb(file, cb) {
                let img = new o.image.Image();
                let resolveUrl = o.core.utils.Url.resolveUrl;

                img.onload = function () {
                    let thumb = $('#' + file.id + ' .plupload_file_thumb', self.filelist).html('');
                    this.embed(thumb[0], {
                        width: self.options.thumb_width,
                        height: self.options.thumb_height,
                        crop: self.options.thumb_crop,
                        swf_url: resolveUrl(self.options.flash_swf_url),
                        xap_url: resolveUrl(self.options.silverlight_xap_url)
                    });
                };

                img.bind("embedded error", function () {
                    $('#' + file.id, self.filelist).removeClass('plupload_file_loading');
                    this.destroy();
                    setTimeout(cb, 1); // detach, otherwise ui might hang (in SilverLight for example)
                });

                img.load(file.getSource());
            }


            function lazyLoad() {
                if (self.view_mode !== 'thumbs' || loading) {
                    return;
                }

                pickThumbsToLoad();
                if (!thumbs.length) {
                    return;
                }

                loading = true;

                preloadThumb(self.getFile($(thumbs.shift()).attr('id')), function () {
                    loading = false;
                    lazyLoad();
                });
            }

            // this has to run only once to measure structures and bind listeners
            this.element.on('selected', function onselected() {
                self.element.off('selected', onselected);
                init();
            });
        },


        _addFiles: function (files) {
            let self = this, file_html, html = '';

            file_html = '<li class="plupload_file ui-state-default plupload_file_loading plupload_delete" id="%id%">' +
                '<div class="plupload_file_thumb" style="width:%thumb_width%px;height:%thumb_height%px;">' +
                '<div class="plupload_file_dummy ui-widget-content" style="line-height:%thumb_height%px;"><img height="100% auto" width="100% auto" src="%url%"></div>' +
                '</div>' +
                '<div class="plupload_file_status">' +
                '<div class="plupload_file_progress ui-widget-header" style="width: 0%"> </div>' +
                '<span class="plupload_file_percent">%percent%%</span>' +
                '</div>' +
                '<div class="plupload_file_name" title="%name%">' +
                '<span class="plupload_file_name_wrapper">%name% </span>' +
                '</div>' +
                '<div class="plupload_file_action">' +
                '<div class="plupload_action_icon ui-icon ui-icon-circle-minus"> </div>' +
                '</div>' +
                '<div class="plupload_file_size">%size% </div>' +
                '<div class="plupload_file_fields"> </div>' +
                '</li>';

            if (plupload.typeOf(files) !== 'array') {
                files = [files];
            }

            $.each(files, function (i, file) {
                let m = file.name.match(/\.([^.]+)$/);
                let ext = m && m[1].toLowerCase() || 'none';

                html += file_html.replace(/%(\w+)%/g, function ($0, $1) {
                    switch ($1) {
                        case 'thumb_width':
                        case 'thumb_height':
                            return self.options[$1];

                        case 'size':
                            return plupload.formatSize(file.size);

                        case 'ext':
                            return ext;

                            case 'url':
                                return file.url || '';

                        default:
                            return file[$1] || '';
                    }
                });
            });

            self.filelist.append(html);
        },


        _addFormFields: function () {
            let self = this;

            // re-add from fresh
            $('.plupload_file_fields', this.filelist).html('');

            let field_input = "input_" + self.options.multipart_params.currentFieldID;

            plupload.each(this.uploader.files, function (file, count) {

                $('#' + file.id).find('.plupload_file_fields').html(
                    '<input type="hidden" name="' + field_input + '[]" value="' + plupload.xmlEncode(file.id) + '" />' +
                    '<input type="hidden" name="' + plupload.xmlEncode(file.id) + '_name" value="' + plupload.xmlEncode(file.name) + '" />'
                );
            });

            this.counter.val(this.uploader.files.length);
        },


        _updateViewToggle: function (view) {
            let toggle = $('.plupload_view_toggle', this.container)
                , nextView
                , label
                , iconClass
            ;

            if (!toggle.length) {
                return;
            }

            nextView = view === 'thumbs' ? 'list' : 'thumbs';

            if (!this.options.views[nextView]) {
                nextView = view;
            }

            label = nextView === 'thumbs' ? _('Thumbnails') : _('List');
            iconClass = nextView === 'thumbs' ? 'ui-icon-image' : 'ui-icon-grip-dotted-horizontal';

            toggle
                .attr({
                    'data-view': nextView,
                    'title': label,
                    'aria-label': label
                })
                .toggleClass('is-list-target', nextView === 'list')
                .toggleClass('is-thumbs-target', nextView === 'thumbs')
                .find('.plupload_view_toggle_label')
                .text(label);

            toggle.find('.plupload_view_toggle_icon')
                .removeClass('ui-icon-image ui-icon-grip-dotted-horizontal')
                .addClass(iconClass);
        },


        _viewChanged: function (view) {
            // update or write a new cookie
            if (this.options.views.remember && $.cookie) {
                $.cookie('plupload_ui_view', view, {expires: 7, path: '/'});
            }

            // ugly fix for IE6 - make content area stretchable
            if (plupload.ua.browser === 'IE' && plupload.ua.version < 7) {
                this.content.attr('style', 'height:expression(document.getElementById("' + this.id + '_container' + '").clientHeight - ' + (view === 'list' ? 132 : 102) + ')');
            }

            this.container.removeClass('plupload_view_list plupload_view_thumbs').addClass('plupload_view_' + view);
            this.view_mode = view;
            this._updateViewToggle(view);
            this._trigger('viewchanged', null, {view: view});
        },

        _enableViewSwitcher: function () {
            let self = this
                , view
                , switcher = $('.plupload_view_switch', this.container)
                , availableViews = []
            ;

            plupload.each(['list', 'thumbs'], function (view) {
                if (self.options.views[view]) {
                    availableViews.push(view);
                }
            });

            if (availableViews.length <= 1) {
                switcher.hide();
                this._viewChanged(availableViews[0] || this.options.views.active);
            } else {
                if (this.options.views.remember && $.cookie) {
                    view = $.cookie('plupload_ui_view');
                }

                if (!~plupload.inArray(view, availableViews)) {
                    view = this.options.views.active;
                }

                if (!~plupload.inArray(view, availableViews)) {
                    view = availableViews[0];
                }

                switcher
                    .show()
                    .off('click.pluploadViewToggle')
                    .on('click.pluploadViewToggle', '.plupload_view_toggle', function (e) {
                        let nextView = $(this).attr('data-view');

                        if (!~plupload.inArray(nextView, availableViews)) {
                            nextView = self.view_mode === 'thumbs' ? 'list' : 'thumbs';
                        }

                        if (~plupload.inArray(nextView, availableViews)) {
                            self._viewChanged(nextView);
                        }

                        e.preventDefault();
                    });

                this._viewChanged(view);
            }

            // initialize thumb viewer if requested
            if (this.options.views.thumbs) {
                this._displayThumbs();
            }
        },

        _enableSortingList: function () {
            let self = this;

            if ($('.plupload_file', this.filelist).length < 2) {
                return;
            }

            // destroy sortable if enabled
            $('tbody', this.filelist).sortable('destroy');

            // enable
            this.filelist.sortable({
                items: '.plupload_delete, .plupload_done',

                cancel: 'object, .plupload_clearer',

                stop: function () {
                    let files = [];

                    $.each($(this).sortable('toArray'), function (i, id) {
                        files[files.length] = self.uploader.getFile(id);
                    });

                    files.unshift(files.length);
                    files.unshift(0);

                    // re-populate files array
                    Array.prototype.splice.apply(self.uploader.files, files);
                }
            });
        }
    });

}(window, document, plupload, moxie, jQuery));
