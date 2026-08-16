<?php
$current = isset($_SERVER['PHP_SELF']) ? basename($_SERVER['PHP_SELF']) : '';
$currentQuery = isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '';
$modules = App\Config::headerModulesFor(App\Support\Security::permissions());
$urlMatches = function ($url) use ($current, $currentQuery) {
    $path = parse_url($url, PHP_URL_PATH);
    if ($current === '' || $path === null || basename($path) !== $current) {
        return false;
    }
    parse_str((string)parse_url($url, PHP_URL_QUERY), $urlParams);
    if (count($urlParams) === 0) {
        return true;
    }
    parse_str($currentQuery, $queryParams);
    foreach ($urlParams as $key => $value) {
        if (!isset($queryParams[$key]) || (string)$queryParams[$key] !== (string)$value) {
            return false;
        }
    }
    return true;
};
$crumbs = [];
foreach ($modules as $module) {
    $moduleUrl = isset($module['url']) ? (string)$module['url'] : '#';
    $children = isset($module['children']) && is_array($module['children']) ? $module['children'] : [];
    if (count($children) > 0) {
        $matchedChild = null;
        foreach ($children as $child) {
            if ($urlMatches(isset($child['url']) ? (string)$child['url'] : '')) {
                $matchedChild = $child;
                break;
            }
        }
        if ($matchedChild !== null) {
            $crumbs[] = ['title' => isset($module['title']) ? $module['title'] : $moduleUrl, 'url' => $moduleUrl];
            $crumbs[] = ['title' => isset($matchedChild['title']) ? $matchedChild['title'] : '', 'url' => isset($matchedChild['url']) ? (string)$matchedChild['url'] : '#'];
        } elseif ($urlMatches($moduleUrl)) {
            $crumbs[] = ['title' => isset($module['title']) ? $module['title'] : $moduleUrl, 'url' => ''];
        }
    } elseif ($urlMatches($moduleUrl)) {
        $crumbs[] = ['title' => isset($module['title']) ? $module['title'] : $moduleUrl, 'url' => ''];
    }
}
if (count($crumbs) === 0) {
    $crumbs[] = ['title' => 'HUGO 站点管理', 'url' => ''];
}
?>
<aside class="sops-sidebar">
  <div class="sops-sidebar-brand">
    <span class="brand-badge"><i class="bi bi-hammer" aria-hidden="true"></i></span>
    <span class="sops-sidebar-title" translate="no">HUGO 站点管理</span>
  </div>
  <nav class="sops-sidebar-nav" aria-label="主导航">
    <ul class="sops-sidebar-menu">
      <?php $menuIndex = 0; ?>
      <?php foreach ($modules as $module): ?>
        <?php
        $menuIndex++;
        $children = isset($module['children']) && is_array($module['children']) ? $module['children'] : [];
        $url = isset($module['url']) ? (string)$module['url'] : '#';
        $active = $urlMatches($url);
        $childActive = false;
        foreach ($children as $child) {
            if ($urlMatches(isset($child['url']) ? (string)$child['url'] : '')) {
                $childActive = true;
                break;
            }
        }
        ?>
        <?php if (count($children) > 0): ?>
        <li class="sops-nav-item sops-nav-has-children">
          <a class="sops-sidebar-link sops-sidebar-link-parent<?php echo $childActive ? ' active' : ''; ?>" href="<?php echo e($url); ?>" data-toggle="collapse" data-target="#sops-submenu-<?php echo $menuIndex; ?>" role="button" aria-expanded="<?php echo $childActive ? 'true' : 'false'; ?>" aria-controls="sops-submenu-<?php echo $menuIndex; ?>">
            <?php if (!empty($module['icon'])): ?><i class="bi <?php echo e($module['icon']); ?> menu-icon" aria-hidden="true"></i><?php endif; ?>
            <span><?php echo e(isset($module['title']) ? $module['title'] : $url); ?></span>
            <i class="bi bi-chevron-right sops-nav-caret" aria-hidden="true"></i>
          </a>
          <div class="collapse<?php echo $childActive ? ' show' : ''; ?>" id="sops-submenu-<?php echo $menuIndex; ?>">
            <ul class="sops-submenu">
              <?php foreach ($children as $child): ?>
                <?php
                $childUrl = isset($child['url']) ? (string)$child['url'] : '#';
                $childActiveClass = $urlMatches($childUrl) ? ' active' : '';
                ?>
                <li>
                  <a class="sops-sidebar-link sops-submenu-link<?php echo $childActiveClass; ?>" href="<?php echo e($childUrl); ?>">
                    <?php if (!empty($child['icon'])): ?><i class="bi <?php echo e($child['icon']); ?> submenu-icon" aria-hidden="true"></i><?php endif; ?>
                    <span><?php echo e(isset($child['title']) ? $child['title'] : $childUrl); ?></span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </li>
        <?php else: ?>
        <li class="sops-nav-item">
          <a class="sops-sidebar-link<?php echo $active ? ' active' : ''; ?>" href="<?php echo e($url); ?>">
            <?php if (!empty($module['icon'])): ?><i class="bi <?php echo e($module['icon']); ?> menu-icon" aria-hidden="true"></i><?php endif; ?>
            <span><?php echo e(isset($module['title']) ? $module['title'] : $url); ?></span>
          </a>
        </li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ul>
  </nav>
  <div class="sops-sidebar-foot">
    <i class="bi bi-clock-history mr-1" aria-hidden="true"></i><?php echo date('Y-m-d H:i'); ?>
  </div>
</aside>
<main class="sops-main">
  <div class="sops-topbar">
    <button type="button" class="sops-sidebar-toggle" id="sidebarToggle" aria-label="折叠侧边栏" aria-expanded="true" title="折叠侧边栏">
      <i class="bi bi-layout-sidebar" aria-hidden="true"></i>
    </button>
    <span class="sops-topbar-title"><nav class="sops-breadcrumb" aria-label="面包屑">
      <ol class="sops-breadcrumb-list">
        <?php foreach ($crumbs as $crumbIndex => $crumb): ?>
        <?php $isLast = $crumbIndex === count($crumbs) - 1; ?>
        <li class="sops-breadcrumb-item<?php echo $isLast ? ' active' : ''; ?>">
          <?php if ($isLast || $crumb['url'] === ''): ?>
          <span><?php echo e($crumb['title']); ?></span>
          <?php else: ?>
          <a href="<?php echo e($crumb['url']); ?>"><?php echo e($crumb['title']); ?></a>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
    </nav></span>
  </div>