(function () {
    'use strict';

    var currentTheme = null;
    var themeTimer = 0;

    function isDarkTheme() {
        if (document.body && document.body.classList.contains('dark-theme')) return true;
        if (document.body && document.body.classList.contains('light-theme')) return false;
        return Boolean(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }

    function configureTinyMceTheme(settings, editor, inputfield) {
        var element = inputfield && inputfield[0] ? inputfield[0] : inputfield;
        if (!element || !element.classList || !element.classList.contains('mrc-notification-tinymce')) return;
        var dark = isDarkTheme();
        settings.skin = dark ? 'oxide-dark' : 'oxide';
        settings.content_css = dark ? 'dark' : 'default';
        delete settings.content_css_url;
    }

    function registerTheme() {
        if (currentTheme) return true;
        if (!window.InputfieldTinyMCE) return false;
        window.InputfieldTinyMCE.onConfig(configureTinyMceTheme);
        currentTheme = isDarkTheme() ? 'dark' : 'light';
        return true;
    }

    function refreshTheme() {
        if (!registerTheme() || !window.jQuery || !window.tinymce) return;
        var next = isDarkTheme() ? 'dark' : 'light';
        if (next === currentTheme) return;
        currentTheme = next;
        window.clearTimeout(themeTimer);
        themeTimer = window.setTimeout(function () {
            var editors = window.jQuery('.mrc-notification-tinymce .InputfieldTinyMCEEditor.InputfieldTinyMCELoaded');
            editors.each(function () {
                var editor = window.tinymce.get(this.id);
                if (editor) editor.save();
            });
            if (editors.length) window.InputfieldTinyMCE.resetEditors(editors);
        }, 50);
    }

    function samplesFor(element) {
        try { return JSON.parse(element.dataset.mrcSamples || '{}'); }
        catch (error) { return {}; }
    }

    function replaceVariables(value, samples) {
        return String(value || '').replace(/\{([a-z_]+)\}/g, function (match, key) {
            return Object.prototype.hasOwnProperty.call(samples, key) ? String(samples[key]) : match;
        });
    }

    function editorContent(id) {
        var textarea = document.getElementById(id);
        if (!textarea) return '';
        var editor = window.tinymce && window.tinymce.get(id);
        return editor ? editor.getContent() : textarea.value;
    }

    function previewDocument(content) {
        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            + 'html{background:#eef2f1}body{max-width:680px;margin:0 auto;padding:28px 16px;background:#eef2f1;color:#182022;font:16px/1.55 Arial,sans-serif}a{color:#087f79}blockquote{margin:16px 0;padding:12px 16px;border-left:3px solid #087f79;background:#f4f6f6}img{max-width:100%;height:auto}table{max-width:100%}'
            + '</style></head><body>' + content + '</body></html>';
    }

    function renderTemplatePreview(form) {
        var subject = form.querySelector('[data-mrc-notification-subject]');
        var subjectPreview = form.querySelector('[data-mrc-preview-subject]');
        var frame = form.querySelector('[data-mrc-preview-frame]');
        var editor = form.querySelector('[name^="html_body_"]');
        if (!subject || !subjectPreview || !frame || !editor) return;
        var samples = samplesFor(form);
        subjectPreview.textContent = replaceVariables(subject.value, samples) || 'Email subject';
        var body = replaceVariables((form.dataset.mrcMailHeader || '') + editorContent(editor.id) + (form.dataset.mrcMailFooter || ''), samples);
        frame.srcdoc = previewDocument(body);
    }

    function renderLayoutPreview(form) {
        var frame = form.querySelector('[data-mrc-layout-preview]');
        var header = form.querySelector('[name="notification_header_html"]');
        var footer = form.querySelector('[name="notification_footer_html"]');
        if (!frame || !header || !footer) return;
        var samples = samplesFor(form);
        var sampleBody = '<div style="padding:28px;background:#ffffff"><p style="margin:0 0 12px">Hello ' + (samples.customer || 'customer') + ',</p><h2 style="margin:0 0 12px">Sample transactional update</h2><p style="margin:0">This area is replaced by the selected notification template.</p></div>';
        var html = editorContent(header.id) + sampleBody + editorContent(footer.id);
        frame.srcdoc = previewDocument(replaceVariables(html, samples));
    }

    function queueRender(element, callback) {
        window.clearTimeout(element.mrcPreviewTimer);
        element.mrcPreviewTimer = window.setTimeout(function () { callback(element); }, 120);
    }

    function insertVariable(button) {
        var editorId = button.getAttribute('data-mrc-editor');
        var value = button.getAttribute('data-mrc-variable') || '';
        var textarea = document.getElementById(editorId);
        var editor = window.tinymce && window.tinymce.get(editorId);
        if (editor) {
            editor.focus();
            editor.insertContent(value);
            editor.fire('change');
        } else if (textarea) {
            textarea.focus();
            textarea.setRangeText(value, textarea.selectionStart, textarea.selectionEnd, 'end');
            textarea.dispatchEvent(new Event('input', {bubbles: true}));
        }
    }

    registerTheme();

    document.addEventListener('DOMContentLoaded', function () {
        registerTheme();
        if (document.body && window.MutationObserver) {
            new MutationObserver(refreshTheme).observe(document.body, {attributes: true, attributeFilter: ['class']});
        }
        if (window.matchMedia) {
            var scheme = window.matchMedia('(prefers-color-scheme: dark)');
            if (scheme.addEventListener) scheme.addEventListener('change', refreshTheme);
            else if (scheme.addListener) scheme.addListener(refreshTheme);
        }

        document.querySelectorAll('[data-mrc-notification-form]').forEach(function (form) {
            renderTemplatePreview(form);
            form.addEventListener('input', function () { queueRender(form, renderTemplatePreview); });
            form.addEventListener('submit', function () { if (window.tinymce) window.tinymce.triggerSave(); });
        });
        document.querySelectorAll('[data-mrc-layout-form]').forEach(function (form) {
            renderLayoutPreview(form);
            form.addEventListener('input', function () { queueRender(form, renderLayoutPreview); });
            form.addEventListener('submit', function () { if (window.tinymce) window.tinymce.triggerSave(); });
        });
        document.addEventListener('click', function (event) {
            var button = event.target.closest && event.target.closest('[data-mrc-variable]');
            if (button) insertVariable(button);
        });
        if (window.jQuery) {
            window.jQuery(document).on('change', '.mrc-notification-tinymce', function () {
                var templateForm = this.closest('[data-mrc-notification-form]');
                if (templateForm) queueRender(templateForm, renderTemplatePreview);
                var layoutForm = this.closest('[data-mrc-layout-form]');
                if (layoutForm) queueRender(layoutForm, renderLayoutPreview);
            });
        }
        window.setTimeout(function () {
            document.querySelectorAll('[data-mrc-notification-form]').forEach(renderTemplatePreview);
            document.querySelectorAll('[data-mrc-layout-form]').forEach(renderLayoutPreview);
        }, 600);
    });
}());
