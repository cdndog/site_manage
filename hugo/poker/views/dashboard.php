<?php
$stats = isset($stats) && is_array($stats) ? $stats : [];
$summary = isset($stats['summary']) && is_array($stats['summary']) ? $stats['summary'] : [];
$sites = isset($stats['sites']) && is_array($stats['sites']) ? $stats['sites'] : [];
$topics = isset($stats['topics']) && is_array($stats['topics']) ? $stats['topics'] : [];
$keywords = isset($stats['keywords']) && is_array($stats['keywords']) ? $stats['keywords'] : [];
$articles = isset($stats['articles']) && is_array($stats['articles']) ? $stats['articles'] : [];
$siteEnable = isset($sites['by_status']['enable']) ? (int)$sites['by_status']['enable'] : 0;
$topicAidone = isset($topics['by_status']['aidone']) ? (int)$topics['by_status']['aidone'] : 0;
$topicEnable = isset($topics['by_status']['enable']) ? (int)$topics['by_status']['enable'] : 0;
$kwEnable = isset($keywords['by_status']['enable']) ? (int)$keywords['by_status']['enable'] : 0;
$articlePublished = isset($articles['by_publish']['已发布']) ? (int)$articles['by_publish']['已发布'] : 0;
$newcomers = isset($stats['newcomers']) && is_array($stats['newcomers']) ? $stats['newcomers'] : [];
$deltaOf = function ($name) use ($newcomers) {
    $box = isset($newcomers[$name]) && is_array($newcomers[$name]) ? $newcomers[$name] : [];
    return [
        ['key' => 'new_' . $name . '_today', 'label' => '当日', 'value' => (string)(int)($box['today'] ?? 0)],
        ['key' => 'new_' . $name . '_week', 'label' => '本周', 'value' => (string)(int)($box['week'] ?? 0)],
        ['key' => 'new_' . $name . '_month', 'label' => '本月', 'value' => (string)(int)($box['month'] ?? 0)],
    ];
};
$kpis = [
    ['key' => 'sites', 'label' => '站点总数', 'value' => (string)(int)($summary['sites'] ?? 0), 'sub' => '启用 ' . $siteEnable, 'icon' => 'bi-globe2', 'href' => 'seo_report.php?reporttype=sitelist', 'deltas' => $deltaOf('sites')],
    ['key' => 'topics', 'label' => '话题总数', 'value' => (string)(int)($summary['topics'] ?? 0), 'sub' => 'aidone ' . $topicAidone . ' · 启用 ' . $topicEnable, 'icon' => 'bi-journal-text', 'href' => 'topiclist.php', 'deltas' => $deltaOf('topics')],
    ['key' => 'keywords', 'label' => '关键词总数', 'value' => (string)(int)($summary['keywords'] ?? 0), 'sub' => '启用 ' . $kwEnable, 'icon' => 'bi-search', 'href' => 'seo_report.php?reporttype=wordlist', 'deltas' => $deltaOf('keywords')],
    ['key' => 'articles', 'label' => '文章总数', 'value' => (string)(int)($summary['articles'] ?? 0), 'sub' => '已发布 ' . $articlePublished, 'icon' => 'bi-file-earmark-text', 'href' => 'article_list.php', 'deltas' => $deltaOf('articles')],
];
$statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
?>
<style>
  /* 图表配色基准色：整套图表配色由这一个颜色按亮度派生（见 JS rampColors）。
     想换成侧边栏图标默认灰蓝，把下行改为 #96a0b5 即可 */
  .dash-page { --sops-chart-base: #6f6af8; }
  /* 图标配色跟随侧边栏 menu-icon：默认 --sops-sidebar-icon，hover 变 --sops-indigo */
  .dash-page .bi { color: var(--sops-sidebar-icon); transition: color .15s ease; }
  .dash-page a:hover .bi,
  .dash-page button:hover .bi,
  .dash-page .card:hover .bi { color: var(--sops-indigo); }
  /* 按钮内图标 hover 跟随按钮文字色（btn-outline-* hover 均为 #fff），不再被上面的 indigo 规则盖住 */
  .dash-page .btn:hover .bi { color: #fff; }
  .dash-kpi { border: 0; box-shadow: none; }
  .dash-kpi .card-body { padding: 1rem; }
  .dash-kpi .dash-kpi-icon { font-size: 1.4rem; line-height: 1; }
  /* KPI 总数值取主题正文色；增量数字（当日/本周/本月）保留图表色阶首色 */
  .dash-kpi .dash-kpi-value { font-size: 1.9rem; font-weight: 600; line-height: 1.1; font-variant-numeric: tabular-nums; color: var(--sops-text); }
  .dash-kpi .dash-kpi-deltas { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; margin-top: auto; padding-top: .6rem; border-top: 1px dashed var(--sops-border); }
  .dash-kpi .dash-kpi-delta { display: flex; flex-direction: column; gap: .1rem; }
  .dash-kpi .dash-kpi-delta-label { font-size: .7rem; color: var(--sops-muted); }
  .dash-kpi .dash-kpi-delta-value { font-size: 1.35rem; font-weight: 600; line-height: 1.05; font-variant-numeric: tabular-nums; color: var(--sops-chart-base); }
  .dash-kpi .dash-kpi-label { font-size: .8rem; color: var(--sops-muted); }
  .dash-kpi .dash-kpi-sub { font-size: .75rem; color: var(--sops-muted); }
  .dash-chart { min-height: 280px; }
  .dash-chart-empty { display: flex; align-items: center; justify-content: center; min-height: 240px; color: var(--sops-muted); font-size: .85rem; }
  .dash-fallback-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: .8rem; }
  .dash-fallback-label { width: 96px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--sops-text); }
  .dash-fallback-track { flex: 1; height: 10px; background: var(--sops-border); border-radius: 5px; overflow: hidden; }
  .dash-fallback-track span { display: block; height: 100%; background: var(--sops-chart-base); }
  .dash-fallback-value { width: 40px; text-align: right; color: var(--sops-muted); font-variant-numeric: tabular-nums; }
  .dash-num { font-variant-numeric: tabular-nums; }
  .dash-chart-card .card-header { font-size: .85rem; font-weight: 600; }
  /* 表格：表内横向滚动（防整页被宽表撑出横向滚动条），列宽改由 class 控制以便小屏收窄 */
  .dash-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .dash-table-wrap .table { margin-bottom: 0; }
  .dash-cell-site { max-width: 160px; }
  .dash-cell-domain { max-width: 160px; }
  .dash-cell-keyword { max-width: 150px; }
  .dash-cell-time { white-space: nowrap; }
  /* 移动端：收窄留白、缩小字号与图表高度（KPI 2 列窄卡 ~160px，过大字号会挤压换行） */
  @media (max-width: 576px) {
    .sops-main .app-page.dash-page { padding-left: .75rem; padding-right: .75rem; }
    .dash-page .card-header { padding: .6rem .75rem; }
    .dash-page .page-title { font-size: .95rem; }
    .dash-kpi .card-body { padding: .65rem .7rem; }
    .dash-kpi .dash-kpi-icon { font-size: 1.15rem; }
    .dash-kpi .dash-kpi-label { font-size: .7rem; }
    .dash-kpi .dash-kpi-value { font-size: 1.35rem; }
    .dash-kpi .dash-kpi-sub { font-size: .66rem; }
    .dash-kpi .dash-kpi-deltas { gap: .3rem; padding-top: .45rem; }
    .dash-kpi .dash-kpi-delta-label { font-size: .62rem; }
    .dash-kpi .dash-kpi-delta-value { font-size: .98rem; }
    .dash-chart { min-height: 220px; }
    .dash-cell-site, .dash-cell-domain, .dash-cell-keyword { max-width: 104px; }
    .dash-table-wrap .table { font-size: .78rem; }
  }
</style>

<div class="container app-page dash-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-graph-up mr-2" aria-hidden="true"></i>数据看板</h1>
      <div class="d-flex align-items-center" style="gap:6px;flex-wrap:wrap;">
        <span class="text-muted small mr-2" id="dashGeneratedAt">数据更新于 <?php echo e($stats['generated_at'] ?? ''); ?></span>
        <a class="btn btn-sm btn-outline-secondary" href="seo_report.php?reporttype=sitelist"><i class="bi bi-globe mr-1" aria-hidden="true"></i>站点列表</a>
        <a class="btn btn-sm btn-outline-secondary" href="topiclist.php"><i class="bi bi-journal-richtext mr-1" aria-hidden="true"></i>话题列表</a>
        <button type="button" class="btn btn-sm btn-outline-primary" id="dashRefresh" title="重新拉取统计数据"><i class="bi bi-arrow-clockwise mr-1" aria-hidden="true"></i>刷新</button>
      </div>
    </div>
    <div class="card-body" id="dashApp" data-csrf="<?php echo e($csrf_token ?? ''); ?>">

      <div class="row mb-3" id="dashKpis">
        <?php foreach ($kpis as $kpi): ?>
        <div class="col-6 col-lg-3 mb-3">
          <a class="card dash-kpi h-100 text-decoration-none" href="<?php echo e($kpi['href']); ?>">
            <div class="card-body d-flex flex-column">
              <div class="d-flex align-items-center">
                <i class="bi <?php echo e($kpi['icon']); ?> dash-kpi-icon" aria-hidden="true"></i>
                <span class="dash-kpi-label ml-2"><?php echo e($kpi['label']); ?></span>
              </div>
              <div class="dash-kpi-value" data-kpi-value="<?php echo e($kpi['key']); ?>"><?php echo e($kpi['value']); ?></div>
              <div class="dash-kpi-sub" data-kpi-sub="<?php echo e($kpi['key']); ?>"><?php echo e($kpi['sub']); ?></div>
              <div class="dash-kpi-deltas">
                <?php foreach (($kpi['deltas'] ?? []) as $kpiDelta): ?>
                <div class="dash-kpi-delta">
                  <span class="dash-kpi-delta-label"><?php echo e($kpiDelta['label']); ?></span>
                  <span class="dash-kpi-delta-value" data-kpi-value="<?php echo e($kpiDelta['key']); ?>"><?php echo e($kpiDelta['value']); ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </a>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="row">
        <div class="col-lg-6 mb-3">
          <div class="card dash-chart-card">
            <div class="card-header"><i class="bi bi-pie-chart mr-1" aria-hidden="true"></i>站点状态分布</div>
            <div class="card-body"><div id="chartSiteStatus" class="dash-chart"></div></div>
          </div>
        </div>
        <div class="col-lg-6 mb-3">
          <div class="card dash-chart-card">
            <div class="card-header"><i class="bi bi-translate mr-1" aria-hidden="true"></i>站点语言分布</div>
            <div class="card-body"><div id="chartSiteLang" class="dash-chart"></div></div>
          </div>
        </div>
        <div class="col-lg-6 mb-3">
          <div class="card dash-chart-card">
            <div class="card-header"><i class="bi bi-pie-chart mr-1" aria-hidden="true"></i>话题状态分布</div>
            <div class="card-body"><div id="chartTopicStatus" class="dash-chart"></div></div>
          </div>
        </div>
        <div class="col-lg-6 mb-3">
          <div class="card dash-chart-card">
            <div class="card-header"><i class="bi bi-bar-chart mr-1" aria-hidden="true"></i>话题按站点 TOP10</div>
            <div class="card-body"><div id="chartTopicDomain" class="dash-chart"></div></div>
          </div>
        </div>
        <div class="col-lg-6 mb-3">
          <div class="card dash-chart-card">
            <div class="card-header"><i class="bi bi-geo-alt mr-1" aria-hidden="true"></i>关键词地域分布</div>
            <div class="card-body"><div id="chartKeywordGeo" class="dash-chart"></div></div>
          </div>
        </div>
        <div class="col-lg-6 mb-3">
          <div class="card dash-chart-card">
            <div class="card-header"><i class="bi bi-translate mr-1" aria-hidden="true"></i>关键词语言分布</div>
            <div class="card-body"><div id="chartKeywordLang" class="dash-chart"></div></div>
          </div>
        </div>
        <div class="col-lg-6 mb-3">
          <div class="card dash-chart-card">
            <div class="card-header"><i class="bi bi-send-check mr-1" aria-hidden="true"></i>文章发布状态</div>
            <div class="card-body"><div id="chartArticlePublish" class="dash-chart"></div></div>
          </div>
        </div>
        <div class="col-lg-6 mb-3">
          <div class="card dash-chart-card">
            <div class="card-header"><i class="bi bi-calendar3 mr-1" aria-hidden="true"></i>创建 / 发布趋势（按月）</div>
            <div class="card-body"><div id="chartTrend" class="dash-chart"></div></div>
          </div>
        </div>
        <div class="col-lg-6 mb-3">
          <div class="card dash-chart-card">
            <div class="card-header"><i class="bi bi-trophy mr-1" aria-hidden="true"></i>话题数 TOP 站点</div>
            <div class="card-body p-0">
              <div class="table-responsive dash-table-wrap">
              <table class="table table-sm mb-0" id="dashTopSites">
                <thead><tr><th>站点</th><th>域名</th><th class="text-right">话题数</th></tr></thead>
                <tbody>
                  <?php $topSites = isset($stats['top_sites']) && is_array($stats['top_sites']) ? $stats['top_sites'] : []; ?>
                  <?php if (count($topSites) === 0): ?>
                  <tr><td colspan="3" class="text-muted small text-center py-3">暂无数据</td></tr>
                  <?php endif; ?>
                  <?php foreach ($topSites as $row): ?>
                  <tr>
                    <td class="text-truncate dash-cell-site"><?php echo e($row['site_title'] !== '' ? $row['site_title'] : '-'); ?></td>
                    <td class="text-truncate dash-cell-domain"><?php echo e($row['domain']); ?></td>
                    <td class="text-right dash-num"><?php echo e((string)$row['count']); ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-6 mb-3">
          <div class="card dash-chart-card">
            <div class="card-header"><i class="bi bi-clock-history mr-1" aria-hidden="true"></i>最近发布</div>
            <div class="card-body p-0">
              <div class="table-responsive dash-table-wrap">
              <table class="table table-sm mb-0" id="dashRecentPublish">
                <thead><tr><th>关键词</th><th>域名</th><th>语言</th><th>发布时间</th></tr></thead>
                <tbody>
                  <?php $recent = isset($stats['publish']['recent']) && is_array($stats['publish']['recent']) ? $stats['publish']['recent'] : []; ?>
                  <?php if (count($recent) === 0): ?>
                  <tr><td colspan="4" class="text-muted small text-center py-3">暂无数据</td></tr>
                  <?php endif; ?>
                  <?php foreach ($recent as $row): ?>
                  <tr>
                    <td class="text-truncate dash-cell-keyword"><?php echo e($row['keyword'] !== '' ? $row['keyword'] : '-'); ?></td>
                    <td class="text-truncate dash-cell-domain"><?php echo e($row['pubdomain'] !== '' ? $row['pubdomain'] : '-'); ?></td>
                    <td><?php echo e($row['lang']); ?></td>
                    <td class="small text-muted dash-num dash-cell-time"><?php echo e($row['publishAt']); ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <p class="small text-muted mb-0">
        <i class="bi bi-info-circle mr-1" aria-hidden="true"></i>数据维度：站点（状态/语言/主题）、话题（状态/语言/地域/站点/目录）、关键词（状态/语言/地域/站点）、文章（目录/目标域名/语言）、发布（状态/趋势）；新增口径按 站点/话题/关键词/文章 分别统计（当日=今日 0 点起、本周=滚动近 7 天、本月=本月 1 号起）。
      </p>
    </div>
  </div>
</div>

<script type="application/json" id="dash-stats"><?php echo htmlspecialchars($statsJson, ENT_NOQUOTES); ?></script>
<script src="js/apexcharts.min.js"></script>
<script>
(function () {
    var charts = {};

    // 移动端断点（与 CSS @media (max-width:576px) 一致）：图表高度/字号按断点适配
    var mobileQuery = window.matchMedia ? window.matchMedia('(max-width: 576px)') : null;
    function isMobile() {
        if (mobileQuery && typeof mobileQuery.matches === 'boolean') return mobileQuery.matches;
        return (window.innerWidth || 0) <= 576;
    }
    function axisFont() { return isMobile() ? '10px' : '11px'; }

    // 单一基准色派生整套图表配色：色相/饱和度不变，只按亮度逐级递减
    function themeColor(name) {
        var host = document.getElementById('dashApp') || document.documentElement;
        var value = '';
        try {
            value = getComputedStyle(host).getPropertyValue(name);
        } catch (e) {}
        return String(value).trim();
    }

    function chartBaseHex() {
        var value = themeColor('--sops-chart-base');
        return /^#[0-9a-f]{6}$/i.test(value) ? value : '#6f6af8';
    }

    // 图表内文字统一取主题色（与正文/次要文字一致，不出现硬编码灰）
    function chartText() { var v = themeColor('--sops-text'); return v || '#1f2328'; }
    function chartMuted() { var v = themeColor('--sops-muted'); return v || '#6e7681'; }
    function chartBorder() { var v = themeColor('--sops-border'); return v || '#e6e8eb'; }

    // 所有图表共用的非数据配置：浅色网格、浅色提示框、空态色（foreColor 需写在 chart 下，由各图自行带入）
    function chartChrome() {
        return {
            grid: { borderColor: chartBorder() },
            tooltip: { theme: 'light', fillSeriesColor: false, style: { fontSize: '12px' } },
            noData: { text: '暂无数据', style: { color: chartMuted() } }
        };
    }

    function hexToRgb(hex) {
        var m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex).trim());
        if (!m) return { r: 111, g: 106, b: 248 };
        var h = m[1];
        if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
        return { r: parseInt(h.slice(0, 2), 16), g: parseInt(h.slice(2, 4), 16), b: parseInt(h.slice(4, 6), 16) };
    }

    function rgbToHsl(rgb) {
        var r = rgb.r / 255, g = rgb.g / 255, b = rgb.b / 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b);
        var l = (max + min) / 2, h = 0, s = 0;
        if (max !== min) {
            var d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            if (max === r) h = (g - b) / d + (g < b ? 6 : 0);
            else if (max === g) h = (b - r) / d + 2;
            else h = (r - g) / d + 4;
            h *= 60;
        }
        return { h: h, s: s * 100, l: l * 100 };
    }

    function hslToHex(h, s, l) {
        s = Math.max(0, Math.min(100, s)) / 100;
        l = Math.max(0, Math.min(100, l)) / 100;
        var c = (1 - Math.abs(2 * l - 1)) * s;
        var x = c * (1 - Math.abs(((h / 60) % 2) - 1));
        var m = l - c / 2;
        var rgb = [0, 0, 0];
        if (h < 60) rgb = [c, x, 0];
        else if (h < 120) rgb = [x, c, 0];
        else if (h < 180) rgb = [0, c, x];
        else if (h < 240) rgb = [0, x, c];
        else if (h < 300) rgb = [x, 0, c];
        else rgb = [c, 0, x];
        var hex = '';
        for (var i = 0; i < 3; i++) {
            var n = Math.round((rgb[i] + m) * 255);
            n = Math.max(0, Math.min(255, n));
            hex += ('0' + n.toString(16)).slice(-2);
        }
        return '#' + hex;
    }

    // 由基准色生成 count 个颜色：色相/饱和度不变，亮度从基准色逐级变浅（首色=基准色本身）
    // 类目越多步长越小，最高亮度 94%，过渡更自然
    function rampColors(count) {
        var base = rgbToHsl(hexToRgb(chartBaseHex()));
        var top = 94;
        // 类目少时步长收敛（浅色不至于白到看不见），类目多时铺满到浅色端
        var room = Math.max(8, top - base.l);
        var span = Math.min(room, Math.max(14, count * 3.5));
        var out = [];
        for (var i = 0; i < count; i++) {
            var lightness = count <= 1 ? base.l : base.l + (span * i) / (count - 1);
            out.push(hslToHex(base.h, base.s, Math.min(top, lightness)));
        }
        return out;
    }

    function relativeLuminance(rgb) {
        var channel = function (value) {
            value /= 255;
            return value <= 0.03928 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * channel(rgb.r) + 0.7152 * channel(rgb.g) + 0.0722 * channel(rgb.b);
    }

    // 数据标签取白字或主题正文中对比度更高的一方（色块变浅后仍可读，且与页面文字同色）
    function labelColorOf(hex) {
        var lum = relativeLuminance(hexToRgb(hex));
        var dark = chartText();
        var darkLum = relativeLuminance(hexToRgb(dark));
        return (1.05 / (lum + 0.05)) >= ((lum + 0.05) / (darkLum + 0.05)) ? '#ffffff' : dark;
    }

    function labelColorsOf(colors) {
        return colors.map(labelColorOf);
    }

    function esc(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function readStats() {
        var el = document.getElementById('dash-stats');
        try {
            return JSON.parse(el ? el.textContent : '{}');
        } catch (e) {
            return {};
        }
    }

    function pairsOf(map) {
        if (!map) return [];
        return Object.keys(map).map(function (key) {
            return { label: key, value: Number(map[key]) || 0 };
        });
    }

    function pick(stats, group) {
        return (stats && stats[group]) ? stats[group] : {};
    }

    function destroy(id) {
        if (charts[id]) {
            try { charts[id].destroy(); } catch (e) {}
            delete charts[id];
        }
    }

    function emptyBox(el) {
        el.innerHTML = '<div class="dash-chart-empty">暂无数据</div>';
    }

    function fallback(el, pairs) {
        if (pairs.length === 0) { emptyBox(el); return; }
        var max = 1;
        pairs.forEach(function (p) { if (p.value > max) max = p.value; });
        var ramp = rampColors(pairs.length);
        el.innerHTML = pairs.map(function (p, index) {
            return '<div class="dash-fallback-row">' +
                '<span class="dash-fallback-label" title="' + esc(p.label) + '">' + esc(p.label) + '</span>' +
                '<span class="dash-fallback-track"><span style="width:' + Math.round(p.value / max * 100) + '%;background:' + ramp[index] + '"></span></span>' +
                '<span class="dash-fallback-value">' + p.value + '</span>' +
                '</div>';
        }).join('');
    }

    function drawDonut(id, map) {
        var el = document.getElementById(id);
        if (!el) return;
        destroy(id);
        var pairs = pairsOf(map);
        if (typeof ApexCharts === 'undefined') { fallback(el, pairs); return; }
        if (pairs.length === 0) { emptyBox(el); return; }
        var ramp = rampColors(pairs.length);
        charts[id] = new ApexCharts(el, Object.assign({
            chart: { type: 'donut', height: (isMobile() ? 248 : 280) + (pairs.length > 4 ? (isMobile() ? 24 : 36) : 0), toolbar: { show: false }, foreColor: chartText() },
            series: pairs.map(function (p) { return p.value; }),
            labels: pairs.map(function (p) { return p.label; }),
            colors: ramp,
            // 指标移出色块：扇区内不再压字，数值与占比放到图例，合计放到中心孔
            dataLabels: { enabled: false },
            plotOptions: { pie: { donut: { size: '68%', labels: {
                show: true,
                name: { show: true, color: chartMuted(), fontSize: '12px', fontWeight: 600, offsetY: -8 },
                value: { show: true, color: chartText(), fontSize: '24px', fontWeight: 600, offsetY: 14 },
                total: { show: true, label: '合计', color: chartMuted(), fontSize: '12px', fontWeight: 600, formatter: function (w) {
                    var series = (w && w.globals && w.globals.seriesTotals) || (w && w.globals && w.globals.series) || [];
                    return series.reduce(function (sum, v) { return sum + (Number(v) || 0); }, 0);
                } }
            } } } },
            legend: {
                position: 'bottom',
                fontSize: isMobile() ? '11px' : '12px',
                labels: { colors: chartText() },
                formatter: function (label, opts) {
                    var series = (opts && opts.w && opts.w.globals && opts.w.globals.series) || [];
                    var value = Number(series[opts.seriesIndex]) || 0;
                    var total = series.reduce(function (sum, v) { return sum + (Number(v) || 0); }, 0);
                    var pct = total > 0 ? Math.round(value / total * 100) : 0;
                    return label + ' · ' + value + '（' + pct + '%）';
                }
            }
        }, chartChrome()));
        charts[id].render();
    }

    function drawBar(id, map) {
        var el = document.getElementById(id);
        if (!el) return;
        destroy(id);
        var pairs = pairsOf(map);
        if (typeof ApexCharts === 'undefined') { fallback(el, pairs); return; }
        if (pairs.length === 0) { emptyBox(el); return; }
        var barRamp = rampColors(pairs.length);
        var muted = chartMuted();
        charts[id] = new ApexCharts(el, Object.assign({
            chart: { type: 'bar', height: isMobile() && pairs.length > 6 ? 360 : 300, toolbar: { show: false }, foreColor: chartText() },
            series: [{ name: '数量', data: pairs.map(function (p) { return p.value; }) }],
            colors: barRamp,
            plotOptions: { bar: { horizontal: true, barHeight: '60%', distributed: true } },
            // Apex 横向条形图的数值只能落在条内（offsetX 对 bar 强制为 0、position 仅对纵向生效），
            // 因此保留条内数值，颜色按对比度取白字或主题正文字
            dataLabels: { enabled: true, style: { colors: labelColorsOf(barRamp) } },
            legend: { show: false },
            xaxis: {
                categories: pairs.map(function (p) { return p.label; }),
                axisBorder: { color: chartBorder() },
                axisTicks: { color: chartBorder() },
                labels: { style: { colors: muted, fontSize: axisFont() } }
            },
            // 移动端 y 轴长域名标签限宽（防绘图区被挤没），字号同步缩小
            yaxis: { labels: { maxWidth: isMobile() ? 96 : 160, style: { colors: muted, fontSize: axisFont() } } }
        }, chartChrome()));
        charts[id].render();
    }

    function drawTrend(id, trend) {
        var el = document.getElementById(id);
        if (!el) return;
        destroy(id);
        trend = trend || {};
        var months = trend.months || [];
        if (typeof ApexCharts === 'undefined') {
            fallback(el, months.map(function (m, i) {
                return { label: m, value: (trend.created || [])[i] || 0 };
            }));
            return;
        }
        if (months.length === 0) { emptyBox(el); return; }
        charts[id] = new ApexCharts(el, Object.assign({
            chart: { type: 'area', height: isMobile() ? 260 : 300, toolbar: { show: false }, zoom: { enabled: false }, foreColor: chartText() },
            series: [
                { name: '文章创建', data: trend.created || [] },
                { name: '发布', data: trend.published || [] }
            ],
            colors: rampColors(2),
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2 },
            // 只有一个月数据时折线/面积画不出来，靠数据点标记把数值显示出来
            markers: { size: months.length > 1 ? 4 : 7, strokeWidth: 2, strokeColors: '#ffffff', hover: { size: 7 } },
            fill: { type: 'solid' },
            legend: { position: 'bottom', fontSize: isMobile() ? '11px' : '12px', labels: { colors: chartText() } },
            xaxis: {
                categories: months,
                axisBorder: { color: chartBorder() },
                axisTicks: { color: chartBorder() },
                labels: { style: { colors: chartMuted(), fontSize: axisFont() } }
            },
            yaxis: { decimalsInFloat: 0, labels: { style: { colors: chartMuted(), fontSize: '11px' } } }
        }, chartChrome()));
        charts[id].render();
    }

    function setText(selector, text) {
        var el = document.querySelector(selector);
        if (el) el.textContent = String(text);
    }

    function renderKpis(stats) {
        var s = stats.summary || {};
        setText('[data-kpi-value="sites"]', Number(s.sites) || 0);
        setText('[data-kpi-value="topics"]', Number(s.topics) || 0);
        setText('[data-kpi-value="keywords"]', Number(s.keywords) || 0);
        setText('[data-kpi-value="articles"]', Number(s.articles) || 0);
        setText('[data-kpi-sub="sites"]', '启用 ' + (Number(pick(stats, 'sites').by_status.enable) || 0));
        var topicStatus = pick(stats, 'topics').by_status || {};
        setText('[data-kpi-sub="topics"]', 'aidone ' + (Number(topicStatus.aidone) || 0) + ' · 启用 ' + (Number(topicStatus.enable) || 0));
        setText('[data-kpi-sub="keywords"]', '启用 ' + (Number(pick(stats, 'keywords').by_status.enable) || 0));
        var articlePublish = pick(stats, 'articles').by_publish || {};
        setText('[data-kpi-sub="articles"]', '已发布 ' + (Number(articlePublish['已发布']) || 0));
        var nc = pick(stats, 'newcomers') || {};
        ['sites', 'topics', 'keywords', 'articles'].forEach(function (name) {
            var box = nc[name] || {};
            setText('[data-kpi-value="new_' + name + '_today"]', Number(box.today) || 0);
            setText('[data-kpi-value="new_' + name + '_week"]', Number(box.week) || 0);
            setText('[data-kpi-value="new_' + name + '_month"]', Number(box.month) || 0);
        });
        setText('#dashGeneratedAt', '数据更新于 ' + (stats.generated_at || ''));
    }

    function rowHtml(cells) {
        return '<tr>' + cells.map(function (cell) {
            return '<td' + (cell.align ? ' class="' + cell.align + '"' : '') + '>' + esc(cell.text) + '</td>';
        }).join('') + '</tr>';
    }

    function renderTables(stats) {
        var topBody = document.querySelector('#dashTopSites tbody');
        var topSites = stats.top_sites || [];
        if (topBody) {
            topBody.innerHTML = topSites.length === 0
                ? '<tr><td colspan="3" class="text-muted small text-center py-3">暂无数据</td></tr>'
                : topSites.map(function (row) {
                    return rowHtml([
                        { text: row.site_title || '-', align: 'text-truncate dash-cell-site' },
                        { text: row.domain || '-', align: 'text-truncate dash-cell-domain' },
                        { text: row.count, align: 'text-right dash-num' }
                    ]);
                }).join('');
        }
        var recentBody = document.querySelector('#dashRecentPublish tbody');
        var recent = (stats.publish || {}).recent || [];
        if (recentBody) {
            recentBody.innerHTML = recent.length === 0
                ? '<tr><td colspan="4" class="text-muted small text-center py-3">暂无数据</td></tr>'
                : recent.map(function (row) {
                    return rowHtml([
                        { text: row.keyword || '-', align: 'text-truncate dash-cell-keyword' },
                        { text: row.pubdomain || '-', align: 'text-truncate dash-cell-domain' },
                        { text: row.lang || '' },
                        { text: typeof window.sopsFormatTime === 'function' ? window.sopsFormatTime(row.publishAt || '') : String(row.publishAt || ''), align: 'small text-muted dash-num dash-cell-time' }
                    ]);
                }).join('');
        }
    }

    function renderAll(stats) {
        renderKpis(stats);
        drawDonut('chartSiteStatus', pick(stats, 'sites').by_status);
        drawBar('chartSiteLang', pick(stats, 'sites').by_language);
        drawDonut('chartTopicStatus', pick(stats, 'topics').by_status);
        drawBar('chartTopicDomain', pick(stats, 'topics').by_domain);
        drawBar('chartKeywordGeo', pick(stats, 'keywords').by_geo);
        drawDonut('chartKeywordLang', pick(stats, 'keywords').by_lang);
        drawDonut('chartArticlePublish', pick(stats, 'articles').by_publish);
        drawTrend('chartTrend', stats.trend);
        renderTables(stats);
    }

    function refresh() {
        var btn = document.getElementById('dashRefresh');
        var app = document.getElementById('dashApp');
        if (btn) btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'stats');
        fd.append('csrf_token', app ? app.getAttribute('data-csrf') : '');
        fetch(location.pathname, { method: 'POST', body: fd })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.ok && data.stats) {
                    renderAll(data.stats);
                    if (window.sopsToast) sopsToast('看板数据已刷新', 'success');
                } else if (window.sopsToast) {
                    sopsToast((data && data.message) || '刷新失败', 'warning');
                }
            })
            .catch(function () {
                if (window.sopsToast) sopsToast('刷新失败', 'warning');
            })
            .then(function () {
                if (btn) btn.disabled = false;
            });
    }

    window.dashRefresh = refresh;
    document.addEventListener('DOMContentLoaded', function () {
        renderAll(readStats());
        var btn = document.getElementById('dashRefresh');
        if (btn) btn.addEventListener('click', refresh);
        // 屏宽跨断点（手机横竖屏/窗口缩放）后按新断点重绘图表与表格
        var resizeTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () { renderAll(readStats()); }, 300);
        });
    });
})();
</script>
