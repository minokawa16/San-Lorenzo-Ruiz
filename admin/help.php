<?php
/**
 * Admin Operational Manual & Documentation Guide (/admin/help.php)
 * Comprehensive operational manual for Parish Administrators explaining parishioner verification,
 * request approval workflows, automatic record generation, calendar scheduling, and analytics.
 */

require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';
require_once '../includes/permissions.php';

requireLogin();
if (!isBackOfficeUser()) {
    redirect(BASE_URL . 'index.php');
}

$page_title = 'Admin Operational Manual';
$breadcrumbs = [
    'Dashboard' => 'index.php',
    'Admin Manual' => null
];

include '../templates/header.php';
?>

<style>
/* --------------------------------------------------------------------------
   ADMIN OPERATIONAL MANUAL STYLES
   Palette: Forest Green (#2E3A2D), Gold (#C89B3C), Teal (#0d9488), Slate
   -------------------------------------------------------------------------- */
:root {
  --adm-green:      #2E3A2D;
  --adm-green-mid:  #3D5C3A;
  --adm-green-dim:  rgba(46, 58, 45, 0.08);
  --adm-gold:       #C89B3C;
  --adm-gold-dim:   rgba(200, 155, 60, 0.12);
  --adm-teal:       #0d9488;
  --adm-teal-dim:   rgba(13, 148, 136, 0.10);
  --adm-blue:       #2563eb;
  --adm-blue-dim:   rgba(37, 99, 235, 0.10);
  --adm-purple:     #7c3aed;
  --adm-purple-dim: rgba(124, 58, 237, 0.10);
  --adm-amber:      #d97706;
  --adm-amber-dim:  rgba(217, 119, 6, 0.10);
  --adm-rose:       #e11d48;
  --adm-rose-dim:   rgba(225, 29, 72, 0.10);
  --adm-slate-50:   #f8fafc;
  --adm-slate-100:  #f1f5f9;
  --adm-slate-200:  #e2e8f0;
  --adm-slate-300:  #cbd5e1;
  --adm-slate-600:  #475569;
  --adm-slate-700:  #334155;
  --adm-slate-800:  #1e293b;
  --adm-radius:     14px;
  --adm-shadow-sm:  0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
  --adm-shadow-md:  0 4px 14px rgba(0,0,0,0.07), 0 2px 5px rgba(0,0,0,0.03);
}

.adm-docs-wrap {
  font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
  color: var(--adm-slate-700);
  padding-bottom: 50px;
}

/* --- Hero Banner --- */
.adm-hero {
  background: linear-gradient(135deg, #1e293b 0%, #2E3A2D 60%, #152219 100%);
  border-radius: var(--adm-radius);
  padding: 32px 36px;
  color: #ffffff;
  margin-bottom: 28px;
  position: relative;
  overflow: hidden;
  box-shadow: var(--adm-shadow-md);
}
.adm-hero::after {
  content: '';
  position: absolute;
  top: -40px;
  right: -40px;
  width: 280px;
  height: 280px;
  background: radial-gradient(circle, rgba(200,155,60,0.22) 0%, rgba(200,155,60,0) 70%);
  pointer-events: none;
}
.adm-hero-title {
  font-size: 1.65rem;
  font-weight: 800;
  letter-spacing: -0.02em;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.adm-hero-badge {
  background: var(--adm-gold);
  color: #1e293b;
  font-size: 0.72rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  padding: 4px 10px;
  border-radius: 20px;
  vertical-align: middle;
}
.adm-hero-sub {
  font-size: 0.92rem;
  color: rgba(255,255,255,0.85);
  max-width: 720px;
  margin-bottom: 22px;
  line-height: 1.5;
}

/* Search bar */
.adm-search-wrap {
  position: relative;
  max-width: 620px;
}
.adm-search-input {
  width: 100%;
  padding: 13px 44px 13px 46px;
  border-radius: 12px;
  border: 1.5px solid rgba(255,255,255,0.18);
  background: rgba(255,255,255,0.95);
  font-size: 0.92rem;
  color: var(--adm-slate-800);
  outline: none;
  box-shadow: 0 4px 18px rgba(0,0,0,0.15);
  transition: all 0.2s ease;
}
.adm-search-input:focus {
  background: #ffffff;
  border-color: var(--adm-gold);
  box-shadow: 0 0 0 4px rgba(200,155,60,0.30);
}
.adm-search-icon {
  position: absolute;
  left: 16px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--adm-slate-600);
  font-size: 1rem;
  pointer-events: none;
}
.adm-search-clear {
  position: absolute;
  right: 14px;
  top: 50%;
  transform: translateY(-50%);
  background: transparent;
  border: none;
  color: var(--adm-slate-600);
  cursor: pointer;
  display: none;
  font-size: 0.9rem;
}
.adm-quick-pills {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 14px;
}
.adm-pill {
  background: rgba(255,255,255,0.12);
  color: #fff;
  border: 1px solid rgba(255,255,255,0.16);
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 0.76rem;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.15s;
  cursor: pointer;
}
.adm-pill:hover {
  background: var(--adm-gold);
  color: #1e293b;
  border-color: var(--adm-gold);
}

/* --- Layout Grid --- */
.adm-layout {
  display: grid;
  grid-template-columns: 290px minmax(0, 1fr);
  gap: 28px;
  align-items: flex-start;
}
@media (max-width: 991px) {
  .adm-layout {
    grid-template-columns: 1fr;
  }
}

/* --- Left TOC Column --- */
.adm-toc-card {
  background: #ffffff;
  border: 1px solid var(--adm-slate-200);
  border-radius: var(--adm-radius);
  padding: 18px 16px;
  box-shadow: var(--adm-shadow-sm);
  position: sticky;
  top: 90px;
  max-height: calc(100vh - 110px);
  overflow-y: auto;
  scrollbar-width: thin;
}
.adm-toc-header {
  font-size: 0.72rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--adm-slate-600);
  padding: 0 8px 10px;
  border-bottom: 1px solid var(--adm-slate-100);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.adm-toc-list {
  list-style: none;
  padding: 0;
  margin: 0;
}
.adm-toc-item {
  margin-bottom: 3px;
}
.adm-toc-link {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--adm-slate-700);
  text-decoration: none;
  transition: all 0.15s;
}
.adm-toc-link i {
  width: 18px;
  text-align: center;
  font-size: 0.88rem;
  color: var(--adm-slate-600);
  flex-shrink: 0;
}
.adm-toc-link:hover {
  background: var(--adm-slate-100);
  color: var(--adm-green);
}
.adm-toc-link.active {
  background: var(--adm-green-dim);
  color: var(--adm-green);
  font-weight: 700;
  border-left: 3px solid var(--adm-green);
}
.adm-toc-link.active i {
  color: var(--adm-gold);
}
.adm-toc-shortcuts {
  margin-top: 20px;
  padding-top: 14px;
  border-top: 1px solid var(--adm-slate-100);
}
.adm-shortcut-btn {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 0.78rem;
  font-weight: 600;
  background: var(--adm-slate-50);
  border: 1px solid var(--adm-slate-200);
  color: var(--adm-slate-700);
  text-decoration: none;
  margin-bottom: 6px;
  transition: all 0.15s;
}
.adm-shortcut-btn:hover {
  background: var(--adm-gold-dim);
  border-color: var(--adm-gold);
  color: var(--adm-green);
}

/* --- Content Cards --- */
.adm-card {
  background: #ffffff;
  border: 1px solid var(--adm-slate-200);
  border-radius: var(--adm-radius);
  padding: 28px 32px;
  margin-bottom: 24px;
  box-shadow: var(--adm-shadow-sm);
  transition: box-shadow 0.2s;
  scroll-margin-top: 90px;
}
.adm-card:hover {
  box-shadow: var(--adm-shadow-md);
}
.adm-card-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  border-bottom: 1.5px solid var(--adm-slate-100);
  padding-bottom: 16px;
  margin-bottom: 20px;
}
.adm-card-meta {
  display: flex;
  align-items: center;
  gap: 14px;
}
.adm-card-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.15rem;
  flex-shrink: 0;
}
.icon-green  { background: var(--adm-green-dim);  color: var(--adm-green); }
.icon-gold   { background: var(--adm-gold-dim);   color: var(--adm-gold); }
.icon-teal   { background: var(--adm-teal-dim);   color: var(--adm-teal); }
.icon-blue   { background: var(--adm-blue-dim);   color: var(--adm-blue); }
.icon-purple { background: var(--adm-purple-dim); color: var(--adm-purple); }
.icon-amber  { background: var(--adm-amber-dim);  color: var(--adm-amber); }

.adm-card-title {
  font-size: 1.25rem;
  font-weight: 800;
  color: var(--adm-slate-800);
  margin: 0 0 2px;
  letter-spacing: -0.01em;
}
.adm-card-sub {
  font-size: 0.8rem;
  color: var(--adm-slate-600);
  margin: 0;
}
.adm-card-tag {
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 3px 10px;
  border-radius: 20px;
  background: var(--adm-slate-100);
  color: var(--adm-slate-600);
}

/* Step lists */
.adm-step-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin: 18px 0;
}
.adm-step-item {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  padding: 14px 16px;
  border-radius: 10px;
  background: var(--adm-slate-50);
  border: 1px solid var(--adm-slate-200);
}
.adm-step-num {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--adm-green);
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.82rem;
  font-weight: 800;
  flex-shrink: 0;
  margin-top: 1px;
}
.adm-step-content {
  flex: 1;
}
.adm-step-title {
  font-size: 0.9rem;
  font-weight: 700;
  color: var(--adm-slate-800);
  margin-bottom: 3px;
}
.adm-step-desc {
  font-size: 0.83rem;
  color: var(--adm-slate-600);
  margin: 0;
  line-height: 1.5;
}

/* Callouts */
.callout {
  padding: 14px 18px;
  border-radius: 10px;
  margin: 16px 0;
  display: flex;
  gap: 12px;
  align-items: flex-start;
  font-size: 0.84rem;
  line-height: 1.5;
}
.callout i {
  font-size: 1.05rem;
  margin-top: 2px;
  flex-shrink: 0;
}
.callout-info {
  background: #eff6ff;
  border-left: 4px solid #3b82f6;
  color: #1e40af;
}
.callout-warning {
  background: #fffbeb;
  border-left: 4px solid #f59e0b;
  color: #92400e;
}
.callout-tip {
  background: #f0fdf4;
  border-left: 4px solid #10b981;
  color: #166534;
}
.callout-purple {
  background: #faf5ff;
  border-left: 4px solid #8b5cf6;
  color: #5b21b6;
}

/* Accordions */
.adm-accordion-item {
  border: 1px solid var(--adm-slate-200);
  border-radius: 10px;
  margin-bottom: 10px;
  overflow: hidden;
}
.adm-accordion-btn {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  background: #fff;
  border: none;
  font-size: 0.87rem;
  font-weight: 700;
  color: var(--adm-slate-800);
  cursor: pointer;
  text-align: left;
  transition: background 0.15s;
}
.adm-accordion-btn:hover {
  background: var(--adm-slate-50);
}
.adm-accordion-btn i.fa-chevron-down {
  transition: transform 0.2s;
  font-size: 0.8rem;
  color: var(--adm-slate-600);
}
.adm-accordion-btn.active i.fa-chevron-down {
  transform: rotate(180deg);
}
.adm-accordion-body {
  display: none;
  padding: 14px 18px;
  background: var(--adm-slate-50);
  border-top: 1px solid var(--adm-slate-200);
  font-size: 0.84rem;
  line-height: 1.55;
  color: var(--adm-slate-700);
}
.adm-accordion-body.show {
  display: block;
}

/* Workflow Diagrams / Flowboxes */
.workflow-box {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  margin: 16px 0;
}
.wf-node {
  background: #fff;
  border: 1.5px solid var(--adm-slate-200);
  border-radius: 10px;
  padding: 12px 14px;
  position: relative;
}
.wf-node-step {
  font-size: 0.68rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--adm-gold);
  margin-bottom: 3px;
}
.wf-node-title {
  font-size: 0.84rem;
  font-weight: 700;
  color: var(--adm-slate-800);
  margin-bottom: 2px;
}
.wf-node-desc {
  font-size: 0.74rem;
  color: var(--adm-slate-600);
  line-height: 1.4;
  margin: 0;
}

/* Badges */
.badge-pill {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 6px;
  font-size: 0.72rem;
  font-weight: 700;
}
.badge-gold   { background: var(--adm-gold-dim); color: #854d0e; }
.badge-green  { background: #dcfce7; color: #166534; }
.badge-blue   { background: #dbeafe; color: #1e40af; }
.badge-purple { background: #f3e8ff; color: #6b21a8; }
.badge-red    { background: #ffe4e6; color: #9f1239; }

/* Empty Search State */
#admSearchEmpty {
  display: none;
  text-align: center;
  padding: 50px 20px;
  background: #fff;
  border: 1px dashed var(--adm-slate-300);
  border-radius: var(--adm-radius);
  color: var(--adm-slate-600);
}
</style>

<div class="adm-docs-wrap container-fluid px-0">

  <!-- ================= HERO BANNER ================= -->
  <div class="adm-hero">
    <div class="adm-hero-title">
      <i class="fas fa-book-bookmark"></i>
      Parish Administrator Operational Manual
      <span class="adm-hero-badge">Staff &amp; Admin</span>
    </div>
    <p class="adm-hero-sub">
      Comprehensive operational guidelines for verifying parishioner profiles, processing requests, automated sacramental record logging, calendar slot locking, canonical registries, and analytics reporting.
    </p>

    <!-- Search input -->
    <div class="adm-search-wrap">
      <i class="fas fa-search adm-search-icon"></i>
      <input type="text" id="admSearchInput" class="adm-search-input" placeholder="Search operational steps, workflows, canonical records, calendar..." aria-label="Search Admin Manual">
      <button type="button" id="admSearchClear" class="adm-search-clear" title="Clear search"><i class="fas fa-times"></i></button>
    </div>

    <!-- Quick Navigation Pills -->
    <div class="adm-quick-pills">
      <span class="adm-pill" data-target="#adminModule1">Parishioner Verification</span>
      <span class="adm-pill" data-target="#adminModule2">Request Workflows</span>
      <span class="adm-pill" data-target="#adminModule3">Sacramental Registries</span>
      <span class="adm-pill" data-target="#adminModule4">Master Calendar</span>
      <span class="adm-pill" data-target="#adminModule5">Analytics &amp; PDF Reports</span>
      <span class="adm-pill" data-target="#adminModule6">Security &amp; Audit Logs</span>
    </div>
  </div>

  <!-- ================= MAIN LAYOUT ================= -->
  <div class="adm-layout">

    <!-- LEFT: Sticky TOC -->
    <aside class="adm-toc-col">
      <div class="adm-toc-card">
        <div class="adm-toc-header">
          <span>Operational Modules</span>
          <i class="fas fa-shield-halved"></i>
        </div>
        <ul class="adm-toc-list">
          <li class="adm-toc-item">
            <a href="#adminModule1" class="adm-toc-link active">
              <i class="fas fa-users-viewfinder"></i>
              <span>1. Parishioner Verification</span>
            </a>
          </li>
          <li class="adm-toc-item">
            <a href="#adminModule2" class="adm-toc-link">
              <i class="fas fa-diagram-project"></i>
              <span>2. Request Workflows</span>
            </a>
          </li>
          <li class="adm-toc-item">
            <a href="#adminModule3" class="adm-toc-link">
              <i class="fas fa-book-bible"></i>
              <span>3. Sacramental Registries</span>
            </a>
          </li>
          <li class="adm-toc-item">
            <a href="#adminModule4" class="adm-toc-link">
              <i class="fas fa-calendar-check"></i>
              <span>4. Schedule &amp; Calendar</span>
            </a>
          </li>
          <li class="adm-toc-item">
            <a href="#adminModule5" class="adm-toc-link">
              <i class="fas fa-chart-pie"></i>
              <span>5. Analytics &amp; Reports</span>
            </a>
          </li>
          <li class="adm-toc-item">
            <a href="#adminModule6" class="adm-toc-link">
              <i class="fas fa-file-shield"></i>
              <span>6. Security &amp; Audit Logs</span>
            </a>
          </li>
        </ul>

        <!-- Direct Admin Tool Shortcuts -->
        <div class="adm-toc-shortcuts">
          <div class="adm-toc-header" style="padding-left:0; margin-bottom:8px;">
            <span>Admin Tool Shortcuts</span>
            <i class="fas fa-arrow-up-right-from-square"></i>
          </div>
          <a href="<?php echo BASE_URL; ?>admin/request-workflow.php" class="adm-shortcut-btn">
            <span><i class="fas fa-inbox" style="margin-right:6px; color:var(--adm-blue);"></i> Request Workflow</span>
            <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
          </a>
          <a href="<?php echo BASE_URL; ?>admin/parishioners.php" class="adm-shortcut-btn">
            <span><i class="fas fa-users" style="margin-right:6px; color:var(--adm-green);"></i> Parishioners Queue</span>
            <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
          </a>
          <a href="<?php echo BASE_URL; ?>admin/sacramental-records.php" class="adm-shortcut-btn">
            <span><i class="fas fa-book-bookmark" style="margin-right:6px; color:var(--adm-gold);"></i> Registry Books</span>
            <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
          </a>
          <a href="<?php echo BASE_URL; ?>admin/schedule.php" class="adm-shortcut-btn">
            <span><i class="fas fa-calendar-days" style="margin-right:6px; color:var(--adm-teal);"></i> Master Schedule</span>
            <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
          </a>
          <a href="<?php echo BASE_URL; ?>admin/reports.php" class="adm-shortcut-btn">
            <span><i class="fas fa-chart-line" style="margin-right:6px; color:var(--adm-purple);"></i> Analytics &amp; Reports</span>
            <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
          </a>
        </div>
      </div>
    </aside>

    <!-- RIGHT: Content Area -->
    <main class="adm-content-col">

      <!-- Empty Search State -->
      <div id="admSearchEmpty">
        <i class="fas fa-magnifying-glass" style="font-size:2.4rem; opacity:0.3; margin-bottom:12px;"></i>
        <h5 style="font-weight:800; color:var(--adm-slate-800);">No administrative topics match your search</h5>
        <p style="font-size:0.85rem; margin-bottom:14px;">Try searching for terms like "verification", "workflow", "sacramental records", "schedule", or "export".</p>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetAdmSearch()">Clear Search</button>
      </div>

      <!-- ================= MODULE 1 ================= -->
      <section id="adminModule1" class="adm-card">
        <div class="adm-card-header">
          <div class="adm-card-meta">
            <div class="adm-card-icon icon-gold">
              <i class="fas fa-users-viewfinder"></i>
            </div>
            <div>
              <h2 class="adm-card-title">Module 1: Parishioner Management &amp; Verification</h2>
              <p class="adm-card-sub">Validating OCR captures, managing account status, and canonical registration</p>
            </div>
          </div>
          <span class="adm-card-tag">Census &amp; Profiles</span>
        </div>

        <p style="font-size:0.87rem; line-height:1.6;">
          All new accounts created through the registration portal start in <code>pending_verification</code> status. Parish staff must inspect the live-captured government ID image and confirm that the OCR-extracted details accurately reflect the registrant.
        </p>

        <!-- Step List -->
        <div class="adm-step-list">
          <div class="adm-step-item">
            <div class="adm-step-num">1</div>
            <div class="adm-step-content">
              <div class="adm-step-title">Access the Verification Queue</div>
              <p class="adm-step-desc">Open <a href="<?php echo BASE_URL; ?>admin/parishioners.php">Parishioners</a> and filter by status: <strong>Pending Verification</strong>. Users requiring review are highlighted with an amber clock badge.</p>
            </div>
          </div>

          <div class="adm-step-item">
            <div class="adm-step-num">2</div>
            <div class="adm-step-content">
              <div class="adm-step-title">Examine the ID Inspection Modal</div>
              <p class="adm-step-desc">Click <strong>View Details / Verify</strong>. The system displays the high-resolution live camera ID capture side-by-side with the registrant's name, birthdate, gender, address, and ID number.</p>
            </div>
          </div>

          <div class="adm-step-item">
            <div class="adm-step-num">3</div>
            <div class="adm-step-content">
              <div class="adm-step-title">Verification Approval or Flagging</div>
              <p class="adm-step-desc">
                If the details match: Click <strong><i class="fas fa-check"></i> Verify &amp; Activate Account</strong>. The user's status updates to <code>active</code>, sending an automated notification to their phone and email.<br>
                If the image is blurry or mismatched: Click <strong>Flag Profile</strong> and supply remarks explaining why a re-capture is needed.
              </p>
            </div>
          </div>
        </div>

        <div class="callout callout-tip">
          <i class="fas fa-user-shield"></i>
          <div>
            <strong>Role Hierarchies:</strong>
            Only administrators with the <code>users.manage</code> permission can modify roles (e.g. promoting a verified parishioner to staff). Every role change is permanently recorded in the central audit ledger.
          </div>
        </div>
      </section>

      <!-- ================= MODULE 2 ================= -->
      <section id="adminModule2" class="adm-card">
        <div class="adm-card-header">
          <div class="adm-card-meta">
            <div class="adm-card-icon icon-blue">
              <i class="fas fa-diagram-project"></i>
            </div>
            <div>
              <h2 class="adm-card-title">Module 2: Processing Service &amp; Certificate Requests</h2>
              <p class="adm-card-sub">Request review queue, conditional views, and automated record/calendar sync</p>
            </div>
          </div>
          <span class="adm-card-tag">Request Processing</span>
        </div>

        <p style="font-size:0.87rem; line-height:1.6;">
          The <a href="<?php echo BASE_URL; ?>admin/request-workflow.php">Request Workflow</a> module manages the complete lifecycle of sacramental certificates, mass intentions, blessings, and liturgy bookings.
        </p>

        <!-- Lifecycle flow nodes -->
        <div class="workflow-box">
          <div class="wf-node">
            <div class="wf-node-step">Stage 1</div>
            <div class="wf-node-title">Submitted</div>
            <p class="wf-node-desc">Request received from parishioner. Assigned a unique tracking code.</p>
          </div>
          <div class="wf-node">
            <div class="wf-node-step">Stage 2</div>
            <div class="wf-node-title">Requirements Review</div>
            <p class="wf-node-desc">Staff validates PSA certificates, sponsor lists, and investigation forms.</p>
          </div>
          <div class="wf-node">
            <div class="wf-node-step">Stage 3</div>
            <div class="wf-node-title">In Processing</div>
            <p class="wf-node-desc">Archival books retrieved. Certificate encoded and printed for sealing.</p>
          </div>
          <div class="wf-node">
            <div class="wf-node-step">Stage 4</div>
            <div class="wf-node-title">Completed / Ready</div>
            <p class="wf-node-desc">Signed and dry-sealed. Automatic calendar lock &amp; sacramental record logging.</p>
          </div>
        </div>

        <!-- Conditional View Rules -->
        <div class="docs-accordion-item">
          <button type="button" class="adm-accordion-btn">
            <span><i class="fas fa-sliders" style="margin-right:8px; color:var(--adm-teal);"></i> Conditional Interface Logic by Request Category</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="adm-accordion-body">
            <ul class="docs-checklist">
              <li><strong>Certificate Requests:</strong> Displays the <em>Release Certificate</em> card, <em>Payment Receipts</em> section, and <em>Certificates Released to Parishioner</em> history.</li>
              <li><strong>Blessings &amp; Sacramental Services (Baptism, Wedding, Funeral):</strong> Hides certificate release cards and instead renders the dedicated <em>Submitted Application Form</em> card with sponsors, priest preference, and investigation sheets.</li>
            </ul>
          </div>
        </div>

        <!-- Automated Record & Calendar Generation Callout -->
        <div class="callout callout-purple">
          <i class="fas fa-wand-magic-sparkles"></i>
          <div>
            <strong>Automated Canonical Pipeline:</strong><br>
            When an admin marks a Sacramental Service (such as a Baptism, Wedding, or Funeral) as <strong>Completed</strong>:
            <ul style="margin: 6px 0 0; padding-left: 18px;">
              <li><strong>Master Calendar Booking:</strong> The ceremony date, time, and location are automatically placed on the parish master schedule (<code>schedule_events</code>) and locked against conflicting events.</li>
              <li><strong>Canonical Record Creation:</strong> The candidate, parents, minister, and registry details are automatically entered into the official Sacramental Records table (<code>baptism_records</code>, <code>marriage_records</code>, etc.).</li>
            </ul>
          </div>
        </div>
      </section>

      <!-- ================= MODULE 3 ================= -->
      <section id="adminModule3" class="adm-card">
        <div class="adm-card-header">
          <div class="adm-card-meta">
            <div class="adm-card-icon icon-green">
              <i class="fas fa-book-bible"></i>
            </div>
            <div>
              <h2 class="adm-card-title">Module 3: Sacramental Records &amp; Registry Books</h2>
              <p class="adm-card-sub">Canonical registry archives, Book &amp; Page indexes, and read-only preservation</p>
            </div>
          </div>
          <span class="adm-card-tag">Canonical Archives</span>
        </div>

        <p style="font-size:0.87rem; line-height:1.6;">
          The <a href="<?php echo BASE_URL; ?>admin/sacramental-records.php">Sacramental Records</a> registry contains the historical Catholic sacramental ledgers of the parish:
        </p>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 16px;">
          <div style="padding:10px 14px; background:var(--adm-slate-50); border:1px solid var(--adm-slate-200); border-radius:8px;">
            <strong style="color:var(--adm-slate-800);"><i class="fas fa-water" style="color:var(--adm-blue); margin-right:6px;"></i> Libro de Bautismos</strong>
            <div style="font-size:0.75rem; color:var(--adm-slate-600); margin-top:2px;">Baptismal register entries, godparents, and ministers.</div>
          </div>
          <div style="padding:10px 14px; background:var(--adm-slate-50); border:1px solid var(--adm-slate-200); border-radius:8px;">
            <strong style="color:var(--adm-slate-800);"><i class="fas fa-dove" style="color:var(--adm-gold); margin-right:6px;"></i> Libro de Confirmaciones</strong>
            <div style="font-size:0.75rem; color:var(--adm-slate-600); margin-top:2px;">Confirmation register entries, bishops, and sponsors.</div>
          </div>
          <div style="padding:10px 14px; background:var(--adm-slate-50); border:1px solid var(--adm-slate-200); border-radius:8px;">
            <strong style="color:var(--adm-slate-800);"><i class="fas fa-rings-wedding" style="color:var(--adm-teal); margin-right:6px;"></i> Libro de Matrimonios</strong>
            <div style="font-size:0.75rem; color:var(--adm-slate-600); margin-top:2px;">Pre-nuptial inquiries, witnesses, and church wedding records.</div>
          </div>
          <div style="padding:10px 14px; background:var(--adm-slate-50); border:1px solid var(--adm-slate-200); border-radius:8px;">
            <strong style="color:var(--adm-slate-800);"><i class="fas fa-cross" style="color:var(--adm-purple); margin-right:6px;"></i> Libro de Entierros</strong>
            <div style="font-size:0.75rem; color:var(--adm-slate-600); margin-top:2px;">Burial, funeral, and Viaticum registry records.</div>
          </div>
        </div>

        <div class="callout callout-warning">
          <i class="fas fa-lock"></i>
          <div>
            <strong>Canonical Record Permanence Policy:</strong><br>
            Canonical registers represent perpetual legal evidence under Canon Law. <strong>Sacramental records cannot be deleted</strong>. If an entry was created in error, it may only be marked as <code>archived</code> or appended with an official canonical note authorized by the Chancery / Diocese.
          </div>
        </div>
      </section>

      <!-- ================= MODULE 4 ================= -->
      <section id="adminModule4" class="adm-card">
        <div class="adm-card-header">
          <div class="adm-card-meta">
            <div class="adm-card-icon icon-teal">
              <i class="fas fa-calendar-check"></i>
            </div>
            <div>
              <h2 class="adm-card-title">Module 4: Schedule &amp; Calendar Management</h2>
              <p class="adm-card-sub">Master parish liturgical calendar, mass intentions, and event conflict avoidance</p>
            </div>
          </div>
          <span class="adm-card-tag">Liturgical Calendar</span>
        </div>

        <p style="font-size:0.87rem; line-height:1.6;">
          The <a href="<?php echo BASE_URL; ?>admin/schedule.php">Master Calendar</a> tracks all liturgical events, community masses, pastoral recollections, and confirmed sacramental bookings.
        </p>

        <div class="adm-step-list">
          <div class="adm-step-item">
            <div class="adm-step-num"><i class="fas fa-plus"></i></div>
            <div class="adm-step-content">
              <div class="adm-step-title">Adding a New Liturgical Event</div>
              <p class="adm-step-desc">Click <strong>Add Schedule / Event</strong>. Select event category (<em>Mass Schedule</em>, <em>Parish Event</em>, <em>Patronal Fiesta</em>, <em>Sacramental Activity</em>), specify the date and time span, and set visibility to <code>Public</code> so it appears on the parishioner calendar.</p>
            </div>
          </div>

          <div class="adm-step-item">
            <div class="adm-step-num"><i class="fas fa-ban"></i></div>
            <div class="adm-step-content">
              <div class="adm-step-title">Conflict Detection &amp; Prevention</div>
              <p class="adm-step-desc">The system automatically blocks overlapping sacramental bookings for the same chapel or priest. If an administrator manually schedules an event on an already-occupied slot, a warning prompt appears.</p>
            </div>
          </div>
        </div>
      </section>

      <!-- ================= MODULE 5 ================= -->
      <section id="adminModule5" class="adm-card">
        <div class="adm-card-header">
          <div class="adm-card-meta">
            <div class="adm-card-icon icon-purple">
              <i class="fas fa-chart-pie"></i>
            </div>
            <div>
              <h2 class="adm-card-title">Module 5: Analytics &amp; Reports</h2>
              <p class="adm-card-sub">Operational KPI metrics, dynamic chart analysis, and high-res PDF export</p>
            </div>
          </div>
          <span class="adm-card-tag">Executive Reports</span>
        </div>

        <p style="font-size:0.87rem; line-height:1.6;">
          The <a href="<?php echo BASE_URL; ?>admin/reports.php">Analytics &amp; Reports</a> engine compiles real-time parish operational statistics, visual charts, and diocesan-compliant PDF/CSV exports.
        </p>

        <div class="docs-accordion-item">
          <button type="button" class="adm-accordion-btn">
            <span><i class="fas fa-chart-line" style="margin-right:8px; color:var(--adm-gold);"></i> Understanding the 4 Dynamic Charts</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="adm-accordion-body">
            <ul class="docs-checklist">
              <li><strong>Sacramental Records Administered:</strong> Full-width grouped bar chart tracking monthly volumes across Baptism, Confirmation, Communion, Marriage, and Funeral records over the last 12 months.</li>
              <li><strong>Request Status Breakdown:</strong> Donut chart showing real-time proportions of requests in Pending, In Progress, Completed, and Rejected states.</li>
              <li><strong>Most Requested Services:</strong> Horizontal bar chart detailing top volume service and certificate types.</li>
              <li><strong>Parishioner Registration Growth:</strong> Area curve tracking new monthly account registrations.</li>
            </ul>
          </div>
        </div>

        <div class="callout callout-info">
          <i class="fas fa-file-pdf"></i>
          <div>
            <strong>High-Resolution PDF Export Engine:</strong><br>
            Clicking <strong>Export PDF</strong> captures all dynamic screen charts via high-res Base64 PNGs and renders them into an official, print-aligned A4 report featuring the Diocese of Kalookan letterhead, 15mm margins, KPI summary overview, and <code>page-break-inside: avoid</code> safeguards.
          </div>
        </div>
      </section>

      <!-- ================= MODULE 6 ================= -->
      <section id="adminModule6" class="adm-card">
        <div class="adm-card-header">
          <div class="adm-card-meta">
            <div class="adm-card-icon icon-amber">
              <i class="fas fa-file-shield"></i>
            </div>
            <div>
              <h2 class="adm-card-title">Module 6: Security, Audit Logs &amp; Best Practices</h2>
              <p class="adm-card-sub">Tracking administrator operations, dry seal authentication, and data privacy</p>
            </div>
          </div>
          <span class="adm-card-tag">Compliance</span>
        </div>

        <div class="adm-step-list">
          <div class="adm-step-item">
            <div class="adm-step-num"><i class="fas fa-list-check"></i></div>
            <div class="adm-step-content">
              <div class="adm-step-title">Audit Trail Monitoring</div>
              <p class="adm-step-desc">Open <a href="<?php echo BASE_URL; ?>admin/audit-logs.php">Audit Logs</a> to inspect any administrative action (approvals, exports, user role changes, certificate releases). Every entry logs the actor's user ID, IP address, timestamp, and before/after values.</p>
            </div>
          </div>

          <div class="adm-step-item">
            <div class="adm-step-num"><i class="fas fa-stamp"></i></div>
            <div class="adm-step-content">
              <div class="adm-step-title">Certificate Dry Seal &amp; Signature Policy</div>
              <p class="adm-step-desc">Printed certificates must bear the authentic handwritten signature of the Parish Priest or Parochial Vicar along with the embossed Parish Dry Seal over the signature line. Digital tracking codes on the document ensure authenticity can be validated at any time.</p>
            </div>
          </div>

          <div class="adm-step-item">
            <div class="adm-step-num"><i class="fas fa-user-lock"></i></div>
            <div class="adm-step-content">
              <div class="adm-step-title">Data Privacy Compliance (RA 10173)</div>
              <p class="adm-step-desc">Parishioner records and uploaded government IDs must be handled with strict confidentiality. Never disclose parishioner contact numbers or residential addresses to third parties without explicit authorization.</p>
            </div>
          </div>
        </div>
      </section>

    </main>
  </div>
</div>

<script>
(function () {
  'use strict';

  const searchInput = document.getElementById('admSearchInput');
  const searchClear = document.getElementById('admSearchClear');
  const emptyState  = document.getElementById('admSearchEmpty');
  const cards       = Array.from(document.querySelectorAll('.adm-card'));

  function doAdmSearch() {
    const q = (searchInput.value || '').trim().toLowerCase();
    searchClear.style.display = q ? 'block' : 'none';

    let matchCount = 0;
    cards.forEach(card => {
      const text = card.textContent.toLowerCase();
      if (!q || text.includes(q)) {
        card.style.display = 'block';
        matchCount++;

        if (q) {
          card.querySelectorAll('.adm-accordion-item').forEach(acc => {
            const accText = acc.textContent.toLowerCase();
            const btn = acc.querySelector('.adm-accordion-btn');
            const body = acc.querySelector('.adm-accordion-body');
            if (accText.includes(q)) {
              btn?.classList.add('active');
              body?.classList.add('show');
            }
          });
        }
      } else {
        card.style.display = 'none';
      }
    });

    if (emptyState) {
      emptyState.style.display = (matchCount === 0 && q) ? 'block' : 'none';
    }
  }

  if (searchInput) {
    searchInput.addEventListener('input', doAdmSearch);
  }

  if (searchClear) {
    searchClear.addEventListener('click', function () {
      searchInput.value = '';
      doAdmSearch();
      searchInput.focus();
    });
  }

  window.resetAdmSearch = function () {
    if (searchInput) {
      searchInput.value = '';
      doAdmSearch();
    }
  };

  // Quick pills scroll
  document.querySelectorAll('.adm-pill').forEach(pill => {
    pill.addEventListener('click', function () {
      const targetId = this.getAttribute('data-target');
      const targetEl = document.querySelector(targetId);
      if (targetEl) {
        if (searchInput && searchInput.value) {
          searchInput.value = '';
          doAdmSearch();
        }
        targetEl.scrollIntoView({ behavior: 'smooth' });
      }
    });
  });

  // Accordion toggle
  document.querySelectorAll('.adm-accordion-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const body = this.nextElementSibling;
      this.classList.toggle('active');
      if (body) {
        body.classList.toggle('show');
      }
    });
  });

  // TOC scrollspy
  const tocLinks = Array.from(document.querySelectorAll('.adm-toc-link'));
  function onScroll() {
    const scrollPos = window.scrollY + 140;
    cards.forEach(card => {
      const top = card.offsetTop;
      const height = card.offsetHeight;
      const id = card.getAttribute('id');
      if (scrollPos >= top && scrollPos < top + height) {
        tocLinks.forEach(link => {
          if (link.getAttribute('href') === '#' + id) {
            link.classList.add('active');
          } else {
            link.classList.remove('active');
          }
        });
      }
    });
  }
  window.addEventListener('scroll', onScroll, { passive: true });

})();
</script>

<?php include '../templates/footer.php'; ?>
