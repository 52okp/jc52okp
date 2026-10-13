/** Verified CKEditor 5 48.5.2 core, integrated with the existing project uploader. */
define(['jquery', 'upload', 'ckeditor5-core', 'ckeditor5-zh'], function ($, Upload, CK) {
    const cssUrl = require.toUrl('plugs/ckeditor5/ckeditor.css');
    if (!Array.from(document.querySelectorAll('link[rel=stylesheet]')).some(link => link.href.endsWith('/plugs/ckeditor5/ckeditor.css'))) {
        const link = document.createElement('link'); link.rel = 'stylesheet'; link.href = cssUrl; document.head.appendChild(link);
    }
    function ProjectUpload(editor) {
        editor.plugins.get('FileRepository').createUploadAdapter = loader => {
            let rejectUpload;
            return {
                upload: () => loader.file.then(file => new Promise((resolve, reject) => {
                    rejectUpload = reject;
                    const adapter = new window.AdminUploadAdapter();
                    const dispatch = adapter.event.bind(adapter);
                    adapter.event = function (name, data, item, message) {
                        if (name === 'upload.error') reject(new Error(message || '图片上传失败'));
                        return dispatch(name, data, item, message);
                    };
                    adapter.upload([file], url => resolve({ default: url }));
                })),
                abort: () => { if (rejectUpload) rejectUpload(new Error('上传已取消')); }
            };
        };
    }
    class VideoUpload extends CK.Plugin {
        init() {
            const editor = this.editor;
            editor.ui.componentFactory.add('uploadVideo', locale => {
                const button = new CK.ButtonView(locale);
                button.set({ label: '上传视频', withText: true, tooltip: true });
                button.on('execute', () => {
                    const input = document.createElement('input');
                    input.type = 'file'; input.accept = 'video/*';
                    input.addEventListener('change', () => {
                        if (!input.files[0]) return;
                        new window.AdminUploadAdapter().upload([input.files[0]], url => {
                            const safe = String(url).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
                            const view = editor.data.processor.toView('<video controls src="' + safe + '"></video>');
                            editor.model.insertContent(editor.data.toModel(view));
                        });
                    }, { once: true });
                    input.click();
                });
                return button;
            });
        }
    }
    const names = ['Essentials', 'Paragraph', 'Heading', 'Bold', 'Italic', 'Underline', 'Strikethrough', 'Code',
        'FontFamily', 'FontSize', 'FontColor', 'FontBackgroundColor', 'Alignment', 'Indent', 'IndentBlock',
        'List', 'Link', 'BlockQuote', 'HorizontalLine', 'Table', 'TableToolbar', 'TableProperties',
        'TableCellProperties', 'Image', 'ImageUpload', 'ImageInsert', 'ImageToolbar', 'ImageCaption',
        'ImageStyle', 'ImageTextAlternative', 'LinkImage', 'PasteFromOffice', 'RemoveFormat', 'SourceEditing', 'GeneralHtmlSupport'];
    window.createEditor = function (selector, options = {}) {
        const element = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (!element) return Promise.reject(new Error('编辑区域不存在'));
        const height = typeof options.height === 'number' ? options.height + 'px' : options.height || '400px';
        const config = Object.assign({
            licenseKey: window.taEditorLicense || 'GPL', language: 'zh-cn',
            plugins: names.map(name => CK[name]).concat([VideoUpload]), extraPlugins: [ProjectUpload],
            toolbar: ['heading', '|', 'fontFamily', 'fontSize', 'fontColor', 'fontBackgroundColor', 'bold', 'italic',
                'underline', 'strikethrough', 'removeFormat', 'link', '|', 'alignment', 'outdent', 'indent',
                'numberedList', 'bulletedList', '|', 'horizontalLine', 'code', 'blockQuote', 'insertTable',
                'insertImage', 'uploadVideo', '|', 'sourceEditing', 'undo', 'redo'],
            image: { toolbar: ['imageTextAlternative', 'imageStyle:inline', 'imageStyle:block', 'imageStyle:side', 'toggleImageCaption', 'linkImage'] },
            table: { contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells', 'tableCellProperties', 'tableProperties'] },
            htmlSupport: { allow: [{ name: /^(p|div|span|h[1-6]|figure|figcaption|table|thead|tbody|tr|td|th|a|img|video|source|ul|ol|li|pre|code|blockquote|hr|br)$/, styles: true, classes: true, attributes: ['src', 'href', 'alt', 'title', 'controls', 'width', 'height', 'colspan', 'rowspan'] }] }
        }, options);
        delete config.height;
        return CK.ClassicEditor.create(element, config).then(editor => {
            $(element).data('editor', editor).triggerHandler('editor.init', editor);
            editor.model.document.on('change:data', () => { element.value = editor.getData(); });
            editor.editing.view.change(writer => writer.setStyle('min-height', height, editor.editing.view.document.getRoot()));
            return editor;
        });
    };
    return CK;
});
