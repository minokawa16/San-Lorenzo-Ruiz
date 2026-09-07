<?php
/**
 * Admin Operational Manual & Documentation Guide (/admin/help.php)
 * Simple, plain-English step-by-step operational guide for Parish Administrators and Church Staff.
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
   Clean, easy-to-read typography and scannable visual step cards
   Palette: Forest Green (#2E3A2D), Church Gold (#C89B3C), Slate, Teal
   -------------------------------------------------------------------------- */
:root {
  --adm-green:       #2E3A2D;
  --adm-green-mid:   #3D5C3A;
  --adm-green-light: #ebf3ec;
  --adm-gold:        #C89B3C;
  --adm-gold-light:  #fdf8ec;
  --adm-teal:        #0d9488;
  --adm-teal-light:  #f0fdfa;
  --adm-blue:        #2563eb;
  --adm-blue-light:  #eff6ff;
  --adm-purple:      #7c3aed;
  --adm-purple-light:#f5f3ff;
  --adm-amber:       #d97706;
  --adm-amber-light: #fffbeb;
  --adm-rose:        #e11d48;
  --adm-rose-light:  #fff1f2;
  --adm-slate-50:    #f8fafc;
  --adm-slate-100:   #f1f5f9;
  --adm-slate-200:   #e2e8f0;
  --adm-slate-300:   #cbd5e1;
  --adm-slate-600:   #475569;
  --adm-slate-700:   #334155;
  --adm-slate-800:   #1e293b;
  --adm-slate-900:   #0f172a;
  --adm-radius:      14px;
}

.adm-manual-wrap {
  font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  color: var(--adm-slate-800);
  font-size: 0.94rem;
  line-height: 1.65;
  padding-bottom: 60px;
}

/* --- Hero Banner --- */
.adm-hero {
  background: linear-gradient(135deg, #1e293b 0%, #2E3A2D 60%, #172a1e 100%);
  border-radius: var(--adm-radius);
  padding: 34px 38px;
  color: #ffffff;
  margin-bottom: 28px;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 18px rgba(0,0,0,0.08);
}
.adm-hero::after {
  content: '';
  position: absolute;
  top: -40px;
  right: -40px;
  width: 260px;
  height: 260px;
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
  padding: 4px 12px;
  border-radius: 20px;
  vertical-align: middle;
}
.adm-hero-sub {
  font-size: 0.95rem;
  color: rgba(255,255,255,0.88);
  max-width: 720px;
  margin-bottom: 22px;
  line-height: 1.55;
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
  background: rgba(255,255,255,0.98);
  font-size: 0.93rem;
  color: var(--adm-slate-900);
  outline: none;
  box-shadow: 0 4px 18px rgba(0,0,0,0.12);
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
  color: #ffffff;
  border: 1px solid rgba(255,255,255,0.18);
  padding: 5px 13px;
  border-radius: 20px;
  font-size: 0.78rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
}
.adm-pill:hover,
.adm-pill.active {
  background: var(--adm-gold);
  color: #1e293b;
  border-color: var(--adm-gold);
  font-weight: 700;
}
.adm-pill.active {
  box-shadow: 0 2px 8px rgba(200, 155, 60, 0.4);
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

/* --- Left TOC Card --- */
.adm-toc-card {
  background: #ffffff;
  border: 1px solid var(--adm-slate-200);
  border-radius: var(--adm-radius);
  padding: 20px 16px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
  position: sticky;
  top: 86px;
  max-height: calc(100vh - 100px);
  overflow-y: auto;
  scrollbar-width: thin;
}
.adm-toc-header {
  font-size: 0.74rem;
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
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--adm-slate-700);
  text-decoration: none;
  transition: all 0.15s ease;
  border-left: 4px solid transparent;
}
.adm-toc-link i {
  width: 18px;
  text-align: center;
  font-size: 0.9rem;
  color: var(--adm-slate-600);
  flex-shrink: 0;
  transition: color 0.15s;
}
.adm-toc-link:hover {
  background: var(--adm-slate-100);
  color: var(--adm-green);
}
.adm-toc-link.active {
  background: var(--adm-green-light);
  color: var(--adm-green);
  font-weight: 700;
  border-left-color: var(--adm-green);
  box-shadow: 0 2px 6px rgba(46, 58, 45, 0.08);
}
.adm-toc-link.active i {
  color: var(--adm-green-mid);
}

/* --- Content Section Cards (Tabbed View: Inactive Hidden) --- */
.adm-section-card {
  background: #ffffff;
  border: 1px solid var(--adm-slate-200);
  border-radius: var(--adm-radius);
  padding: 30px 34px;
  margin-bottom: 26px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
  scroll-margin-top: 86px;
  display: none; /* Inactive modules hidden by default */
}
.adm-section-card.active-module {
  display: block; /* Only active module displayed */
  animation: admModuleFadeIn 0.22s ease-out;
}
@keyframes admModuleFadeIn {
  from {
    opacity: 0;
    transform: translateY(6px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* Bottom Module Navigation */
.adm-module-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 32px;
  padding-top: 20px;
  border-top: 1.5px solid var(--adm-slate-100);
  flex-wrap: wrap;
}
.adm-module-nav-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  border-radius: 8px;
  font-size: 0.86rem;
  font-weight: 700;
  color: var(--adm-slate-700);
  background: var(--adm-slate-50);
  border: 1.5px solid var(--adm-slate-200);
  cursor: pointer;
  text-decoration: none;
  transition: all 0.15s ease;
}
.adm-module-nav-btn:hover {
  background: var(--adm-green-light);
  color: var(--adm-green);
  border-color: var(--adm-green);
}
.adm-module-nav-btn.btn-primary-nav {
  background: var(--adm-green);
  color: #ffffff;
  border-color: var(--adm-green);
  margin-left: auto;
}
.adm-module-nav-btn.btn-primary-nav:hover {
  background: var(--adm-green-mid);
  color: #ffffff;
  border-color: var(--adm-green-mid);
}
.adm-section-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  border-bottom: 1.5px solid var(--adm-slate-100);
  padding-bottom: 16px;
  margin-bottom: 22px;
}
.adm-section-meta {
  display: flex;
  align-items: center;
  gap: 14px;
}
.adm-section-icon {
  width: 46px;
  height: 46px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.25rem;
  flex-shrink: 0;
}
.icon-gold   { background: var(--adm-gold-light);   color: var(--adm-gold);   border: 1px solid rgba(200,155,60,0.25); }
.icon-blue   { background: var(--adm-blue-light);   color: var(--adm-blue);   border: 1px solid rgba(37,99,235,0.20); }
.icon-green  { background: var(--adm-green-light);  color: var(--adm-green);  border: 1px solid rgba(46,58,45,0.20); }
.icon-teal   { background: var(--adm-teal-light);   color: var(--adm-teal);   border: 1px solid rgba(13,148,136,0.20); }
.icon-purple { background: var(--adm-purple-light); color: var(--adm-purple); border: 1px solid rgba(124,58,237,0.20); }
.icon-amber  { background: var(--adm-amber-light);  color: var(--adm-amber);  border: 1px solid rgba(217,119,6,0.20); }

.adm-section-title {
  font-size: 1.28rem;
  font-weight: 800;
  color: var(--adm-slate-900);
  margin: 0 0 3px;
  letter-spacing: -0.01em;
}
.adm-section-sub {
  font-size: 0.85rem;
  color: var(--adm-slate-600);
  margin: 0;
}
.adm-section-tag {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 4px 11px;
  border-radius: 20px;
  background: var(--adm-slate-100);
  color: var(--adm-slate-700);
  white-space: nowrap;
}

.adm-intro-text {
  font-size: 0.94rem;
  line-height: 1.65;
  color: var(--adm-slate-700);
  margin-bottom: 20px;
}

/* --- Visual Step Cards (Clean, High-Contrast) --- */
.step-cards-grid {
  display: flex;
  flex-direction: column;
  gap: 14px;
  margin: 20px 0;
}
.step-card {
  display: flex;
  gap: 16px;
  align-items: flex-start;
  padding: 18px 20px;
  border-radius: 12px;
  background: #ffffff;
  border: 1.5px solid var(--adm-slate-200);
  box-shadow: 0 1px 2px rgba(0,0,0,0.03);
  transition: all 0.15s ease-in-out;
}
.step-card:hover {
  border-color: var(--adm-gold);
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.step-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--adm-green);
  color: #ffffff;
  font-size: 0.76rem;
  font-weight: 800;
  padding: 4px 10px;
  border-radius: 6px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  flex-shrink: 0;
  margin-top: 2px;
}
.step-card-body {
  flex: 1;
}
.step-card-title {
  font-size: 0.96rem;
  font-weight: 800;
  color: var(--adm-slate-900);
  margin-bottom: 4px;
}
.step-card-desc {
  font-size: 0.9rem;
  color: var(--adm-slate-700);
  margin: 0;
  line-height: 1.6;
}

/* --- Action Badges (Highlight Key UI Buttons & Tabs) --- */
.action-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 0.8rem;
  font-weight: 700;
  padding: 2px 9px;
  border-radius: 6px;
  border: 1px solid transparent;
  vertical-align: baseline;
  white-space: nowrap;
}
.action-badge-amber {
  background: #fef3c7;
  color: #92400e;
  border-color: #fde68a;
}
.action-badge-green {
  background: #dcfce7;
  color: #166534;
  border-color: #bbf7d0;
}
.action-badge-blue {
  background: #dbeafe;
  color: #1e40af;
  border-color: #bfdbfe;
}
.action-badge-purple {
  background: #f3e8ff;
  color: #6b21a8;
  border-color: #e9d5ff;
}
.action-badge-red {
  background: #fee2e2;
  color: #991b1b;
  border-color: #fecaca;
}
.action-badge-slate {
  background: #f1f5f9;
  color: #334155;
  border-color: #e2e8f0;
}

/* --- Visual Callout Boxes --- */
.callout-box {
  padding: 16px 20px;
  border-radius: 12px;
  margin: 20px 0;
  display: flex;
  gap: 14px;
  align-items: flex-start;
  font-size: 0.89rem;
  line-height: 1.6;
}
.callout-box i {
  font-size: 1.15rem;
  margin-top: 3px;
  flex-shrink: 0;
}
.callout-box strong {
  display: block;
  margin-bottom: 3px;
  font-size: 0.92rem;
}
.callout-tip {
  background: #f0fdf4;
  border: 1.5px solid #bbf7d0;
  border-left: 5px solid #16a34a;
  color: #14532d;
}
.callout-note {
  background: #eff6ff;
  border: 1.5px solid #bfdbfe;
  border-left: 5px solid #2563eb;
  color: #1e3a8a;
}
.callout-warning {
  background: #fffbeb;
  border: 1.5px solid #fde68a;
  border-left: 5px solid #d97706;
  color: #78350f;
}
.callout-purple {
  background: #faf5ff;
  border: 1.5px solid #e9d5ff;
  border-left: 5px solid #7c3aed;
  color: #4c1d95;
}

/* --- Accordions --- */
.adm-accordion-item {
  border: 1.5px solid var(--adm-slate-200);
  border-radius: 10px;
  margin-bottom: 12px;
  overflow: hidden;
  background: #ffffff;
}
.adm-accordion-btn {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 15px 20px;
  background: #ffffff;
  border: none;
  font-size: 0.92rem;
  font-weight: 700;
  color: var(--adm-slate-900);
  cursor: pointer;
  text-align: left;
  transition: background 0.15s;
}
.adm-accordion-btn:hover {
  background: var(--adm-slate-50);
}
.adm-accordion-btn i.fa-chevron-down {
  transition: transform 0.2s;
  font-size: 0.85rem;
  color: var(--adm-slate-600);
}
.adm-accordion-btn.active i.fa-chevron-down {
  transform: rotate(180deg);
}
.adm-accordion-body {
  display: none;
  padding: 16px 20px;
  background: var(--adm-slate-50);
  border-top: 1px solid var(--adm-slate-200);
  font-size: 0.88rem;
  line-height: 1.6;
  color: var(--adm-slate-700);
}
.adm-accordion-body.show {
  display: block;
}

/* Empty search container */
#admSearchEmpty {
  display: none;
  text-align: center;
  padding: 50px 20px;
  background: #ffffff;
  border: 2px dashed var(--adm-slate-300);
  border-radius: var(--adm-radius);
  color: var(--adm-slate-600);
}
</style>

<div class="adm-manual-wrap container-fluid px-0">

  <!-- ================= HERO BANNER ================= -->
  <div class="adm-hero">
    <div class="adm-hero-title">
      <i class="fas fa-book-bookmark"></i>
      Parish Administrator Operational Manual
      <span class="adm-hero-badge">Staff &amp; Admin</span>
    </div>
    <p class="adm-hero-sub">
      A simple, step-by-step guide to help church staff and administrators verify new members, handle certificate and service requests, manage official church records, schedule events, and print reports.
    </p>

    <!-- Quick Search -->
    <div class="adm-search-wrap">
      <i class="fas fa-search adm-search-icon"></i>
      <input type="text" id="admSearchInput" class="adm-search-input" placeholder="Search a topic or step (e.g., verify member, approve request, print PDF, calendar)..." aria-label="Search Admin Manual">
      <button type="button" id="admSearchClear" class="adm-search-clear" title="Clear search"><i class="fas fa-times"></i></button>
    </div>

    <!-- Quick Navigation Pills -->
    <div class="adm-quick-pills">
      <span class="adm-pill" data-target="#module1">1. Member Verification</span>
      <span class="adm-pill" data-target="#module2">2. Requests Workflow</span>
      <span class="adm-pill" data-target="#module3">3. Official Church Records</span>
      <span class="adm-pill" data-target="#module4">4. Schedule Calendar</span>
      <span class="adm-pill" data-target="#module5">5. Analytics &amp; Reports</span>
      <span class="adm-pill" data-target="#module6">6. Audit Logs</span>
    </div>
  </div>

  <!-- ================= MAIN LAYOUT ================= -->
  <div class="adm-layout">

    <!-- LEFT: Sticky TOC -->
    <aside class="adm-toc-col">
      <div class="adm-toc-card">
        <div class="adm-toc-header">
          <span>Manual Modules</span>
          <i class="fas fa-bars-staggered"></i>
        </div>
        <ul class="adm-toc-list">
          <li class="adm-toc-item">
            <a href="#module1" class="adm-toc-link active">
              <i class="fas fa-id-card"></i>
              <span>1. Member Verification</span>
            </a>
          </li>
          <li class="adm-toc-item">
            <a href="#module2" class="adm-toc-link">
              <i class="fas fa-inbox"></i>
              <span>2. Request Workflows</span>
            </a>
          </li>
          <li class="adm-toc-item">
            <a href="#module3" class="adm-toc-link">
              <i class="fas fa-book-bible"></i>
              <span>3. Church Records</span>
            </a>
          </li>
          <li class="adm-toc-item">
            <a href="#module4" class="adm-toc-link">
              <i class="fas fa-calendar-check"></i>
              <span>4. Schedule Calendar</span>
            </a>
          </li>
          <li class="adm-toc-item">
            <a href="#module5" class="adm-toc-link">
              <i class="fas fa-chart-line"></i>
              <span>5. Analytics &amp; Reports</span>
            </a>
          </li>
          <li class="adm-toc-item">
            <a href="#module6" class="adm-toc-link">
              <i class="fas fa-clipboard-list"></i>
              <span>6. Audit Logs</span>
            </a>
          </li>
        </ul>
      </div>
    </aside>

    <!-- RIGHT: Content Sections -->
    <main class="adm-content-col">

      <!-- Empty Search State -->
      <div id="admSearchEmpty">
        <i class="fas fa-magnifying-glass" style="font-size:2.4rem; opacity:0.3; margin-bottom:12px;"></i>
        <h5 style="font-weight:800; color:var(--adm-slate-900);">No matching topics found</h5>
        <p style="font-size:0.9rem; margin-bottom:14px;">Try searching for simple words like "approve", "request", "calendar", "records", or "print".</p>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetAdmSearch()">Clear Search</button>
      </div>

      <!-- ================= MODULE 1 ================= -->
      <section id="module1" class="adm-section-card active-module">
        <div class="adm-section-header">
          <div class="adm-section-meta">
            <div class="adm-section-icon icon-gold">
              <i class="fas fa-id-card"></i>
            </div>
            <div>
              <h2 class="adm-section-title">Module 1: Member Verification (How to Approve Accounts)</h2>
              <p class="adm-section-sub">Check ID photos and activate new parishioner accounts</p>
            </div>
          </div>
          <span class="adm-section-tag">Member Profiles</span>
        </div>

        <p class="adm-intro-text">
          When someone registers on TUGON, they take a live photo of their government ID card. Follow these simple steps to review their profile and approve their account.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Go to the Parishioners Page</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>admin/parishioners.php"><strong>Parishioners</strong></a> in the menu. At the top of the list, click the <span class="action-badge action-badge-amber"><i class="fas fa-clock"></i> Pending Verification</span> tab to see everyone waiting for review.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Open Member Details &amp; Compare ID Photo</div>
              <p class="step-card-desc">
                Click <span class="action-badge action-badge-blue">View Details</span> next to the person's name. Look at the ID photo on the screen and compare it with the full name, birthday, and address they typed in.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Approve or Reject the Account</div>
              <p class="step-card-desc">
                If the details match: Click <span class="action-badge action-badge-green"><i class="fas fa-check"></i> Approve</span>. Their account will immediately become <span class="action-badge action-badge-green">Active</span> and they will receive a confirmation text.<br>
                If the photo is too dark, blurry, or does not match: Click <span class="action-badge action-badge-red"><i class="fas fa-times"></i> Reject / Flag</span> and write a short reason so the member knows to retake a clear photo.
              </p>
            </div>
          </div>
        </div>

        <!-- Tip Box -->
        <div class="callout-box callout-tip">
          <i class="fas fa-circle-check"></i>
          <div>
            <strong>Helpful Tip:</strong>
            Make sure the parishioner's full name and birthdate match their ID card exactly before clicking <strong>Approve</strong>. This keeps our church records accurate and protects members from identity mix-ups.
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="adm-module-nav">
          <div></div>
          <button type="button" class="adm-module-nav-btn btn-primary-nav" data-target="#module2">
            <span>Next: Module 2 (Request Workflows)</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </section>

      <!-- ================= MODULE 2 ================= -->
      <section id="module2" class="adm-section-card">
        <div class="adm-section-header">
          <div class="adm-section-meta">
            <div class="adm-section-icon icon-blue">
              <i class="fas fa-inbox"></i>
            </div>
            <div>
              <h2 class="adm-section-title">Module 2: Request Workflows (Handling Certificates &amp; Services)</h2>
              <p class="adm-section-sub">Review certificate requests and approve baptism, wedding, or funeral bookings</p>
            </div>
          </div>
          <span class="adm-section-tag">Requests</span>
        </div>

        <p class="adm-intro-text">
          Parishioners submit requests online for sacramental certificates (Baptism, Confirmation, Marriage) and bookings for church services (Baptism, Wedding, Funeral, or Blessing).
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Open the Requests Page</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>admin/request-workflow.php"><strong>Requests</strong></a> (or <em>Request Workflow</em>) in the sidebar menu to see all incoming applications.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Review the Submitted Details</div>
              <p class="step-card-desc">
                Click on the request to view it:
                <br>&bull; <strong>For Certificates:</strong> Check the person's name, sacrament year, and attached birth certificate or ID.
                <br>&bull; <strong>For Church Services (Baptism / Wedding / Funeral):</strong> Review the submitted form with parents, sponsors, and preferred dates.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Update the Request Status</div>
              <p class="step-card-desc">
                Change the status as you work on the request:
                <br>&bull; <span class="action-badge action-badge-amber">Pending</span>: Newly received request, waiting for staff review.
                <br>&bull; <span class="action-badge action-badge-blue">In Progress</span>: Staff is looking up physical church record books or printing the certificate.
                <br>&bull; <span class="action-badge action-badge-green">Completed</span>: The certificate is signed and sealed, or the service is confirmed!
                <br>&bull; <span class="action-badge action-badge-red">Rejected</span>: If required papers are missing or incorrect (always enter a helpful remark).
              </p>
            </div>
          </div>
        </div>

        <!-- Automatic Record & Calendar Sync Callout -->
        <div class="callout-box callout-purple">
          <i class="fas fa-wand-magic-sparkles"></i>
          <div>
            <strong>Automatic Calendar &amp; Church Record Sync:</strong>
            When you mark a service request (like Baptism, Wedding, or Funeral) as <span class="action-badge action-badge-green">Completed</span>:
            <ul style="margin: 6px 0 0; padding-left: 18px;">
              <li>The ceremony date and time are <strong>automatically added to the Parish Calendar</strong> so no other event can take that slot.</li>
              <li>The details are <strong>automatically saved into our official Church Records</strong>!</li>
            </ul>
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="adm-module-nav">
          <button type="button" class="adm-module-nav-btn" data-target="#module1">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 1</span>
          </button>
          <button type="button" class="adm-module-nav-btn btn-primary-nav" data-target="#module3">
            <span>Next: Module 3 (Church Records)</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </section>

      <!-- ================= MODULE 3 ================= -->
      <section id="module3" class="adm-section-card">
        <div class="adm-section-header">
          <div class="adm-section-meta">
            <div class="adm-section-icon icon-green">
              <i class="fas fa-book-bible"></i>
            </div>
            <div>
              <h2 class="adm-section-title">Module 3: Church Records (Baptism, Confirmation, Marriage &amp; Funeral)</h2>
              <p class="adm-section-sub">Look up, add, and manage permanent church record books</p>
            </div>
          </div>
          <span class="adm-section-tag">Official Records</span>
        </div>

        <p class="adm-intro-text">
          The <a href="<?php echo BASE_URL; ?>admin/sacramental-records.php"><strong>Sacramental Records</strong></a> page holds our parish's official historical church registers.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Go to Sacramental Records</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>admin/sacramental-records.php"><strong>Sacramental Records</strong></a> in the sidebar menu.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Choose the Record Book</div>
              <p class="step-card-desc">
                Click the book you want to see:
                <span class="action-badge action-badge-blue">Baptism</span>,
                <span class="action-badge action-badge-amber">Confirmation</span>,
                <span class="action-badge action-badge-green">Marriage</span>, or
                <span class="action-badge action-badge-purple">Funeral</span>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Search, View, or Add Entries</div>
              <p class="step-card-desc">
                Type a name into the search bar to find someone quickly. You can also search by Book Number, Page Number, or year. Click <strong>Add New Record</strong> to enter an older paper record into the digital system.
              </p>
            </div>
          </div>
        </div>

        <!-- Warning Callout -->
        <div class="callout-box callout-warning">
          <i class="fas fa-lock"></i>
          <div>
            <strong>Church Records are Permanent:</strong>
            Under Catholic Church rules, official church records cannot be deleted once created. If a mistake was made during encoding, edit the entry to correct it or mark it as archived.
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="adm-module-nav">
          <button type="button" class="adm-module-nav-btn" data-target="#module2">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 2</span>
          </button>
          <button type="button" class="adm-module-nav-btn btn-primary-nav" data-target="#module4">
            <span>Next: Module 4 (Schedule Calendar)</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </section>

      <!-- ================= MODULE 4 ================= -->
      <section id="module4" class="adm-section-card">
        <div class="adm-section-header">
          <div class="adm-section-meta">
            <div class="adm-section-icon icon-teal">
              <i class="fas fa-calendar-check"></i>
            </div>
            <div>
              <h2 class="adm-section-title">Module 4: Schedule Calendar (Viewing &amp; Managing Events)</h2>
              <p class="adm-section-sub">Oversee parish masses, blessings, and community events</p>
            </div>
          </div>
          <span class="adm-section-tag">Calendar</span>
        </div>

        <p class="adm-intro-text">
          The <a href="<?php echo BASE_URL; ?>admin/schedule.php"><strong>Schedule Calendar</strong></a> displays all upcoming parish masses, confirmed baptisms and weddings, and special feast day schedules in one place.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Open the Schedule Calendar</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>admin/schedule.php"><strong>Schedule Calendar</strong></a> in the menu to view the monthly, weekly, or daily view.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Add a Parish Mass or Event</div>
              <p class="step-card-desc">
                Click <span class="action-badge action-badge-green"><i class="fas fa-plus"></i> Add Event</span>. Enter the event title (e.g. <em>Sunday Mass</em> or <em>Fiesta Novena</em>), select the date and time, and set visibility to <strong>Public</strong> so parishioners can see it on their calendar.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Preventing Double Bookings</div>
              <p class="step-card-desc">
                The calendar automatically checks for schedule conflicts. If a time slot already has a confirmed wedding or baptism, the system will warn you so two events are never booked at the same time.
              </p>
            </div>
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="adm-module-nav">
          <button type="button" class="adm-module-nav-btn" data-target="#module3">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 3</span>
          </button>
          <button type="button" class="adm-module-nav-btn btn-primary-nav" data-target="#module5">
            <span>Next: Module 5 (Analytics &amp; Reports)</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </section>

      <!-- ================= MODULE 5 ================= -->
      <section id="module5" class="adm-section-card">
        <div class="adm-section-header">
          <div class="adm-section-meta">
            <div class="adm-section-icon icon-purple">
              <i class="fas fa-chart-line"></i>
            </div>
            <div>
              <h2 class="adm-section-title">Module 5: Reports &amp; Statistics (Printing PDF Summaries)</h2>
              <p class="adm-section-sub">Check monthly numbers and download official printed PDF reports</p>
            </div>
          </div>
          <span class="adm-section-tag">Reports &amp; PDF</span>
        </div>

        <p class="adm-intro-text">
          Use the <a href="<?php echo BASE_URL; ?>admin/reports.php"><strong>Analytics &amp; Reports</strong></a> page to review parish numbers and print official monthly reports for the Parish Priest or Diocese.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Open Analytics &amp; Reports</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>admin/reports.php"><strong>Analytics &amp; Reports</strong></a> in the sidebar menu.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">View Monthly Charts and Numbers</div>
              <p class="step-card-desc">
                You will see cards and charts showing total registered parishioners, how many certificates were requested, and monthly sacrament numbers.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Print or Download an Official PDF</div>
              <p class="step-card-desc">
                Click <span class="action-badge action-badge-amber"><i class="fas fa-file-pdf"></i> Export PDF</span> at the top right. The system captures the charts on your screen and creates a beautiful, print-ready A4 PDF complete with our church letterhead and summary tables. You can also click <span class="action-badge action-badge-slate"><i class="fas fa-file-csv"></i> Export CSV</span> to download into Excel.
              </p>
            </div>
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="adm-module-nav">
          <button type="button" class="adm-module-nav-btn" data-target="#module4">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 4</span>
          </button>
          <button type="button" class="adm-module-nav-btn btn-primary-nav" data-target="#module6">
            <span>Next: Module 6 (Audit Logs)</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </section>

      <!-- ================= MODULE 6 ================= -->
      <section id="module6" class="adm-section-card">
        <div class="adm-section-header">
          <div class="adm-section-meta">
            <div class="adm-section-icon icon-amber">
              <i class="fas fa-clipboard-list"></i>
            </div>
            <div>
              <h2 class="adm-section-title">Module 6: Audit Logs (Tracking System Activity)</h2>
              <p class="adm-section-sub">See who made changes, approved requests, or printed certificates</p>
            </div>
          </div>
          <span class="adm-section-tag">History &amp; Security</span>
        </div>

        <p class="adm-intro-text">
          To maintain honesty, transparency, and security, TUGON keeps an automatic record of every action taken by church staff.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Go to Audit Logs</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>admin/audit-logs.php"><strong>Audit Logs</strong></a> in the menu.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">See the Full Activity History</div>
              <p class="step-card-desc">
                You will see a list showing which staff member approved a request, who edited a church record, who exported a report, and the exact date and time it happened.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Filter and Search Logs</div>
              <p class="step-card-desc">
                You can filter by staff name or action type (e.g. <em>APPROVE_USER</em>, <em>EXPORT_REPORT</em>, <em>UPDATE_REQUEST</em>) anytime you need to double-check a transaction.
              </p>
            </div>
          </div>
        </div>

        <!-- Note Box -->
        <div class="callout-box callout-note">
          <i class="fas fa-shield-halved"></i>
          <div>
            <strong>Parish Privacy &amp; Data Protection:</strong>
            Member phone numbers and government IDs are private. Never share parishioner contact information or personal documents with outside parties.
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="adm-module-nav">
          <button type="button" class="adm-module-nav-btn" data-target="#module5">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 5</span>
          </button>
          <a href="<?php echo BASE_URL; ?>admin/index.php" class="adm-module-nav-btn btn-primary-nav">
            <span>Back to Dashboard</span>
            <i class="fas fa-house"></i>
          </a>
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
  const cards       = Array.from(document.querySelectorAll('.adm-section-card'));
  const tocLinks    = Array.from(document.querySelectorAll('.adm-toc-link'));
  const pills       = Array.from(document.querySelectorAll('.adm-pill'));
  let currentActiveId = 'module1';

  /**
   * Switch the active module tab dynamically
   * @param {string} targetId e.g. '#module2' or 'module2'
   * @param {boolean} updateHash whether to update browser URL hash
   * @param {boolean} scrollIntoView whether to smoothly scroll into view
   */
  function switchModule(targetId, updateHash = true, scrollIntoView = false) {
    const cleanId = (targetId || '').replace(/^#/, '');
    const targetEl = document.getElementById(cleanId);
    const targetCard = (targetEl && targetEl.classList.contains('adm-section-card'))
      ? targetEl
      : document.getElementById('module1');

    if (!targetCard) return;
    currentActiveId = targetCard.id;

    // Reset search bar display if user was searching
    if (searchInput && searchInput.value) {
      searchInput.value = '';
      if (searchClear) searchClear.style.display = 'none';
      if (emptyState) emptyState.style.display = 'none';
    }

    // Toggle modules: display ONLY the single active module
    cards.forEach(card => {
      const isTarget = (card.id === currentActiveId);
      card.classList.toggle('active-module', isTarget);
      card.style.display = isTarget ? 'block' : 'none';
    });

    // Update left sidebar TOC highlighting
    tocLinks.forEach(link => {
      const href = (link.getAttribute('href') || '').replace(/^#/, '');
      const isActive = (href === currentActiveId);
      link.classList.toggle('active', isActive);
      link.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    // Update quick navigation pills in hero
    pills.forEach(pill => {
      const pTarget = (pill.getAttribute('data-target') || '').replace(/^#/, '');
      pill.classList.toggle('active', pTarget === currentActiveId);
    });

    // Update URL hash for bookmarking & history without jump
    if (updateHash) {
      if (window.history && window.history.pushState) {
        window.history.pushState(null, null, '#' + currentActiveId);
      } else {
        window.location.hash = '#' + currentActiveId;
      }
    }

    // Smooth scroll to content top if requested or on mobile
    if (scrollIntoView || window.innerWidth < 992) {
      const mainCol = document.querySelector('.adm-content-col');
      if (mainCol) {
        const topOffset = mainCol.getBoundingClientRect().top + window.pageYOffset - 90;
        window.scrollTo({ top: Math.max(0, topOffset), behavior: 'smooth' });
      }
    }
  }

  // Handle URL hash on initial load
  function initFromHash() {
    const hash = window.location.hash;
    const cleanId = hash ? hash.replace(/^#/, '') : '';
    if (cleanId && document.getElementById(cleanId)) {
      switchModule(cleanId, false, false);
    } else {
      switchModule('module1', false, false);
    }
  }

  // Handle browser back/forward buttons
  window.addEventListener('hashchange', function () {
    const hash = window.location.hash;
    if (hash) {
      const cleanId = hash.replace(/^#/, '');
      if (document.getElementById(cleanId)) {
        switchModule(cleanId, false, false);
      }
    }
  });

  // Sidebar TOC click handling
  tocLinks.forEach(link => {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      const targetId = this.getAttribute('href');
      switchModule(targetId, true, window.innerWidth < 992);
    });
  });

  // Quick navigation pills click handling
  pills.forEach(pill => {
    pill.addEventListener('click', function (e) {
      e.preventDefault();
      const targetId = this.getAttribute('data-target');
      switchModule(targetId, true, true);
    });
  });

  // Prev / Next module buttons click handling
  document.querySelectorAll('.adm-module-nav-btn[data-target]').forEach(btn => {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      const targetId = this.getAttribute('data-target');
      switchModule(targetId, true, true);
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

  // Search functionality: when typing, reveals matching modules; when cleared, restores active tab
  function doAdmSearch() {
    const q = (searchInput.value || '').trim().toLowerCase();
    if (searchClear) searchClear.style.display = q ? 'block' : 'none';

    if (!q) {
      // Restore tabbed view of current active module
      switchModule(currentActiveId, false, false);
      if (emptyState) emptyState.style.display = 'none';
      return;
    }

    // While searching, display all matching modules
    let matchCount = 0;
    cards.forEach(card => {
      const text = card.textContent.toLowerCase();
      if (text.includes(q)) {
        card.style.display = 'block';
        card.classList.add('active-module');
        matchCount++;

        // Auto-expand matching accordions
        card.querySelectorAll('.adm-accordion-item').forEach(acc => {
          const accText = acc.textContent.toLowerCase();
          const btn = acc.querySelector('.adm-accordion-btn');
          const body = acc.querySelector('.adm-accordion-body');
          if (accText.includes(q)) {
            btn?.classList.add('active');
            body?.classList.add('show');
          }
        });
      } else {
        card.style.display = 'none';
        card.classList.remove('active-module');
      }
    });

    if (emptyState) {
      emptyState.style.display = (matchCount === 0) ? 'block' : 'none';
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

  // Initialize active tab on load
  initFromHash();

})();
</script>

<?php include '../templates/footer.php'; ?>
