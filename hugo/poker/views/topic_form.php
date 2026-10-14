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
            <input type="text" class="form-control" id="post_uuid" name="post_uuid" value="<?php echo e($form['ctx_id']); ?>">
            </div>
        </div>
        <div class="row">
            <div class="col-md-9 col-xs-12">
            <div class="sops-label-row">
            <label for="post_keyword">生成文章及关键词 <span class="text-danger" aria-hidden="true">*</span></label>
            <div class="form-hint" data-toggle="tooltip" data-placement="top" title="启用批量时用英文逗号分隔多个话题"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
            </div>
            <input type="text" class="form-control" id="post_keyword" name="post_keyword" required placeholder="录入需要批量生成文章及关键词" value="<?php echo e($form['post_keyword']); ?>" pattern="[^|｜]*" maxlength="1000" data-msg="关键词不能包含 ｜">
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
            <div class="col-md-6 col-xs-12">
                <label for="post_domain">发布到站点(域名)</label>
                <select class="selectpicker form-control" id="post_domain" name="post_domain" data-id="post_domain" size="1" data-show-subtext="true" data-live-search="true" required>
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
          <select class="selectpicker form-control" id="post_pubdir" name="post_pubdir" data-id="post_pubdir" data-show-subtext="true" required>
            <?php
            $pubdirCurrent = isset($form['post_pubdir']) ? (string)$form['post_pubdir'] : '';
            $pubdirOptions = (isset($config['pubdir']) && is_array($config['pubdir'])) ? $config['pubdir'] : [];
            $pubdirMatched = false;
            foreach ($pubdirOptions as $pubdirOption) {
                if (strtolower((string)$pubdirOption) === strtolower($pubdirCurrent)) {
                    $pubdirMatched = true;
                    break;
                }
            }
            foreach ($pubdirOptions as $value): ?>
            <option value="<?php echo e($value); ?>"<?php echo strtolower((string)$value) === strtolower($pubdirCurrent) && $pubdirCurrent !== '' ? ' selected' : ''; ?>><?php echo e($value); ?></option>
            <?php endforeach;
            // 下拉里没有与配置值对应的选项时补一个（如站点菜单项），避免保存时被首个配置项覆盖
            if (!$pubdirMatched && $pubdirCurrent !== ''): ?>
            <option data-subtext="配置值" value="<?php echo e($pubdirCurrent); ?>" selected><?php echo e($pubdirCurrent); ?></option>
            <?php endif; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_status">状态</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control" id="post_status" name="post_status" required>
            <?php foreach ($config['statuses'] as $label => $value): ?>
            <option data-subtext="<?php echo e($label); ?>"<?php echo strtolower($value) === strtolower($form['post_status']) ? ' selected' : ''; ?>>
                <?php echo e($value); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-end mt-4">
        <button type="submit" class="btn btn-primary px-5"><i class="bi bi-check-circle mr-1"></i>提交入库</button>
    </div>
</form>
<script src="js/sops-site-menus.js"></script>
<script>
    // 选定发布到站点(域名)后，异步拉取该站点的顶部菜单(topnav_menus)追加为「发布目录」的可选项
    (function () {
        var form = document.getElementById('formtable');
        if (!form) return;
        var token = form.querySelector('input[name="csrf_token"]');
        sopsAttachSiteMenus({
            triggerId: 'post_domain',
            targetId: 'post_pubdir',
            csrfToken: token ? token.value : ''
        });
    })();
</script>
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