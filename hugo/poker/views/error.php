<div class="container app-page" id="main">
    <div class="card card-sops">
        <div class="card-header">
            <h1 class="h5 mb-0 page-title"><i class="bi bi-exclamation-triangle mr-2" aria-hidden="true"></i>Error</h1>
        </div>
        <div class="card-body">
            <div class="alert alert-danger mb-0">
                <strong>Error:</strong> <?php echo e(isset($message) ? $message : 'Something went wrong.'); ?>
            </div>
        </div>
    </div>
</div>