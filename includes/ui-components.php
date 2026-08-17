<?php
/**
 * Reusable UI Components
 * Presentation-only helpers for the parish design system.
 */

function pdsClassList($base, $extra = '') {
    return trim($base . ' ' . (string) $extra);
}

function pdsButton($label, $href = '', $variant = 'ghost-outline', $icon = '', $extra_class = '', $attributes = '') {
    $variants = [
        'primary' => 'pds-btn-primary-gold',
        'primary-gold' => 'pds-btn-primary-gold',
        'ghost-outline' => 'pds-btn-ghost-outline',
        'outline' => 'pds-btn-ghost-outline',
        'success' => 'pds-btn-success',
        'danger' => 'pds-btn-danger',
        'ghost' => 'pds-btn-ghost'
    ];
    $class = pdsClassList('pds-btn ' . ($variants[$variant] ?? $variants['ghost-outline']), $extra_class);
    $content = ($icon !== '' ? '<i class="' . e($icon) . '"></i> ' : '') . e($label);
    if ($href !== '') {
        return '<a class="' . e($class) . '" href="' . e($href) . '" ' . $attributes . '>' . $content . '</a>';
    }
    return '<button type="button" class="' . e($class) . '" ' . $attributes . '>' . $content . '</button>';
}

function pdsBadge($label, $status = 'neutral', $icon = '') {
    $status = strtolower((string) $status);
    $map = [
        'active' => 'approved',
        'approved' => 'approved',
        'completed' => 'completed',
        'pending' => 'pending',
        'processing' => 'neutral',
        'rejected' => 'rejected',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled'
    ];
    $class = 'pds-badge pds-badge-' . ($map[$status] ?? 'neutral');
    return '<span class="' . e($class) . '">' . ($icon !== '' ? '<i class="' . e($icon) . '"></i> ' : '') . e($label) . '</span>';
}

function pdsStatusClass($status) {
    $status = strtolower((string) $status);
    $map = [
        'active' => 'approved',
        'approved' => 'approved',
        'completed' => 'completed',
        'pending' => 'pending',
        'processing' => 'neutral',
        'rejected' => 'rejected',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
        'archived' => 'neutral'
    ];
    return 'pds-badge pds-badge-' . ($map[$status] ?? 'neutral');
}

function pdsAlert($message, $variant = 'success', $icon = '') {
    $variant = in_array($variant, ['success', 'danger'], true) ? $variant : 'success';
    $default_icon = $variant === 'success' ? 'far fa-circle-check' : 'fas fa-triangle-exclamation';
    $icon = $icon ?: $default_icon;
    return '<div class="pds-alert pds-alert-' . e($variant) . '"><i class="' . e($icon) . '"></i><div>' . e($message) . '</div></div>';
}

function pdsEmptyState($title, $message = '', $icon = 'far fa-folder-open') {
    return '<div class="pds-empty-state"><i class="' . e($icon) . '"></i><strong>' . e($title) . '</strong>' . ($message !== '' ? '<div>' . e($message) . '</div>' : '') . '</div>';
}

function pdsCard($title, $body, $icon = '', $extra_class = '') {
    $header_icon = $icon !== '' ? '<i class="' . e($icon) . '"></i> ' : '';
    return '<section class="' . e(pdsClassList('pds-card', $extra_class)) . '"><div class="pds-card-header">' . $header_icon . e($title) . '</div><div class="pds-card-body">' . $body . '</div></section>';
}

function pdsFeaturedCard($title, $body, $icon = '', $extra_class = '') {
    return pdsCard($title, $body, $icon, pdsClassList('pds-featured-card', $extra_class));
}

function pdsFormField($name, $label, $type = 'text', $value = '', $placeholder = '', $error = '', $extra_class = '', $attributes = '') {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    $error_html = $error !== '' ? '<div class="pds-field-error">' . e($error) . '</div>' : '';
    return '<div class="pds-field ' . e($extra_class) . '">'
        . '<label class="pds-form-label" for="' . e($id) . '">' . e($label) . '</label>'
        . '<input class="pds-form-control form-control" type="' . e($type) . '" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '" placeholder="' . e($placeholder) . '" ' . $attributes . '>'
        . $error_html
        . '</div>';
}

function pdsTimeline($items) {
    $html = '<ol class="pds-timeline">';
    foreach ($items as $item) {
        $title = $item['title'] ?? '';
        $meta = $item['meta'] ?? '';
        $body = $item['body'] ?? '';
        $html .= '<li class="pds-timeline-item">'
            . '<div class="pds-timeline-title">' . e($title) . '</div>'
            . ($meta !== '' ? '<div class="pds-timeline-meta">' . e($meta) . '</div>' : '')
            . ($body !== '' ? '<div>' . e($body) . '</div>' : '')
            . '</li>';
    }
    return $html . '</ol>';
}

function pdsSkeleton($width = '100%', $extra_class = '') {
    return '<div class="' . e(pdsClassList('pds-skeleton', $extra_class)) . '" style="width:' . e($width) . ';"></div>';
}

function pdsModalShellClass($extra_class = '') {
    return e(pdsClassList('modal-content pds-modal-shell', $extra_class));
}

function pdsHeroCard($title, $description = '', $icon = 'fas fa-church', $badge = '', $extra_class = '') {
    $badge_html = $badge !== '' ? '<span class="premium-pill">' . e($badge) . '</span>' : '';
    return '<section class="' . e(pdsClassList('page-hero theme-hero-card', $extra_class)) . '">'
        . '<div class="theme-hero-icon"><i class="' . e($icon) . '"></i></div>'
        . '<div class="theme-hero-copy"><h1>' . e($title) . '</h1>'
        . ($description !== '' ? '<p>' . e($description) . '</p>' : '')
        . '</div>' . $badge_html . '</section>';
}

function pdsStatCard($label, $value, $icon = 'fas fa-chart-simple', $note = '', $extra_class = '') {
    return '<article class="' . e(pdsClassList('premium-kpi-card theme-stat-card', $extra_class)) . '">'
        . '<div class="premium-kpi-icon"><i class="' . e($icon) . '"></i></div>'
        . '<div class="premium-kpi-label">' . e($label) . '</div>'
        . '<div class="premium-kpi-value">' . e($value) . '</div>'
        . ($note !== '' ? '<div class="premium-kpi-trend">' . e($note) . '</div>' : '')
        . '</article>';
}

function pdsModuleCard($title, $description, $href, $icon = 'fas fa-folder-open', $action_label = 'Open', $badge = '', $extra_class = '') {
    $badge_html = $badge !== '' ? '<span class="registry-count">' . e($badge) . '</span>' : '';
    return '<a class="' . e(pdsClassList('registry-card theme-module-card', $extra_class)) . '" href="' . e($href) . '">'
        . '<div class="registry-card-header"><span class="registry-icon"><i class="' . e($icon) . '"></i></span>' . $badge_html . '</div>'
        . '<div><h2>' . e($title) . '</h2><p>' . e($description) . '</p></div>'
        . '<span class="registry-action">' . e($action_label) . ' <i class="fas fa-arrow-right"></i></span>'
        . '</a>';
}

function pdsStatusBadge($status, $label = '') {
    $display = $label !== '' ? $label : ucwords(str_replace('_', ' ', (string) $status));
    return pdsBadge($display, $status);
}

function pdsFormInput($name, $label, $type = 'text', $value = '', $placeholder = '', $error = '', $extra_class = '', $attributes = '') {
    return pdsFormField($name, $label, $type, $value, $placeholder, $error, $extra_class, $attributes);
}

function pdsButtonPrimary($label, $href = '', $icon = '', $extra_class = '', $attributes = '') {
    return pdsButton($label, $href, 'primary-gold', $icon, pdsClassList('theme-primary-btn', $extra_class), $attributes);
}

/**
 * Shared phone-only progress rail for request and application workflows.
 * Desktop/tablet visibility is controlled by mobile-design-system.css.
 */
function mobileStepRail(array $labels, $active_step = 1, $aria_label = 'Form progress') {
    $safe_labels = array_values(array_filter(array_map('trim', $labels), static function ($label) {
        return $label !== '';
    }));
    if (empty($safe_labels)) {
        return '';
    }

    $active_step = max(1, min(intval($active_step), count($safe_labels)));
    $html = '<div class="mobile-step-rail" aria-label="' . e($aria_label) . '">';
    foreach ($safe_labels as $index => $label) {
        $step = $index + 1;
        $state = $step < $active_step ? ' is-complete' : ($step === $active_step ? ' is-active' : '');
        $html .= '<span class="mobile-step-rail-step' . $state . '">'
            . '<i class="mobile-step-rail-dot" aria-hidden="true"></i>'
            . '<span>' . e($label) . '</span>'
            . '</span>';
        if ($step < count($safe_labels)) {
            $html .= '<i class="mobile-step-rail-line" aria-hidden="true"></i>';
        }
    }
    return $html . '</div>';
}
?>
