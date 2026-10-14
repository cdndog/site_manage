<?php // 用户与登出已迁移至顶部栏（views/header.php .sops-topbar-actions，最右侧）；此处仅保留版权与功能入口 ?>
<footer class="footer footer-sops mt-auto">
  <div class="container d-flex justify-content-between align-items-center flex-wrap small">
    <div><i class="bi bi-hammer mr-1" aria-hidden="true"></i><span translate="no">HUGO</span> 站点管理工具</div>
    <div id="footerModules">
      <!-- 后续功能入口可在此追加，如：<a class="text-muted mr-3" href="xxx.php"><i class="bi bi-gear mr-1"></i>设置</a> -->
    </div>
    <div class="footer-actions">
      <span class="text-muted">&copy; <?php echo date('Y'); ?></span>
    </div>
  </div>
</footer>
</main>
