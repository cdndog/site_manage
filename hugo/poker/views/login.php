<div class="container auth-wrap" id="main">
    <div class="card card-sops">
        <div class="card-header">
            <h1 class="h5 mb-0 page-title"><i class="bi bi-shield-lock mr-2" aria-hidden="true"></i>Login</h1>
        </div>
        <div class="card-body">
    <?php if (isset($error) && $error !== ''): ?>
    <div class="alert alert-danger"><?php echo e($error); ?></div>
    <?php endif; ?>
    <form action="<?php echo e(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '/siteops.php'); ?>" method="post">
        <input type="hidden" name="login_action" value="login">
        <div class="form-group">
            <label for="auth_user">User:</label>
            <input type="text" class="form-control form-control-sm" id="auth_user" name="auth_user" autocomplete="username">
        </div>
        <div class="form-group">
            <label for="auth_password">Password:</label>
            <input type="password" class="form-control form-control-sm" id="auth_password" name="auth_password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-in-right mr-1" aria-hidden="true"></i>Login</button>
    </form>
        </div>
    </div>
</div>