<?php
declare(strict_types=1);

/**
 * Skills-to-Trade Hub — presentation helpers (badges, steppers, empty states).
 */

if (!function_exists('eh_status_chip')) {
    function eh_status_chip(string $status): string
    {
        $safe = preg_replace('/[^a-z_]/', '', strtolower($status)) ?: 'draft';
        $label = eh_status_label($safe);
        return '<span class="eh-status eh-status-' . eh_h($safe) . '">' . eh_h($label) . '</span>';
    }
}

if (!function_exists('eh_item_type_icon')) {
    function eh_item_type_icon(string $type): string
    {
        return match ($type) {
            'product' => 'fa-box-open',
            'service' => 'fa-hands-helping',
            'innovation' => 'fa-lightbulb',
            'business_idea' => 'fa-seedling',
            'investment_opportunity' => 'fa-chart-line',
            default => 'fa-briefcase',
        };
    }
}

if (!function_exists('eh_workflow_steps')) {
    /**
     * @return list<array{key:string,label:string,href:string}>
     */
    function eh_workflow_steps(string $base, ?int $itemId = null): array
    {
        $idQ = $itemId && $itemId > 0 ? ('?id=' . $itemId) : '';
        $itemQ = $itemId && $itemId > 0 ? ('?item_id=' . $itemId) : '';
        return [
            ['key' => 'profile', 'label' => 'Profile', 'href' => $base . '/profile.php'],
            ['key' => 'item', 'label' => 'Item details', 'href' => $itemId ? ($base . '/item_edit.php' . $idQ) : ($base . '/item_create.php')],
            ['key' => 'media', 'label' => 'Photos', 'href' => $itemId ? ($base . '/item_edit.php' . $idQ . '#media') : ($base . '/items.php')],
            ['key' => 'costs', 'label' => 'Costs', 'href' => $itemId ? ($base . '/cost_calculator.php' . $itemQ) : ($base . '/items.php')],
            ['key' => 'readiness', 'label' => 'Readiness', 'href' => $itemId ? ($base . '/readiness_assessment.php' . $itemQ) : ($base . '/items.php')],
            ['key' => 'submit', 'label' => 'Submit', 'href' => $itemId ? ($base . '/item_view.php' . $idQ) : ($base . '/submissions.php')],
        ];
    }
}

if (!function_exists('eh_render_workflow_stepper')) {
    /**
     * @param list<string> $doneKeys
     */
    function eh_render_workflow_stepper(string $base, string $currentKey, ?int $itemId = null, array $doneKeys = []): void
    {
        $steps = eh_workflow_steps($base, $itemId);
        echo '<ol class="eh-stepper" aria-label="Showcase preparation steps">';
        $n = 1;
        foreach ($steps as $step) {
            $key = $step['key'];
            $done = in_array($key, $doneKeys, true);
            $current = $key === $currentKey;
            $locked = !$itemId && !in_array($key, ['profile', 'item'], true);
            $class = $current ? 'is-current' : ($done ? 'is-done' : ($locked ? 'is-locked' : ''));
            echo '<li class="' . eh_h($class) . '">';
            $inner = '<span class="eh-step-num">' . ($done ? '<i class="fas fa-check"></i>' : (string)$n) . '</span>'
                . '<span>' . eh_h($step['label']) . '</span>';
            if ($locked) {
                echo '<span>' . $inner . '</span>';
            } else {
                echo '<a href="' . eh_h($step['href']) . '">' . $inner . '</a>';
            }
            echo '</li>';
            $n++;
        }
        echo '</ol>';
    }
}

if (!function_exists('eh_render_empty')) {
    function eh_render_empty(string $icon, string $message, string $actionHref = '', string $actionLabel = ''): void
    {
        echo '<div class="eh-empty">';
        echo '<i class="fas ' . eh_h($icon) . '" aria-hidden="true"></i>';
        echo '<p>' . eh_h($message) . '</p>';
        if ($actionHref !== '' && $actionLabel !== '') {
            echo '<a class="btn btn-primary" href="' . eh_h($actionHref) . '">' . eh_h($actionLabel) . '</a>';
        }
        echo '</div>';
    }
}

if (!function_exists('eh_render_stylesheet_link')) {
    function eh_render_stylesheet_link(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        echo '<link rel="stylesheet" href="/wucportal/css/enterprise-hub.css?v=20260721">';
    }
}
