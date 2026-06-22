<?php
/**
 * Reusable Breadcrumb Component
 * 
 * Usage:
 * <?php 
 *   $breadcrumbs = [
 *       'Dashboard' => '../admin/dashboard.php',
 *       'Manage Requests' => null  // null = current page
 *   ];
 *   include '../includes/breadcrumb.php'; 
 * ?>
 */

if (isset($breadcrumbs) && is_array($breadcrumbs) && count($breadcrumbs) > 0):
?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0">
        <?php 
        $items = array_keys($breadcrumbs);
        foreach ($items as $index => $item):
            $url = $breadcrumbs[$item];
            $is_active = ($index === count($items) - 1); // Last item is active
        ?>
            <li class="breadcrumb-item <?php echo $is_active ? 'active' : ''; ?>">
                <?php if (!$is_active && $url): ?>
                    <a href="<?php echo $url; ?>" class="text-decoration-none">
                        <?php echo htmlspecialchars($item); ?>
                    </a>
                <?php else: ?>
                    <?php echo htmlspecialchars($item); ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
<?php 
endif;
?>
