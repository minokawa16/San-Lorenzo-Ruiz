<?php
/**
 * ADMIN SIDEBAR NAVIGATION
 * Modern redesigned sidebar matching user sidebar UI/UX
 * Displays navigation menu for parish administrative staff with
 * collapsible category accordion menus and TUGON branding.
 */

$currentPage = basename($_SERVER['PHP_SELF']);

// Detect which sections contain the active page to auto-expand them
$isParishMgmtActive = in_array($currentPage, ['manage-users.php', 'manage-parishioners.php', 'organization.php', 'org-chart.php', 'verify-registrations.php'], true);
$isRequestMgmtActive = in_array($currentPage, ['manage-requests.php', 'request-workflow.php', 'process-request.php', 'manage-reservations.php', 'manage-resources.php', 'manage-calendar.php'], true);
$isRecordsActive = in_array($currentPage, ['manage-records.php', 'baptism-records.php', 'confirmation-records.php', 'communion-records.php', 'marriage-records.php', 'funeral-records.php', 'sacramental-import.php', 'record-corrections.php', 'archives.php'], true);
$isCertificatesActive = in_array($currentPage, ['certificate-generator.php', 'manual-certificate-generator.php', 'certificate-workflow.php', 'certificate-templates.php', 'certificate-layout-editor.php'], true);
$isCommunicationActive = in_array($currentPage, ['manage-announcements.php', 'post-announcement.php'], true);
$isReportsActive = in_array($currentPage, ['reports.php', 'audit-logs.php'], true);
$isSystemActive = in_array($currentPage, ['settings.php', 'help.php'], true);

// Get pending requests count for badge
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

<?php $responsive_sidebar_style_version = filemtime(__DIR__ . '/../assets/css/responsive-unified.css'); ?>
<?php $admin_sidebar_style_version = file_exists(__DIR__ . '/../assets/css/admin-sidebar.css') ? filemtime(__DIR__ . '/../assets/css/admin-sidebar.css') : time(); ?>
<link rel="stylesheet" href="../assets/css/responsive-unified.css?v=<?php echo $responsive_sidebar_style_version; ?>">
<link rel="stylesheet" href="../assets/css/admin-sidebar.css?v=<?php echo $admin_sidebar_style_version; ?>">
<button class="responsive-nav-toggle responsive-nav-toggle-floating" type="button" data-admin-sidebar-toggle aria-controls="adminSidebar" aria-expanded="false" aria-label="Open navigation">
  <i class="fas fa-bars" aria-hidden="true"></i>
</button>

<style id="admin-sidebar-accordion-css">
/* --- Collapsible Accordion Category Styles --- */
.nav-accordion-section {
  display: flex;
  flex-direction: column;
  width: 100%;
  margin-bottom: 2px;
}

.nav-section-accordion-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  background: transparent;
  border: none;
  padding: 8px 10px 8px 8px;
  margin: 6px 0 2px 0;
  color: rgba(216, 216, 216, 0.7);
  font-family: "Inter", "Segoe UI", Arial, sans-serif;
  font-size: 0.63rem;
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  text-align: left;
  cursor: pointer;
  border-radius: 6px;
  transition: color 0.18s ease, background 0.18s ease;
  user-select: none;
  box-sizing: border-box;
}

.nav-section-accordion-header:hover {
  color: var(--admin-sidebar-text, #ffffff);
  background: rgba(255, 255, 255, 0.04);
}

.nav-section-accordion-header:focus-visible {
  outline: 1px solid var(--admin-sidebar-gold, #c89b3c);
  outline-offset: 1px;
}

.nav-section-title {
  flex-grow: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Accordion Arrow */
.nav-accordion-arrow {
  font-size: 0.65rem;
  color: rgba(200, 155, 60, 0.7);
  transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), color 0.2s ease;
  flex-shrink: 0;
  margin-left: 8px;
}

.nav-section-accordion-header:hover .nav-accordion-arrow {
  color: var(--admin-sidebar-gold, #c89b3c);
}

/* Rotated Arrow on Open State */
.nav-accordion-section.open > .nav-section-accordion-header .nav-accordion-arrow,
.nav-section-accordion-header[aria-expanded="true"] .nav-accordion-arrow {
  transform: rotate(-180deg);
  color: var(--admin-sidebar-gold, #c89b3c);
}

/* Submenu container with smooth animation in expanded mode */
.nav-section-submenu {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 0;
  overflow: hidden;
  opacity: 0;
  transition: max-height 0.28s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.22s ease-in-out;
  pointer-events: none;
}

.nav-accordion-section.open > .nav-section-submenu,
.nav-section-accordion-header[aria-expanded="true"] + .nav-section-submenu {
  max-height: 450px;
  opacity: 1;
  pointer-events: auto;
  padding-top: 2px;
  padding-bottom: 4px;
}

/* In expanded mode, group-icon is hidden */
html body .admin-sidebar:not(.collapsed) .nav-section-accordion-header .group-icon {
  display: none !important;
}

/* Support for collapsed mini sidebar (74px) */
html body .admin-sidebar.collapsed .nav-accordion-section {
  align-items: center !important;
}

html body .admin-sidebar.collapsed .nav-section-accordion-header {
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  width: 48px !important;
  min-width: 48px !important;
  max-width: 48px !important;
  height: 48px !important;
  min-height: 48px !important;
  border-radius: 10px !important;
  margin: 4px auto !important;
  padding: 0 !important;
  background: transparent;
  color: rgba(255, 255, 255, 0.88) !important;
  border: 1px solid transparent;
  cursor: pointer;
  box-sizing: border-box !important;
  transition: all 0.18s ease;
}

html body .admin-sidebar.collapsed .nav-section-accordion-header:hover {
  background: rgba(255, 255, 255, 0.08) !important;
  color: #ffffff !important;
}

html body .admin-sidebar.collapsed .nav-section-accordion-header:focus-visible {
  outline: 2px solid #c89b3c !important;
  outline-offset: 2px !important;
}

html body .admin-sidebar.collapsed .nav-section-accordion-header.active,
html body .admin-sidebar.collapsed .nav-accordion-section.active-group .nav-section-accordion-header {
  background: rgba(200, 155, 60, 0.18) !important;
  color: #ffffff !important;
  border: 1px solid rgba(200, 155, 60, 0.55) !important;
  box-shadow: 0 0 10px rgba(200, 155, 60, 0.25) !important;
}

html body .admin-sidebar.collapsed .nav-section-accordion-header.active .group-icon,
html body .admin-sidebar.collapsed .nav-accordion-section.active-group .nav-section-accordion-header .group-icon {
  color: #c89b3c !important;
}

html body .admin-sidebar.collapsed .nav-section-accordion-header .group-icon {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  font-size: 1.15rem !important;
  margin: 0 !important;
  width: 100% !important;
  text-align: center !important;
}

html body .admin-sidebar.collapsed .nav-section-accordion-header .nav-section-title,
html body .admin-sidebar.collapsed .nav-section-accordion-header .nav-accordion-arrow {
  display: none !important;
}

html body .admin-sidebar.collapsed .nav-section-submenu {
  display: none !important;
}
</style>

<!-- Immediate early inline check to guarantee zero FOUC on reload -->
<script>
(function() {
  try {
    // Clear legacy localStorage keys to ensure clean migration to sessionStorage
    localStorage.removeItem('sidebar_state');
    localStorage.removeItem('adminSidebarCollapsed');

    var state = sessionStorage.getItem('admin_sidebar_state');
    if (state === 'expanded' && window.innerWidth >= 1024) {
      document.documentElement.classList.add('admin-sidebar-expanded');
      document.documentElement.classList.remove('admin-sidebar-collapsed');
      if (document.body) {
        document.body.classList.remove('admin-sidebar-collapsed');
      }
    } else {
      document.documentElement.classList.add('admin-sidebar-collapsed');
      document.documentElement.classList.remove('admin-sidebar-expanded');
      if (document.body) {
        document.body.classList.add('admin-sidebar-collapsed');
      }
    }
  } catch (e) {
    document.documentElement.classList.add('admin-sidebar-collapsed');
  }
})();
</script>

<aside class="admin-sidebar collapsed" id="adminSidebar" aria-label="Parish Administration Navigation">
  <!-- 1. Header Branding Update: TUGON / PARISH ADMINISTRATION -->
  <div class="sidebar-brand">
    <div class="brand-logo">
      <i class="fas fa-cross" aria-hidden="true"></i>
    </div>
    <div class="brand-text">
      <div class="brand-title">TUGON</div>
    </div>
    <button class="sidebar-toggle" id="adminSidebarToggle" type="button" data-admin-sidebar-toggle aria-controls="adminSidebar" aria-expanded="false" aria-label="Toggle navigation">
      <i class="fas fa-bars" aria-hidden="true"></i>
    </button>
  </div>

  <nav class="sidebar-nav">
    <!-- GENERAL (Direct Link) -->
    <div class="nav-section-label">General</div>
    <a href="<?php echo BASE_URL; ?>admin/dashboard.php" class="nav-link <?php echo ($currentPage == 'dashboard.php' && strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? 'active' : ''; ?>" aria-label="<?php echo e(t('nav.dashboard', 'Dashboard')); ?>" data-tooltip="<?php echo e(t('nav.dashboard', 'Dashboard')); ?>">
      <i class="fas fa-table-cells-large" aria-hidden="true"></i>
      <span><?php echo e(t('nav.dashboard', 'Dashboard')); ?></span>
    </a>

    <!-- 2. PARISH MANAGEMENT (Accordion) -->
    <?php if (hasAnyPermission(['users.view', 'registrations.verify'])): ?>
    <div class="nav-accordion-section <?php echo $isParishMgmtActive ? 'open active-group' : ''; ?>" data-section="parish-management" data-group-title="Parish Management">
      <button type="button" 
              class="nav-section-accordion-header <?php echo $isParishMgmtActive ? 'active' : ''; ?>" 
              id="accordion-parish-mgmt" 
              aria-expanded="<?php echo $isParishMgmtActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-parish-mgmt"
              aria-label="Parish Management"
              data-tooltip="Parish Management"
              data-group="parish-management"
              tabindex="0">
        <i class="group-icon fas fa-users" aria-hidden="true"></i>
        <span class="nav-section-title">Parish Management</span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-parish-mgmt" role="region" aria-labelledby="accordion-parish-mgmt">
        <?php if (hasPermission('users.view')): ?>
        <a href="<?php echo BASE_URL; ?>admin/manage-users.php" class="nav-link <?php echo in_array($currentPage, ['manage-users.php', 'manage-parishioners.php'], true) ? 'active' : ''; ?>" aria-label="Manage Parishioners" data-tooltip="Manage Parishioners">
          <i class="fas fa-users" aria-hidden="true"></i>
          <span>Manage Parishioners</span>
        </a>
        <a href="<?php echo BASE_URL; ?>admin/organization.php" class="nav-link <?php echo in_array($currentPage, ['organization.php', 'org-chart.php'], true) ? 'active' : ''; ?>" aria-label="Parish Organization" data-tooltip="Parish Organization">
          <i class="fas fa-sitemap" aria-hidden="true"></i>
          <span>Parish Organization</span>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('registrations.verify')): ?>
        <a href="<?php echo BASE_URL; ?>admin/verify-registrations.php" class="nav-link <?php echo ($currentPage == 'verify-registrations.php') ? 'active' : ''; ?>" aria-label="<?php echo e(t('nav.verify_registrations', 'Verify Registrations')); ?>" data-tooltip="<?php echo e(t('nav.verify_registrations', 'Verify Registrations')); ?>">
          <i class="fas fa-user-check" aria-hidden="true"></i>
          <span><?php echo e(t('nav.verify_registrations', 'Verify Registrations')); ?></span>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- 3. REQUEST MANAGEMENT (Accordion) -->
    <?php if (hasAnyPermission(['requests.manage', 'requests.view', 'reservations.manage', 'reservations.view', 'calendar.manage'])): ?>
    <div class="nav-accordion-section <?php echo $isRequestMgmtActive ? 'open active-group' : ''; ?>" data-section="request-management" data-group-title="<?php echo e(t('nav.request_management', 'Request Management')); ?>">
      <button type="button" 
              class="nav-section-accordion-header <?php echo $isRequestMgmtActive ? 'active' : ''; ?>" 
              id="accordion-request-mgmt" 
              aria-expanded="<?php echo $isRequestMgmtActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-request-mgmt"
              aria-label="<?php echo e(t('nav.request_management', 'Request Management')); ?>"
              data-tooltip="<?php echo e(t('nav.request_management', 'Request Management')); ?>"
              data-group="request-management"
              tabindex="0">
        <i class="group-icon fas fa-inbox" aria-hidden="true"></i>
        <span class="nav-section-title"><?php echo e(t('nav.request_management', 'Request Management')); ?></span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-request-mgmt" role="region" aria-labelledby="accordion-request-mgmt">
        <?php if (hasAnyPermission(['requests.manage', 'requests.view', 'reservations.manage', 'reservations.view'])): ?>
        <a href="<?php echo BASE_URL; ?>admin/manage-requests.php" class="nav-link <?php echo in_array($currentPage, ['manage-requests.php', 'request-workflow.php', 'process-request.php', 'manage-reservations.php', 'manage-resources.php'], true) ? 'active' : ''; ?>" aria-label="<?php echo e(t('nav.requests', 'Requests')); ?>" data-tooltip="<?php echo e(t('nav.requests', 'Requests')); ?>">
          <i class="fas fa-inbox" aria-hidden="true"></i>
          <span><?php echo e(t('nav.requests', 'Requests')); ?></span>
          <?php if ($sidebarPendingCount > 0): ?>
          <span class="pill-badge" id="pendingBadge"><?php echo $sidebarPendingCount > 99 ? '99+' : $sidebarPendingCount; ?></span>
          <?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('calendar.manage')): ?>
        <a href="<?php echo BASE_URL; ?>admin/manage-calendar.php" class="nav-link <?php echo ($currentPage == 'manage-calendar.php') ? 'active' : ''; ?>" aria-label="<?php echo e(t('nav.schedule_calendar', 'Parish Calendar')); ?>" data-tooltip="<?php echo e(t('nav.schedule_calendar', 'Parish Calendar')); ?>">
          <i class="fas fa-calendar-days" aria-hidden="true"></i>
          <span><?php echo e(t('nav.schedule_calendar', 'Parish Calendar')); ?></span>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- 4. SACRAMENTAL RECORDS (Accordion) -->
    <?php if (hasAnyPermission(['records.manage', 'archives.manage'])): ?>
    <div class="nav-accordion-section <?php echo $isRecordsActive ? 'open active-group' : ''; ?>" data-section="sacramental-records" data-group-title="<?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?>">
      <button type="button" 
              class="nav-section-accordion-header <?php echo $isRecordsActive ? 'active' : ''; ?>" 
              id="accordion-records-mgmt" 
              aria-expanded="<?php echo $isRecordsActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-records-mgmt"
              aria-label="<?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?>"
              data-tooltip="<?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?>"
              data-group="sacramental-records"
              tabindex="0">
        <i class="group-icon fas fa-book-bible" aria-hidden="true"></i>
        <span class="nav-section-title"><?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?></span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-records-mgmt" role="region" aria-labelledby="accordion-records-mgmt">
        <?php if (hasPermission('records.manage')): ?>
        <a href="<?php echo BASE_URL; ?>admin/manage-records.php" class="nav-link <?php echo in_array($currentPage, ['manage-records.php', 'baptism-records.php', 'confirmation-records.php', 'communion-records.php', 'marriage-records.php', 'funeral-records.php', 'sacramental-import.php', 'record-corrections.php'], true) ? 'active' : ''; ?>" aria-label="<?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?>" data-tooltip="<?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?>">
          <i class="fas fa-book-bible" aria-hidden="true"></i>
          <span><?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?></span>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('archives.manage')): ?>
        <a href="<?php echo BASE_URL; ?>admin/archives.php" class="nav-link <?php echo ($currentPage == 'archives.php') ? 'active' : ''; ?>" aria-label="<?php echo e(t('nav.archives', 'Archives')); ?>" data-tooltip="<?php echo e(t('nav.archives', 'Archives')); ?>">
          <i class="fas fa-box-archive" aria-hidden="true"></i>
          <span><?php echo e(t('nav.archives', 'Archives')); ?></span>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- 5. CERTIFICATES (Accordion) -->
    <?php if (hasPermission('certificates.manage')): ?>
    <div class="nav-accordion-section <?php echo $isCertificatesActive ? 'open active-group' : ''; ?>" data-section="certificates" data-group-title="Certificates">
      <button type="button" 
              class="nav-section-accordion-header <?php echo $isCertificatesActive ? 'active' : ''; ?>" 
              id="accordion-certificates" 
              aria-expanded="<?php echo $isCertificatesActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-certificates"
              aria-label="Certificates"
              data-tooltip="Certificates"
              data-group="certificates"
              tabindex="0">
        <i class="group-icon fas fa-certificate" aria-hidden="true"></i>
        <span class="nav-section-title">Certificates</span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-certificates" role="region" aria-labelledby="accordion-certificates">
        <a href="<?php echo BASE_URL; ?>admin/certificate-generator.php" class="nav-link <?php echo in_array($currentPage, ['certificate-generator.php', 'manual-certificate-generator.php', 'certificate-workflow.php'], true) ? 'active' : ''; ?>" aria-label="<?php echo e(t('nav.generate_certificates', 'Generate Certificates')); ?>" data-tooltip="<?php echo e(t('nav.generate_certificates', 'Generate Certificates')); ?>">
          <i class="fas fa-certificate" aria-hidden="true"></i>
          <span><?php echo e(t('nav.generate_certificates', 'Generate Certificates')); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>admin/certificate-templates.php" class="nav-link <?php echo in_array($currentPage, ['certificate-templates.php', 'certificate-layout-editor.php'], true) ? 'active' : ''; ?>" aria-label="Certificate Layouts" data-tooltip="Certificate Layouts">
          <i class="fas fa-layer-group" aria-hidden="true"></i>
          <span>Certificate Layouts</span>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- 6. COMMUNICATION (Accordion) -->
    <div class="nav-accordion-section <?php echo $isCommunicationActive ? 'open active-group' : ''; ?>" data-section="communication" data-group-title="<?php echo e(t('nav.communication', 'Communication')); ?>">
      <button type="button" 
              class="nav-section-accordion-header <?php echo $isCommunicationActive ? 'active' : ''; ?>" 
              id="accordion-communication" 
              aria-expanded="<?php echo $isCommunicationActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-communication"
              aria-label="<?php echo e(t('nav.communication', 'Communication')); ?>"
              data-tooltip="<?php echo e(t('nav.communication', 'Communication')); ?>"
              data-group="communication"
              tabindex="0">
        <i class="group-icon fas fa-bullhorn" aria-hidden="true"></i>
        <span class="nav-section-title"><?php echo e(t('nav.communication', 'Communication')); ?></span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-communication" role="region" aria-labelledby="accordion-communication">
        <?php if (hasPermission('announcements.manage')): ?>
        <a href="<?php echo BASE_URL; ?>admin/manage-announcements.php" class="nav-link <?php echo in_array($currentPage, ['manage-announcements.php', 'post-announcement.php'], true) ? 'active' : ''; ?>" aria-label="<?php echo e(t('nav.announcements', 'Announcements')); ?>" data-tooltip="<?php echo e(t('nav.announcements', 'Announcements')); ?>">
          <i class="fas fa-bullhorn" aria-hidden="true"></i>
          <span><?php echo e(t('nav.announcements', 'Announcements')); ?></span>
        </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- 7. REPORTS & MONITORING (Accordion) -->
    <?php if (hasAnyPermission(['reports.view', 'audit.view'])): ?>
    <div class="nav-accordion-section <?php echo $isReportsActive ? 'open active-group' : ''; ?>" data-section="reports-monitoring" data-group-title="Reports &amp; Monitoring">
      <button type="button" 
              class="nav-section-accordion-header <?php echo $isReportsActive ? 'active' : ''; ?>" 
              id="accordion-reports" 
              aria-expanded="<?php echo $isReportsActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-reports"
              aria-label="Reports &amp; Monitoring"
              data-tooltip="Reports &amp; Monitoring"
              data-group="reports-monitoring"
              tabindex="0">
        <i class="group-icon fas fa-chart-line" aria-hidden="true"></i>
        <span class="nav-section-title">Reports &amp; Monitoring</span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-reports" role="region" aria-labelledby="accordion-reports">
        <?php if (hasPermission('reports.view')): ?>
        <a href="<?php echo BASE_URL; ?>admin/reports.php" class="nav-link <?php echo ($currentPage == 'reports.php') ? 'active' : ''; ?>" aria-label="<?php echo e(t('nav.analytics_report', 'Analytics Report')); ?>" data-tooltip="<?php echo e(t('nav.analytics_report', 'Analytics &amp; Reports')); ?>">
          <i class="fas fa-chart-line" aria-hidden="true"></i>
          <span><?php echo e(t('nav.analytics_report', 'Analytics &amp; Reports')); ?></span>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('audit.view')): ?>
        <a href="<?php echo BASE_URL; ?>admin/audit-logs.php" class="nav-link <?php echo ($currentPage == 'audit-logs.php') ? 'active' : ''; ?>" aria-label="<?php echo e(t('nav.audit_logs', 'Audit Logs')); ?>" data-tooltip="<?php echo e(t('nav.audit_logs', 'Audit Logs')); ?>">
          <i class="fas fa-clipboard-list" aria-hidden="true"></i>
          <span><?php echo e(t('nav.audit_logs', 'Audit Logs')); ?></span>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- 8. SYSTEM (Accordion) -->
    <div class="nav-accordion-section <?php echo $isSystemActive ? 'open active-group' : ''; ?>" data-section="system" data-group-title="System">
      <button type="button" 
              class="nav-section-accordion-header <?php echo $isSystemActive ? 'active' : ''; ?>" 
              id="accordion-system" 
              aria-expanded="<?php echo $isSystemActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-system"
              aria-label="System"
              data-tooltip="System"
              data-group="system"
              tabindex="0">
        <i class="group-icon fas fa-gear" aria-hidden="true"></i>
        <span class="nav-section-title">System</span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-system" role="region" aria-labelledby="accordion-system">
        <?php if (hasPermission('system.settings')): ?>
        <a href="<?php echo BASE_URL; ?>admin/settings.php" class="nav-link <?php echo ($currentPage == 'settings.php') ? 'active' : ''; ?>" aria-label="<?php echo e(t('nav.settings', 'Settings')); ?>" data-tooltip="<?php echo e(t('nav.settings', 'Settings')); ?>">
          <i class="fas fa-cog" aria-hidden="true"></i>
          <span><?php echo e(t('nav.settings', 'Settings')); ?></span>
        </a>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>admin/help.php" class="nav-link <?php echo ($currentPage == 'help.php') ? 'active' : ''; ?>" aria-label="Admin Manual" data-tooltip="Admin Manual">
          <i class="fas fa-circle-question" aria-hidden="true"></i>
          <span>Admin Manual</span>
        </a>
      </div>
    </div>

    <!-- ACCOUNT (Direct Link) -->
    <div class="nav-section-label"><?php echo e(t('nav.account', 'Account')); ?></div>
    <a href="<?php echo BASE_URL; ?>auth/logout.php" class="nav-link logout" aria-label="<?php echo e(t('nav.logout', 'Logout')); ?>" data-tooltip="<?php echo e(t('nav.logout', 'Logout')); ?>" onclick="try{sessionStorage.removeItem('admin_sidebar_state');}catch(e){}">
      <i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i>
      <span><?php echo e(t('nav.logout', 'Logout')); ?></span>
    </a>
  </nav>
</aside>

<script>
// Sidebar Toggle Mechanics (Desktop Collapse & Mobile Drawer), Floating Tooltips, and Collapsed Flyouts
(function() {
  'use strict';

  // ── 1. Sidebar Collapse Management ──────────────────────────────────────────
  function applySidebarCollapse(isCollapsed) {
    const sidebar = document.getElementById('adminSidebar') || document.querySelector('.admin-sidebar');
    if (!sidebar) return;

    if (isCollapsed) {
      sidebar.classList.add('collapsed');
      document.body.classList.add('admin-sidebar-collapsed');
      document.documentElement.classList.add('admin-sidebar-collapsed');
      document.documentElement.classList.remove('admin-sidebar-expanded');
      try {
        sessionStorage.setItem('admin_sidebar_state', 'collapsed');
      } catch (e) {}
    } else {
      sidebar.classList.remove('collapsed');
      document.body.classList.remove('admin-sidebar-collapsed');
      document.documentElement.classList.remove('admin-sidebar-collapsed');
      document.documentElement.classList.add('admin-sidebar-expanded');
      try {
        sessionStorage.setItem('admin_sidebar_state', 'expanded');
      } catch (e) {}
      // Close flyout panel when expanded
      closeFlyout();
      hideTooltip();
    }
  }

  window.toggleSidebar = function() {
    const sidebar = document.getElementById('adminSidebar') || document.querySelector('.admin-sidebar');
    if (!sidebar) return;

    if (window.innerWidth < 1024) {
      // Mobile off-canvas drawer
      const isOpen = sidebar.classList.toggle('open');
      document.body.classList.toggle('sidebar-open', isOpen);
      document.querySelectorAll('#adminSidebarToggle, [data-admin-sidebar-toggle], .sidebar-toggle, .responsive-nav-toggle').forEach(function(t) {
        t.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });
    } else {
      // Desktop collapse toggle
      const isCurrentlyCollapsed = sidebar.classList.contains('collapsed');
      applySidebarCollapse(!isCurrentlyCollapsed);
    }
  };

  // ── 2. Floating Tooltip Manager (Outside Sidebar, Unclipped, High Z-Index) ──
  let tooltipEl = null;
  let tooltipTimer = null;
  let activeTooltipTarget = null;

  function ensureTooltipElement() {
    if (!tooltipEl) {
      tooltipEl = document.getElementById('adminSidebarTooltip');
      if (!tooltipEl) {
        tooltipEl = document.createElement('div');
        tooltipEl.id = 'adminSidebarTooltip';
        tooltipEl.className = 'admin-sidebar-floating-tooltip';
        tooltipEl.setAttribute('role', 'tooltip');
        tooltipEl.setAttribute('aria-hidden', 'true');
        document.body.appendChild(tooltipEl);
      }
    }
    return tooltipEl;
  }

  function showTooltip(target) {
    const sidebar = document.getElementById('adminSidebar') || document.querySelector('.admin-sidebar');
    if (!sidebar || !sidebar.classList.contains('collapsed') || window.innerWidth < 1024) {
      return;
    }
    // If a flyout is open for this item, suppress tooltip
    const flyout = document.getElementById('adminSidebarFlyout');
    if (flyout && flyout.classList.contains('open') && flyout.getAttribute('data-active-header') === target.id) {
      return;
    }

    const text = target.getAttribute('data-tooltip') || target.getAttribute('aria-label') || '';
    if (!text) return;

    clearTimeout(tooltipTimer);
    tooltipTimer = setTimeout(function() {
      const el = ensureTooltipElement();
      el.textContent = text;

      const rect = target.getBoundingClientRect();
      const left = rect.right + 10;
      const top = rect.top + (rect.height / 2);

      el.style.left = left + 'px';
      el.style.top = top + 'px';
      el.classList.add('visible');
      el.setAttribute('aria-hidden', 'false');

      target.setAttribute('aria-describedby', 'adminSidebarTooltip');
      activeTooltipTarget = target;
    }, 100); // 100ms tiny delay to prevent mouse-movement flicker
  }

  function hideTooltip() {
    clearTimeout(tooltipTimer);
    if (tooltipEl) {
      tooltipEl.classList.remove('visible');
      tooltipEl.setAttribute('aria-hidden', 'true');
    }
    if (activeTooltipTarget) {
      activeTooltipTarget.removeAttribute('aria-describedby');
      activeTooltipTarget = null;
    }
  }

  // ── 3. Collapsed Flyout Panel Manager ─────────────────────────────────────────
  let flyoutEl = null;

  function ensureFlyoutElement() {
    if (!flyoutEl) {
      flyoutEl = document.getElementById('adminSidebarFlyout');
      if (!flyoutEl) {
        flyoutEl = document.createElement('div');
        flyoutEl.id = 'adminSidebarFlyout';
        flyoutEl.className = 'admin-sidebar-flyout-panel';
        flyoutEl.setAttribute('role', 'menu');
        flyoutEl.setAttribute('aria-hidden', 'true');
        document.body.appendChild(flyoutEl);
      }
    }
    return flyoutEl;
  }

  function closeFlyout() {
    if (flyoutEl) {
      flyoutEl.classList.remove('open');
      flyoutEl.setAttribute('aria-hidden', 'true');
      flyoutEl.removeAttribute('data-active-header');
    }
    document.querySelectorAll('.nav-section-accordion-header[data-flyout-open="true"]').forEach(function(btn) {
      btn.removeAttribute('data-flyout-open');
    });
  }
  window.closeAdminFlyout = closeFlyout;

  function openFlyout(section, headerBtn) {
    const sidebar = document.getElementById('adminSidebar') || document.querySelector('.admin-sidebar');
    if (!sidebar || !sidebar.classList.contains('collapsed') || window.innerWidth < 1024) {
      return;
    }

    hideTooltip();
    const flyout = ensureFlyoutElement();

    // If already open for this header, toggle closed
    if (flyout.classList.contains('open') && flyout.getAttribute('data-active-header') === headerBtn.id) {
      closeFlyout();
      return;
    }

    closeFlyout();

    const groupTitle = section.getAttribute('data-group-title') || headerBtn.getAttribute('aria-label') || 'Menu';
    const submenu = section.querySelector('.nav-section-submenu');
    if (!submenu) return;

    // Build flyout content
    let html = '<div class="admin-sidebar-flyout-header">' + escapeHtml(groupTitle) + '</div>';
    html += '<div class="admin-sidebar-flyout-list">';

    const links = submenu.querySelectorAll('a.nav-link');
    links.forEach(function(link) {
      const href = link.getAttribute('href');
      const isActive = link.classList.contains('active');
      const icon = link.querySelector('i');
      const iconHtml = icon ? icon.outerHTML : '';
      const textSpan = link.querySelector('span:not(.pill-badge)');
      const text = textSpan ? textSpan.textContent.trim() : link.textContent.trim();
      const badge = link.querySelector('.pill-badge');
      const badgeHtml = badge ? badge.outerHTML : '';

      html += '<a href="' + href + '" class="admin-sidebar-flyout-link ' + (isActive ? 'active' : '') + '">';
      html += iconHtml;
      html += '<span>' + escapeHtml(text) + '</span>';
      html += badgeHtml;
      html += '</a>';
    });

    html += '</div>';
    flyout.innerHTML = html;

    // Position flyout
    const rect = headerBtn.getBoundingClientRect();
    const left = rect.right + 10;
    let top = rect.top;

    flyout.style.left = left + 'px';
    flyout.style.top = top + 'px';
    flyout.classList.add('open');
    flyout.setAttribute('aria-hidden', 'false');
    flyout.setAttribute('data-active-header', headerBtn.id);
    headerBtn.setAttribute('data-flyout-open', 'true');

    // Clamp vertical position so it does not overflow viewport bottom
    const flyoutRect = flyout.getBoundingClientRect();
    if (flyoutRect.bottom > window.innerHeight - 10) {
      const clampedTop = Math.max(10, window.innerHeight - flyoutRect.height - 10);
      flyout.style.top = clampedTop + 'px';
    }
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── 4. Main Event Listeners & Accordion Wiring ──────────────────────────────
  document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('adminSidebar') || document.querySelector('.admin-sidebar');
    if (!sidebar) return;

    const sidebarToggles = Array.from(document.querySelectorAll('#adminSidebarToggle, [data-admin-sidebar-toggle], .sidebar-toggle, .responsive-nav-toggle'));

    // Check saved desktop session state (default: COLLAPSED)
    let savedState = 'collapsed';
    try {
      savedState = sessionStorage.getItem('admin_sidebar_state') || 'collapsed';
    } catch (e) {
      savedState = 'collapsed';
    }

    if (savedState === 'expanded' && window.innerWidth >= 1024) {
      applySidebarCollapse(false);
    } else {
      applySidebarCollapse(true);
    }

    // Toggle button clicks
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

    // Close flyout when clicking outside
    document.addEventListener('click', function(event) {
      const flyout = document.getElementById('adminSidebarFlyout');
      if (!flyout || !flyout.classList.contains('open')) return;

      const clickedInsideFlyout = flyout.contains(event.target);
      const clickedHeader = event.target.closest('.nav-section-accordion-header');
      if (!clickedInsideFlyout && !clickedHeader) {
        closeFlyout();
      }
    });

    // Close mobile drawer / flyouts on nav item click
    sidebar.querySelectorAll('a.nav-link').forEach(function(link) {
      link.addEventListener('click', function() {
        hideTooltip();
        closeFlyout();
        if (window.innerWidth < 1024) {
          sidebar.classList.remove('open');
          document.body.classList.remove('sidebar-open');
          sidebarToggles.forEach(function(t) { t.setAttribute('aria-expanded', 'false'); });
        }
      });
    });

    // Escape key closes tooltips, flyouts, and mobile drawer
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') {
        hideTooltip();
        closeFlyout();
        if (sidebar && sidebar.classList.contains('open') && window.innerWidth < 1024) {
          sidebar.classList.remove('open');
          document.body.classList.remove('sidebar-open');
          sidebarToggles.forEach(function(t) { t.setAttribute('aria-expanded', 'false'); });
        }
      }
    });

    // Close flyout on window resize or scroll
    window.addEventListener('resize', function() {
      hideTooltip();
      closeFlyout();
    });
    window.addEventListener('scroll', function() {
      hideTooltip();
      closeFlyout();
    }, true);

    // ── 5. Accordion & Flyout Behavior ─────────────────────────────────────────
    const accordionSections = sidebar.querySelectorAll('.nav-accordion-section');
    accordionSections.forEach(function(section) {
      const headerBtn = section.querySelector('.nav-section-accordion-header');
      const submenu = section.querySelector('.nav-section-submenu');
      const sectionKey = section.getAttribute('data-section');
      if (!headerBtn || !submenu) return;

      const hasActiveLink = !!submenu.querySelector('.nav-link.active');
      if (hasActiveLink) {
        section.classList.add('open', 'active-group');
        headerBtn.classList.add('active');
        headerBtn.setAttribute('aria-expanded', 'true');
      }

      headerBtn.addEventListener('click', function(e) {
        e.preventDefault();

        // In desktop collapsed mode: open the flyout panel to the right
        if (sidebar.classList.contains('collapsed') && window.innerWidth >= 1024) {
          openFlyout(section, headerBtn);
          return;
        }

        // In expanded or mobile mode: normal accordion expand/collapse
        const isOpen = section.classList.toggle('open');
        headerBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });
    });

    // ── 6. Tooltip Listeners (Hover & Keyboard Focus) ─────────────────────────
    const tooltipTargets = sidebar.querySelectorAll('.sidebar-nav .nav-link[data-tooltip], .nav-section-accordion-header[data-tooltip]');
    tooltipTargets.forEach(function(target) {
      target.addEventListener('mouseenter', function() {
        showTooltip(target);
      });
      target.addEventListener('mouseleave', function() {
        hideTooltip();
      });
      target.addEventListener('focus', function() {
        showTooltip(target);
      });
      target.addEventListener('blur', function() {
        hideTooltip();
      });
    });

  });
})();
</script>
