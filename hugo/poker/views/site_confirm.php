<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-check2-circle mr-2" aria-hidden="true"></i>提交确认</h1>
      <span class="badge badge-success"><i class="bi bi-check-circle mr-1" aria-hidden="true"></i>已写入数据库</span>
    </div>
    <div class="card-body">
        <div class="form-group">
            <div class="row">
                <div class="col-md-6 col-xs-12">
                <label for="post_gitname">Git Name:</label>
                <input type="text" class="form-control form-control-sm" id="post_gitname" name="post_gitname" value="<?php echo e($content['git_name']); ?>" readonly>
                </div>
                <div class="col-md-6 col-xs-12">
                <label for="post_domain">域名:</label>
                <input type="text" class="form-control form-control-sm" id="post_domain" name="post_domain" value="<?php echo e($content['domain']); ?>" readonly>
                </div>
            </div>
            <label for="post_sitetitle">站点标题:</label>
            <input type="text" class="form-control form-control-sm" id="post_sitetitle" name="post_sitetitle" value="<?php echo e(htmlspecialchars_decode($content['site_title'])); ?>" readonly>

            <label for="post_description">站点描述:</label>
            <input type="text" class="form-control form-control-sm" id="post_description" name="post_description" value="<?php echo e(htmlspecialchars_decode($content['site_subtitle'])); ?>" readonly>

            <label for="post_sitelogo">站点图标:</label>
            <input type="text" class="form-control form-control-sm" id="post_sitelogo" name="post_sitelogo" value="<?php echo e($content['site_logo']); ?>" readonly>

            <div class="row">
                <div class="col-md-4 col-xs-12">
                <label for="post_sitedeploy">部署模式:</label>
                <input type="text" class="form-control form-control-sm" id="post_sitedeploy" name="post_sitedeploy" value="<?php echo e($content['deploy']); ?>" readonly>
                </div>
                <div class="col-md-4 col-xs-12">
                <label for="post_gitaccount">代码库名:</label>
                <input type="text" class="form-control form-control-sm" id="post_gitaccount" name="post_gitaccount" value="<?php echo e($content['git_account']); ?>" readonly>
                </div>
                <div class="col-md-4 col-xs-12">
                <label for="post_sitehostip">部署服务器IP:</label>
                <input type="text" class="form-control form-control-sm" id="post_sitehostip" name="post_sitehostip" value="<?php echo e($content['hostip']); ?>" readonly>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 col-xs-12">
                <label for="post_sns_id">SNS ID:</label>
                <input type="text" class="form-control form-control-sm" id="post_sns_id" name="post_sns_id" value="<?php echo e($content['sns_id']); ?>" readonly>
                </div>
                <div class="col-md-4 col-xs-12">
                <label for="post_topnavmenus">顶部菜单项:</label>
                <input type="text" class="form-control form-control-sm" id="post_topnavmenus" name="post_topnavmenus" value="<?php echo e($content['topnav_menus']); ?>" readonly>
                </div>
                <div class="col-md-4 col-xs-12">
                <label for="post_keyword">SEO关键词:</label>
                <input type="text" class="form-control form-control-sm" id="post_keyword" name="post_keyword" value="<?php echo e($content['keyword']); ?>" readonly>
                </div>
            </div>
        </div>

        <div class="form-group">
          <div class="row">
            <div class="col-md-3 col-xs-12">
                <label for="post_lang">站点语言:</label>
                <input type="text" class="form-control form-control-sm" id="post_lang" name="post_lang" value="<?php echo e($content['languages']); ?>" readonly>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_sitetype">站点归类:</label>
                <input type="text" class="form-control form-control-sm" id="post_sitetype" name="post_sitetype" value="<?php echo e($content['site_type']); ?>" readonly>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_themetype">模板:</label>
                <input type="text" class="form-control form-control-sm" id="post_themetype" name="post_themetype" value="<?php echo e($content['theme_type']); ?>" readonly>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_status">状态:</label>
                <input type="text" class="form-control form-control-sm" id="post_status" name="post_status" value="<?php echo e($content['status']); ?>" readonly>
            </div>
          </div>
        </div>
        <div class="form-group">
            <label for="json" >Json:</label>
            <textarea class="form-control form-control-sm codearea" rows="4" readonly><?php echo e($post_json); ?></textarea>
        </div>
        <div class="form-group" hidden >
            <label for="title" hidden >setupNum:</label>
            <input type="text" class="form-control form-control-sm" id="title" name="title" value="<?php echo e($content['setupNum']); ?>" readonly class="d-none">
        </div>
        <hr>
        <div>
            <a class="btn btn-sm btn-primary" href="./topicedit.php"><i class="bi bi-pencil mr-1" aria-hidden="true"></i>录入网站文章标题</a>
        </div>
    </div>
  </div>
</div>