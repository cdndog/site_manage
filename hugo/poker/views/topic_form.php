<div class="container app-page" id="main">
<div class="card card-sops">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h1 class="h5 mb-0 page-title"><i class="bi bi-journal-text mr-2" aria-hidden="true"></i><?php echo isset($form['ctx_id']) && $form['ctx_id'] !== '' ? '话题编辑' : '话题录入'; ?></h1>
    <span class="badge badge-info"><i class="bi bi-info-circle mr-1" aria-hidden="true"></i>提交后写入 sitetopic 并导出 topic_monitor_list.txt</span>
  </div>
  <div class="card-body">
<form action="<?php echo e(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '/topicops.php');?>" method="post" id="formtable" target="_self" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
    <div class="form-group">
        <div class="row" hidden>
            <div class="col-md-12 col-xs-12">
            <label for="post_uuid">话题ID</label>
            <input type="text" class="form-control form-control-sm" id="post_uuid" name="post_uuid" value="<?php echo e($form['ctx_id']); ?>">
            </div>
        </div>
        <div class="row">
            <div class="col-md-9 col-xs-12">
            <div class="sops-label-row">
            <label for="post_keyword">生成文章及关键词 <span class="text-danger" aria-hidden="true">*</span></label>
            <div class="form-hint" data-toggle="tooltip" data-placement="top" title="启用批量时用英文逗号分隔多个话题"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
            </div>
            <input type="text" class="form-control form-control-sm" id="post_keyword" name="post_keyword" required placeholder="录入需要批量生成文章及关键词" value="<?php echo e($form['post_keyword']); ?>">
            </div>
            <div class="col-md-3 col-xs-12">
            <label for="post_bulkkeyword">启用批量</label>
            <div class="checkbox">
              <label>
                <input type="checkbox" value="enable" id="post_bulkkeyword" name="post_bulkkeyword" <?php echo $form['post_bulkkeyword'] === 'enable' ? 'checked' : ''; ?>>
              </label>
            </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 col-xs-12">
                <label for="post_gitname">发布到站点(github仓库名)</label>
                <select class="selectpicker form-control form-control-sm" id="post_gitname" name="post_gitname" size="1" data-show-subtext="true" data-live-search="true" required>
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
            <div class="col-md-6 col-xs-12">
                <label for="post_domain">发布到站点(域名)</label>
                <select class="selectpicker form-control form-control-sm" id="post_domain" name="post_domain" size="1" data-show-subtext="true" data-live-search="true" required>
                    <option data-subtext="">Choose a Domain</option>
                <?php foreach ($sites as $site) :?>
                    <?php
                    $label = !empty($site['languages']) ? $site['domain'] . '(' . $site['languages'] . ')' : $site['domain'];
                    $selected = $form['post_domain'] === $site['domain'] ? ' selected' : '';
                    ?>
                <option data-subtext="<?php echo e($label); ?>"<?php echo $selected; ?>><?php echo e($site['domain']); ?></option>
                <?php endforeach ?>
                </select>
            </div>
        </div>
        <input type="text" class="form-control form-control-sm sr-readonly" id="setupNum" name="setupNum" size=60 value="ckeditorFormated" readonly style="display: none;">
    </div>

    <div class="form-group">
      <div class="row">
        <div class="col-md-3 col-xs-12">
          <label for="post_lang">站点语言</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control form-control-sm" id="post_lang" name="post_lang" required>
            <?php foreach ($config['languages'] as $label => $value): ?>
            <option data-subtext="<?php echo e($label); ?>"<?php echo strtolower($value) === strtolower($form['post_lang']) ? ' selected' : ''; ?>>
                <?php echo e($value); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_geo">国家地区</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control form-control-sm" id="post_geo" name="post_geo" required>
            <?php foreach ($config['countries'] as $label => $value): ?>
            <option data-subtext="<?php echo e($label); ?>"<?php echo strtolower($value) === strtolower($form['post_geo']) ? ' selected' : ''; ?>>
                <?php echo e($value); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_pubdir">发布目录</label>
          <select class="selectpicker form-control form-control-sm" id="post_pubdir" name="post_pubdir" required>
            <?php foreach ($config['pubdir'] as $value): ?>
            <option value="<?php echo e($value); ?>"<?php echo $form['post_pubdir'] === $value ? ' selected' : ''; ?>><?php echo e($value); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_status">状态</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control form-control-sm" id="post_status" name="post_status" required>
            <?php foreach ($config['statuses'] as $label => $value): ?>
            <option data-subtext="<?php echo e($label); ?>"<?php echo strtolower($value) === strtolower($form['post_status']) ? ' selected' : ''; ?>>
                <?php echo e($value); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary btn-block"><i class="bi bi-check-circle mr-1"></i>提交入库</button>
</form>
  </div>
</div>

<script>
    // 表单提示 tooltip 初始化：图标 hover 显示说明（jQuery 在 layout_tail 末尾加载，需等待就绪）
    (function () {
        function initHints() {
            if (!window.jQuery || !jQuery.fn.tooltip) {
                return false;
            }
            jQuery('[data-toggle="tooltip"]').tooltip();
            return true;
        }
        if (!initHints()) {
            var timer = setInterval(function () {
                if (initHints()) {
                    clearInterval(timer);
                }
            }, 100);
            setTimeout(function () { clearInterval(timer); }, 10000);
        }
    })();
</script>
</div>