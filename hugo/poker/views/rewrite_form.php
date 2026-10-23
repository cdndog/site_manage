<div class="container app-page" id="main">
<div class="card card-sops">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h1 class="h5 mb-0 page-title"><i class="bi bi-arrow-repeat mr-2" aria-hidden="true"></i><?php echo !empty($form['post_keyword']) || !empty($form['post_gitname']) ? '改写编辑' : '改写录入'; ?></h1>
    <span class="badge badge-info"><i class="bi bi-info-circle mr-1" aria-hidden="true"></i>保存到 sitetopic，状态为 rewrite（待改写）</span>
  </div>
  <div class="card-body">
<form action="<?php echo e(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '/rewriteops.php');?>" method="post" id="rewriteform" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
    <input type="hidden" name="setupNum" value="ckeditorFormated">
    <input type="hidden" name="post_uuid" value="<?php echo e($form['ctx_id']); ?>">
    <input type="hidden" name="post_lasttask" value="<?php echo e($form['post_lasttask']); ?>">

    <div class="form-group">
        <div class="row">
            <div class="col-md-9 col-xs-12">
            <div class="sops-label-row">
            <label for="post_keyword">改写关键词 <span class="text-danger" aria-hidden="true">*</span></label>
            <div class="form-hint" data-toggle="tooltip" data-placement="top" title="待改写文章的关键词"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
            </div>
            <input type="text" class="form-control" id="post_keyword" name="post_keyword" value="<?php echo e($form['post_keyword']); ?>" required placeholder="录入需要改写的关键词" pattern="[^\u007C\uFF5C]*" maxlength="1000" data-msg="关键词不能包含 ｜">
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 col-xs-12">
                <label for="post_gitname">发布到站点(github仓库名)</label>
                <select class="selectpicker form-control" id="post_gitname" name="post_gitname" size="1" data-show-subtext="true" data-live-search="true" required>
                    <option data-subtext="">Choose a gitname</option>
                <?php
                $gitCurrent = isset($form['post_gitname']) ? (string)$form['post_gitname'] : '';
                $gitMatched = false;
                foreach ($sites as $site) {
                    if ($gitCurrent === (string)$site['git_name']) { $gitMatched = true; }
                }
                ?>
                <?php if ($gitCurrent !== '' && !$gitMatched): ?>
                    <option selected><?php echo e($gitCurrent); ?></option>
                <?php endif; ?>
                <?php foreach ($sites as $site) :?>
                    <?php
                    $label = !empty($site['languages']) ? $site['git_name'] . '(' . $site['languages'] . ')' : $site['git_name'];
                    $selected = $gitCurrent === (string)$site['git_name'] ? ' selected' : '';
                    ?>
                <option data-subtext="<?php echo e($label); ?>"<?php echo $selected; ?>><?php echo e($site['git_name']); ?></option>
                <?php endforeach ?>
                </select>
            </div>
            <div class="col-md-6 col-xs-12">
                <label for="post_domain">发布到站点(域名)</label>
                <select class="selectpicker form-control" id="post_domain" name="post_domain" data-id="post_domain" size="1" data-show-subtext="true" data-live-search="true">
                    <option data-subtext="">Choose a Domain</option>
                <?php
                $domainCurrent = isset($form['post_domain']) ? (string)$form['post_domain'] : '';
                $domainMatched = false;
                foreach ($sites as $site) {
                    if ($domainCurrent === (string)$site['domain']) { $domainMatched = true; }
                }
                ?>
                <?php if ($domainCurrent !== '' && !$domainMatched): ?>
                    <option selected><?php echo e($domainCurrent); ?></option>
                <?php endif; ?>
                <?php foreach ($sites as $site) :?>
                    <?php
                    $label = !empty($site['languages']) ? $site['domain'] . '(' . $site['languages'] . ')' : $site['domain'];
                    $selected = $domainCurrent === (string)$site['domain'] ? ' selected' : '';
                    ?>
                <option data-subtext="<?php echo e($label); ?>"<?php echo $selected; ?>><?php echo e($site['domain']); ?></option>
                <?php endforeach ?>
                </select>
            </div>
        </div>
    </div>

    <div class="form-group">
        <label class="badge badge-light my-2" for="post_ckeditor_contents"><i class="bi bi-info-circle mr-1" aria-hidden="true"></i>正文内容：</label>
        <label id="word-count" class="badge badge-light ml-2"></label>
        <div class="row mx-0">
            <label id="list2number" class="badge badge-warning mr-2 order-1" style="cursor:pointer;">[列表]转[数字前缀]</label>
            <label id="removeBlankLines" class="badge badge-primary mr-2 order-2" style="cursor:pointer;">删除空行</label>
            <label id="removeRefLink" class="badge badge-primary mr-2 order-2" style="cursor:pointer;">删除引文</label>
            <label id="resizeImagesButton" class="badge badge-success mr-2 order-3" style="cursor:pointer;">调整图片</label>
            <label id="cdnImagesButton" class="badge badge-success mr-2 order-4" style="cursor:pointer;">CDN图片</label>
            <label id="removeHtmlTag" class="badge badge-success mr-2 order-4" style="cursor:pointer;">删除HTML标签</label>
        </div>
        <textarea cols="1" id="post_ckeditor_contents" name="post_ckeditor_contents" rows="1" required style="display:none;"><?php echo e($form['content']); ?></textarea>
    </div>

    <div class="form-group">
      <div class="row">
        <div class="col-md-3 col-xs-12">
          <label for="post_lang">站点语言</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control" id="post_lang" name="post_lang" required>
            <?php foreach ($config['languages'] as $label => $value): ?>
            <option data-subtext="<?php echo e($label); ?>"<?php echo strtolower((string)$value) === strtolower((string)$form['post_lang']) ? ' selected' : ''; ?>>
                <?php echo e($value); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_geo">国家地区</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control" id="post_geo" name="post_geo" required>
            <?php foreach ($config['countries'] as $label => $value): ?>
            <option data-subtext="<?php echo e($label); ?>"<?php echo strtolower((string)$value) === strtolower((string)$form['post_geo']) ? ' selected' : ''; ?>>
                <?php echo e($value); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_pubdir">发布目录</label>
          <select class="selectpicker form-control" id="post_pubdir" name="post_pubdir" data-id="post_pubdir" data-show-subtext="true" required>
            <?php
            $pubdirCurrent = isset($form['post_pubdir']) ? (string)$form['post_pubdir'] : '';
            $pubdirOptions = (isset($config['pubdir']) && is_array($config['pubdir'])) ? $config['pubdir'] : [];
            $pubdirMatched = false;
            foreach ($pubdirOptions as $pubdirOption) {
                if (strtolower((string)$pubdirOption) === strtolower($pubdirCurrent)) {
                    $pubdirMatched = true;
                    break;
                }
            }
            foreach ($pubdirOptions as $value): ?>
            <option value="<?php echo e($value); ?>"<?php echo strtolower((string)$value) === strtolower($pubdirCurrent) && $pubdirCurrent !== '' ? ' selected' : ''; ?>><?php echo e($value); ?></option>
            <?php endforeach;
            if (!$pubdirMatched && $pubdirCurrent !== ''): ?>
            <option data-subtext="配置值" value="<?php echo e($pubdirCurrent); ?>" selected><?php echo e($pubdirCurrent); ?></option>
            <?php endif; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_status">状态</label>
          <input type="hidden" name="post_status" value="rewrite">
          <input type="text" class="form-control" id="post_status" value="rewrite（待改写）" readonly>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-end mt-4">
        <button type="submit" class="btn btn-primary px-5" id="submitBtn"><i class="bi bi-check-circle mr-1" aria-hidden="true"></i>保存</button>
    </div>
</form>
  </div>
</div>

<script>
    (function () {
        function initHints() {
            if (!window.jQuery || !jQuery.fn.tooltip) {
                return false;
            }
            jQuery('[data-toggle="tooltip"]').tooltip();
            return true;
        }
        if (!initHints()) {
            var timer = setInterval(function () {
                if (initHints()) {
                    clearInterval(timer);
                }
            }, 100);
            setTimeout(function () { clearInterval(timer); }, 10000);
        }
    })();

    (function () {
        document.querySelectorAll('[data-msg]').forEach(function (el) {
            el.addEventListener('invalid', function () {
                if (el.validity.patternMismatch || el.validity.tooLong) {
                    el.setCustomValidity(el.getAttribute('data-msg'));
                }
            });
            el.addEventListener('input', function () {
                el.setCustomValidity('');
            });
        });
    })();

    (function () {
        var btn = document.getElementById('submitBtn');
        var form = document.getElementById('rewriteform');
        if (!btn || !form) return;
        var defaultHtml = btn.innerHTML;
        var resetBtn = function () {
            btn.disabled = false;
            btn.innerHTML = defaultHtml;
        };
        window.addEventListener('pageshow', resetBtn);
        form.addEventListener('submit', function () {
            if (btn.disabled) return;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" aria-hidden="true"></span>保存中…';
        });
        form.addEventListener('submit', function (event) {
            setTimeout(function () {
                if (event.defaultPrevented) resetBtn();
            }, 0);
        });
    })();
</script>

<div class="toast-container" style="position: fixed; top: 1rem; right: 1rem; z-index: 2000;">
  <div class="toast" id="tipToast" role="alert" aria-live="assertive" aria-atomic="true" style="min-width: 280px;">
    <div class="toast-header">
      <strong class="mr-auto" id="tipToastTitle"><i class="bi bi-info-circle mr-1" aria-hidden="true"></i>提示</strong>
      <button type="button" class="ml-2 mb-1 close" data-dismiss="toast" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    </div>
    <div class="toast-body" id="tipToastMessage"></div>
  </div>
</div>

<script type="importmap">
{
  "imports": {
    "ckeditor5": "./js/ckeditor5/ckeditor5.js",
    "ckeditor5/": "./js/ckeditor5/"
  }
}
</script>
<script type="module">
(function () {
    var textarea = document.getElementById('post_ckeditor_contents');
    var form = document.getElementById('rewriteform');
    if (!textarea || !form) return;

    var csrfToken = form.querySelector('input[name="csrf_token"]').value;

    function showTip(message, isError) {
        if (window.jQuery && jQuery.fn.toast && document.getElementById('tipToast')) {
            jQuery('#tipToastTitle').html((isError ? '<i class="bi bi-exclamation-circle mr-1"></i>' : '<i class="bi bi-check-circle mr-1"></i>') + (isError ? '提示' : '成功'));
            jQuery('#tipToastTitle').css('color', isError ? '#dc3545' : '#28a745');
            jQuery('#tipToastMessage').text(message);
            var tipToast = new bootstrap.Toast(document.getElementById('tipToast'), { delay: 3000 });
            tipToast.show();
        } else if (window.sopsToast) {
            sopsToast(message, isError ? 'danger' : 'success');
        }
    }
    window.showTip = showTip;

    import('ckeditor5').then(function (CKEditor) {
        var ClassicEditor = CKEditor.ClassicEditor;
        var plugins = CKEditor;
        var editor;

        class ImgBBUploadAdapter {
            constructor(loader) {
                this.loader = loader;
            }

            upload() {
                return this.loader.file.then(file => new Promise((resolve, reject) => {
                    if (file.size > 10 * 1024 * 1024) {
                        if (window.showTip) { window.showTip('图片大小超过 10MB 限制，请压缩后重试。', true); }
                        reject(new Error('图片大小超过 10MB 限制，请压缩后重试。'));
                        return;
                    }
                    const formData = new FormData();
                    formData.append('image', file);
                    fetch('imgbb_proxy.php', {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-CSRF-Token': csrfToken }
                    })
                    .then(response => response.json().catch(() => null))
                    .then(result => {
                        if (result && result.success && result.data && result.data.url) {
                            resolve({ default: result.data.url });
                        } else {
                            var message = (result && result.error && result.error.message) ? result.error.message : '上传失败';
                            if (window.showTip) { window.showTip(message, true); }
                            reject(new Error(message));
                        }
                    })
                    .catch(error => {
                        if (window.showTip) { window.showTip('上传过程中出错，请重试。', true); }
                        reject(error);
                    });
                }));
            }

            abort() {}
        }

        function ImgBBUploadAdapterPlugin(editorInstance) {
            editorInstance.plugins.get('FileRepository').createUploadAdapter = (loader) => {
                return new ImgBBUploadAdapter(loader);
            };
        }

        var editorConfig = {
            licenseKey: 'GPL',
            toolbar: {
                items: [
                    'undo', 'redo', '|', 'heading', 'findAndReplace', '|',
                    'bold', 'italic', 'code', 'removeFormat', '|',
                    'sourceEditing', 'showBlocks', 'selectAll', '|',
                    'specialCharacters', 'link', 'insertImageViaUrl', 'imageUpload',
                    'insertTable', 'codeBlock', 'htmlEmbed', '|',
                    'bulletedList', 'numberedList', 'todoList', '|'
                ],
                shouldNotGroupWhenFull: false
            },
            plugins: [
                plugins.AccessibilityHelp, plugins.Autoformat, plugins.AutoImage,
                plugins.AutoLink, plugins.Autosave, plugins.BalloonToolbar,
                plugins.BlockToolbar, plugins.Bold, plugins.CloudServices,
                plugins.Code, plugins.CodeBlock, plugins.Essentials,
                plugins.FindAndReplace, plugins.FullPage, plugins.GeneralHtmlSupport,
                plugins.Heading, plugins.HtmlComment, plugins.HtmlEmbed,
                plugins.ImageBlock, plugins.ImageCaption, plugins.ImageInline,
                plugins.ImageInsertViaUrl, plugins.ImageResize, plugins.ImageStyle,
                plugins.ImageTextAlternative, plugins.ImageToolbar, plugins.ImageUpload,
                ImgBBUploadAdapterPlugin, plugins.Italic, plugins.Link, plugins.LinkImage,
                plugins.List, plugins.ListProperties, plugins.Markdown,
                plugins.MediaEmbed, plugins.Mention, plugins.Paragraph,
                plugins.PasteFromMarkdownExperimental, plugins.PasteFromOffice,
                plugins.RemoveFormat, plugins.SelectAll, plugins.ShowBlocks,
                plugins.SourceEditing, plugins.SpecialCharacters,
                plugins.SpecialCharactersArrows, plugins.SpecialCharactersCurrency,
                plugins.SpecialCharactersEssentials, plugins.SpecialCharactersLatin,
                plugins.SpecialCharactersMathematical, plugins.SpecialCharactersText,
                plugins.Table, plugins.TableCaption, plugins.TableCellProperties,
                plugins.TableColumnResize, plugins.TableProperties, plugins.TableToolbar,
                plugins.TextTransformation, plugins.TodoList, plugins.Undo, plugins.WordCount
            ],
            balloonToolbar: ['bold', 'italic', '|', 'link', '|', 'bulletedList', 'numberedList'],
            blockToolbar: ['bold', 'italic', '|', 'link', 'insertTable', '|', 'bulletedList', 'numberedList'],
            heading: {
                options: [
                    { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                    { model: 'heading1', view: 'h1', title: 'H1', class: 'ck-heading_heading1' },
                    { model: 'heading2', view: 'h2', title: 'H2', class: 'ck-heading_heading2' },
                    { model: 'heading3', view: 'h3', title: 'H3', class: 'ck-heading_heading3' },
                    { model: 'heading4', view: 'h4', title: 'H4', class: 'ck-heading_heading4' },
                    { model: 'heading5', view: 'h5', title: 'H5', class: 'ck-heading_heading5' },
                    { model: 'heading6', view: 'h6', title: 'H6', class: 'ck-heading_heading6' }
                ]
            },
            htmlSupport: {
                allow: [
                    { name: /^(div|p|span|section|strong|h1|h2|h3|h4|h5|h6)$/, styles: false, attributes: false, classes: false }
                ]
            },
            image: {
                toolbar: [
                    'toggleImageCaption', 'imageTextAlternative', '|',
                    'imageStyle:inline', 'imageStyle:wrapText', 'imageStyle:breakText', '|', 'resizeImage'
                ]
            },
            link: {
                addTargetToExternalLinks: true,
                defaultProtocol: 'https://'
            },
            list: { properties: { styles: true, startIndex: true, reversed: true } },
            mention: { feeds: [{ marker: '@', feed: [] }] },
            table: { contentToolbar: ['tableColumn', 'tableRow'] }
        };

        ClassicEditor
            .create(textarea, editorConfig)
            .then(function (newEditor) {
                editor = newEditor;
                window.editor = editor;
                window.rewriteEditor = editor;

                var wordCountDisplay = document.createElement('div');
                wordCountDisplay.id = 'word-count-display';
                document.getElementById('word-count').appendChild(wordCountDisplay);
                updateWordCountDisplay(editor.plugins.get('WordCount'));
                editor.plugins.get('WordCount').on('update', function () {
                    updateWordCountDisplay(editor.plugins.get('WordCount'));
                });

                editor.editing.view.document.on('click', function () { textarea.value = editor.getData(); });
                editor.editing.view.document.on('keydown', function () { textarea.value = editor.getData(); });
                editor.model.document.on('change:data', function () { textarea.value = editor.getData(); });

                form.addEventListener('submit', function () {
                    editor.updateSourceElement();
                });
            })
            .catch(function (e) { console.error('CKEditor init failed:', e); });

        function updateWordCountDisplay(wordCountPlugin) {
            const counts = countWordsAndPunctuation(wordCountPlugin);
            const characters = wordCountPlugin.characters;
            document.getElementById('word-count-display').innerHTML = '总字数: ' + counts.words + ' / 字节: ' + characters + ' / 其它: ' + counts.punctuation;
        }

        function countWordsAndPunctuation(wordCountPlugin) {
            const text = wordCountPlugin.editor.getData().replace(/<[^>]*>/g, '');
            const cjkRegex = /[\u4E00-\u9FFF\u3400-\u4DBF\u3000-\u303F\uFF00-\uFFEF\u3040-\u309F\u30A0-\u30FF]/g;
            const punctuationRegex = /[.,!?;:()“”‘’、。〃〄々〆〇〈〉《》「」『』【】〒〓〔〕〖〗〖〘〙〚〛〜〝〞〟，？；、]/g;
            const cjkWords = text.match(cjkRegex);
            const otherWords = text.split(/\s+/).filter(word => !cjkRegex.test(word)).length;
            const punctuationMatches = text.match(punctuationRegex);
            return {
                words: (cjkWords ? cjkWords.length : 0) + otherWords,
                punctuation: punctuationMatches ? punctuationMatches.length : 0
            };
        }

        function removeHtmlTag() {
            var content = editor.getData();
            var container = document.createElement('div');
            container.innerHTML = content;
            var sections = container.querySelectorAll('section');
            sections.forEach(function(section) {
                var parent = section.parentNode;
                while (section.firstChild) parent.insertBefore(section.firstChild, section);
                parent.removeChild(section);
            });
            editor.setData(container.innerHTML);
        }

        function removeBlankLines() {
            var container = document.createElement('div');
            container.innerHTML = editor.getData();
            function isEmptyElement(element) {
                if (element.nodeType === Node.TEXT_NODE) return !element.textContent.trim();
                if (element.nodeType === Node.ELEMENT_NODE) {
                    if (element.tagName.toLowerCase() === 'img') return false;
                    for (var i = 0; i < element.childNodes.length; i++) {
                        if (!isEmptyElement(element.childNodes[i])) return false;
                    }
                    return true;
                }
                return true;
            }
            function removeEmptyElements(element) {
                if (!element || element.nodeType !== Node.ELEMENT_NODE) return;
                for (var i = element.childNodes.length - 1; i >= 0; i--) {
                    var child = element.childNodes[i];
                    if (child.nodeType === Node.ELEMENT_NODE) {
                        removeEmptyElements(child);
                        if (isEmptyElement(child)) element.removeChild(child);
                    } else if (child.nodeType === Node.TEXT_NODE) {
                        if (!child.textContent.trim()) element.removeChild(child);
                    }
                }
            }
            removeEmptyElements(container);
            var brs = container.querySelectorAll('br');
            brs.forEach(function(br) {
                var p = document.createElement('p');
                br.parentNode.replaceChild(p, br);
            });
            var imgs = container.querySelectorAll('img');
            imgs.forEach(function(img) {
                if (img.parentNode.tagName.toLowerCase() !== 'p') {
                    var p = document.createElement('p');
                    img.parentNode.insertBefore(p, img);
                    p.appendChild(img);
                }
            });
            editor.setData(container.innerHTML);
        }

        function removeRefLink() {
            var content = editor.getData();
            content = content.replace(/<a[^>]*>(.+?)<\/a>/gi, '');
            editor.setData(content);
        }

        function changeAllImageWidths() {
            var content = editor.getData();
            var tempDiv = document.createElement('div');
            tempDiv.innerHTML = content;
            var images = tempDiv.getElementsByTagName('img');
            for (var i = 0; i < images.length; i++) {
                images[i].style.width = '200px';
                images[i].style.height = 'auto';
            }
            editor.setData(tempDiv.innerHTML);
            textarea.value = editor.getData();
        }

        function cdnAllImages() {
            var content = editor.getData();
            var tempDiv = document.createElement('div');
            tempDiv.innerHTML = content;
            var images = tempDiv.getElementsByTagName('img');
            for (var i = 0; i < images.length; i++) {
                if (!images[i].src.startsWith('https://i1.wp.com/')) {
                    if (images[i].src.startsWith('https://')) {
                        images[i].src = images[i].src.replace('https://', 'https://i1.wp.com/');
                    }
                }
            }
            editor.setData(tempDiv.innerHTML);
            textarea.value = editor.getData();
        }

        function convertListsToParagraphs() {
            removeHtmlTag();
            let editorData = editor.getData();
            editorData = editorData.replace(/<\/?div[^>]*>/g, '');
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = editorData;
            const lists = tempDiv.querySelectorAll(':scope > ul, :scope > ol');
            function processList(list, level = 0) {
                const items = list.querySelectorAll(':scope > li');
                let numberedParagraphs = '';
                items.forEach((item, index) => {
                    const currentIndex = level > 0 ? level + '.' + (index + 1) : '' + (index + 1);
                    numberedParagraphs += '<p>' + currentIndex + '. ' + item.innerHTML.trim() + '</p>';
                    const nestedLists = item.querySelectorAll(':scope > ul, :scope > ol');
                    nestedLists.forEach(nestedList => {
                        numberedParagraphs += processList(nestedList, currentIndex);
                    });
                });
                const clearHtml = document.createElement('div');
                clearHtml.innerHTML = numberedParagraphs;
                const removeLists = clearHtml.querySelectorAll(':scope > ul, :scope > ol');
                removeLists.forEach((newlist) => { newlist.remove(); });
                Array.from(clearHtml.childNodes).forEach((node) => {
                    if (node.nodeType === Node.TEXT_NODE && node.nodeValue.trim() === '') node.remove();
                });
                clearHtml.querySelectorAll(':scope > *:empty').forEach((emptyElement) => { emptyElement.remove(); });
                return clearHtml.innerHTML;
            }
            lists.forEach(list => {
                const numberedParagraphs = processList(list);
                list.insertAdjacentHTML('afterend', numberedParagraphs);
                list.remove();
            });
            editor.setData(tempDiv.innerHTML);
        }

        function wireTool(buttonId, fn) {
            var el = document.getElementById(buttonId);
            if (el) el.addEventListener('click', fn);
        }
        wireTool('removeBlankLines', removeBlankLines);
        wireTool('resizeImagesButton', changeAllImageWidths);
        wireTool('cdnImagesButton', cdnAllImages);
        wireTool('list2number', convertListsToParagraphs);
        wireTool('removeRefLink', removeRefLink);
        wireTool('removeHtmlTag', removeHtmlTag);
    }).catch(function (e) {
        console.error('CKEditor import failed:', e);
    });
})();
</script>
</div>
