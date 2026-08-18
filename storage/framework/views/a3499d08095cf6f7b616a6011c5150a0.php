<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'title' => '',
    'icon' => null,
    'badge' => null,
    'badgeColor' => 'primary',
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'title' => '',
    'icon' => null,
    'badge' => null,
    'badgeColor' => 'primary',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<article class="wuc-card">
    <?php if($title): ?>
        <div class="wuc-card-header">
            <h3>
                <?php if($icon): ?> <i class="<?php echo e($icon); ?> me-1"></i> <?php endif; ?>
                <?php echo e($title); ?>

            </h3>
            <?php if($badge): ?>
                <span class="badge bg-<?php echo e($badgeColor); ?>"><?php echo e($badge); ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="wuc-card-body">
        <?php echo e($slot); ?>

    </div>
</article>
<?php /**PATH C:\xampp\htdocs\wucportal\resources\views/components/card.blade.php ENDPATH**/ ?>