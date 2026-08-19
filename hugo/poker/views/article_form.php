<div class="container app-page" id="main">
<div class="card card-sops">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h1 class="h5 mb-0 page-title"><i class="bi bi-file-earmark-text mr-2" aria-hidden="true"></i><?php echo isset($form['ctx_id']) && isset($form['title']) && $form['title'] !== '' ? '文章编辑' : '新建文章'; ?></h1>
    <span class="badge badge-info"><i class="bi bi-info-circle mr-1" aria-hidden="true"></i>提交后写入 article 表并保存 json/{uuid}.json</span>
  </div>
  <div class="card-body">
<form action="<?php echo e(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '/article_new.php'); ?>" method="post" id="wechatpost" target="_self" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
    <input type="hidden" id="post_uuid" name="post_uuid" value="<?php echo e($form['ctx_id']); ?>">
    <input type="text" class="form-control sr-readonly" id="setupNum" name="setupNum" size="60" value="ckeditorFormated" readonly style="display: none;">
    <div class="form-group">
        <label class="badge badge-light my-2" for="post_url"><i class="bi bi-link-45deg mr-1" aria-hidden="true"></i>原文链接：<b class="text-muted">（文章参考链接，没有引用的原文，请不要修改！）</b></label>
        <input type="text" class="form-control form-control-sm" id="post_url" name="post_url" value="<?php echo e($form['url'] !== '' ? $form['url'] : $form['ctx_id']); ?>">

        <label class="badge badge-light my-2" for="post_title"><i class="bi bi-type mr-1" aria-hidden="true"></i>文章标题：<b class="text-muted">（标题包含网站关键词，文章排名更好！）</b></label>
        <input type="text" class="form-control form-control-sm" id="post_title" name="post_title" placeholder="文章标题，字数控制在80以内" value="<?php echo e($form['title']); ?>" required>

        <label for="post_static_thumbnail" class="badge badge-light my-2"><i class="bi bi-image mr-1" aria-hidden="true"></i>封面图片：<b class="text-muted">（文章封面）支持直接粘贴</b></label>
        <div class="input-group mb-3">
          <input
            required
            type="text"
            class="form-control form-control-sm"
            id="post_static_thumbnail"
            name="post_static_thumbnail"
            placeholder="文生图可以留空，图生图请上传链接"
            aria-label="图片链接"
            value="<?php echo e($form['static_thumbnail']); ?>"
          >
          <div class="input-group-append">
            <input type="file" id="imageFileInput" accept="image/*" style="display: none;" />
            <button class="btn btn-outline-primary btn-sm" type="button" id="previewImageTrigger"><i class="bi bi-eye mr-1" aria-hidden="true"></i>预览</button>
            <button class="btn btn-primary btn-sm" type="button" id="imagesource">
              <i class="bi bi-upload mr-1" aria-hidden="true"></i>上传
            </button>
          </div>
        </div>
        <label class="badge badge-light my-2" for="post_iframesrc"><i class="bi bi-film mr-1" aria-hidden="true"></i>动图视频：<b class="text-muted">支持格式：Gif/Video</b></label>
        <input type="text" class="form-control form-control-sm" id="post_iframesrc" name="post_iframesrc" value="<?php echo e($form['iframesrc']); ?>" placeholder="文章的动图或视频外链接网址，只支持外部链接">
        <div class="row">
            <div class="col-md-6 col-xs-12">
            <label class="badge badge-light my-2" for="post_tag"><i class="bi bi-tags mr-1" aria-hidden="true"></i>文章标签：</label>
            <input type="text" class="form-control form-control-sm" id="post_tag" name="post_tag" placeholder="多个词，请用英文逗号(,)分隔" value="<?php echo e($form['tags']); ?>" required>
            </div>
            <div class="col-md-6 col-xs-12">
            <label class="badge badge-light my-2" for="post_keyword"><i class="bi bi-keyboard mr-1" aria-hidden="true"></i>关键词：<b class="text-muted">（关键词用于文章内链）</b></label>
            <input type="text" class="form-control form-control-sm" id="post_keyword" name="post_keyword" placeholder="多个词，请用英文逗号(,)分隔" value="<?php echo e($form['keyword']); ?>">
            </div>
        </div>

        <label class="badge badge-light my-2" for="post_description"><i class="bi bi-file-text mr-1" aria-hidden="true"></i>概要总结：</label>
        <input type="text" class="form-control form-control-sm" id="post_description" name="post_description" placeholder="输入文章概要总结，字数控制在120左右" value="<?php echo e($form['description']); ?>" required>
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
          <label class="badge badge-light my-2" for="post_lang"><i class="bi bi-translate mr-1" aria-hidden="true"></i>语言：<b class="text-muted">（撰写文章使用的语言-国家/地区）</b></label>
          <select class="selectpicker form-control form-control-sm" id="post_lang" name="post_lang" data-show-subtext="true" data-live-search="true">
            <?php foreach ((isset($config['languages']) && is_array($config['languages'])) ? $config['languages'] : [] as $label => $value): ?>
            <option data-subtext="<?php echo e($label); ?>"<?php echo strtolower($value) === strtolower($form['lang']) ? ' selected' : ''; ?>><?php echo e($value); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label class="badge badge-light my-2" for="post_series"><i class="bi bi-folder mr-1" aria-hidden="true"></i>保存栏目：<b class="text-muted">（用于网站后台归类）</b></label>
          <select class="selectpicker form-control form-control-sm" id="post_series" name="post_series" data-show-subtext="true" data-live-search="true">
            <?php foreach ((isset($config['series']) && is_array($config['series'])) ? $config['series'] : [] as $label => $value): ?>
            <option data-subtext="<?php echo e($label); ?>"<?php echo strtolower($value) === strtolower($form['series']) ? ' selected' : ''; ?>><?php echo e($value); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label class="badge badge-light my-2" for="post_pubdir"><i class="bi bi-folder-open mr-1" aria-hidden="true"></i>保存目录：<b class="text-muted">（文章发布到的网站目录）</b></label>
          <select class="selectpicker form-control form-control-sm" id="post_pubdir" name="post_pubdir" data-show-subtext="true" data-live-search="true">
            <?php foreach ((isset($config['pubdir']) && is_array($config['pubdir'])) ? $config['pubdir'] : [] as $label => $value): ?>
            <option data-subtext="<?php echo e($label); ?>"<?php echo strtolower($value) === strtolower($form['pubdir']) ? ' selected' : ''; ?>><?php echo e($value); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label class="badge badge-light my-2" for="post_savename"><i class="bi bi-file-earmark-text mr-1" aria-hidden="true"></i>保存名称：<b class="text-muted">（文章url名称SEO优化，可选项）</b></label>
          <input type="text" class="form-control form-control-sm" id="post_savename" name="post_savename" placeholder="示例：post-nice-name" value="<?php echo e($form['savename']); ?>">
        </div>
      </div>
    </div>
    <div class="form-group">
        <div class="row">
            <div class="col-md-12 col-xs-12">
            <label class="badge badge-light my-2" for="post_pubdomain"><i class="bi bi-link-45deg mr-1" aria-hidden="true"></i>关联站点：<b class="text-muted">(将内容发布到选定域名（支持多选），留空默认不发布，选择global发布到列表中所有网站)</b></label>
            <div class="input-group mb-3">
                <select class="selectpicker form-control form-control-sm" id="post_pubdomain" name="post_pubdomain[]" data-show-subtext="true" data-live-search="true" multiple>
                <option data-subtext="global"<?php if (in_array(strtolower('global'), array_map('strtolower', (array)$form['pubdomain']), true)) { echo ' selected'; } ?>>global</option>
                <?php foreach ($sites as $site): ?>
                <option data-subtext="<?php echo e(isset($site['domain']) ? $site['domain'] . '(' . (isset($site['languages']) ? $site['languages'] : '') . ')' : ''); ?>"<?php if (in_array(strtolower(isset($site['domain']) ? $site['domain'] : ''), array_map('strtolower', (array)$form['pubdomain']), true)) { echo ' selected'; } ?>><?php echo e(isset($site['domain']) ? $site['domain'] : ''); ?></option>
                <?php endforeach; ?>
                </select>
              <div class="input-group-append">
                <button class="btn btn-sm btn-warning" type="button" id="reloadSitesBtn" title="刷新列表">
                  <i class="bi bi-arrow-repeat mr-1" aria-hidden="true"></i>刷新列表
                </button>
              </div>
            </div>
            </div>
        </div>
    </div>
    <div class="form-group">
        <label class="badge badge-light my-2" for="autopublish"><i class="bi bi-send mr-1" aria-hidden="true"></i>发布文章：<b class="text-muted">(勾选☑️【启用】时，发布脚本调用该内容发布到指定网站；没有勾选时：只保存不发布)</b></label>
        <div class="checkbox mt-2" id="autopublish">
            <input type="checkbox" id="post_globalpublish" name="post_globalpublish" value="yes"<?php if ($form['globalpublish'] === 'yes') { echo ' checked'; } ?>> <e class="badge badge-primary">启用</e>
        </div>
        <label class="badge badge-light my-2" for="autotranslate"><i class="bi bi-globe2 mr-1" aria-hidden="true"></i>自动翻译：<span class="text-muted">(勾选以下国家语言启用翻译语言)</span></label>
        <div class="row m-auto bg-light" id="autotranslate">
            <?php foreach ((isset($config['translateto']) && is_array($config['translateto'])) ? $config['translateto'] : [] as $value => $label): ?>
            <div class="col-xs-12 col-sm-2">
                <div class="checkbox text-truncate">
                    <label>
                        <input type="checkbox"<?php if (in_array($value, (array)$form['translate_to_langs'], true)) { echo ' checked'; } ?> id="<?php echo e('post_translate_to_langs_' . $value); ?>" name="post_translate_to_langs[]" value="<?php echo e(trim($value)); ?>"><span class="badge badge-light"><?php echo e($label); ?></span>
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="d-flex justify-content-end mt-4">
        <button type="submit" class="btn btn-primary px-5" id="submitBtn"><i class="bi bi-check-circle mr-1" aria-hidden="true"></i>提交</button>
    </div>
</form>
  </div>
</div>
</div>

<script type="module">
    import slug from './js/slug.js';

    document.getElementById('post_savename').addEventListener('focusout', function() {
        const input = document.getElementById('post_savename');
        const opts = {
            replacement: '-',
            lower: true,
            trim: true,
            fallback: true
        };
        const parts = input.value.split('/');
        const slugifiedParts = parts.map(part => slug(part, opts));
        input.value = slugifiedParts.join('/');
    });
</script>

<script>
    (function () {
        var form = document.getElementById('wechatpost');
        var csrfToken = form.querySelector('input[name="csrf_token"]').value;

        var imgInput = document.getElementById('post_static_thumbnail');
        var fileInput = document.getElementById('imageFileInput');
        var uploadBtn = document.getElementById('imagesource');

        function setUploadBtn(loading) {
            if (!uploadBtn) return;
            uploadBtn.disabled = loading;
            uploadBtn.innerHTML = loading
                ? '<i class="bi bi-arrow-repeat mr-1" aria-hidden="true"></i>上传中...'
                : '<i class="bi bi-upload mr-1" aria-hidden="true"></i>上传';
        }

        uploadBtn.addEventListener('click', function() {
            if (fileInput) {
                fileInput.value = '';
                fileInput.click();
            }
        });

        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file && file.type.startsWith('image/')) {
                uploadImageToImgBB(file);
            } else if (file) {
                showTip('请选择图片文件（jpg/png/gif/webp 等）。', true);
            }
        });

        document.addEventListener('paste', function(e) {
            var target = e.target;
            if (target && target.closest && target.closest('.ck-editor__editable')) {
                return;
            }
            var items = (e.clipboardData || (e.originalEvent && e.originalEvent.clipboardData) || {}).items;
            if (!items) return;
            for (var i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    var blob = items[i].getAsFile();
                    if (blob) {
                        uploadImageToImgBB(blob);
                        e.preventDefault();
                        break;
                    }
                }
            }
        });

        function uploadImageToImgBB(file) {
            if (file.size > 10 * 1024 * 1024) {
                showTip('图片大小超过 10MB 限制，请压缩后重试。', true);
                return;
            }
            const formData = new FormData();
            formData.append('image', file);
            setUploadBtn(true);
            fetch('imgbb_proxy.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-CSRF-Token': csrfToken }
            })
            .then(response => response.text().then(text => ({ status: response.status, text: text })))
            .then(({ status, text }) => {
                let result = null;
                try {
                    result = JSON.parse(text);
                } catch (e) {
                    result = null;
                }
                if (!result) {
                    showTip('上传失败: 服务器返回异常 (HTTP ' + status + (text ? '): ' + text.replace(/\s+/g, ' ').trim().slice(0, 200) : ')'), true);
                    return;
                }
                if (result.success && result.data && result.data.url) {
                    imgInput.value = result.data.url;
                    imgInput.dispatchEvent(new Event('input', { bubbles: true }));
                    showTip('图片上传成功', false);
                } else {
                    showTip('上传失败: ' + (result.error && result.error.message || '未知错误'), true);
                }
            })
            .catch(error => {
                console.error('Upload error:', error);
                showTip('上传过程中出错，请重试。 (' + error.message + ')', true);
            })
            .finally(() => {
                setUploadBtn(false);
                fileInput.value = '';
            });
        }

        var previewTrigger = document.getElementById('previewImageTrigger');
        if (previewTrigger) {
            previewTrigger.addEventListener('click', function() {
                var imageUrl = document.getElementById('post_static_thumbnail').value.trim();
                if (!imageUrl) return;
                try {
                    new URL(imageUrl);
                } catch (e) {
                    alert('图片链接格式无效');
                    return;
                }
                document.getElementById('modalImage').setAttribute('src', imageUrl);
                var modalEl = document.getElementById('imagePreviewModal');
                if (window.bootstrap && window.bootstrap.Modal) {
                    new window.bootstrap.Modal(modalEl).show();
                } else if (window.jQuery && window.jQuery.fn.modal) {
                    window.jQuery(modalEl).modal('show');
                }
            });
        }

        form.addEventListener('submit', function(event) {
            var submitBtn = document.getElementById('submitBtn');
            if (submitBtn && submitBtn.disabled) {
                event.preventDefault();
                return;
            }
            var textarea = document.getElementById('post_ckeditor_contents');
            if (window.editor) {
                textarea.value = window.editor.getData();
            }
            var requiredInputs = form.querySelectorAll('input[required], select[required]');
            for (var i = 0; i < requiredInputs.length; i++) {
                var input = requiredInputs[i];
                if (!input.value.trim()) {
                    var label = form.querySelector('label[for="' + input.id + '"]');
                    event.preventDefault();
                    alert('Please fill in all required fields. ' + (label ? label.textContent : input.id) + ' is required.');
                    input.focus();
                    return;
                }
            }
            if (!textarea.value.trim()) {
                event.preventDefault();
                alert('Please fill in all required fields. 正文内容 is required.');
                textarea.focus();
                return;
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" aria-hidden="true"></span>提交中…';
            }
        });

        function showTip(message, isError) {
            if (window.jQuery && jQuery.fn.toast && document.getElementById('tipToast')) {
                jQuery('#tipToastTitle').html((isError ? '<i class="bi bi-exclamation-circle mr-1"></i>' : '<i class="bi bi-check-circle mr-1"></i>') + (isError ? '提示' : '成功'));
                jQuery('#tipToastTitle').css('color', isError ? '#dc3545' : '#28a745');
                jQuery('#tipToastMessage').text(message);
                var tipToast = new bootstrap.Toast(document.getElementById('tipToast'), { delay: 3000 });
                tipToast.show();
            } else {
                alert(message);
            }
        }
        window.showTip = showTip;

        function reloadSites() {
            var btn = document.getElementById('reloadSitesBtn');
            if (!btn) return;
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-arrow-repeat mr-1" aria-hidden="true"></i>获取中…';
            var fd = new FormData();
            fd.append('action', 'site_list');
            fd.append('csrf_token', csrfToken);
            fetch(location.pathname, { method: 'POST', body: fd }).then(function(res) {
                return res.json();
            }).then(function(data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-repeat mr-1" aria-hidden="true"></i>刷新列表';
                var list = (data && Array.isArray(data.rows)) ? data.rows : [];
                var failed = data && data.rows && data.rows[0] && data.rows[0].ok === false;
                if (failed || list.length === 0) {
                    showTip(failed ? (data.rows[0].message || 'CSRF 校验失败，请刷新页面后重试。') : '站点列表获取失败，请稍后重试。', true);
                    return;
                }
                var sel = document.getElementById('post_pubdomain');
                var current = Array.prototype.map.call(sel.selectedOptions, function(o) {
                    return o.value.toLowerCase();
                });
                sel.innerHTML = '';
                var g = document.createElement('option');
                g.setAttribute('data-subtext', 'global');
                g.value = 'global';
                g.textContent = 'global';
                sel.appendChild(g);
                list.forEach(function(v) {
                    var o = document.createElement('option');
                    o.setAttribute('data-subtext', (v.domain || '') + '(' + (v.languages || '') + ')');
                    o.value = v.domain;
                    o.textContent = v.domain;
                    sel.appendChild(o);
                });
                Array.prototype.forEach.call(sel.options, function(o) {
                    if (current.indexOf(o.value.toLowerCase()) !== -1) o.selected = true;
                });
                if (window.jQuery && jQuery.fn.selectpicker) {
                    jQuery('#post_pubdomain').selectpicker('refresh');
                }
                showTip('站点列表刷新成功，共 ' + list.length + ' 个站点。', false);
            }).catch(function(e) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-repeat mr-1" aria-hidden="true"></i>刷新列表';
                showTip('站点列表获取失败：' + e, true);
            });
        }
        document.getElementById('reloadSitesBtn').addEventListener('click', reloadSites);

        (function autoRetrySites(attempts) {
            var sel = document.getElementById('post_pubdomain');
            var btn = document.getElementById('reloadSitesBtn');
            if (sel && sel.options.length <= 1 && !(btn && btn.disabled) && attempts > 0) {
                setTimeout(function() { reloadSites(); autoRetrySites(attempts - 1); }, 1500);
            }
        })(3);
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
    var form = document.getElementById('wechatpost');
    if (!textarea || !form) return;

    var csrfToken = form.querySelector('input[name="csrf_token"]').value;

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
