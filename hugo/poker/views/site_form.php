<div class="container app-page" id="main">
<div class="card card-sops">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h1 class="h5 mb-0 page-title"><i class="bi bi-pencil-square mr-2" aria-hidden="true"></i>站点录入</h1>
    <span class="badge badge-info"><i class="bi bi-info-circle mr-1" aria-hidden="true"></i>提交后自动写入数据库并导出 siteops_setting.txt</span>
  </div>
  <div class="card-body">
<form action="<?php echo e(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '/siteops.php');?>" method="post" id="wechatpost" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
    <h6 class="sops-fieldset-title"><i class="bi bi-card-text mr-1" aria-hidden="true"></i>基础信息</h6>
    <div class="form-group">
        <label for="post_uuid" hidden>Post UUID</label>
        <input type="text" class="form-control form-control-sm d-none" id="post_uuid" name="post_uuid" value="<?php echo e($form['post_uuid']);?>" readonly>
        <div class="row">
            <div class="col-md-6 col-12">
            <div class="sops-label-row">
            <label for="post_gitname">Git Name <span class="text-danger" aria-hidden="true">*</span></label>
            <div class="form-hint" data-toggle="tooltip" data-placement="top" title="GitHub 代码唯一标识，规则：域名同名（abc），或域名+语言（abcja）"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
            </div>
            <input type="text" class="form-control form-control-sm" id="post_gitname" name="post_gitname" value="<?php echo e($form['post_gitname']); ?>" required placeholder="abc 或 abcja">
            </div>
            <div class="col-md-6 col-12">
            <div class="sops-label-row">
            <label for="post_domain">域名 <span class="text-danger" aria-hidden="true">*</span></label>
            <div class="form-hint" data-toggle="tooltip" data-placement="top" title="不要加 https，如 abc.com，或子域名 ja.abc.com"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
            </div>
            <input type="text" class="form-control form-control-sm" id="post_domain" name="post_domain" value="<?php echo e($form['post_domain']); ?>" required placeholder="abc.com 或 ja.abc.com">
            </div>
        </div>
        <div class="sops-label-row">
        <label for="post_sitetitle">站点标题 <span class="text-danger" aria-hidden="true">*</span></label>
        <div class="form-hint" data-toggle="tooltip" data-placement="top" title="字数控制在 100 字内，不包含（｜）"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
        </div>
        <input type="text" class="form-control form-control-sm" id="post_sitetitle" name="post_sitetitle" value="<?php echo e(htmlspecialchars_decode(strip_tags($form['post_sitetitle']))); ?>" required placeholder="站点标题">

        <div class="sops-label-row">
        <label for="post_description">站点描述 <span class="text-danger" aria-hidden="true">*</span></label>
        <div class="form-hint" data-toggle="tooltip" data-placement="top" title="字数控制在 200 字内，不包含（｜）"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
        </div>
        <input type="text" class="form-control form-control-sm" id="post_description" name="post_description" value="<?php echo e(htmlspecialchars_decode(strip_tags($form['post_description']))); ?>" required placeholder="站点描述">

        <div class="sops-label-row">
        <label for="post_sitelogo"><i class="bi bi-image mr-1" aria-hidden="true"></i>参考图片</label>
        <div class="form-hint" data-toggle="tooltip" data-placement="top" title="封面图片仅支持外部图床链接，可直接粘贴或上传，不包含（｜）"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
        </div>
        <div class="input-group">
          <input 
            type="text" 
            class="form-control form-control-sm" 
            id="post_sitelogo" 
            name="post_sitelogo" 
            placeholder="https://图床图片地址" 
            aria-label="图片链接" 
            value="<?php echo e($form['post_sitelogo']); ?>"
          >
          <div class="input-group-append">
            <!-- Hidden file input -->
            <input type="file" id="imageFileInput" accept="image/*" class="d-none" />
            <button class="btn btn-sm btn-outline-primary" type="button" id="previewImageTrigger"><i class="bi bi-eye mr-1" aria-hidden="true"></i>预览</button>
            <button class="btn btn-sm btn-primary" type="button" id="imagesource"><i class="bi bi-upload mr-1" aria-hidden="true"></i>上传</button>
          </div>
        </div>

        <hr class="sops-divider">
        <h6 class="sops-fieldset-title"><i class="bi bi-server mr-1" aria-hidden="true"></i>部署配置</h6>
        <div class="row">
            <div class="col-md-4 col-12">
                <div class="sops-label-row">
                <label for="post_sitedeploy">部署模式</label>
                <div class="form-hint" data-toggle="tooltip" data-placement="top" title="CloudFlare 无服务器；Linux 需配置服务器 IP"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
                </div>
                <select class="form-control form-control-sm" id="post_sitedeploy" name="post_sitedeploy">
                <option value="cloudflare" <?php if ($form['post_sitedeploy'] == "cloudflare") {echo "selected";}?>>cloudflare</option>
                <option value="linux" <?php if ($form['post_sitedeploy'] == "linux") {echo "selected";}?>>linux</option>
                </select>
            </div>
            <div class="col-md-4 col-12">
                <div class="sops-label-row">
                <label for="post_gitaccount">代码库名</label>
                <div class="form-hint" data-toggle="tooltip" data-placement="top" title="CloudFlare 模式须与部署服务关联后生效"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
                </div>
                <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control form-control-sm" id="post_gitaccount" name="post_gitaccount">
                <option data-subtext="null" > </option>
                <?php $extraAccount = $form['post_gitaccount'] !== '' && !in_array($form['post_gitaccount'], array_values($config['gitaccount']), true) ? $form['post_gitaccount'] : ''; ?>
                <?php if ($extraAccount !== '') : ?>
                <option data-subtext="<?php echo e($extraAccount); ?>" selected><?php echo e($extraAccount); ?></option>
                <?php endif; ?>
                <?php foreach ($config['gitaccount'] as $label => $value ): ?>
                <option data-subtext="<?php echo e($label); ?>" <?php echo strtolower($value) === strtolower($form['post_gitaccount']) ? 'selected' : ''; ?>>
                    <?php echo e($value); ?>
                </option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 col-12">
                <div class="sops-label-row">
                <label for="post_sitehostip">部署服务器IP(可留空)</label>
                <div class="form-hint" id="hostIpHint" data-toggle="tooltip" data-placement="top" title="仅 Linux 部署模式需要配置"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
                </div>
                <select class="selectpicker form-control form-control-sm" id="post_sitehostip" name="post_sitehostip" data-show-subtext="true" data-live-search="true" >
                    <option data-subtext="" <?php if (empty($form['post_sitehostip'])) echo "selected"; ?>>select deploy server ip</option>
                <?php $extraServer = $form['post_sitehostip'] !== '' && !in_array($form['post_sitehostip'], array_values($config['gitserver']), true) ? $form['post_sitehostip'] : ''; ?>
                <?php if ($extraServer !== '') : ?>
                <option data-subtext="<?php echo e($extraServer); ?>" selected><?php echo e($extraServer); ?></option>
                <?php endif; ?>
                <?php foreach ($config['gitserver'] as $label => $value ): ?>
                <?php if (!empty($label)) : ?>
                <option data-subtext="<?php echo e($label); ?>" <?php if ($form['post_sitehostip'] == $value && !empty($form['post_sitehostip']) ) {echo "selected";}?> >
                    <?php echo e($value); ?>
                </option>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php foreach ( $servers as $skey => $server) :?>
                    <?php if ($server['local_hostip'] == $server['git_name']) : ?>
                    <option data-subtext="<?php echo e('['.++$skey.'] '.$server['local_hostip'] .' - '. $server['local_deploy'] .' - '. $server['domain']); ?>" <?php if ($form['post_sitehostip'] == $server['local_hostip'] && !empty($form['post_sitehostip']) ) {echo "selected";}?> ><?php echo e($server['local_hostip']); ?></option>
                    <?php endif ?>
                <?php endforeach ?>
                </select>
            </div>
        </div>
        <input type="text" class="form-control sr-readonly d-none" id="setupNum" name="setupNum" size=60 value="ckeditorFormated" readonly>
        <h6 class="sops-fieldset-title"><i class="bi bi-share mr-1" aria-hidden="true"></i>渠道信息</h6>
        <div class="row">
            <div class="col-md-4 col-12">
            <div class="sops-label-row">
            <label for="post_sns_id">SNS ID <span class="text-danger" aria-hidden="true">*</span></label>
            <div class="form-hint" data-toggle="tooltip" data-placement="top" title="Facebook / Twitter / YouTube ID，不包含（｜）"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
            </div>
            <input type="text" class="form-control form-control-sm" id="post_sns_id" name="post_sns_id" value="<?php echo e($form['post_sns_id']);?>" required placeholder="Facebook">
            </div>
            <div class="col-md-4 col-12">
            <div class="sops-label-row">
            <label for="post_topnavmenus">顶部菜单项 <span class="text-danger" aria-hidden="true">*</span></label>
            <div class="form-hint" data-toggle="tooltip" data-placement="top" title="顶部导航栏目，英文逗号（,）分隔，不包含（｜）"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
            </div>
            <input type="text" class="form-control form-control-sm" id="post_topnavmenus" name="post_topnavmenus" value="<?php echo e($form['post_topnavmenus']);?>" required placeholder="首页, 关于">
            </div>
            <div class="col-md-4 col-12">
            <div class="sops-label-row">
            <label for="post_keyword">SEO关键词 <span class="text-danger" aria-hidden="true">*</span></label>
            <div class="form-hint" data-toggle="tooltip" data-placement="top" title="SEO 关键词，英文逗号（,）分隔，不包含（｜）"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
            </div>
            <input type="text" class="form-control form-control-sm" id="post_keyword" name="post_keyword" value="<?php echo e($form['post_keyword']);?>" required placeholder="关键词1, 关键词2">
            </div>
        </div>
    </div>

    <h6 class="sops-fieldset-title"><i class="bi bi-sliders mr-1" aria-hidden="true"></i>内容配置</h6>
    <div class="form-group">
      <div class="row">
        <div class="col-md-3 col-12">
          <label for="post_lang">站点语言</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control form-control-sm" id="post_lang" name="post_lang">
            <?php foreach ($config['languages'] as $label => $value ): ?>
            <option data-subtext="<?php echo e($label); ?>" <?php echo strtolower($value) === strtolower($form['post_lang']) ? 'selected' : ''; ?>>
                <?php echo e($value); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-12">
          <label for="post_sitetype">站点归类 <em class="text-danger">精品原创站选[cta]</em></label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control form-control-sm" id="post_sitetype" name="post_sitetype">
            <?php foreach ($config['sitetype'] as $label => $value ): ?>
            <option data-subtext="<?php echo e($label); ?>" <?php echo strtolower($value) === strtolower($form['post_sitetype']) ? 'selected' : ''; ?>>
                <?php echo e($value); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-12">
          <label for="post_themetype">模板</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control form-control-sm" id="post_themetype" name="post_themetype">
            <?php foreach ($config['themetype'] as $label => $value ): ?>
            <option data-subtext="<?php echo e($label); ?>" <?php echo strtolower($value) === strtolower($form['post_themetype']) ? 'selected' : ''; ?>>
                <?php echo e($value); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-12">
          <label for="post_status">状态</label>
          <select class="form-control form-control-sm" id="post_status" name="post_status">
            <option value="new" <?php if ($form['post_status'] == "new") {echo "selected";}?>>new</option>
            <!-- <option value="publish" <?php if ($form['post_status'] == "publish") {echo "selected";}?>>publish</option> -->
            <option value="draft" <?php if ($form['post_status'] == "draft") {echo "selected";}?>>draft</option>
            <option value="redo" <?php if ($form['post_status'] == "redo") {echo "selected";}?>>redo</option>
            <option value="done" <?php if ($form['post_status'] == "done") {echo "selected";}?>>done</option>
          </select>
        </div>
      </div>
    </div>
   
    <div class="d-flex justify-content-end mt-4">
        <button type="submit" class="btn btn-primary px-5" id="submitBtn"><i class="bi bi-check-circle mr-1" aria-hidden="true"></i>提交</button>
    </div>
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

    // 部署模式联动提示：cloudflare 无服务器模式时 IP 一般不使用，仅提示不锁定
    (function () {
        var deploy = document.getElementById('post_sitedeploy');
        var hint = document.getElementById('hostIpHint');
        if (!deploy || !hint) return;
        var syncDeploy = function () {
            var isLinux = deploy.value === 'linux';
            if (isLinux) {
                hint.setAttribute('title', '部署服务器 IP（可留空），需提前将 IP 与域名解析，否则部署会失败');
            } else {
                hint.setAttribute('title', '当前为 CloudFlare 无服务器模式，此选项一般不需要；选择 Linux 模式后再配置');
            }
            if (window.jQuery && jQuery.fn.tooltip) {
                jQuery(hint).tooltip('dispose');
                jQuery(hint).tooltip();
            }
        };
        deploy.addEventListener('change', syncDeploy);
        syncDeploy();
    })();

    // 提交防重复：禁用按钮并显示状态
    (function () {
        var btn = document.getElementById('submitBtn');
        var form = document.getElementById('wechatpost');
        if (!btn || !form) return;
        form.addEventListener('submit', function () {
            if (btn.disabled) return;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" aria-hidden="true"></span>提交中…';
        });
    })();

    // Get the form element
    var form = document.getElementById('wechatpost');

    // Add submit event listener to the form
    form.addEventListener('submit', function(event) {
        // Get all inputs with the required attribute
        var requiredInputs = form.querySelectorAll('input[required], select[required]');

        // Check each required input
        for (var i = 0; i < requiredInputs.length; i++) {
            var input = requiredInputs[i];

            // Check if the input is empty
            if (!input.value.trim()) {
                // Get the associated label
                var label = form.querySelector('label[for="' + input.id + '"]');
                var labelText = label ? label.textContent : 'This field';

                // Prevent form submission
                event.preventDefault();

                // Display an alert with the label value
                alert('请填写必填字段：' + labelText);

                // Optionally, you can focus on the empty input field
                input.focus();

                // Exit the loop, as there is no need to check the remaining inputs
                return;
            }
        }

        // Check the required textarea
        var textarea = document.getElementById('post_ckeditor_contents');

        if (textarea && !textarea.value.trim()) {
            // Get the associated label
            var label = form.querySelector('label[for="' + textarea.id + '"]');
            var labelText = label ? label.textContent : 'This field';

            // Prevent form submission
            event.preventDefault();

            // Display an alert with the label value
            alert('请填写必填字段：' + labelText);

            // Optionally, you can focus on the empty textarea
            textarea.focus();
        }
    });
</script>

</div>