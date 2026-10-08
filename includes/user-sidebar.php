<?php
/**
 * USER SIDEBAR NAVIGATION
 * Displays navigation menu for regular parishioner users
 */
?>

<?php
$user_navigation_page = basename($_SERVER['PHP_SELF']);
$is_user_dashboard_page = in_array($user_navigation_page, ['index.php', 'dashboard.php'], true)
  && strpos($_SERVER['PHP_SELF'], '/users/') !== false;
$is_primary_user_dashboard = $is_user_dashboard_page && (($_GET['view'] ?? '') !== 'dashboard');
?>
<?php if ($is_primary_user_dashboard): ?>
  <button class="responsive-nav-toggle responsive-nav-toggle-floating tablet-nav-trigger" type="button" data-user-sidebar-toggle aria-controls="userSidebar" aria-expanded="false" aria-label="Open navigation">
    <i class="fas fa-bars" aria-hidden="true"></i>
  </button>
<?php else: ?>
  <button class="responsive-nav-toggle responsive-nav-toggle-floating mobile-context-back" type="button" data-user-context-back data-dashboard-url="<?php echo e(BASE_URL . 'users/index.php'); ?>" aria-label="Back to dashboard menu">
    <i class="fas fa-chevron-left" aria-hidden="true"></i>
  </button>
  <button class="responsive-nav-toggle responsive-nav-toggle-floating tablet-nav-trigger" type="button" data-user-sidebar-toggle aria-controls="userSidebar" aria-expanded="false" aria-label="Open navigation">
    <i class="fas fa-bars" aria-hidden="true"></i>
  </button>
<?php endif; ?>

<aside class="user-sidebar" id="userSidebar">
  <div class="sidebar-brand">
    <div class="brand-logo">
      <i class="fas fa-church"></i>
    </div>
    <div class="brand-text">
      <div class="brand-title">TUGON</div>
    </div>
    <button class="sidebar-toggle" id="sidebarToggle" type="button" data-user-sidebar-toggle aria-controls="userSidebar" aria-expanded="false" aria-label="Toggle navigation">
      <i class="fas fa-bars"></i>
    </button>
  </div>

  <nav class="sidebar-nav">
    <?php
    // Canonical navigation config (sections: Main Menu, Communication, Account; icons include fa-table-cells-large)
    require_once __DIR__ . '/user-nav-config.php';
    $nav_config = getParishionerNavConfig($conn ?? null, $_SESSION['user_id'] ?? 0);
    foreach ($nav_config['sections'] as $sec_key => $section):
    ?>
      <div class="nav-section-label"><?php echo e($section['label']); ?></div>
      <?php foreach ($section['items'] as $item): ?>
        <?php if (!empty($item['is_collapsible'])): ?>
          <div class="nav-item nav-collapsible">
            <button class="nav-link nav-toggle" aria-expanded="false" aria-controls="requestsSubmenu">
              <i class="fas <?php echo e($item['icon']); ?>"></i>
              <span><?php echo e($item['title']); ?></span>
              <i class="fas fa-chevron-down ms-auto toggle-icon"></i>
            </button>
            <div class="nav-submenu" id="requestsSubmenu">
              <?php foreach ($item['subitems'] as $sub): ?>
                <a href="<?php echo e($sub['url']); ?>" class="nav-link sublink <?php echo !empty($sub['active']) ? 'active' : ''; ?>">
                  <i class="fas <?php echo e($sub['icon']); ?>"></i>
                  <span><?php echo e($sub['title']); ?></span>
                  <?php if (!empty($sub['badge']) && $sub['badge'] > 0): ?>
                    <span class="pill-badge ms-auto"><?php echo (int)$sub['badge']; ?></span>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php else: ?>
          <?php
          $is_ai = !empty($item['is_ai']);
          $ai_attrs = $is_ai ? ' id="sidebarAiAssistantLink" data-open-ai-chat="true" role="button" tabindex="0"' : '';
          $extra_classes = $is_ai ? ' nav-item-ai' : '';
          if (!empty($item['active'])) {
              $extra_classes .= ' active';
          }
          ?>
          <a href="<?php echo e($item['url']); ?>" class="nav-link<?php echo $extra_classes; ?>" data-tooltip="<?php echo e($item['tooltip']); ?>"<?php echo $ai_attrs; ?>>
            <i class="fas <?php echo e($item['icon']); ?>"></i>
            <span><?php echo e($item['title']); ?></span>
            <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
              <span class="pill-badge"><?php echo (int)$item['badge']; ?></span>
            <?php endif; ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <a href="<?php echo BASE_URL; ?>auth/logout.php" class="nav-link logout" data-tooltip="<?php echo e(t('nav.logout', 'Logout')); ?>">
      <i class="fas fa-arrow-right-from-bracket"></i>
      <span><?php echo e(t('nav.logout', 'Logout')); ?></span>
    </a>
  </nav>
</aside>

<script>
// Sidebar Toggle for Mobile
document.addEventListener('DOMContentLoaded', function() {
  const sidebarToggles = Array.from(document.querySelectorAll('[data-user-sidebar-toggle]'));
  const contextualBack = document.querySelector('[data-user-context-back]');
  const sidebar = document.querySelector('.user-sidebar');

  if (contextualBack) {
    contextualBack.addEventListener('click', function() {
      const dashboardUrl = contextualBack.getAttribute('data-dashboard-url') || '<?php echo e(BASE_URL . 'users/index.php'); ?>';
      window.location.assign(dashboardUrl);
    });
  }

  if (localStorage.getItem('userSidebarCollapsed') === 'true') {
    document.body.classList.add('user-sidebar-collapsed');
  }

  sidebarToggles.forEach(function(toggle) {
    toggle.addEventListener('click', function() {
      if (window.innerWidth <= 1023) {
        sidebar.classList.toggle('open');
        document.body.classList.toggle('sidebar-open', sidebar.classList.contains('open'));
        sidebarToggles.forEach(function(button) {
          button.setAttribute('aria-expanded', sidebar.classList.contains('open') ? 'true' : 'false');
        });
      } else {
        document.body.classList.toggle('user-sidebar-collapsed');
        localStorage.setItem(
          'userSidebarCollapsed',
          document.body.classList.contains('user-sidebar-collapsed')
        );
      }
    });
  });

  document.addEventListener('click', function(event) {
    if (!sidebar || window.innerWidth > 1023 || !sidebar.classList.contains('open')) {
      return;
    }
    const clickedToggle = sidebarToggles.some(function(toggle) {
      return toggle.contains(event.target);
    });
    if (!sidebar.contains(event.target) && !clickedToggle) {
      sidebar.classList.remove('open');
      document.body.classList.remove('sidebar-open');
      sidebarToggles.forEach(function(button) { button.setAttribute('aria-expanded', 'false'); });
    }
  });

  sidebar.querySelectorAll('a.nav-link').forEach(function(link) {
    link.addEventListener('click', function() {
      if (window.innerWidth <= 1023) {
        sidebar.classList.remove('open');
        document.body.classList.remove('sidebar-open');
        sidebarToggles.forEach(function(button) { button.setAttribute('aria-expanded', 'false'); });
      }
    });
  });

  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && sidebar) {
      sidebar.classList.remove('open');
      document.body.classList.remove('sidebar-open');
      sidebarToggles.forEach(function(button) { button.setAttribute('aria-expanded', 'false'); });
    }
  });

});

// Collapsible submenu toggles and auto-open active submenu
document.addEventListener('DOMContentLoaded', function() {
  var collapsibles = document.querySelectorAll('.nav-collapsible');
  collapsibles.forEach(function(item) {
    var toggle = item.querySelector('.nav-toggle');
    var submenu = item.querySelector('.nav-submenu');
    if (!toggle || !submenu) return;

    // If any submenu link is active, open the parent by default
    if (submenu.querySelector('.sublink.active')) {
      item.classList.add('open');
      toggle.setAttribute('aria-expanded', 'true');
    }

    toggle.addEventListener('click', function() {
      var isOpen = item.classList.toggle('open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  });
});
</script>
