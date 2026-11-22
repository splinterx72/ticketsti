<?php
/**
 * @var int $status
 * @var string $title
 * @var string $message
 */
?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6 text-center py-5">
        <div class="display-1 fw-bold text-primary opacity-50"><?= e($status) ?></div>
        <h1 class="h3 mb-3"><?= e($title) ?></h1>
        <p class="text-body-secondary mb-4"><?= e($message) ?></p>
        <a href="<?= e(url('/')) ?>" class="btn btn-primary"><i class="bi bi-house me-1"></i>Volver al inicio</a>
    </div>
</div>
