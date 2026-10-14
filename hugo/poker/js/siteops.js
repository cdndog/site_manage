/* 全局 Toast/确认提示组件已移至 js/sops-toast.js（由 layout_tail.php 统一引入） */

/* 时间格式化 helper 已移至 js/sops-time.js（由 layout_head.php 提前加载：
   表格 bootstrap-table 首屏渲染早于本文件，若在此定义会出现
   ReferenceError: sopsFormatTime is not defined → 表格卡 Loading） */

(function () {
    // 侧边栏双模交互：
    //  - 桌面：顶栏按钮切换 body[data-sidebar-size=collapsed] 图标态（记忆 localStorage）
    //  - 移动端(≤991.98px)：离屏抽屉，body[data-mobile-nav=open] 展开；
    //    顶栏按钮/遮罩/Esc/选中菜单项 控制开合；父级菜单仅展开子菜单不跳转
    const toggle = document.getElementById('sidebarToggle');
    const backdrop = document.getElementById('sopsNavBackdrop');
    const mobileNavQuery = window.matchMedia('(max-width: 991.98px)');
    const isMobileNav = function () { return !!mobileNavQuery && mobileNavQuery.matches; };

    function setMobileNav(open) {
        if (open) {
            document.body.setAttribute('data-mobile-nav', 'open');
        } else {
            document.body.removeAttribute('data-mobile-nav');
        }
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? '关闭导航菜单' : '打开导航菜单');
            toggle.title = open ? '关闭导航菜单' : '打开导航菜单';
        }
        if (backdrop) {
            backdrop.hidden = !open;
        }
        if (open) {
            // 当前页激活项滚入抽屉可视区（长菜单不用手动找）
            const active = document.querySelector('.sops-sidebar-link.active');
            if (active && typeof active.scrollIntoView === 'function') {
                try { active.scrollIntoView({ block: 'nearest' }); } catch (e) {}
            }
        }
    }

    if (toggle) {
        try {
            if (localStorage.getItem('sopsSidebarCollapsed') === '1') {
                document.body.setAttribute('data-sidebar-size', 'collapsed');
            }
        } catch (e) {}

        toggle.addEventListener('click', function () {
            if (isMobileNav()) {
                setMobileNav(!document.body.hasAttribute('data-mobile-nav'));
                return;
            }
            const collapsed = document.body.getAttribute('data-sidebar-size') === 'collapsed';
            document.body.setAttribute('data-sidebar-size', collapsed ? 'default' : 'collapsed');
            toggle.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
            toggle.title = collapsed ? '折叠侧边栏' : '展开侧边栏';
            try {
                localStorage.setItem('sopsSidebarCollapsed', collapsed ? '0' : '1');
            } catch (e) {}
        });
    }

    // 移动端初始态：抽屉关闭、按钮 aria 语义为“打开导航”
    if (isMobileNav()) {
        setMobileNav(false);
    }

    if (backdrop) {
        backdrop.addEventListener('click', function () { setMobileNav(false); });
    }

    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Escape' || e.key === 'Esc') && document.body.hasAttribute('data-mobile-nav')) {
            setMobileNav(false);
        }
    });

    document.addEventListener('click', function (e) {
        const target = e && e.target;
        if (!target || typeof target.closest !== 'function') return;
        const parentLink = target.closest('a.sops-sidebar-link-parent');
        if (parentLink && isMobileNav()) {
            // 移动端父级 = 手风琴开关（preventDefault 保留 bootstrap collapse 展开，不跳父级落地页）
            e.preventDefault();
            return;
        }
        if (target.closest('.sops-sidebar-nav a') && isMobileNav() && document.body.hasAttribute('data-mobile-nav')) {
            // 选中子菜单/单项后收起抽屉（随后页面跳转）
            setMobileNav(false);
        }
    });

    // 跨断点（旋转/缩放回桌面）时复位抽屉，避免开着抽屉切回桌面布局错乱
    const onBreakpointChange = function () {
        if (!isMobileNav()) setMobileNav(false);
    };
    if (typeof mobileNavQuery.addEventListener === 'function') {
        mobileNavQuery.addEventListener('change', onBreakpointChange);
    } else if (typeof mobileNavQuery.addListener === 'function') {
        mobileNavQuery.addListener(onBreakpointChange);
    }
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
        if (!imgInput.length) return;
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
                var extra = text ? '): ' + text.replace(/\s+/g, ' ').trim().slice(0, 200) : ')';
                sopsToast('上传失败: 服务器返回异常 (HTTP ' + status + extra, 'danger');
                return;
            }
            if (result.success) {
                imgInput.val(result.data.url);
                imgInput.trigger('input');
            } else {
                sopsToast('上传失败: ' + (result.error && result.error.message || '未知错误'), 'danger');
            }
        })
        .catch(error => {
            console.error('Upload error:', error);
            sopsToast('上传过程中出错，请重试。 (' + error.message + ')', 'danger');
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