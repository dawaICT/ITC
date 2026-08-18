<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'label' => '',
    'value' => '0',
    'icon' => 'fas fa-chart-line',
    'color' => 'purple',
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
    'label' => '',
    'value' => '0',
    'icon' => 'fas fa-chart-line',
    'color' => 'purple',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<div class="wuc-stat-card">
    <div class="wuc-stat-icon <?php echo e($color); ?>">
        <i class="<?php echo e($icon); ?>"></i>
    </div>
    <div>
        <div class="wuc-stat-label"><?php echo e($label); ?></div>
        <div class="wuc-stat-value"><?php echo e($value); ?></div>
    </div>
</div>
<?php /**PATH C:\xampp\htdocs\wucportal\resources\views/components/stat-card.blade.php ENDPATH**/ ?>