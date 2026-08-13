<div class="container app-page" id="main">
<div class="card card-sops">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h1 class="h5 mb-0 page-title"><i class="bi bi-search mr-2" aria-hidden="true"></i><?php echo isset($form['ctx_id']) && $form['ctx_id'] !== '' ? '关键词编辑' : '关键词配置'; ?></h1>
    <span class="badge badge-info"><i class="bi bi-info-circle mr-1" aria-hidden="true"></i>提交后写入 keywordmonitorlist 并导出 keyword_monitor_list.txt</span>
  </div>
  <div class="card-body">
<form action="<?php echo e(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '/keywordops.php');?>" method="post" id="formtable" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
    <input type="hidden" name="post_ctxid" value="<?php echo e(isset($form['ctx_id']) ? $form['ctx_id'] : ''); ?>">
    <div class="form-group">
        <div class="row">
            <div class="col-md-6 col-xs-12">
            <label for="post_keyword">监控关键词</label>
            <input type="text" class="form-control" id="post_keyword" name="post_keyword" value="<?php echo e($form['post_keyword']); ?>" required placeholder="录入需要批量监控的关键词" pattern="[^|｜]*" maxlength="1000" data-msg="关键词不能包含 ｜">
            </div>
            <div class="col-md-3 col-xs-12">
            <label for="post_bulkkeyword">启用批量（逗号分隔）</label>
            <div class="checkbox">
              <label>
                <input type="checkbox" value="enable" id="post_bulkkeyword" name="post_bulkkeyword" <?php echo $form['post_bulkkeyword'] === 'enable' ? 'checked' : ''; ?>>
              </label>
            </div>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_gitname">发布到站点(github仓库名)</label>
                <select class="selectpicker form-control" id="post_gitname" name="post_gitname" size="1" data-show-subtext="true" data-live-search="true" required>
                    <option data-subtext="">Choose a gitname</option>
                <?php foreach ($sites as $site) :?>
                    <?php
                    $label = !empty($site['languages']) ? $site['git_name'] . '(' . $site['languages'] . ')' : $site['git_name'];
                    $selected = $form['post_gitname'] === $site['git_name'] ? ' selected' : '';
                    ?>
                <option data-subtext="<?php echo e($label); ?>"<?php echo $selected; ?>><?php echo e($site['git_name']); ?></option>
                <?php endforeach ?>
                </select>
            </div>
        </div>
        <input type="text" class="form-control sr-readonly" id="setupNum" name="setupNum" size=60 value="ckeditorFormated" readonly style="display: none;">
    </div>

    <div class="form-group">
      <div class="row">
        <div class="col-md-3 col-xs-12">
          <label for="post_lang">站点语言</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control" id="post_lang" name="post_lang" required>
            <?php foreach ($config['languages'] as $label => $value): ?>
            <option data-subtext="<?php echo e($label); ?>"<?php echo strtolower($value) === strtolower($form['post_lang']) ? ' selected' : ''; ?>>
                <?php echo e($value); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_geo">国家地区</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control" id="post_geo" name="post_geo" required>
            <?php foreach ($config['countries'] as $label => $value): ?>
            <option data-subtext="<?php echo e($label); ?>"<?php echo strtolower($value) === strtolower($form['post_geo']) ? ' selected' : ''; ?>>
                <?php echo e($value); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_pubdir">发布目录</label>
          <select class="form-control" id="post_pubdir" name="post_pubdir" required>
            <?php foreach ($config['pubdir'] as $value): ?>
            <option value="<?php echo e($value); ?>"<?php echo $form['post_pubdir'] === $value ? ' selected' : ''; ?>><?php echo e($value); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_status">状态</label>
          <select class="form-control" id="post_status" name="post_status" required>
            <?php foreach (['enable', 'disable', 'draft'] as $value): ?>
            <option value="<?php echo e($value); ?>"<?php echo $form['post_status'] === $value ? ' selected' : ''; ?>><?php echo e($value); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-end mt-4">
        <button type="submit" class="btn btn-primary px-5"><i class="bi bi-check-circle mr-1"></i>提交入库</button>
    </div>
</form>
  </div>
</div>

<script>
    // 自定义校验提示：pattern / maxlength 违规时显示 data-msg 中文提示
    (function () {
        document.querySelectorAll('[data-msg]').forEach(function (el) {
            el.addEventListener('invalid', function () {
                if (el.validity.patternMismatch || el.validity.tooLong) {
                    el.setCustomValidity(el.getAttribute('data-msg'));
                }
            });
            el.addEventListener('input', function () {
                el.setCustomValidity('');
            });
        });
    })();
</script>
</div>