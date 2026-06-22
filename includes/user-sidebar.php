<?php
/**
 * USER SIDEBAR NAVIGATION
 * Displays navigation menu for regular parishioner users
 */
?>

<aside class="user-sidebar" id="userSidebar">
  <div class="sidebar-brand">
    <div class="brand-logo">
      <i class="fas fa-church"></i>
    </div>
    <div class="brand-text">
      <div class="brand-title">San Lorenzo Ruiz</div>
      <div class="brand-subtitle">Mission Station</div>
    </div>
    <button class="sidebar-toggle" id="sidebarToggle">
      <i class="fas fa-bars"></i>
    </button>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label"><?php echo e(t('nav.main_menu', 'Main Menu')); ?></div>
    <a href="<?php echo BASE_URL; ?>users/index.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], '/users/') !== false) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.dashboard', 'Dashboard')); ?>">
      <i class="fas fa-grid-2"></i>
      <span><?php echo e(t('nav.dashboard', 'Dashboard')); ?></span>
    </a>
    <div class="nav-item nav-collapsible">
      <button class="nav-link nav-toggle" aria-expanded="false" aria-controls="requestsSubmenu">
        <i class="fas fa-layer-group"></i>
        <span><?php echo e(t('nav.my_requests', 'My Requests')); ?></span>
        <i class="fas fa-chevron-down ms-auto toggle-icon"></i>
      </button>
      <div class="nav-submenu" id="requestsSubmenu">
        <a href="<?php echo BASE_URL; ?>users/my-requests.php" class="nav-link sublink <?php echo in_array(basename($_SERVER['PHP_SELF']), ['my-requests.php', 'view-request.php'], true) ? 'active' : ''; ?>">
          <i class="fas fa-list-check"></i>
          <span><?php echo e(t('nav.track_requests', 'Track Requests')); ?></span>
        </a>
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
    <a href="<?php echo BASE_URL; ?>users/ai-assistant.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'ai-assistant.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.ai_assistant', 'AI Assistant')); ?>">
      <i class="fas fa-robot"></i>
      <span><?php echo e(t('nav.ai_assistant', 'AI Assistant')); ?></span>
    </a>

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

  <div class="sidebar-footer">
    <div class="profile-mini">
      <span class="profile-dot"></span>
      <span><?php echo substr($_SESSION['fullname'], 0, 15); ?></span>
    </div>
  </div>
</aside>

<style>
/* User Sidebar Styles */
.user-sidebar {
  position: fixed;
  left: 0;
  top: 0;
  width: 280px;
  height: 100vh;
  background:
    radial-gradient(circle at 20% 0%, rgba(247,223,158,0.2), transparent 30%),
    radial-gradient(circle at 90% 14%, rgba(135,174,234,0.2), transparent 32%),
    linear-gradient(180deg, rgba(18,24,38,0.98), rgba(13,19,32,0.96));
  color: white;
  overflow-y: auto;
  z-index: 900;
  box-shadow: 18px 0 42px rgba(22,19,20,0.16);
  border-right: 1px solid rgba(255,255,255,0.08);
  transition: width 0.3s ease;
}

.user-sidebar .sidebar-brand {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 18px 20px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}

.brand-logo {
  width: 42px;
  height: 42px;
  border-radius: 8px;
  background: linear-gradient(135deg, #fff7d5, #d7ad43);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #181204;
  border: 1px solid rgba(255,255,255,0.08);
}

.brand-text {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.brand-title {
  font-weight: 700;
  letter-spacing: 0.4px;
  font-size: 1rem;
}

.brand-subtitle {
  font-size: 0.75rem;
  opacity: 0.7;
}

.sidebar-toggle {
  background: none;
  border: none;
  color: white;
  font-size: 1.1rem;
  cursor: pointer;
}

.sidebar-nav {
  padding: 20px 16px 90px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.nav-section-label {
  text-transform: uppercase;
  font-size: 0.7rem;
  letter-spacing: 1px;
  color: rgba(255, 255, 255, 0.5);
  margin: 16px 8px 6px;
}

.nav-link {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 12px;
  border-radius: 8px;
  color: rgba(255, 255, 255, 0.75);
  text-decoration: none;
  transition: background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
  position: relative;
}

.nav-link i {
  min-width: 22px;
  text-align: center;
}

.nav-link:hover {
  background: rgba(255, 255, 255, 0.075);
  color: #ffe9a8;
}

.nav-link.active {
  background: linear-gradient(135deg, rgba(247,223,158,0.18), rgba(135,174,234,0.12));
  color: #ffffff;
  box-shadow: inset 3px 0 0 #d7ad43, 0 10px 24px rgba(0,0,0,0.12);
}

.nav-link.logout {
  color: #fca5a5;
}

.pill-badge {
  margin-left: auto;
  background: linear-gradient(135deg, #fff7d5, #d7ad43);
  color: #181204;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 0.7rem;
}

.sidebar-footer {
  position: absolute;
  bottom: 0;
  width: 100%;
  padding: 14px 20px;
  border-top: 1px solid rgba(255, 255, 255, 0.06);
  background: rgba(22,19,20,0.84);
}

.profile-mini {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 0.85rem;
}

.profile-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #d7ad43;
}

body.user-sidebar-collapsed .user-sidebar {
  width: 88px;
}

body.user-sidebar-collapsed .user-sidebar .brand-text,
body.user-sidebar-collapsed .user-sidebar .nav-link span,
body.user-sidebar-collapsed .user-sidebar .nav-section-label,
body.user-sidebar-collapsed .user-sidebar .pill-badge,
body.user-sidebar-collapsed .user-sidebar .profile-mini {
  display: none;
}

body.user-sidebar-collapsed .user-sidebar .nav-link::after {
  content: attr(data-tooltip);
  position: absolute;
  left: 72px;
  background: #0f172a;
  color: white;
  padding: 6px 10px;
  border-radius: 8px;
  font-size: 0.75rem;
  white-space: nowrap;
  opacity: 0;
  transform: translateX(-6px);
  pointer-events: none;
  transition: all 0.2s ease;
}

body.user-sidebar-collapsed .user-sidebar .nav-link:hover::after {
  opacity: 1;
  transform: translateX(0);
}

/* Collapsible submenu styles */
.nav-collapsible .nav-toggle {
  width: 100%;
  background: transparent;
  border: none;
  padding: 10px 12px;
  display: flex;
  align-items: center;
  gap: 12px;
  color: rgba(255,255,255,0.9);
  border-radius: 8px;
}
.nav-collapsible .nav-toggle .toggle-icon {
  transition: transform 0.22s ease-in-out;
}
.nav-submenu {
  max-height: 0;
  overflow: hidden;
  transition: max-height 0.25s ease-in-out, padding 0.2s ease-in-out;
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding-left: 8px;
  will-change: max-height;
}
.nav-submenu .sublink {
  padding-left: 36px;
  font-size: 0.88rem;
  color: rgba(255,255,255,0.85);
  border-radius: 8px;
  padding-top: 8px;
  padding-bottom: 8px;
}
.nav-submenu .sublink:hover {
  background: rgba(255,255,255,0.075);
  color: #ffe9a8;
}
.nav-collapsible.open .nav-submenu {
  max-height: 420px; /* large enough for submenu items */
  padding-top: 8px;
  padding-bottom: 8px;
}
.nav-collapsible.open .nav-toggle .toggle-icon {
  transform: rotate(-180deg);
}

/* Mobile Responsive */
@media (max-width: 768px) {
  .user-sidebar {
    transform: translateX(-100%);
    width: 280px;
  }

  .user-sidebar.open {
    transform: translateX(0);
  }
}
</style>

<script>
// Sidebar Toggle for Mobile
document.addEventListener('DOMContentLoaded', function() {
  const sidebarToggles = [
    document.getElementById('sidebarToggle'),
    document.getElementById('userSidebarToggle')
  ].filter(Boolean);
  const sidebar = document.querySelector('.user-sidebar');

  if (localStorage.getItem('userSidebarCollapsed') === 'true') {
    document.body.classList.add('user-sidebar-collapsed');
  }

  sidebarToggles.forEach(function(toggle) {
    toggle.addEventListener('click', function() {
      if (window.innerWidth <= 768) {
        sidebar.classList.toggle('open');
        document.body.classList.toggle('sidebar-open', sidebar.classList.contains('open'));
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
    if (!sidebar || window.innerWidth > 768 || !sidebar.classList.contains('open')) {
      return;
    }
    const clickedToggle = sidebarToggles.some(function(toggle) {
      return toggle.contains(event.target);
    });
    if (!sidebar.contains(event.target) && !clickedToggle) {
      sidebar.classList.remove('open');
      document.body.classList.remove('sidebar-open');
    }
  });

  sidebar.querySelectorAll('a.nav-link').forEach(function(link) {
    link.addEventListener('click', function() {
      if (window.innerWidth <= 768) {
        sidebar.classList.remove('open');
        document.body.classList.remove('sidebar-open');
      }
    });
  });

  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && sidebar) {
      sidebar.classList.remove('open');
      document.body.classList.remove('sidebar-open');
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
