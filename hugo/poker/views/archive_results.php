<?php
use App\Services\WaybackService;

$items = isset($items) && is_array($items) ? $items : [];
$domain = isset($domain) ? (string)$domain : '';
?>
<?php if (!$items): ?>
<div class="alert alert-secondary py-2 small mb-0">未找到 <?php echo e($domain); ?> 的首页归档快照。</div>
<?php else: ?>
<form method="post" id="form-baseline" class="mb-0">
  <input type="hidden" name="action" value="baseline">
  <input type="hidden" name="domain" value="<?php echo e($domain); ?>">
  <div class="archive-table-wrap">
    <table class="table table-hover table-sm mb-0">
      <thead>
        <tr>
          <th scope="col" style="width:44px;">选择</th>
          <th scope="col">日期</th>
          <th scope="col">归档链接</th>
          <th scope="col">网站标题</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $item): ?>
        <?php
        $ts = (string)($item['ts'] ?? '');
        $url = (string)($item['url'] ?? '');
        $title = (string)($item['title'] ?? '');
        $replay = WaybackService::replayUrl($ts, $url);
        ?>
        <tr>
          <td>
            <div class="custom-control custom-radio">
              <input type="radio" class="custom-control-input archive-baseline-radio" name="baseline_pick"
                     id="bl_<?php echo e($ts); ?>_<?php echo e(substr(md5($url), 0, 6)); ?>"
                     value="<?php echo e($ts); ?>"
                     data-ts="<?php echo e($ts); ?>" data-url="<?php echo e($url); ?>"
                     data-title="<?php echo e($title); ?>" data-domain="<?php echo e($domain); ?>" required>
              <label class="custom-control-label" for="bl_<?php echo e($ts); ?>_<?php echo e(substr(md5($url), 0, 6)); ?>"></label>
            </div>
          </td>
          <td class="archive-cell-time"><?php echo e(WaybackService::formatTimestamp($ts)); ?></td>
          <td>
            <div class="text-truncate archive-cell-url" title="<?php echo e($replay); ?>">
              <a href="<?php echo e($replay); ?>" target="_blank" rel="noopener"><?php echo e($replay); ?></a>
            </div>
          </td>
          <td><div class="text-truncate archive-cell-title" title="<?php echo e($title); ?>"><?php echo e($title); ?></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex justify-content-end mt-2">
    <button type="button" class="btn btn-sm btn-primary" id="btnSelectBaseline"><i class="bi bi-check2-circle mr-1" aria-hidden="true"></i>选择为基线 →</button>
  </div>
</form>
<?php endif; ?>
