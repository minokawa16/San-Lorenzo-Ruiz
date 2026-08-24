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
    <div class="nav-section-label"><?php echo e(t('nav.request_management', 'Request Management')); ?></div>
    <?php if (hasAnyPermission(['requests.manage', 'requests.view', 'reservations.manage', 'reservations.view'])): ?>
    <a href="<?php echo BASE_URL; ?>admin/manage-requests.php" class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['manage-requests.php', 'request-workflow.php', 'process-request.php', 'manage-reservations.php', 'manage-resources.php'], true) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.requests', 'Requests')); ?>">
      <i class="fas fa-inbox"></i>
      <span><?php echo e(t('nav.requests', 'Requests')); ?></span>
      <span class="pill-badge" id="pendingBadge" style="display:none;">0</span>
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

    <?php if (hasPermission('announcements.manage')): ?>
    <div class="nav-section-label">Communication</div>
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
      <span><?php echo e(t('nav.analytics_report', 'Analytics Report')); ?></span>
    </a>
    <?php endif; ?>
    <?php if (hasPermission('audit.view')): ?>
    <a href="<?php echo BASE_URL; ?>admin/audit-logs.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'audit-logs.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.audit_logs', 'Audit Logs')); ?>">
      <i class="fas fa-clipboard-list"></i>
      <span><?php echo e(t('nav.audit_logs', 'Audit Logs')); ?></span>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (hasPermission('system.settings')): ?>
    <div class="nav-section-label">System</div>
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
    linear-gradient(180deg, rgba(28,28,28,0.98), rgba(39,35,29,0.96));
  color: white;
  overflow-y: auto;
  z-index: 900;
  box-shadow: 18px 0 42px rgba(22,19,20,0.16);
  border-right: 1px solid rgba(255,255,255,0.08);
  transition: width 0.3s ease;
}

body {
  color: #1C1C1E;
  background:
    linear-gradient(180deg, #F8F6F1 0%, #FCFBF8 100%);
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
  border: 1px solid transparent;
  border-radius: 8px;
  color: rgba(255, 255, 255, 0.75);
  text-decoration: none;
  transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
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
  background: rgba(200, 155, 60, 0.12);
  color: #ffffff;
  box-shadow: inset 3px 0 0 #C89B3C;
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

/* Unified warm cream-white/gold admin styling. */
:root {
  --tugon-ui-primary: #C89B3C;
  --tugon-ui-primary-hover: #A77F2A;
  --tugon-ui-gold: #C89B3C;
  --tugon-ui-gold-hover: #A77F2A;
  --tugon-ui-border: #E8E1D5;
  --tugon-ui-bg: #F8F6F1;
  --tugon-ui-bg-soft: #FCFBF8;
  --tugon-ui-card: #FFFFFF;
  --tugon-ui-accent: #C89B3C;
  --tugon-ui-text: #222222;
  --tugon-ui-muted: #6B7280;
  --tugon-ui-sidebar: #2E3A2D;
  --tugon-ui-sidebar-soft: #384637;
  --tugon-ui-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
  --font-heading: "Lora", Georgia, "Times New Roman", serif;
  --font-ui: "Inter", "Segoe UI", Arial, sans-serif;
}

.admin-sidebar {
  background:
    linear-gradient(180deg, var(--tugon-ui-sidebar), var(--tugon-ui-sidebar-soft)) !important;
  color: #FFFFFF !important;
  border-right: 1px solid rgba(200, 155, 60, 0.24) !important;
  box-shadow: 12px 0 28px rgba(46, 58, 45, 0.14) !important;
}

body {
  color: var(--tugon-ui-text) !important;
  background:
    linear-gradient(180deg, var(--tugon-ui-bg) 0%, var(--tugon-ui-bg-soft) 100%) !important;
}

.admin-sidebar .sidebar-brand {
  background: rgba(255, 255, 255, 0.04) !important;
  border-bottom: 1px solid rgba(200, 155, 60, 0.24) !important;
  color: #FFFFFF !important;
}

.admin-sidebar .brand-logo,
.admin-sidebar .pill-badge {
  background: var(--tugon-ui-gold) !important;
  color: #FFFFFF !important;
  border: 1px solid var(--tugon-ui-gold) !important;
}

.admin-sidebar .brand-title,
.admin-sidebar .brand-subtitle,
.admin-sidebar .sidebar-toggle {
  color: #FFFFFF !important;
}

.admin-sidebar .brand-title {
  font-family: var(--font-heading) !important;
  font-weight: 600 !important;
}

.admin-sidebar .brand-subtitle {
  color: rgba(255, 255, 255, 0.72) !important;
}

.admin-sidebar .nav-link,
.admin-sidebar .nav-toggle,
.admin-sidebar .nav-submenu .sublink {
  color: rgba(255, 255, 255, 0.82) !important;
  font-size: 0.88rem !important;
  min-height: 44px !important;
  font-weight: 500 !important;
}

.admin-sidebar .nav-section-label {
  color: rgba(255, 255, 255, 0.52) !important;
  font-size: 0.68rem !important;
  letter-spacing: 1.2px !important;
  font-weight: 600 !important;
}

.admin-content .card-section,
.admin-content .dashboard-section,
.admin-content .request-card,
.admin-content .registry-card,
.admin-content .records-note,
.admin-content .certificate-link,
.admin-content .page-hero,
.admin-content .records-hero,
.admin-content .premium-admin-topbar,
.admin-content .premium-glass,
.admin-content .premium-admin-hero,
.admin-content .premium-kpi-card,
.admin-content .premium-panel,
.admin-content .report-card,
.admin-content .analytics-card,
.admin-content .metric-card,
.admin-content .dashboard-panel,
.admin-content .table-responsive,
.calendar-shell .card,
.calendar-shell .calendar-card,
.calendar-shell .calendar-hero {
  background: var(--tugon-ui-card) !important;
  border: 1px solid var(--tugon-ui-border) !important;
  border-radius: 16px !important;
  box-shadow: var(--tugon-ui-shadow) !important;
  color: var(--tugon-ui-text) !important;
}

.admin-content .page-hero,
.admin-content .records-hero,
.calendar-shell .calendar-hero {
  background: var(--tugon-ui-card) !important;
}

.admin-content .records-hero h1,
.admin-content .page-hero h1,
.calendar-shell .calendar-hero h1,
.admin-content .registry-card h2 {
  color: var(--tugon-ui-text) !important;
  font-weight: 700 !important;
}

.admin-content .records-hero p,
.admin-content .page-hero p,
.calendar-shell .calendar-hero p,
.admin-content .registry-card p {
  color: var(--tugon-ui-muted) !important;
  font-weight: 400 !important;
}

.admin-content .registry-icon,
.admin-content .records-total,
.admin-content .kpi-icon,
.admin-content .premium-kpi-icon,
.admin-content .hero-orb,
.admin-content .stat-icon,
.calendar-shell .calendar-icon {
  background: rgba(200, 155, 60, 0.12) !important;
  border: 0 !important;
  border-radius: 10px !important;
  color: var(--tugon-ui-text) !important;
  box-shadow: none !important;
}

.admin-content .registry-count,
.admin-content .premium-pill,
.admin-content .landing-eyebrow {
  background: rgba(200, 155, 60, 0.12) !important;
  border: 1px solid var(--tugon-ui-border) !important;
  color: var(--tugon-ui-text) !important;
  border-radius: 20px !important;
}

.admin-content .registry-action,
.admin-content .premium-btn.primary {
  background: var(--tugon-ui-primary) !important;
  border-color: var(--tugon-ui-primary) !important;
  border-radius: 10px !important;
  color: #FFFFFF !important;
  box-shadow: none !important;
}

.admin-content .registry-action:hover,
.admin-content .premium-btn.primary:hover {
  background: var(--tugon-ui-primary-hover) !important;
  border-color: var(--tugon-ui-primary-hover) !important;
  color: #FFFFFF !important;
  transform: translateY(-1px);
}

.admin-content .btn-warning,
.admin-content .btn-primary-gold,
.admin-content .premium-btn.gold {
  background: var(--tugon-ui-gold) !important;
  border-color: var(--tugon-ui-gold) !important;
  border-radius: 10px !important;
  color: #FFFFFF !important;
}

.admin-content .btn-warning:hover,
.admin-content .btn-primary-gold:hover,
.admin-content .premium-btn.gold:hover {
  background: var(--tugon-ui-gold-hover) !important;
  border-color: var(--tugon-ui-gold-hover) !important;
  color: #FFFFFF !important;
  transform: translateY(-1px);
}

.admin-sidebar .nav-link:hover,
.admin-sidebar .nav-link.active,
.admin-sidebar .nav-toggle:hover,
.admin-sidebar .nav-collapsible.open .nav-toggle,
.admin-sidebar .nav-submenu .sublink:hover,
.admin-sidebar .nav-submenu .sublink.active {
  background: rgba(255, 255, 255, 0.06) !important;
  border: 1px solid transparent !important;
  color: #FFFFFF !important;
  box-shadow: none !important;
}

body.premium-admin .admin-sidebar .nav-link.active,
body.premium-admin .admin-sidebar .nav-submenu .sublink.active {
  background: var(--tugon-ui-gold) !important;
  border: 1px solid var(--tugon-ui-gold) !important;
  color: #FFFFFF !important;
  box-shadow: inset 3px 0 0 rgba(255, 255, 255, 0.78) !important;
}

body.admin-sidebar-collapsed .admin-sidebar .nav-link::after {
  background: #222222 !important;
  color: #FFFFFF !important;
}

/* Reorganized cathedral-inspired admin navigation. */
.admin-sidebar {
  height: 100vh !important;
  overflow-x: hidden !important;
  overflow-y: auto !important;
  background: #2E3A2D !important;
  scrollbar-width: thin;
  scrollbar-color: rgba(200, 155, 60, 0.48) rgba(255, 255, 255, 0.05);
}

.admin-sidebar::-webkit-scrollbar {
  width: 6px;
}

.admin-sidebar::-webkit-scrollbar-track {
  background: rgba(255, 255, 255, 0.05);
}

.admin-sidebar::-webkit-scrollbar-thumb {
  background: rgba(200, 155, 60, 0.48);
  border-radius: 999px;
}

.admin-sidebar::-webkit-scrollbar-thumb:hover {
  background: rgba(200, 155, 60, 0.72);
}

.admin-sidebar .sidebar-brand {
  min-height: 68px !important;
  padding: 12px 14px !important;
  background: rgba(255, 255, 255, 0.035) !important;
  border-bottom: 1px solid rgba(200, 155, 60, 0.35) !important;
}

.admin-sidebar .brand-logo {
  width: 40px !important;
  height: 40px !important;
  flex: 0 0 40px !important;
  color: #C89B3C !important;
  background: rgba(200, 155, 60, 0.1) !important;
  border: 1px solid rgba(200, 155, 60, 0.5) !important;
}

.admin-sidebar .brand-title {
  color: #FFFFFF !important;
  font-family: var(--font-ui) !important;
  font-size: 0.92rem !important;
  font-weight: 700 !important;
  letter-spacing: 0.75px !important;
  white-space: nowrap;
}

.admin-sidebar .brand-subtitle {
  color: #D8D8D8 !important;
  font-size: 0.68rem !important;
}

.admin-sidebar .sidebar-nav {
  gap: 3px !important;
  padding: 8px 14px 72px !important;
}

.admin-sidebar .nav-section-label {
  margin: 18px 10px 5px !important;
  color: rgba(216, 216, 216, 0.62) !important;
  font-size: 0.66rem !important;
  font-weight: 600 !important;
  letter-spacing: 1.15px !important;
  line-height: 1.25 !important;
}

.admin-sidebar .nav-section-label:first-child {
  margin-top: 10px !important;
}

.admin-sidebar .nav-link,
.admin-sidebar .nav-link.logout {
  min-height: 42px !important;
  gap: 11px !important;
  padding: 9px 12px !important;
  color: rgba(255, 255, 255, 0.86) !important;
  background: transparent !important;
  border: 1px solid transparent !important;
  border-radius: 9px !important;
  box-shadow: none !important;
}

.admin-sidebar .nav-link i {
  width: 20px !important;
  min-width: 20px !important;
  color: #D8D8D8 !important;
  font-size: 0.95rem !important;
}

.admin-sidebar .nav-link:hover,
.admin-sidebar .nav-link.logout:hover {
  color: #FFFFFF !important;
  background: rgba(200, 155, 60, 0.10) !important;
  border-color: rgba(200, 155, 60, 0.20) !important;
}

.admin-sidebar .nav-link:hover i {
  color: #C89B3C !important;
}

body.premium-admin .admin-sidebar .nav-link.active,
.admin-sidebar .nav-link.active {
  color: #FFFFFF !important;
  background: rgba(200, 155, 60, 0.15) !important;
  border: 1px solid rgba(200, 155, 60, 0.50) !important;
  box-shadow: inset 3px 0 0 #C89B3C !important;
}

body.premium-admin .admin-sidebar .nav-link.active i,
.admin-sidebar .nav-link.active i {
  color: #C89B3C !important;
}

.admin-sidebar .pill-badge {
  min-width: 22px !important;
  height: 22px !important;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 6px !important;
  color: #2E3A2D !important;
  background: #C89B3C !important;
  border: 0 !important;
  border-radius: 999px !important;
  font-size: 0.68rem !important;
  font-weight: 700 !important;
}

@media (max-width: 1024px) and (min-width: 769px) {
  .admin-sidebar .sidebar-nav {
    padding-inline: 12px !important;
  }

  .admin-sidebar .nav-section-label {
    margin-top: 16px !important;
  }
}

</style>

<?php $canonical_admin_sidebar_version = filemtime(__DIR__ . '/../assets/css/admin-sidebar.css'); ?>
<link rel="stylesheet" href="../assets/css/admin-sidebar.css?v=<?php echo $canonical_admin_sidebar_version; ?>">

<script>
// Sidebar Toggle for Mobile
document.addEventListener('DOMContentLoaded', function() {
  const sidebarToggles = Array.from(document.querySelectorAll('[data-admin-sidebar-toggle]'));
  const sidebar = document.querySelector('.admin-sidebar');

  if (localStorage.getItem('adminSidebarCollapsed') === 'true') {
    document.body.classList.add('admin-sidebar-collapsed');
  }

  sidebarToggles.forEach(function(sidebarToggle) {
    sidebarToggle.addEventListener('click', function() {
      if (window.innerWidth <= 1023) {
        sidebar.classList.toggle('open');
        document.body.classList.toggle('sidebar-open', sidebar.classList.contains('open'));
        sidebarToggles.forEach(function(button) {
          button.setAttribute('aria-expanded', sidebar.classList.contains('open') ? 'true' : 'false');
        });
      } else {
        document.body.classList.toggle('admin-sidebar-collapsed');
        localStorage.setItem(
          'adminSidebarCollapsed',
          document.body.classList.contains('admin-sidebar-collapsed')
        );
      }
    });
  });

  document.addEventListener('click', function(event) {
    if (!sidebar || window.innerWidth > 1023 || !sidebar.classList.contains('open')) {
      return;
    }
    const clickedToggle = sidebarToggles.some(function(toggle) { return toggle.contains(event.target); });
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
</script>
