<?php
/**
 * ADMIN SIDEBAR NAVIGATION
 * Modern redesigned sidebar matching user sidebar UI/UX
 * Displays navigation menu for parish administrative staff
 */
?>

<aside class="admin-sidebar" id="adminSidebar">
  <div class="sidebar-brand">
    <div class="brand-logo">
      <i class="fas fa-crown"></i>
    </div>
    <div class="brand-text">
      <div class="brand-title">ADMIN</div>
    <div class="brand-subtitle">Control Panel</div>
    </div>
    <button class="sidebar-toggle" id="adminSidebarToggle">
      <i class="fas fa-bars"></i>
    </button>
  </div>

  <nav class="sidebar-nav">
    <a href="<?php echo BASE_URL; ?>admin/dashboard.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php' && strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.dashboard', 'Dashboard')); ?>">
      <i class="fas fa-gauge"></i>
      <span><?php echo e(t('nav.dashboard', 'Dashboard')); ?></span>
    </a>

    <?php if (hasAnyPermission(['requests.manage', 'requests.view'])): ?>
    <div class="nav-section-label"><?php echo e(t('nav.request_management', 'Request Management')); ?></div>
    <a href="<?php echo BASE_URL; ?>admin/manage-requests.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'manage-requests.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.requests', 'Requests')); ?>">
      <i class="fas fa-inbox"></i>
      <span><?php echo e(t('nav.requests', 'Requests')); ?></span>
      <span class="pill-badge" id="pendingBadge" style="display:none;">0</span>
    </a>
    <?php endif; ?>

    <?php if (hasPermission('records.manage')): ?>
    <div class="nav-section-label"><?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?></div>
    <a href="<?php echo BASE_URL; ?>admin/manage-records.php" class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['manage-records.php', 'baptism-records.php', 'confirmation-records.php', 'communion-records.php', 'marriage-records.php', 'funeral-records.php']) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?>">
      <i class="fas fa-book-bible"></i>
      <span><?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?></span>
    </a>
    <?php endif; ?>

    <div class="nav-section-label"><?php echo e(t('nav.operations', 'Operations')); ?></div>
    <?php if (hasPermission('certificates.manage')): ?>
    <a href="<?php echo BASE_URL; ?>admin/certificate-generator.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'certificate-generator.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.generate_certificates', 'Generate Certificates')); ?>">
      <i class="fas fa-certificate"></i>
      <span><?php echo e(t('nav.generate_certificates', 'Generate Certificates')); ?></span>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('announcements.manage')): ?>
    <a href="<?php echo BASE_URL; ?>admin/manage-announcements.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'manage-announcements.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.announcements', 'Announcements')); ?>">
      <i class="fas fa-bullhorn"></i>
      <span><?php echo e(t('nav.announcements', 'Announcements')); ?></span>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('calendar.manage')): ?>
    <a href="<?php echo BASE_URL; ?>admin/manage-calendar.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'manage-calendar.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.schedule_calendar', 'Schedule Calendar')); ?>">
      <i class="fas fa-calendar-days"></i>
      <span><?php echo e(t('nav.schedule_calendar', 'Schedule Calendar')); ?></span>
    </a>
    <?php endif; ?>
    <?php if (hasAnyPermission(['reservations.manage', 'reservations.view'])): ?>
    <a href="<?php echo BASE_URL; ?>admin/manage-reservations.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'manage-reservations.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.reservations', 'Reservations')); ?>">
      <i class="fas fa-calendar-check"></i>
      <span><?php echo e(t('nav.reservations', 'Reservations')); ?></span>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('reports.view')): ?>
    <a href="<?php echo BASE_URL; ?>admin/reports.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'reports.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.analytics_report', 'Analytics Report')); ?>">
      <i class="fas fa-chart-line"></i>
      <span><?php echo e(t('nav.analytics_report', 'Analytics Report')); ?></span>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('ai.use')): ?>
    <a href="<?php echo BASE_URL; ?>admin/ai-assistant.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'ai-assistant.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.ai_assistant', 'AI Assistant')); ?>">
      <i class="fas fa-robot"></i>
      <span><?php echo e(t('nav.ai_assistant', 'AI Assistant')); ?></span>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('system.settings')): ?>
    <a href="<?php echo BASE_URL; ?>admin/integration-health.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'integration-health.php') ? 'active' : ''; ?>" data-tooltip="Integration Health">
      <i class="fas fa-plug-circle-check"></i>
      <span>Integration Health</span>
    </a>
    <?php endif; ?>

    <?php if (hasAnyPermission(['users.view', 'registrations.verify', 'audit.view', 'archives.manage', 'system.settings'])): ?>
    <div class="nav-section-label"><?php echo e(t('nav.administration', 'Administration')); ?></div>
    <?php endif; ?>
    <?php if (hasPermission('users.view')): ?>
    <a href="<?php echo BASE_URL; ?>admin/manage-users.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'manage-users.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.parishioners', 'Parishioners')); ?>">
      <i class="fas fa-people-roof"></i>
      <span><?php echo e(t('nav.parishioners', 'Parishioners')); ?></span>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('registrations.verify')): ?>
    <a href="<?php echo BASE_URL; ?>admin/verify-registrations.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'verify-registrations.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.verify_registrations', 'Verify Registrations')); ?>">
      <i class="fas fa-user-shield"></i>
      <span><?php echo e(t('nav.verify_registrations', 'Verify Registrations')); ?></span>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('audit.view')): ?>
    <a href="<?php echo BASE_URL; ?>admin/audit-logs.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'audit-logs.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.audit_logs', 'Audit Logs')); ?>">
      <i class="fas fa-clipboard-list"></i>
      <span><?php echo e(t('nav.audit_logs', 'Audit Logs')); ?></span>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('archives.manage')): ?>
    <a href="<?php echo BASE_URL; ?>admin/archives.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'archives.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.archives', 'Archives')); ?>">
      <i class="fas fa-box-archive"></i>
      <span><?php echo e(t('nav.archives', 'Archives')); ?></span>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('system.settings')): ?>
    <a href="<?php echo BASE_URL; ?>admin/settings.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.settings', 'Settings')); ?>">
      <i class="fas fa-cog"></i>
      <span><?php echo e(t('nav.settings', 'Settings')); ?></span>
    </a>
    <?php endif; ?>

    <div class="nav-section-label"><?php echo e(t('nav.account', 'Account')); ?></div>
    <a href="<?php echo BASE_URL; ?>auth/logout.php" class="nav-link logout" data-tooltip="<?php echo e(t('nav.logout', 'Logout')); ?>">
      <i class="fas fa-arrow-right-from-bracket"></i>
      <span><?php echo e(t('nav.logout', 'Logout')); ?></span>
    </a>
  </nav>

</aside>

<style>
/* Admin Sidebar Styles - Modern Design */
.admin-sidebar {
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

body {
  color: var(--parish-ink, #172033);
  background:
    radial-gradient(circle at 14% 8%, rgba(247, 223, 158, 0.34), transparent 28%),
    radial-gradient(circle at 88% 18%, rgba(135, 174, 234, 0.25), transparent 26%),
    linear-gradient(180deg, #fffdf8 0%, #f7f9fc 48%, #f3f7fb 100%);
}

.admin-content,
.calendar-shell {
  flex: 1;
  margin-left: 280px;
  padding: 18px clamp(16px, 2.4vw, 34px) 38px;
  transition: margin-left 0.24s ease;
}

body.admin-sidebar-collapsed .admin-content,
body.admin-sidebar-collapsed .calendar-shell {
  margin-left: 88px;
}

.admin-content .page-title,
.calendar-shell .page-title,
.admin-content h1,
.calendar-shell h1 {
  color: var(--parish-ink, #172033);
}

.admin-content .card-section,
.admin-content .dashboard-section,
.admin-content .request-card,
.admin-content .registry-card,
.admin-content .records-note,
.admin-content .certificate-link,
.calendar-shell .card,
.calendar-shell .calendar-card {
  border: 1px solid var(--parish-line, rgba(23, 32, 51, 0.1));
  border-radius: 8px;
  background:
    linear-gradient(150deg, rgba(255,255,255,0.88), rgba(255,250,240,0.72)),
    rgba(255,255,255,0.66);
  box-shadow: var(--parish-shadow-soft, 0 14px 40px rgba(30, 41, 59, 0.08));
}

.admin-content .records-hero,
.admin-content .page-hero,
.calendar-shell .calendar-hero {
  border-radius: 8px;
  background:
    radial-gradient(circle at 12% 12%, rgba(255,255,255,0.38), transparent 22%),
    linear-gradient(135deg, rgba(23,32,51,0.94), rgba(78,104,151,0.86) 58%, rgba(215,173,67,0.72));
  color: #ffffff;
}

.admin-sidebar .sidebar-brand {
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

body.admin-sidebar-collapsed .admin-sidebar {
  width: 88px;
}

body.admin-sidebar-collapsed .admin-sidebar .brand-text,
body.admin-sidebar-collapsed .admin-sidebar .nav-link span,
body.admin-sidebar-collapsed .admin-sidebar .nav-section-label,
body.admin-sidebar-collapsed .admin-sidebar .pill-badge {
  display: none;
}

body.admin-sidebar-collapsed .admin-sidebar .nav-link::after {
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

body.admin-sidebar-collapsed .admin-sidebar .nav-link:hover::after {
  opacity: 1;
  transform: translateX(0);
}

/* Mobile Responsive */
@media (max-width: 768px) {
  .admin-sidebar {
    transform: translateX(-100%);
    width: 280px;
  }

  .admin-sidebar.open {
    transform: translateX(0);
}
}
</style>

<script>
// Sidebar Toggle for Mobile
document.addEventListener('DOMContentLoaded', function() {
  const sidebarToggle = document.getElementById('adminSidebarToggle');
  const sidebar = document.querySelector('.admin-sidebar');

  if (localStorage.getItem('adminSidebarCollapsed') === 'true') {
    document.body.classList.add('admin-sidebar-collapsed');
  }

  if (sidebarToggle) {
    sidebarToggle.addEventListener('click', function() {
      if (window.innerWidth <= 768) {
        sidebar.classList.toggle('open');
        document.body.classList.toggle('sidebar-open', sidebar.classList.contains('open'));
      } else {
        document.body.classList.toggle('admin-sidebar-collapsed');
        localStorage.setItem(
          'adminSidebarCollapsed',
          document.body.classList.contains('admin-sidebar-collapsed')
        );
      }
    });
  }

  document.addEventListener('click', function(event) {
    if (!sidebar || window.innerWidth > 768 || !sidebar.classList.contains('open')) {
      return;
    }
    if (!sidebar.contains(event.target) && (!sidebarToggle || !sidebarToggle.contains(event.target))) {
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
</script>
