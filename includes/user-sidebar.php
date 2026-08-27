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
      <div class="brand-title">San Lorenzo Ruiz</div>
      <div class="brand-subtitle">Mission Station</div>
    </div>
    <button class="sidebar-toggle" id="sidebarToggle" type="button" data-user-sidebar-toggle aria-controls="userSidebar" aria-expanded="false" aria-label="Toggle navigation">
      <i class="fas fa-bars"></i>
    </button>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label"><?php echo e(t('nav.main_menu', 'Main Menu')); ?></div>
    <a href="<?php echo BASE_URL; ?>users/index.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], '/users/') !== false) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.dashboard', 'Dashboard')); ?>">
      <i class="fas fa-table-cells-large"></i>
      <span><?php echo e(t('nav.dashboard', 'Dashboard')); ?></span>
    </a>
    <div class="nav-item nav-collapsible">
      <button class="nav-link nav-toggle" aria-expanded="false" aria-controls="requestsSubmenu">
        <i class="fas fa-layer-group"></i>
        <span><?php echo e(t('nav.my_requests', 'My Requests')); ?></span>
        <i class="fas fa-chevron-down ms-auto toggle-icon"></i>
      </button>
      <div class="nav-submenu" id="requestsSubmenu">
        <a href="<?php echo BASE_URL; ?>users/request-certificate.php" class="nav-link sublink <?php echo (basename($_SERVER['PHP_SELF']) == 'request-certificate.php') ? 'active' : ''; ?>">
          <i class="fas fa-certificate"></i>
          <span><?php echo e(t('nav.certificates', 'Certificates')); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>users/request-blessing.php" class="nav-link sublink <?php echo (basename($_SERVER['PHP_SELF']) == 'request-blessing.php') ? 'active' : ''; ?>">
          <i class="fas fa-hands-praying"></i>
          <span><?php echo e(t('nav.blessings', 'Blessings')); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>users/request-service.php" class="nav-link sublink <?php echo (basename($_SERVER['PHP_SELF']) == 'request-service.php') ? 'active' : ''; ?>">
          <i class="fas fa-church"></i>
          <span><?php echo e(t('nav.sacramental_services', 'Sacramental Services')); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>users/my-requests.php" class="nav-link sublink <?php echo in_array(basename($_SERVER['PHP_SELF']), ['my-requests.php', 'view-request.php'], true) ? 'active' : ''; ?>">
          <i class="fas fa-list-check"></i>
          <span><?php echo e(t('nav.track_requests', 'Track Requests')); ?></span>
        </a>
      </div>
    </div>

    <div class="nav-section-label"><?php echo e(t('nav.communication', 'Communication')); ?></div>
    <a href="<?php echo BASE_URL; ?>users/view-schedule.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'view-schedule.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.schedule', 'Schedule')); ?>">
      <i class="fas fa-calendar-days"></i>
      <span><?php echo e(t('nav.schedule', 'Schedule')); ?></span>
    </a>
    <a href="<?php echo BASE_URL; ?>users/announcements.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'announcements.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.announcements', 'Announcements')); ?>">
      <i class="fas fa-bullhorn"></i>
      <span><?php echo e(t('nav.announcements', 'Announcements')); ?></span>
    </a>
    <a href="<?php echo BASE_URL; ?>users/notifications.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'notifications.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.notifications', 'Notifications')); ?>">
      <i class="fas fa-bell"></i>
      <span><?php echo e(t('nav.notifications', 'Notifications')); ?></span>
      <?php $sidebar_unread = getUnreadNotificationCount($conn, $_SESSION['user_id'] ?? 0); ?>
      <?php if ($sidebar_unread > 0): ?>
        <span class="pill-badge"><?php echo $sidebar_unread; ?></span>
      <?php endif; ?>
    </a>
    <?php if (hasPermission('ai.parishioner.use')): ?><a href="<?php echo BASE_URL; ?>users/ai-assistant.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'ai-assistant.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.ai_assistant', 'AI Assistant')); ?>">
      <i class="fas fa-robot"></i>
      <span><?php echo e(t('nav.ai_assistant', 'AI Assistant')); ?></span>
    </a><?php endif; ?>

    <div class="nav-section-label"><?php echo e(t('nav.account', 'Account')); ?></div>
    <a href="<?php echo BASE_URL; ?>auth/profile.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'profile.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.profile_settings', 'Profile Settings')); ?>">
      <i class="fas fa-user-gear"></i>
      <span><?php echo e(t('nav.profile_settings', 'Profile Settings')); ?></span>
    </a>
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
