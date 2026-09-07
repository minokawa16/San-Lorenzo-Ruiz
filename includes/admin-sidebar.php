<?php
/**
 * ADMIN SIDEBAR NAVIGATION
 * Modern redesigned sidebar matching user sidebar UI/UX
 * Displays navigation menu for parish administrative staff
 */
?>

<?php $responsive_sidebar_style_version = filemtime(__DIR__ . '/../assets/css/responsive-unified.css'); ?>
<link rel="stylesheet" href="../assets/css/responsive-unified.css?v=<?php echo $responsive_sidebar_style_version; ?>">
<button class="responsive-nav-toggle responsive-nav-toggle-floating" type="button" data-admin-sidebar-toggle aria-controls="adminSidebar" aria-expanded="false" aria-label="Open navigation">
  <i class="fas fa-bars" aria-hidden="true"></i>
</button>

<aside class="admin-sidebar" id="adminSidebar">
  <div class="sidebar-brand">
    <div class="brand-logo">
      <i class="fas fa-cross"></i>
    </div>
    <div class="brand-text">
      <div class="brand-title">CONTROL PANEL</div>
      <div class="brand-subtitle">Parish Administration</div>
    </div>
    <button class="sidebar-toggle" id="adminSidebarToggle" type="button" data-admin-sidebar-toggle aria-controls="adminSidebar" aria-expanded="false" aria-label="Toggle navigation">
      <i class="fas fa-bars"></i>
    </button>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label">General</div>
    <a href="<?php echo BASE_URL; ?>admin/dashboard.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php' && strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.dashboard', 'Dashboard')); ?>">
      <i class="fas fa-table-cells-large"></i>
      <span><?php echo e(t('nav.dashboard', 'Dashboard')); ?></span>
    </a>

    <?php if (hasAnyPermission(['users.view', 'registrations.verify'])): ?>
    <div class="nav-section-label">Parish Management</div>
    <?php if (hasPermission('users.view')): ?>
    <a href="<?php echo BASE_URL; ?>admin/manage-users.php" class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['manage-users.php', 'manage-parishioners.php'], true) ? 'active' : ''; ?>" data-tooltip="Manage Parishioners">
      <i class="fas fa-users"></i>
      <span>Manage Parishioners</span>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('registrations.verify')): ?>
    <a href="<?php echo BASE_URL; ?>admin/verify-registrations.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'verify-registrations.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.verify_registrations', 'Verify Registrations')); ?>">
      <i class="fas fa-user-check"></i>
      <span><?php echo e(t('nav.verify_registrations', 'Verify Registrations')); ?></span>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (hasAnyPermission(['requests.manage', 'requests.view', 'reservations.manage', 'reservations.view', 'calendar.manage'])): ?>
    <?php
    $sidebarPendingCount = 0;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        @require_once __DIR__ . '/../database/config.php';
    }
    if (isset($conn) && $conn instanceof mysqli) {
        $countRes = $conn->query("SELECT COUNT(*) AS c FROM requests WHERE deleted_at IS NULL AND status IN ('pending', 'submitted', 'requirements_review')");
        if ($countRes) {
            $sidebarPendingCount = (int) ($countRes->fetch_assoc()['c'] ?? 0);
        }
    }
    ?>
    <div class="nav-section-label"><?php echo e(t('nav.request_management', 'Request Management')); ?></div>
    <?php if (hasAnyPermission(['requests.manage', 'requests.view', 'reservations.manage', 'reservations.view'])): ?>
    <a href="<?php echo BASE_URL; ?>admin/manage-requests.php" class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['manage-requests.php', 'request-workflow.php', 'process-request.php', 'manage-reservations.php', 'manage-resources.php'], true) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.requests', 'Requests')); ?>">
      <i class="fas fa-inbox"></i>
      <span><?php echo e(t('nav.requests', 'Requests')); ?></span>
      <?php if ($sidebarPendingCount > 0): ?>
      <span class="pill-badge" id="pendingBadge"><?php echo $sidebarPendingCount > 99 ? '99+' : $sidebarPendingCount; ?></span>
      <?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('calendar.manage')): ?>
    <a href="<?php echo BASE_URL; ?>admin/manage-calendar.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'manage-calendar.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.schedule_calendar', 'Schedule Calendar')); ?>">
      <i class="fas fa-calendar-days"></i>
      <span><?php echo e(t('nav.schedule_calendar', 'Schedule Calendar')); ?></span>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (hasAnyPermission(['records.manage', 'archives.manage'])): ?>
    <div class="nav-section-label"><?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?></div>
    <?php if (hasPermission('records.manage')): ?>
    <a href="<?php echo BASE_URL; ?>admin/manage-records.php" class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['manage-records.php', 'baptism-records.php', 'confirmation-records.php', 'communion-records.php', 'marriage-records.php', 'funeral-records.php', 'sacramental-import.php', 'record-corrections.php'], true) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?>">
      <i class="fas fa-book-bible"></i>
      <span><?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?></span>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('archives.manage')): ?>
    <a href="<?php echo BASE_URL; ?>admin/archives.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'archives.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.archives', 'Archives')); ?>">
      <i class="fas fa-box-archive"></i>
      <span><?php echo e(t('nav.archives', 'Archives')); ?></span>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (hasPermission('certificates.manage')): ?>
    <div class="nav-section-label">Certificates</div>
    <a href="<?php echo BASE_URL; ?>admin/certificate-generator.php" class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['certificate-generator.php', 'manual-certificate-generator.php', 'certificate-workflow.php'], true) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.generate_certificates', 'Generate Certificates')); ?>">
      <i class="fas fa-certificate"></i>
      <span><?php echo e(t('nav.generate_certificates', 'Generate Certificates')); ?></span>
    </a>
    <a href="<?php echo BASE_URL; ?>admin/certificate-templates.php" class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['certificate-templates.php', 'certificate-layout-editor.php'], true) ? 'active' : ''; ?>" data-tooltip="Certificate Layouts">
      <i class="fas fa-layer-group"></i>
      <span>Certificate Layouts</span>
    </a>
    <?php endif; ?>

    <div class="nav-section-label"><?php echo e(t('nav.communication', 'Communication')); ?></div>
    <?php if (hasPermission('announcements.manage')): ?>
    <a href="<?php echo BASE_URL; ?>admin/manage-announcements.php" class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['manage-announcements.php', 'post-announcement.php'], true) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.announcements', 'Announcements')); ?>">
      <i class="fas fa-bullhorn"></i>
      <span><?php echo e(t('nav.announcements', 'Announcements')); ?></span>
    </a>
    <?php endif; ?>

    <?php if (hasAnyPermission(['reports.view', 'audit.view'])): ?>
    <div class="nav-section-label">Reports &amp; Monitoring</div>
    <?php if (hasPermission('reports.view')): ?>
    <a href="<?php echo BASE_URL; ?>admin/reports.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'reports.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.analytics_report', 'Analytics Report')); ?>">
      <i class="fas fa-chart-line"></i>
      <span><?php echo e(t('nav.analytics_report', 'Analytics &amp; Reports')); ?></span>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('audit.view')): ?>
    <a href="<?php echo BASE_URL; ?>admin/audit-logs.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'audit-logs.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.audit_logs', 'Audit Logs')); ?>">
      <i class="fas fa-clipboard-list"></i>
      <span><?php echo e(t('nav.audit_logs', 'Audit Logs')); ?></span>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <div class="nav-section-label">System</div>
    <?php if (hasPermission('system.settings')): ?>
    <a href="<?php echo BASE_URL; ?>admin/settings.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.settings', 'Settings')); ?>">
      <i class="fas fa-cog"></i>
      <span><?php echo e(t('nav.settings', 'Settings')); ?></span>
    </a>
    <?php endif; ?>
    <a href="<?php echo BASE_URL; ?>admin/help.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'help.php') ? 'active' : ''; ?>" data-tooltip="Admin Manual">
      <i class="fas fa-circle-question"></i>
      <span>Admin Manual</span>
    </a>

    <div class="nav-section-label"><?php echo e(t('nav.account', 'Account')); ?></div>
    <a href="<?php echo BASE_URL; ?>auth/logout.php" class="nav-link logout" data-tooltip="<?php echo e(t('nav.logout', 'Logout')); ?>">
      <i class="fas fa-arrow-right-from-bracket"></i>
      <span><?php echo e(t('nav.logout', 'Logout')); ?></span>
    </a>
  </nav>
</aside>

<script>
// Sidebar Toggle Mechanics (Desktop Collapse & Mobile Drawer)
(function() {
  function applySidebarCollapse(isCollapsed) {
    const sidebar = document.getElementById('adminSidebar') || document.querySelector('.admin-sidebar');
    if (!sidebar) return;
    if (isCollapsed) {
      sidebar.classList.add('collapsed');
      document.body.classList.add('admin-sidebar-collapsed');
      localStorage.setItem('sidebar_state', 'collapsed');
      localStorage.setItem('adminSidebarCollapsed', 'true');
    } else {
      sidebar.classList.remove('collapsed');
      document.body.classList.remove('admin-sidebar-collapsed');
      localStorage.setItem('sidebar_state', 'expanded');
      localStorage.setItem('adminSidebarCollapsed', 'false');
    }
  }

  window.toggleSidebar = function() {
    const sidebar = document.getElementById('adminSidebar') || document.querySelector('.admin-sidebar');
    if (!sidebar) return;
    if (window.innerWidth < 1024) {
      const isOpen = sidebar.classList.toggle('open');
      document.body.classList.toggle('sidebar-open', isOpen);
      document.querySelectorAll('#adminSidebarToggle, [data-admin-sidebar-toggle], .sidebar-toggle, .responsive-nav-toggle').forEach(function(t) {
        t.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });
    } else {
      const isCurrentlyCollapsed = sidebar.classList.contains('collapsed') || document.body.classList.contains('admin-sidebar-collapsed');
      applySidebarCollapse(!isCurrentlyCollapsed);
    }
  };

  document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('adminSidebar') || document.querySelector('.admin-sidebar');
    const sidebarToggles = Array.from(document.querySelectorAll('#adminSidebarToggle, [data-admin-sidebar-toggle], .sidebar-toggle, .responsive-nav-toggle'));

    // Restore saved desktop collapsed state
    const savedState = localStorage.getItem('sidebar_state') || (localStorage.getItem('adminSidebarCollapsed') === 'true' ? 'collapsed' : 'expanded');
    if (savedState === 'collapsed' && window.innerWidth >= 1024) {
      applySidebarCollapse(true);
    }

    sidebarToggles.forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        window.toggleSidebar();
      });
    });

    // Close mobile drawer on outside click
    document.addEventListener('click', function(event) {
      if (!sidebar || window.innerWidth >= 1024 || !sidebar.classList.contains('open')) {
        return;
      }
      const clickedToggle = sidebarToggles.some(function(t) { return t.contains(event.target); });
      if (!sidebar.contains(event.target) && !clickedToggle) {
        sidebar.classList.remove('open');
        document.body.classList.remove('sidebar-open');
        sidebarToggles.forEach(function(t) { t.setAttribute('aria-expanded', 'false'); });
      }
    });

    // Close mobile drawer on nav item click
    sidebar.querySelectorAll('a.nav-link').forEach(function(link) {
      link.addEventListener('click', function() {
        if (window.innerWidth < 1024) {
          sidebar.classList.remove('open');
          document.body.classList.remove('sidebar-open');
          sidebarToggles.forEach(function(t) { t.setAttribute('aria-expanded', 'false'); });
        }
      });
    });

    // Close mobile drawer on Escape key
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape' && sidebar && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        document.body.classList.remove('sidebar-open');
        sidebarToggles.forEach(function(t) { t.setAttribute('aria-expanded', 'false'); });
      }
    });
  });
})();
</script>
