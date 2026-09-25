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
/* --- Clean Light Header Banner --- */
.adm-hero {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 2rem 2.25rem;
  color: #0f172a;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05);
  margin-bottom: 2rem;
  position: relative;
  overflow: hidden;
}
.adm-hero-title {
  font-size: 1.55rem;
  font-weight: 700;
  color: #0f172a !important;
  letter-spacing: -0.01em;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.adm-hero-title i {
  color: #c9932b;
  font-size: 1.45rem;
}
.adm-hero-badge {
  background: #fef3c7; /* Light warm gold */
  color: #92400e; /* Dark bronze */
  border: 1px solid #fde68a;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 4px 12px;
  border-radius: 20px;
  vertical-align: middle;
}
.adm-hero-sub,
.adm-hero p {
  color: #475569 !important; /* Crisp, readable charcoal slate */
  font-size: 0.95rem;
  line-height: 1.6;
  margin: 0.65rem 0 1.5rem 0;
  max-width: 840px;
}

/* Search Bar with Floating Light Style */
.adm-search-wrap {
  position: relative;
  max-width: 740px;
  margin-bottom: 1.35rem;
}
.adm-search-input {
  width: 100%;
  padding: 13px 46px 13px 46px;
  border-radius: 12px;
  border: 1.5px solid #cbd5e1;
  background: #f8fafc;
  font-size: 0.92rem;
  color: #0f172a;
  outline: none;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  transition: all 0.2s ease;
}
.adm-search-input:focus {
  background: #ffffff;
  border-color: #c9932b;
  box-shadow: 0 0 0 3px rgba(201, 147, 43, 0.25);
}
.adm-search-input::placeholder {
  color: #64748b;
}
.adm-search-icon {
  position: absolute;
  left: 16px;
  top: 50%;
  transform: translateY(-50%);
  color: #64748b;
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
  color: #64748b;
  cursor: pointer;
  display: none;
  font-size: 0.95rem;
}
.adm-search-clear:hover {
  color: #0f172a;
}

/* Quick Navigation Pills for Light Background */
.adm-quick-pills {
  display: flex;
  gap: 0.55rem;
  flex-wrap: wrap;
  margin-top: 0;
}
.adm-pill {
  background: #f1f5f9;
  color: #334155;
  border: 1px solid #e2e8f0;
  padding: 0.45rem 1rem;
  border-radius: 30px;
  font-size: 0.8rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
  user-select: none;
}
.adm-pill:hover {
  background: #e2e8f0;
  color: #0f172a;
  border-color: #cbd5e1;
}
.adm-pill.active {
  background: #c9932b; /* Parish gold */
  color: #ffffff;
  font-weight: 700;
  border-color: #c9932b;
  box-shadow: 0 2px 8px rgba(201, 147, 43, 0.32);
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
      <span class="adm-pill active" data-target="#module1">1. Member Verification</span>
      <span class="adm-pill" data-target="#module2">2. Request Workflows</span>
      <span class="adm-pill" data-target="#module3">3. Sacramental Records</span>
      <span class="adm-pill" data-target="#module4">4. Parish Calendar</span>
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
              <span>3. Sacramental Records</span>
            </a>
          </li>
          <li class="adm-toc-item">
            <a href="#module4" class="adm-toc-link">
              <i class="fas fa-calendar-days"></i>
              <span>4. Parish Calendar</span>
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
              <h2 class="adm-section-title">Module 1: Member Verification (Reviewing Registrations)</h2>
              <p class="adm-section-sub">Inspect uploaded ID photos and approve verified parishioner accounts</p>
            </div>
          </div>
          <span class="adm-section-tag">Parish Management</span>
        </div>

        <p class="adm-intro-text">
          When parishioners register on TUGON, they upload a photo of their valid government ID and a live selfie. Follow these steps to review their credentials and activate their account.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Go to Verify Registrations</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>admin/verify-registrations.php"><strong>Verify Registrations</strong></a> in the sidebar under <strong>Parish Management</strong> to view all newly registered accounts waiting for review.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Compare ID and Live Photo</div>
              <p class="step-card-desc">
                Compare the face on their uploaded ID card with their live selfie photo. Verify that their entered name, birthdate, chapel, and address look clear, authentic, and consistent.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Approve or Reject the Account</div>
              <p class="step-card-desc">
                If the details match: click <span class="action-badge action-badge-green"><i class="fas fa-check"></i> Approve</span>. Their account becomes <span class="action-badge action-badge-green">Active</span> and they can log in immediately.<br>
                If the photo is blurry, expired, or suspicious: click <span class="action-badge action-badge-red"><i class="fas fa-times"></i> Reject</span> and provide a clear explanation so they can resubmit.
              </p>
            </div>
          </div>
        </div>

        <!-- Tip Box -->
        <div class="callout-box callout-tip">
          <i class="fas fa-circle-check"></i>
          <div>
            <strong>Helpful Tip:</strong>
            Always ensure the parishioner's full name and birthdate match their valid ID card before approving. This maintains clean church records and prevents duplicate registrations.
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
              <h2 class="adm-section-title">Module 2: Request Workflows (Certificates, Blessings &amp; Sacraments)</h2>
              <p class="adm-section-sub">Process incoming certificate requests, blessing schedules, and sacramental service applications</p>
            </div>
          </div>
          <span class="adm-section-tag">Request Management</span>
        </div>

        <p class="adm-intro-text">
          Parishioners submit requests for sacramental certificates (Baptism, Confirmation, Marriage, First Communion, Funeral), blessing appointments (House, Vehicle, Business), and sacramental services.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Open Manage Requests</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>admin/manage-requests.php"><strong>Requests</strong></a> in the sidebar under <strong>Request Management</strong> to view all submissions. Use the status tabs (<span class="action-badge action-badge-slate">All</span>, <span class="action-badge action-badge-amber">Pending</span>, <span class="action-badge action-badge-blue">Processing</span>, <span class="action-badge action-badge-green">Completed</span>, <span class="action-badge action-badge-red">Rejected</span>) or search bar to filter items.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Review the Request in Request Workflow</div>
              <p class="step-card-desc">
                Click <span class="action-badge action-badge-blue"><i class="fas fa-eye"></i> View</span> on any row to open the formal <strong>Request Workflow</strong>. Cross-check sacramental registers for matching entries, inspect attached requirements (birth certificate, ID), and verify submitted GCash/bank payment receipts.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Update Status and Complete Processing</div>
              <p class="step-card-desc">
                Advance the status as you progress:
                <br>&bull; <span class="action-badge action-badge-blue">Processing</span>: Indicates records are being verified or documents encoded.
                <br>&bull; <span class="action-badge action-badge-green">Completed</span>: For certificates, upload the released PDF copy; for sacramental services and blessings, selecting the officiating priest registers the official sacramental record and automatically synchronizes the event date to the <strong>Parish Calendar</strong>.
                <br>&bull; <span class="action-badge action-badge-red">Rejected</span>: If required documents are missing or invalid, enter a remark explaining what is needed.
              </p>
            </div>
          </div>
        </div>

        <!-- Automatic Record & Calendar Sync Callout -->
        <div class="callout-box callout-purple">
          <i class="fas fa-wand-magic-sparkles"></i>
          <div>
            <strong>Automatic Calendar &amp; Church Record Sync:</strong>
            When you complete a sacramental service request (like Baptism, Wedding, or Funeral):
            <ul style="margin: 6px 0 0; padding-left: 18px;">
              <li>The ceremony date and time are <strong>automatically scheduled in the Parish Calendar</strong> to prevent double-booking.</li>
              <li>The sacramental record is <strong>automatically registered into our official digital church records</strong>.</li>
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
            <span>Next: Module 3 (Sacramental Records)</span>
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
              <h2 class="adm-section-title">Module 3: Sacramental Records (Church Registers)</h2>
              <p class="adm-section-sub">Search, encode, correct, and manage official parish church registries</p>
            </div>
          </div>
          <span class="adm-section-tag">Sacramental Records</span>
        </div>

        <p class="adm-intro-text">
          The <a href="<?php echo BASE_URL; ?>admin/manage-records.php"><strong>Sacramental Records</strong></a> hub provides access to the parish's official digital church registers for Baptism, First Communion, Confirmation, Marriage, and Funeral records.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Open Sacramental Records</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>admin/manage-records.php"><strong>Sacramental Records</strong></a> in the sidebar to view the registry dashboard showing total record counts for all 5 sacraments.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Select a Specific Sacrament Registry</div>
              <p class="step-card-desc">
                Click on any registry card to open its dedicated records page:
                <a href="<?php echo BASE_URL; ?>admin/baptism-records.php"><span class="action-badge action-badge-blue">Baptism</span></a>,
                <a href="<?php echo BASE_URL; ?>admin/communion-records.php"><span class="action-badge action-badge-gold">First Communion</span></a>,
                <a href="<?php echo BASE_URL; ?>admin/confirmation-records.php"><span class="action-badge action-badge-amber">Confirmation</span></a>,
                <a href="<?php echo BASE_URL; ?>admin/marriage-records.php"><span class="action-badge action-badge-green">Marriage</span></a>, or
                <a href="<?php echo BASE_URL; ?>admin/funeral-records.php"><span class="action-badge action-badge-purple">Funeral</span></a>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Search, Add, or Issue Certificates</div>
              <p class="step-card-desc">
                Use the search filters to find records by person's name, parents' names, Book, or Page number. Click <span class="action-badge action-badge-green"><i class="fas fa-plus"></i> Add New Record</span> to encode historical records, or click <span class="action-badge action-badge-blue"><i class="fas fa-certificate"></i> Generate Certificate</span> on any record to issue an official certificate.
              </p>
            </div>
          </div>
        </div>

        <!-- Warning Callout -->
        <div class="callout-box callout-warning">
          <i class="fas fa-lock"></i>
          <div>
            <strong>Sacramental Records Preservation:</strong>
            Church records represent permanent canonical registries. If an encoding error is identified, use the record's <strong>Edit</strong> or <strong>Record Corrections</strong> tool to fix the data while preserving the audit history.
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="adm-module-nav">
          <button type="button" class="adm-module-nav-btn" data-target="#module2">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 2</span>
          </button>
          <button type="button" class="adm-module-nav-btn btn-primary-nav" data-target="#module4">
            <span>Next: Module 4 (Parish Calendar)</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </section>

      <!-- ================= MODULE 4 ================= -->
      <section id="module4" class="adm-section-card">
        <div class="adm-section-header">
          <div class="adm-section-meta">
            <div class="adm-section-icon icon-teal">
              <i class="fas fa-calendar-days"></i>
            </div>
            <div>
              <h2 class="adm-section-title">Module 4: Parish Calendar (Scheduling &amp; Event Management)</h2>
              <p class="adm-section-sub">Oversee parish Masses, blessings, meetings, and confirmed sacramental schedules</p>
            </div>
          </div>
          <span class="adm-section-tag">Request Management</span>
        </div>

        <p class="adm-intro-text">
          The <a href="<?php echo BASE_URL; ?>admin/manage-calendar.php"><strong>Parish Calendar</strong></a> provides an interactive scheduling calendar for parish Masses, blessings, meetings, and confirmed sacramental events.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Open Parish Calendar</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>admin/manage-calendar.php"><strong>Parish Calendar</strong></a> in the sidebar under <strong>Request Management</strong>. You can switch between <span class="action-badge action-badge-slate">Month</span>, <span class="action-badge action-badge-slate">Week</span>, <span class="action-badge action-badge-slate">Day</span>, and <span class="action-badge action-badge-slate">List</span> views.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Filter and Search Schedules</div>
              <p class="step-card-desc">
                Use the left sidebar filters to narrow down schedules by Category (<span class="action-badge action-badge-green">Mass</span>, <span class="action-badge action-badge-blue">Event</span>, <span class="action-badge action-badge-purple">Sacramental</span>, <span class="action-badge action-badge-gold">Blessing</span>, <span class="action-badge action-badge-amber">Meeting</span>) or Status (<span class="action-badge action-badge-blue">Upcoming</span>, <span class="action-badge action-badge-green">Ongoing</span>, <span class="action-badge action-badge-slate">Finished</span>).
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Add or Manage Events</div>
              <p class="step-card-desc">
                Click the floating <span class="action-badge action-badge-gold"><i class="fas fa-plus"></i></span> button or click directly on any time slot on the calendar. Fill in the event title, category, date, time, and location in the <strong>Add Schedule</strong> modal. The system automatically detects and prevents double-booking conflicts.
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
              <h2 class="adm-section-title">Module 5: Analytics &amp; Reports (KPIs &amp; PDF Export)</h2>
              <p class="adm-section-sub">Monitor parish growth, sacramental statistics, and generate printable PDF summaries</p>
            </div>
          </div>
          <span class="adm-section-tag">Reports &amp; Monitoring</span>
        </div>

        <p class="adm-intro-text">
          The <a href="<?php echo BASE_URL; ?>admin/reports.php"><strong>Analytics &amp; Reports</strong></a> dashboard provides real-time metrics, interactive charts, and formal PDF/CSV reporting tools for parish operations and diocesan reviews.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Open Analytics &amp; Reports</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>admin/reports.php"><strong>Analytics &amp; Reports</strong></a> in the sidebar under <strong>Reports &amp; Monitoring</strong>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Review KPIs and Interactive Charts</div>
              <p class="step-card-desc">
                Review the top KPI summary cards for Total Parishioners, Sacramental Records, Service Requests, and Monthly Events. Examine dynamic charts including monthly sacramental trends, request status breakdowns, and parishioner registration growth.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Export Official PDF or CSV Reports</div>
              <p class="step-card-desc">
                Click <span class="action-badge action-badge-amber"><i class="fas fa-file-pdf"></i> Export PDF</span> to generate a clean, print-ready document formatted with parish crest branding, KPI metrics, and high-resolution chart snapshots. Click <span class="action-badge action-badge-slate"><i class="fas fa-file-csv"></i> Export CSV</span> for spreadsheet analysis.
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
              <h2 class="adm-section-title">Module 6: Audit Logs (System Activity &amp; Security)</h2>
              <p class="adm-section-sub">Inspect staff actions, track modifications, and maintain complete system transparency</p>
            </div>
          </div>
          <span class="adm-section-tag">Reports &amp; Monitoring</span>
        </div>

        <p class="adm-intro-text">
          TUGON automatically logs every administrative action taken across the system to maintain canonical integrity, data accountability, and operational security.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Open Audit Logs</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>admin/audit-logs.php"><strong>Audit Logs</strong></a> in the sidebar under <strong>Reports &amp; Monitoring</strong>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Browse Chronological Event Logs</div>
              <p class="step-card-desc">
                Review the activity stream showing the timestamp, staff actor name, action type (e.g. <em>APPROVE_REGISTRATION</em>, <em>UPDATE_REQUEST_STATUS</em>, <em>SAVE_CERTIFICATE_LAYOUT</em>), target record, and client IP address.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Filter, Inspect Details, or Export</div>
              <p class="step-card-desc">
                Use the search box, Date Range, Category, and Severity filters (<span class="action-badge action-badge-blue">INFO</span>, <span class="action-badge action-badge-amber">WARNING</span>, <span class="action-badge action-badge-red">CRITICAL</span>) to locate specific events. Click <span class="action-badge action-badge-blue">View Details</span> on any row for raw technical metadata, or export via <span class="action-badge action-badge-slate"><i class="fas fa-file-csv"></i> Export CSV</span> / <span class="action-badge action-badge-amber"><i class="fas fa-file-pdf"></i> Export PDF</span>.
              </p>
            </div>
          </div>
        </div>

        <!-- Note Box -->
        <div class="callout-box callout-note">
          <i class="fas fa-shield-halved"></i>
          <div>
            <strong>Parish Privacy &amp; Data Protection:</strong>
            Parishioner records and government IDs are strictly confidential. Audit logs ensure that all data access and administrative updates are tracked and attributable.
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="adm-module-nav">
          <button type="button" class="adm-module-nav-btn" data-target="#module5">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 5</span>
          </button>
          <a href="<?php echo BASE_URL; ?>admin/dashboard.php" class="adm-module-nav-btn btn-primary-nav">
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
