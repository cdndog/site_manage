/**
 * 选定站点域名后，异步拉取该站点的顶部菜单(topnav_menus)，
 * 并把菜单项追加为「发布目录/保存目录」下拉的可选项。
 *
 * sopsAttachSiteMenus({
 *   triggerId: 'post_pubdomain', // 关联域名下拉（单选/多选均可，忽略 global）
 *   targetId: 'post_pubdir',     // 目录下拉
 *   csrfToken: csrfToken
 * });
 */
(function (global) {
    function sopsAttachSiteMenus(options) {
        options = options || {};
        var triggerSel = document.getElementById(options.triggerId || 'post_pubdomain');
        var targetSel = document.getElementById(options.targetId || 'post_pubdir');
        if (!triggerSel || !targetSel) {
            return null;
        }
        var csrfToken = options.csrfToken || '';
        var rowsCache = null;
        var rowsPending = null;

        function fetchSiteRows() {
            if (rowsCache) {
                return Promise.resolve(rowsCache);
            }
            if (rowsPending) {
                return rowsPending;
            }
            var fd = new FormData();
            fd.append('action', 'site_list');
            fd.append('csrf_token', csrfToken);
            rowsPending = fetch(location.pathname, { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    rowsCache = (data && Array.isArray(data.rows)) ? data.rows : [];
                    return rowsCache;
                })
                .catch(function () {
                    rowsPending = null;
                    return [];
                });
            return rowsPending;
        }

        function applySiteMenus() {
            var selected = [];
            Array.prototype.forEach.call(triggerSel.selectedOptions, function (option) {
                var value = String(option.value || '');
                if (value !== '' && value.toLowerCase() !== 'global') {
                    selected.push(value);
                }
            });
            if (selected.length === 0) {
                return;
            }
            fetchSiteRows().then(function (rows) {
                var wanted = [];
                selected.forEach(function (domain) {
                    rows.forEach(function (row) {
                        if (String(row.domain || '').toLowerCase() !== domain.toLowerCase()) {
                            return;
                        }
                        String(row.topnav_menus || '').split(',').forEach(function (menu) {
                            menu = menu.trim();
                            if (menu !== '' && wanted.indexOf(menu) === -1) {
                                wanted.push(menu);
                            }
                        });
                    });
                });
                if (wanted.length === 0) {
                    return;
                }
                var exists = {};
                Array.prototype.forEach.call(targetSel.options, function (option) {
                    exists[option.value] = true;
                });
                var added = false;
                wanted.forEach(function (menu) {
                    if (exists[menu]) {
                        return;
                    }
                    var option = document.createElement('option');
                    option.setAttribute('data-subtext', '站点菜单');
                    option.value = menu;
                    option.textContent = menu;
                    targetSel.appendChild(option);
                    added = true;
                });
                if (added && global.jQuery && global.jQuery.fn.selectpicker) {
                    global.jQuery(targetSel).selectpicker('refresh');
                }
            });
        }

        triggerSel.addEventListener('change', applySiteMenus);
        if (triggerSel.selectedOptions.length > 0) {
            applySiteMenus();
        }
        return { refresh: applySiteMenus };
    }

    global.sopsAttachSiteMenus = sopsAttachSiteMenus;
})(window);
