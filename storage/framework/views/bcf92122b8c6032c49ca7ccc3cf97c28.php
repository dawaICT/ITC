<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title><?php echo $__env->yieldContent('title', 'WUCPortal'); ?> - Waterfalls University College</title>
    
    <!-- Fonts & Icons -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    
    <!-- Portal CSS Tokens -->
    <link rel="stylesheet" href="<?php echo e(asset('css/app.css')); ?>">
    <?php echo $__env->yieldContent('styles'); ?>
</head>
<body>
    <div class="wuc-layout-wrapper">
        <!-- Sidebar Navigation -->
        <aside class="wuc-sidebar">
            <div class="wuc-sidebar-brand">
                <i class="fas fa-graduation-cap"></i>
                <span>WUCPortal</span>
            </div>
            <nav class="wuc-sidebar-nav">
                <?php echo $__env->yieldContent('sidebar_nav'); ?>
            </nav>
        </aside>

        <!-- Main Workspace -->
        <div class="wuc-main-content">
            <!-- Top Header -->
            <header class="wuc-topbar">
                <div class="d-flex align-items-center gap-3">
                    <h5 class="m-0 font-weight-bold"><?php echo $__env->yieldContent('page_title', 'Dashboard'); ?></h5>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge bg-purple px-3 py-2 text-white" style="background-color: var(--brand-primary)">
                        <i class="fas fa-user-circle me-1"></i> <?php echo e(auth()->user()->name ?? 'User'); ?>

                    </span>
                    <form method="POST" action="<?php echo e(route('logout')); ?>" class="d-inline">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="fas fa-right-from-bracket me-1"></i> Logout
                        </button>
                    </form>
                </div>
            </header>

            <!-- Page Body -->
            <main class="wuc-page-body">
                <?php if(session('success')): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i> <?php echo e(session('success')); ?>

                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if(session('error')): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i> <?php echo e(session('error')); ?>

                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php echo $__env->yieldContent('content'); ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php echo $__env->yieldContent('scripts'); ?>
</body>
</html>
<?php /**PATH C:\xampp\htdocs\wucportal\resources\views/layouts/app.blade.php ENDPATH**/ ?>