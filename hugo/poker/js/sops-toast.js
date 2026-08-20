/**
 * 全局 Toast 提示组件（统一替代 alert/confirm）
 *
 *   sopsToast(message, type)       — 显示 toast，type: info/success/warning/danger
 *   sopsConfirm(message, onConfirm, onCancel) — 显示确认 toast，返回不阻塞
 *
 * 依赖：Bootstrap CSS（已全局加载）。所有页面（含旧版页面）统一引入本文件。
 */
var sopsToast = (function () {
    function ensureContainer() {
        var c = document.getElementById('sopsToastContainer');
        if (!c) {
            c = document.createElement('div');
            c.id = 'sopsToastContainer';
            c.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;min-width:300px;max-width:420px;';
            document.body.appendChild(c);
        }
        return c;
    }
    function show(message, type, duration) {
        type = type || 'info';
        duration = duration || 4000;
        var c = ensureContainer();
        var id = 'toast-' + Date.now() + '-' + Math.random().toString(36).substr(2, 5);
        var bg = type === 'success' ? 'alert-success' : (type === 'warning' ? 'alert-warning' : (type === 'danger' ? 'alert-danger' : 'alert-info'));
        var icon = type === 'success' ? 'bi-check-circle-fill' : (type === 'warning' ? 'bi-exclamation-triangle-fill' : (type === 'danger' ? 'bi-x-circle-fill' : 'bi-info-circle-fill'));
        var html = '<div id="' + id + '" class="alert ' + bg + ' mb-2" style="border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);padding:12px 16px;display:flex;align-items:center;gap:8px;animation:sopsToastIn .25s ease;">'
            + '<i class="bi ' + icon + '" aria-hidden="true"></i>'
            + '<span style="flex:1;font-size:14px;line-height:1.5;">' + String(message).replace(/</g, '&lt;') + '</span>'
            + '<button type="button" style="background:none;border:0;font-size:18px;line-height:1;cursor:pointer;color:inherit;opacity:.5;padding:0 0 0 4px;" onclick="document.getElementById(\'' + id + '\').remove()">&times;</button>'
            + '</div>';
        c.insertAdjacentHTML('beforeend', html);
        var el = document.getElementById(id);
        if (duration > 0) {
            setTimeout(function () {
                if (el && el.parentNode) {
                    el.style.transition = 'opacity .3s,transform .3s';
                    el.style.opacity = '0';
                    el.style.transform = 'translateX(20px)';
                    setTimeout(function () { if (el && el.parentNode) el.remove(); }, 300);
                }
            }, duration);
        }
        return id;
    }
    return function (message, type, duration) { return show(message, type, duration); };
})();

/**
 * 统一确认对话框（替代 confirm()）
 *   sopsConfirm('确定删除？', function () { ... // 确认后执行 }, function () { ... // 可选取消回调 })
 */
function sopsConfirm(message, onConfirm, onCancel) {
    var container = document.getElementById('sopsToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'sopsToastContainer';
        container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;min-width:300px;max-width:420px;';
        document.body.appendChild(container);
    }
    var id = 'confirm-' + Date.now() + '-' + Math.random().toString(36).substr(2, 5);
    var html = '<div id="' + id + '" class="alert alert-warning mb-2" style="border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);padding:12px 16px;animation:sopsToastIn .25s ease;">'
        + '<div style="font-size:14px;line-height:1.5;margin-bottom:10px;">' + String(message).replace(/</g, '&lt;') + '</div>'
        + '<div style="display:flex;gap:8px;justify-content:flex-end;">'
        + '<button type="button" class="btn btn-sm btn-outline-secondary" id="' + id + '-cancel">取消</button>'
        + '<button type="button" class="btn btn-sm btn-danger" id="' + id + '-ok">确认</button>'
        + '</div></div>';
    container.insertAdjacentHTML('beforeend', html);
    var el = document.getElementById(id);
    function close() { if (el && el.parentNode) el.remove(); }
    document.getElementById(id + '-ok').onclick = function () { close(); if (typeof onConfirm === 'function') onConfirm(); };
    document.getElementById(id + '-cancel').onclick = function () { close(); if (typeof onCancel === 'function') onCancel(); };
}

// 全局表单确认拦截：<form data-sops-confirm="提示语"> 提交前用 sopsConfirm 确认
document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || form.tagName !== 'FORM') return;
    var msg = form.getAttribute('data-sops-confirm');
    if (!msg) return;
    ev.preventDefault();
    sopsConfirm(msg, function () {
        form.removeAttribute('data-sops-confirm');
        form.submit();
    });
});

// 全局按钮确认拦截：<a data-sops-confirm="提示语" href="..."> 点击时确认后跳转
document.addEventListener('click', function (ev) {
    var el = ev.target;
    while (el && el !== document) {
        var msg = el.getAttribute ? el.getAttribute('data-sops-confirm') : null;
        if (msg) {
            ev.preventDefault();
            ev.stopPropagation();
            sopsConfirm(msg, function () {
                var href = el.getAttribute('href');
                if (href && href !== '#' && href.indexOf('javascript:') !== 0) {
                    window.location.href = href;
                }
            });
            return;
        }
        el = el.parentNode;
    }
});

// toast 动画 CSS（注入一次）
(function () {
    if (document.getElementById('sopsToastStyle')) return;
    var s = document.createElement('style');
    s.id = 'sopsToastStyle';
    s.textContent = '@keyframes sopsToastIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}';
    document.head.appendChild(s);
})();