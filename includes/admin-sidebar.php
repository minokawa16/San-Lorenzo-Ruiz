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
<link rel="stylesheet" href="../assets/css/responsive-unified.css?v=<?php echo $responsive_sidebar_style_version; ?>">
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

/* Submenu container with smooth animation */
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

/* Graceful support for collapsed mini sidebar (74px) */
html body .admin-sidebar.collapsed .nav-section-accordion-header {
  display: none !important;
}

html body .admin-sidebar.collapsed .nav-section-submenu {
  max-height: none !important;
  opacity: 1 !important;
  overflow: visible !important;
  pointer-events: auto !important;
}
</style>

<aside class="admin-sidebar" id="adminSidebar">
  <!-- 1. Header Branding Update: TUGON / PARISH ADMINISTRATION -->
  <div class="sidebar-brand">
    <div class="brand-logo">
      <i class="fas fa-cross"></i>
    </div>
    <div class="brand-text">
      <div class="brand-title">TUGON</div>
      <div class="brand-subtitle">PARISH ADMINISTRATION</div>
    </div>
    <button class="sidebar-toggle" id="adminSidebarToggle" type="button" data-admin-sidebar-toggle aria-controls="adminSidebar" aria-expanded="false" aria-label="Toggle navigation">
      <i class="fas fa-bars"></i>
    </button>
  </div>

  <nav class="sidebar-nav">
    <!-- GENERAL (Direct Link) -->
    <div class="nav-section-label">General</div>
    <a href="<?php echo BASE_URL; ?>admin/dashboard.php" class="nav-link <?php echo ($currentPage == 'dashboard.php' && strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.dashboard', 'Dashboard')); ?>">
      <i class="fas fa-table-cells-large"></i>
      <span><?php echo e(t('nav.dashboard', 'Dashboard')); ?></span>
    </a>

    <!-- 2. PARISH MANAGEMENT (Accordion) -->
    <?php if (hasAnyPermission(['users.view', 'registrations.verify'])): ?>
    <div class="nav-accordion-section <?php echo $isParishMgmtActive ? 'open' : ''; ?>" data-section="parish-management">
      <button type="button" 
              class="nav-section-accordion-header" 
              id="accordion-parish-mgmt" 
              aria-expanded="<?php echo $isParishMgmtActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-parish-mgmt">
        <span class="nav-section-title">Parish Management</span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-parish-mgmt" role="region" aria-labelledby="accordion-parish-mgmt">
        <?php if (hasPermission('users.view')): ?>
        <a href="<?php echo BASE_URL; ?>admin/manage-users.php" class="nav-link <?php echo in_array($currentPage, ['manage-users.php', 'manage-parishioners.php'], true) ? 'active' : ''; ?>" data-tooltip="Manage Parishioners">
          <i class="fas fa-users"></i>
          <span>Manage Parishioners</span>
        </a>
        <a href="<?php echo BASE_URL; ?>admin/organization.php" class="nav-link <?php echo in_array($currentPage, ['organization.php', 'org-chart.php'], true) ? 'active' : ''; ?>" data-tooltip="Parish Organization">
          <i class="fas fa-sitemap"></i>
          <span>Parish Organization</span>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('registrations.verify')): ?>
        <a href="<?php echo BASE_URL; ?>admin/verify-registrations.php" class="nav-link <?php echo ($currentPage == 'verify-registrations.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.verify_registrations', 'Verify Registrations')); ?>">
          <i class="fas fa-user-check"></i>
          <span><?php echo e(t('nav.verify_registrations', 'Verify Registrations')); ?></span>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- 3. REQUEST MANAGEMENT (Accordion) -->
    <?php if (hasAnyPermission(['requests.manage', 'requests.view', 'reservations.manage', 'reservations.view', 'calendar.manage'])): ?>
    <div class="nav-accordion-section <?php echo $isRequestMgmtActive ? 'open' : ''; ?>" data-section="request-management">
      <button type="button" 
              class="nav-section-accordion-header" 
              id="accordion-request-mgmt" 
              aria-expanded="<?php echo $isRequestMgmtActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-request-mgmt">
        <span class="nav-section-title"><?php echo e(t('nav.request_management', 'Request Management')); ?></span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-request-mgmt" role="region" aria-labelledby="accordion-request-mgmt">
        <?php if (hasAnyPermission(['requests.manage', 'requests.view', 'reservations.manage', 'reservations.view'])): ?>
        <a href="<?php echo BASE_URL; ?>admin/manage-requests.php" class="nav-link <?php echo in_array($currentPage, ['manage-requests.php', 'request-workflow.php', 'process-request.php', 'manage-reservations.php', 'manage-resources.php'], true) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.requests', 'Requests')); ?>">
          <i class="fas fa-inbox"></i>
          <span><?php echo e(t('nav.requests', 'Requests')); ?></span>
          <?php if ($sidebarPendingCount > 0): ?>
          <span class="pill-badge" id="pendingBadge"><?php echo $sidebarPendingCount > 99 ? '99+' : $sidebarPendingCount; ?></span>
          <?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('calendar.manage')): ?>
        <a href="<?php echo BASE_URL; ?>admin/manage-calendar.php" class="nav-link <?php echo ($currentPage == 'manage-calendar.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.schedule_calendar', 'Schedule Calendar')); ?>">
          <i class="fas fa-calendar-days"></i>
          <span><?php echo e(t('nav.schedule_calendar', 'Schedule Calendar')); ?></span>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- 4. SACRAMENTAL RECORDS (Accordion) -->
    <?php if (hasAnyPermission(['records.manage', 'archives.manage'])): ?>
    <div class="nav-accordion-section <?php echo $isRecordsActive ? 'open' : ''; ?>" data-section="sacramental-records">
      <button type="button" 
              class="nav-section-accordion-header" 
              id="accordion-records-mgmt" 
              aria-expanded="<?php echo $isRecordsActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-records-mgmt">
        <span class="nav-section-title"><?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?></span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-records-mgmt" role="region" aria-labelledby="accordion-records-mgmt">
        <?php if (hasPermission('records.manage')): ?>
        <a href="<?php echo BASE_URL; ?>admin/manage-records.php" class="nav-link <?php echo in_array($currentPage, ['manage-records.php', 'baptism-records.php', 'confirmation-records.php', 'communion-records.php', 'marriage-records.php', 'funeral-records.php', 'sacramental-import.php', 'record-corrections.php'], true) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?>">
          <i class="fas fa-book-bible"></i>
          <span><?php echo e(t('nav.sacramental_records', 'Sacramental Records')); ?></span>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('archives.manage')): ?>
        <a href="<?php echo BASE_URL; ?>admin/archives.php" class="nav-link <?php echo ($currentPage == 'archives.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.archives', 'Archives')); ?>">
          <i class="fas fa-box-archive"></i>
          <span><?php echo e(t('nav.archives', 'Archives')); ?></span>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- 5. CERTIFICATES (Accordion) -->
    <?php if (hasPermission('certificates.manage')): ?>
    <div class="nav-accordion-section <?php echo $isCertificatesActive ? 'open' : ''; ?>" data-section="certificates">
      <button type="button" 
              class="nav-section-accordion-header" 
              id="accordion-certificates" 
              aria-expanded="<?php echo $isCertificatesActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-certificates">
        <span class="nav-section-title">Certificates</span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-certificates" role="region" aria-labelledby="accordion-certificates">
        <a href="<?php echo BASE_URL; ?>admin/certificate-generator.php" class="nav-link <?php echo in_array($currentPage, ['certificate-generator.php', 'manual-certificate-generator.php', 'certificate-workflow.php'], true) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.generate_certificates', 'Generate Certificates')); ?>">
          <i class="fas fa-certificate"></i>
          <span><?php echo e(t('nav.generate_certificates', 'Generate Certificates')); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>admin/certificate-templates.php" class="nav-link <?php echo in_array($currentPage, ['certificate-templates.php', 'certificate-layout-editor.php'], true) ? 'active' : ''; ?>" data-tooltip="Certificate Layouts">
          <i class="fas fa-layer-group"></i>
          <span>Certificate Layouts</span>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- 6. COMMUNICATION (Accordion) -->
    <div class="nav-accordion-section <?php echo $isCommunicationActive ? 'open' : ''; ?>" data-section="communication">
      <button type="button" 
              class="nav-section-accordion-header" 
              id="accordion-communication" 
              aria-expanded="<?php echo $isCommunicationActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-communication">
        <span class="nav-section-title"><?php echo e(t('nav.communication', 'Communication')); ?></span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-communication" role="region" aria-labelledby="accordion-communication">
        <?php if (hasPermission('announcements.manage')): ?>
        <a href="<?php echo BASE_URL; ?>admin/manage-announcements.php" class="nav-link <?php echo in_array($currentPage, ['manage-announcements.php', 'post-announcement.php'], true) ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.announcements', 'Announcements')); ?>">
          <i class="fas fa-bullhorn"></i>
          <span><?php echo e(t('nav.announcements', 'Announcements')); ?></span>
        </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- 7. REPORTS & MONITORING (Accordion) -->
    <?php if (hasAnyPermission(['reports.view', 'audit.view'])): ?>
    <div class="nav-accordion-section <?php echo $isReportsActive ? 'open' : ''; ?>" data-section="reports-monitoring">
      <button type="button" 
              class="nav-section-accordion-header" 
              id="accordion-reports" 
              aria-expanded="<?php echo $isReportsActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-reports">
        <span class="nav-section-title">Reports &amp; Monitoring</span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-reports" role="region" aria-labelledby="accordion-reports">
        <?php if (hasPermission('reports.view')): ?>
        <a href="<?php echo BASE_URL; ?>admin/reports.php" class="nav-link <?php echo ($currentPage == 'reports.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.analytics_report', 'Analytics Report')); ?>">
          <i class="fas fa-chart-line"></i>
          <span><?php echo e(t('nav.analytics_report', 'Analytics &amp; Reports')); ?></span>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('audit.view')): ?>
        <a href="<?php echo BASE_URL; ?>admin/audit-logs.php" class="nav-link <?php echo ($currentPage == 'audit-logs.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.audit_logs', 'Audit Logs')); ?>">
          <i class="fas fa-clipboard-list"></i>
          <span><?php echo e(t('nav.audit_logs', 'Audit Logs')); ?></span>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- 8. SYSTEM (Accordion) -->
    <div class="nav-accordion-section <?php echo $isSystemActive ? 'open' : ''; ?>" data-section="system">
      <button type="button" 
              class="nav-section-accordion-header" 
              id="accordion-system" 
              aria-expanded="<?php echo $isSystemActive ? 'true' : 'false'; ?>" 
              aria-controls="submenu-system">
        <span class="nav-section-title">System</span>
        <i class="fas fa-chevron-down nav-accordion-arrow" aria-hidden="true"></i>
      </button>
      <div class="nav-section-submenu" id="submenu-system" role="region" aria-labelledby="accordion-system">
        <?php if (hasPermission('system.settings')): ?>
        <a href="<?php echo BASE_URL; ?>admin/settings.php" class="nav-link <?php echo ($currentPage == 'settings.php') ? 'active' : ''; ?>" data-tooltip="<?php echo e(t('nav.settings', 'Settings')); ?>">
          <i class="fas fa-cog"></i>
          <span><?php echo e(t('nav.settings', 'Settings')); ?></span>
        </a>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>admin/help.php" class="nav-link <?php echo ($currentPage == 'help.php') ? 'active' : ''; ?>" data-tooltip="Admin Manual">
          <i class="fas fa-circle-question"></i>
          <span>Admin Manual</span>
        </a>
      </div>
    </div>

    <!-- ACCOUNT (Direct Link) -->
    <div class="nav-section-label"><?php echo e(t('nav.account', 'Account')); ?></div>
    <a href="<?php echo BASE_URL; ?>auth/logout.php" class="nav-link logout" data-tooltip="<?php echo e(t('nav.logout', 'Logout')); ?>">
      <i class="fas fa-arrow-right-from-bracket"></i>
      <span><?php echo e(t('nav.logout', 'Logout')); ?></span>
    </a>
  </nav>
</aside>

<script>
// Sidebar Toggle Mechanics (Desktop Collapse & Mobile Drawer) & Accordion Logic
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

    // --- Collapsible Category Accordions Logic ---
    const accordionSections = sidebar.querySelectorAll('.nav-accordion-section');
    accordionSections.forEach(function(section) {
      const headerBtn = section.querySelector('.nav-section-accordion-header');
      const submenu = section.querySelector('.nav-section-submenu');
      const sectionKey = section.getAttribute('data-section');
      if (!headerBtn || !submenu) return;

      const hasActiveLink = !!submenu.querySelector('.nav-link.active');

      // Check saved user state from localStorage
      let isCollapsed = false;
      if (sectionKey) {
        isCollapsed = localStorage.getItem('sidebar_accordion_' + sectionKey) === 'collapsed';
      }

      // If active link inside, always keep open; otherwise follow stored state or default open
      if (hasActiveLink) {
        section.classList.add('open');
        headerBtn.setAttribute('aria-expanded', 'true');
      } else if (isCollapsed) {
        section.classList.remove('open');
        headerBtn.setAttribute('aria-expanded', 'false');
      } else {
        // Default to open for discoverability
        section.classList.add('open');
        headerBtn.setAttribute('aria-expanded', 'true');
      }

      headerBtn.addEventListener('click', function(e) {
        e.preventDefault();
        const isOpen = section.classList.toggle('open');
        headerBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if (sectionKey) {
          localStorage.setItem('sidebar_accordion_' + sectionKey, isOpen ? 'expanded' : 'collapsed');
        }
      });
    });

  });
})();
</script>
