<!-- Image Preview Modal -->
<div class="modal fade" id="imagePreviewModal" tabindex="-1" role="dialog" aria-labelledby="imagePreviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="imagePreviewModalLabel"><i class="bi bi-image" aria-hidden="true"></i> 图片预览</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body text-center">
        <img id="modalImage" src="" class="img-fluid modal-img-preview" alt="预览">
      </div>
    </div>
  </div>
</div>
<a class="skip-link" href="#main">跳到主要内容</a>
<?php if (empty($no_shell)): ?>
</div>
<?php endif; ?>
</body>
  <script src="js/jquery.min.js"></script>
  <script src="js/bootstrap.bundle.min.js"></script>
  <script src="js/bootstrap-table.min.js"></script>
  <script src="js/bootstrap-select.min.js"></script>
  <script src="js/siteops.js"></script>
</html>