(function () {
    function toUpperHex(value) {
        return String(value || '').toUpperCase();
    }

    function updatePreview(root) {
        const preview = root.querySelector('.gfmu-admin-preview');

        if (!preview) {
            return;
        }

        const fieldMap = {
            header_title: root.querySelector('[name="gfmu_style[header_title]"]'),
            header_text: root.querySelector('[name="gfmu_style[header_text]"]'),
            primary_color: root.querySelector('[name="gfmu_style[primary_color]"]'),
            primary_text_color: root.querySelector('[name="gfmu_style[primary_text_color]"]'),
            surface_color: root.querySelector('[name="gfmu_style[surface_color]"]'),
            border_color: root.querySelector('[name="gfmu_style[border_color]"]'),
            border_radius: root.querySelector('[name="gfmu_style[border_radius]"]'),
            panel_min_height: root.querySelector('[name="gfmu_style[panel_min_height]"]')
        };

        const title = root.querySelector('.gfmu-admin-preview-title');
        const text = root.querySelector('.gfmu-admin-preview-text');

        if (title && fieldMap.header_title) {
            title.textContent = fieldMap.header_title.value || '';
        }

        if (text && fieldMap.header_text) {
            text.textContent = fieldMap.header_text.value || '';
        }

        if (fieldMap.primary_color) {
            preview.style.setProperty('--gfmu-primary-color', fieldMap.primary_color.value || '#1e7a3a');
        }

        if (fieldMap.primary_text_color) {
            preview.style.setProperty('--gfmu-primary-text-color', fieldMap.primary_text_color.value || '#ffffff');
        }

        if (fieldMap.surface_color) {
            preview.style.setProperty('--gfmu-surface-color', fieldMap.surface_color.value || '#f4faf4');
        }

        if (fieldMap.border_color) {
            preview.style.setProperty('--gfmu-border-color', fieldMap.border_color.value || '#d9e4da');
        }

        if (fieldMap.border_radius) {
            const radius = parseInt(fieldMap.border_radius.value, 10);
            preview.style.setProperty('--gfmu-border-radius', (isNaN(radius) ? 16 : radius) + 'px');
        }

        if (fieldMap.panel_min_height) {
            const minHeight = parseInt(fieldMap.panel_min_height.value, 10);
            preview.style.setProperty('--gfmu-panel-min-height', (isNaN(minHeight) ? 420 : minHeight) + 'px');
        }
    }

    function updateColorControl(input) {
        const control = input.closest('.gfmu-admin-color-control');

        if (!control) {
            return;
        }

        const swatch = control.querySelector('.gfmu-admin-color-swatch');
        const hex = control.querySelector('em');

        if (swatch) {
            swatch.style.backgroundColor = input.value;
        }

        if (hex) {
            hex.textContent = toUpperHex(input.value);
        }
    }

    function init(root) {
        const inputs = root.querySelectorAll('[name^="gfmu_style["]');

        if (!inputs.length) {
            return;
        }

        inputs.forEach(function (input) {
            if (input.type === 'color') {
                updateColorControl(input);
            }

            input.addEventListener('input', function () {
                if (input.type === 'color') {
                    updateColorControl(input);
                }

                updatePreview(root);
            });

            input.addEventListener('change', function () {
                if (input.type === 'color') {
                    updateColorControl(input);
                }

                updatePreview(root);
            });
        });

        updatePreview(root);
    }

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('.gfmu-admin-page');

        if (!root) {
            return;
        }

        init(root);
    });
})();
