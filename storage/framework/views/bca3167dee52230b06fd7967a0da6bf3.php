<?php $__env->startSection('sidebar_nav'); ?>
    <?php $navService = app('App\Services\UserAccess\NavigationService'); ?>
    <?php
        $nav = $navService->getNavigationForUser(auth()->user(), 'admin');
    ?>

    <?php $__currentLoopData = $nav; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php if(isset($item['group'])): ?>
            <div class="wuc-nav-group-title"><?php echo e($item['group']); ?></div>
            <?php $__currentLoopData = $item['items']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $subItem): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <a href="<?php echo e($subItem['url']); ?>" class="wuc-nav-link <?php echo e($subItem['active'] ? 'active' : ''); ?>">
                    <i class="<?php echo e($subItem['icon']); ?>"></i>
                    <span><?php echo e($subItem['label']); ?></span>
                </a>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <?php else: ?>
            <a href="<?php echo e($item['url']); ?>" class="wuc-nav-link <?php echo e($item['active'] ? 'active' : ''); ?>">
                <i class="<?php echo e($item['icon']); ?>"></i>
                <span><?php echo e($item['label']); ?></span>
            </a>
        <?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\xampp\htdocs\wucportal\resources\views/layouts/admin.blade.php ENDPATH**/ ?>