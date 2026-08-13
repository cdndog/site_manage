(function () {
    // 侧边栏折叠（点按钮切换 body[data-sidebar-size]，仅图标态）
    const toggle = document.getElementById('sidebarToggle');
    if (!toggle) return;

    try {
        if (localStorage.getItem('sopsSidebarCollapsed') === '1') {
            document.body.setAttribute('data-sidebar-size', 'collapsed');
        }
    } catch (e) {}

    toggle.addEventListener('click', function () {
        const collapsed = document.body.getAttribute('data-sidebar-size') === 'collapsed';
        document.body.setAttribute('data-sidebar-size', collapsed ? 'default' : 'collapsed');
        toggle.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
        toggle.title = collapsed ? '折叠侧边栏' : '展开侧边栏';
        try {
            localStorage.setItem('sopsSidebarCollapsed', collapsed ? '0' : '1');
        } catch (e) {}
    });
})();

$(document).ready(function() {
    const imgInput = $('#post_sitelogo');
    const fileInput = $('#imageFileInput');
    const uploadBtn = $('#imagesource');
    const csrfToken = $('input[name=csrf_token]').val() || '';

    // 1. Button click → trigger file select
    uploadBtn.on('click', function() {
        fileInput.click();
    });

    // 2. File selected → upload
    fileInput.on('change', function(e) {
        const file = e.target.files[0];
        if (file && file.type.startsWith('image/')) {
            uploadImage(file);
        }
    });

    // 3. Handle paste from clipboard (global listener)
    $(document).on('paste', function(e) {
        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
        if (!items) return;

        for (let i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') !== -1) {
                const blob = items[i].getAsFile();
                if (blob) {
                    uploadImage(blob);
                    e.preventDefault();
                    break;
                }
            }
        }
    });

    // 4. Upload function via server-side proxy
    function uploadImage(file) {
        const formData = new FormData();
        formData.append('image', file);

        uploadBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>上传中...');

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
                alert('上传失败: 服务器返回异常 (HTTP ' + status + (text ? '): ' + text.replace(/\s+/g, ' ').trim().slice(0, 200) : ')'));
                return;
            }
            if (result.success) {
                imgInput.val(result.data.url);
                imgInput.trigger('input');
            } else {
                alert('上传失败: ' + (result.error && result.error.message || '未知错误'));
            }
        })
        .catch(error => {
            console.error('Upload error:', error);
            alert('上传过程中出错，请重试。 (' + error.message + ')');
        })
        .finally(() => {
            uploadBtn.prop('disabled', false).html('<i class="fas fa-upload me-1"></i>上传');
            fileInput.val('');
        });
    }

    // Image preview modal trigger
    $('#previewImageTrigger').on('click', function() {
        const imageUrl = $('#post_sitelogo').val().trim();
        if (!imageUrl) {
            return;
        }

        try {
            new URL(imageUrl);
        } catch (e) {
            alert('图片链接格式无效');
            return;
        }

        $('#modalImage').attr('src', imageUrl);
        $('#imagePreviewModal').modal('show');
    });
});