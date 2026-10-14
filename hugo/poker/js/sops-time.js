/* 时间显示统一口径（唯一实现，由 layout_head.php 在页面最前加载）
   存储一律按 UTC（后端 date.timezone=UTC / ISO8601 带 Z），显示按【系统管理 → 显示时区】
   （app_configs.display_timezone，layout_head 注入 window.sopsTimeZone，默认 Asia/Shanghai 北京时间）
   换算；未配置/非法时区降级为浏览器本地时区；纯日期（YYYY-MM-DD、YYYYMMDD）无时区语义，原样返回。
   为何放 head 而非 siteops.js：bootstrap-table 初始化与首屏渲染发生在页面中部内联脚本阶段，
   而 siteops.js 在 layout_tail 才加载——若此时函数未定义，formatter 抛
   ReferenceError: sopsFormatTime is not defined，表格会一直停在 Loading, Please wait。 */
window.sopsTimeZone = typeof window.sopsTimeZone === 'string' ? window.sopsTimeZone : '';
var sopsTimeZoneParsed = false;
var sopsResolvedTimeZone = undefined; // undefined = 不限定时区（浏览器本地）

function sopsIsValidTimeZone(tz) {
    if (!tz) return false;
    try {
        // Intl 对非法 IANA 标识抛 RangeError
        new Date().toLocaleString('en-US', { timeZone: tz });
        return true;
    } catch (e) {
        return false;
    }
}

function sopsGetTimeZone() {
    if (!sopsTimeZoneParsed) {
        var tz = String(window.sopsTimeZone || '').trim();
        sopsResolvedTimeZone = sopsIsValidTimeZone(tz) ? tz : undefined;
        sopsTimeZoneParsed = true;
    }
    return sopsResolvedTimeZone;
}

/* 运行时切换时区（如配置页保存后即时预览）；传空/非法值回落浏览器本地 */
window.sopsSetTimeZone = function (tz) {
    window.sopsTimeZone = typeof tz === 'string' ? tz : '';
    sopsTimeZoneParsed = false;
};

window.sopsFormatTime = function (value) {
    if (value === null || value === undefined) return '';
    var s = String(value).trim();
    if (s === '') return s;
    if (/^\d{4}-\d{2}-\d{2}$/.test(s) || /^\d{8}$/.test(s)) return s;
    var d = null;
    if (/^\d{10}/.test(s)) {
        d = new Date(parseInt(s.slice(0, 10), 10) * 1000);
    } else {
        var iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(s) ? s.replace(' ', 'T') + 'Z' : s;
        d = new Date(iso);
    }
    if (!d || isNaN(d.getTime())) return value;
    // 只切换时区，locale 格式偏好仍交给浏览器
    var opts = {};
    var tz = sopsGetTimeZone();
    if (tz) opts.timeZone = tz;
    return d.toLocaleString(undefined, opts);
};
