<?php
/**
 * User Help Module & Documentation Guide (/users/help.php)
 * 100% accurate, step-by-step operational guide for Parishioners in the TUGON Parish System.
 */

require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';
require_once '../includes/permissions.php';

requireLogin();
if (!isUser()) {
    redirect('../auth/login.php');
}

$page_title = 'Help & User Guide';
$body_extra_class = 'user-help-page';
$breadcrumbs = [
    'Dashboard' => 'index.php',
    'Help & Guide' => null
];

include '../templates/header.php';
include '../includes/breadcrumb.php';
?>

<style>
/* --------------------------------------------------------------------------
   USER MANUAL & GUIDE STYLES
   Clean, easy-to-read typography and scannable visual step cards
   Palette: Forest Green (#2E3A2D), Church Gold (#C89B3C), Slate, Teal
   -------------------------------------------------------------------------- */
:root {
  --usr-green:       #2E3A2D;
  --usr-green-mid:   #3D5C3A;
  --usr-green-light: #ebf3ec;
  --usr-gold:        #C89B3C;
  --usr-gold-light:  #fdf8ec;
  --usr-teal:        #0d9488;
  --usr-teal-light:  #f0fdfa;
  --usr-blue:        #2563eb;
  --usr-blue-light:  #eff6ff;
  --usr-purple:      #7c3aed;
  --usr-purple-light:#f5f3ff;
  --usr-amber:       #d97706;
  --usr-amber-light: #fffbeb;
  --usr-rose:        #e11d48;
  --usr-rose-light:  #fff1f2;
  --usr-slate-50:    #f8fafc;
  --usr-slate-100:   #f1f5f9;
  --usr-slate-200:   #e2e8f0;
  --usr-slate-300:   #cbd5e1;
  --usr-slate-600:   #475569;
  --usr-slate-700:   #334155;
  --usr-slate-800:   #1e293b;
  --usr-slate-900:   #0f172a;
  --usr-radius:      14px;
}

.usr-manual-wrap {
  font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  color: var(--usr-slate-800);
  font-size: 0.94rem;
  line-height: 1.65;
  padding-bottom: 60px;
}

/* --- Clean Light Header Banner --- */
.usr-hero {
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
.usr-hero-title {
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
.usr-hero-title i {
  color: #c9932b;
  font-size: 1.45rem;
}
.usr-hero-badge {
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #fde68a;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 4px 12px;
  border-radius: 20px;
  vertical-align: middle;
}
.usr-hero-sub,
.usr-hero p {
  color: #475569 !important;
  font-size: 0.95rem;
  line-height: 1.6;
  margin: 0.65rem 0 1.5rem 0;
  max-width: 840px;
}

/* Search Bar */
.usr-search-wrap {
  position: relative;
  max-width: 740px;
  margin-bottom: 1.35rem;
}
.usr-search-input {
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
.usr-search-input:focus {
  background: #ffffff;
  border-color: #c9932b;
  box-shadow: 0 0 0 3px rgba(201, 147, 43, 0.25);
}
.usr-search-input::placeholder {
  color: #64748b;
}
.usr-search-icon {
  position: absolute;
  left: 16px;
  top: 50%;
  transform: translateY(-50%);
  color: #64748b;
  font-size: 1rem;
  pointer-events: none;
}
.usr-search-clear {
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
.usr-search-clear:hover {
  color: #0f172a;
}

/* Quick Navigation Pills */
.usr-quick-pills {
  display: flex;
  gap: 0.55rem;
  flex-wrap: wrap;
  margin-top: 0;
}
.usr-pill {
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
.usr-pill:hover {
  background: #e2e8f0;
  color: #0f172a;
  border-color: #cbd5e1;
}
.usr-pill.active {
  background: #c9932b;
  color: #ffffff;
  font-weight: 700;
  border-color: #c9932b;
  box-shadow: 0 2px 8px rgba(201, 147, 43, 0.32);
}

/* --- Layout Grid --- */
.usr-layout {
  display: grid;
  grid-template-columns: 290px minmax(0, 1fr);
  gap: 28px;
  align-items: flex-start;
}
@media (max-width: 991px) {
  .usr-layout {
    grid-template-columns: 1fr;
  }
}

/* --- Left TOC Card --- */
.usr-toc-card {
  background: #ffffff;
  border: 1px solid var(--usr-slate-200);
  border-radius: var(--usr-radius);
  padding: 20px 16px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
  position: sticky;
  top: 86px;
  max-height: calc(100vh - 100px);
  overflow-y: auto;
  scrollbar-width: thin;
}
.usr-toc-header {
  font-size: 0.74rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--usr-slate-600);
  padding: 0 8px 10px;
  border-bottom: 1px solid var(--usr-slate-100);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.usr-toc-list {
  list-style: none;
  padding: 0;
  margin: 0;
}
.usr-toc-item {
  margin-bottom: 3px;
}
.usr-toc-link {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 0.84rem;
  font-weight: 600;
  color: var(--usr-slate-700);
  text-decoration: none;
  transition: all 0.15s ease;
  border-left: 4px solid transparent;
}
.usr-toc-link i {
  width: 18px;
  text-align: center;
  font-size: 0.9rem;
  color: var(--usr-slate-600);
  flex-shrink: 0;
  transition: color 0.15s;
}
.usr-toc-link:hover {
  background: var(--usr-slate-100);
  color: var(--usr-green);
}
.usr-toc-link.active {
  background: var(--usr-green-light);
  color: var(--usr-green);
  font-weight: 700;
  border-left-color: var(--usr-green);
  box-shadow: 0 2px 6px rgba(46, 58, 45, 0.08);
}
.usr-toc-link.active i {
  color: var(--usr-green-mid);
}

/* --- Content Section Cards --- */
.usr-section-card {
  background: #ffffff;
  border: 1px solid var(--usr-slate-200);
  border-radius: var(--usr-radius);
  padding: 30px 34px;
  margin-bottom: 26px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
  scroll-margin-top: 86px;
  display: none;
}
.usr-section-card.active-module {
  display: block;
  animation: usrModuleFadeIn 0.22s ease-out;
}
@keyframes usrModuleFadeIn {
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
.usr-module-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 32px;
  padding-top: 20px;
  border-top: 1.5px solid var(--usr-slate-100);
  flex-wrap: wrap;
}
.usr-module-nav-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  border-radius: 8px;
  font-size: 0.86rem;
  font-weight: 700;
  color: var(--usr-slate-700);
  background: var(--usr-slate-50);
  border: 1.5px solid var(--usr-slate-200);
  cursor: pointer;
  text-decoration: none;
  transition: all 0.15s ease;
}
.usr-module-nav-btn:hover {
  background: var(--usr-green-light);
  color: var(--usr-green);
  border-color: var(--usr-green);
}
.usr-module-nav-btn.btn-primary-nav {
  background: var(--usr-green);
  color: #ffffff;
  border-color: var(--usr-green);
  margin-left: auto;
}
.usr-module-nav-btn.btn-primary-nav:hover {
  background: var(--usr-green-mid);
  color: #ffffff;
  border-color: var(--usr-green-mid);
}

.usr-section-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  border-bottom: 1.5px solid var(--usr-slate-100);
  padding-bottom: 16px;
  margin-bottom: 22px;
}
.usr-section-meta {
  display: flex;
  align-items: center;
  gap: 14px;
}
.usr-section-icon {
  width: 46px;
  height: 46px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.25rem;
  flex-shrink: 0;
}
.icon-gold   { background: var(--usr-gold-light);   color: var(--usr-gold);   border: 1px solid rgba(200,155,60,0.25); }
.icon-blue   { background: var(--usr-blue-light);   color: var(--usr-blue);   border: 1px solid rgba(37,99,235,0.20); }
.icon-green  { background: var(--usr-green-light);  color: var(--usr-green);  border: 1px solid rgba(46,58,45,0.20); }
.icon-teal   { background: var(--usr-teal-light);   color: var(--usr-teal);   border: 1px solid rgba(13,148,136,0.20); }
.icon-purple { background: var(--usr-purple-light); color: var(--usr-purple); border: 1px solid rgba(124,58,237,0.20); }
.icon-amber  { background: var(--usr-amber-light);  color: var(--usr-amber);  border: 1px solid rgba(217,119,6,0.20); }
.icon-rose   { background: var(--usr-rose-light);   color: var(--usr-rose);   border: 1px solid rgba(225,29,72,0.20); }

.usr-section-title {
  font-size: 1.28rem;
  font-weight: 800;
  color: var(--usr-slate-900);
  margin: 0 0 3px;
  letter-spacing: -0.01em;
}
.usr-section-sub {
  font-size: 0.85rem;
  color: var(--usr-slate-600);
  margin: 0;
}
.usr-section-tag {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 4px 11px;
  border-radius: 20px;
  background: var(--usr-slate-100);
  color: var(--usr-slate-700);
  white-space: nowrap;
}

.usr-intro-text {
  font-size: 0.94rem;
  line-height: 1.65;
  color: var(--usr-slate-700);
  margin-bottom: 20px;
}

/* --- Visual Step Cards --- */
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
  border: 1.5px solid var(--usr-slate-200);
  box-shadow: 0 1px 2px rgba(0,0,0,0.03);
  transition: all 0.15s ease-in-out;
}
.step-card:hover {
  border-color: var(--usr-gold);
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.step-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--usr-green);
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
  color: var(--usr-slate-900);
  margin-bottom: 4px;
}
.step-card-desc {
  font-size: 0.9rem;
  color: var(--usr-slate-700);
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
.callout-danger {
  background: #fff1f2;
  border: 1.5px solid #fecaca;
  border-left: 5px solid #e11d48;
  color: #881337;
}

/* --- Accordions --- */
.usr-accordion-item {
  border: 1.5px solid var(--usr-slate-200);
  border-radius: 10px;
  margin-bottom: 12px;
  overflow: hidden;
  background: #ffffff;
}
.usr-accordion-btn {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 15px 20px;
  background: #ffffff;
  border: none;
  font-size: 0.92rem;
  font-weight: 700;
  color: var(--usr-slate-900);
  cursor: pointer;
  text-align: left;
  transition: background 0.15s;
}
.usr-accordion-btn:hover {
  background: var(--usr-slate-50);
}
.usr-accordion-btn i.fa-chevron-down {
  transition: transform 0.2s;
  font-size: 0.85rem;
  color: var(--usr-slate-600);
}
.usr-accordion-btn.active i.fa-chevron-down {
  transform: rotate(180deg);
}
.usr-accordion-body {
  display: none;
  padding: 16px 20px;
  background: var(--usr-slate-50);
  border-top: 1px solid var(--usr-slate-200);
  font-size: 0.88rem;
  line-height: 1.6;
  color: var(--usr-slate-700);
}
.usr-accordion-body.show {
  display: block;
}

/* Empty search container */
#usrSearchEmpty {
  display: none;
  text-align: center;
  padding: 50px 20px;
  background: #ffffff;
  border: 2px dashed var(--usr-slate-300);
  border-radius: var(--usr-radius);
  color: var(--usr-slate-600);
}
</style>

<div class="usr-manual-wrap container-fluid px-0">

  <!-- ================= HERO BANNER ================= -->
  <div class="usr-hero">
    <div class="usr-hero-title">
      <i class="fas fa-book-open"></i>
      Parishioner User Guide &amp; Operational Manual
      <span class="usr-hero-badge">TUGON Parish System</span>
    </div>
    <p class="usr-hero-sub">
      A step-by-step guide to help you register with ID verification, request official church certificates, book blessings and sacramental services, track requests in real time, and explore parish calendars and announcements.
    </p>

    <!-- Quick Search -->
    <div class="usr-search-wrap">
      <i class="fas fa-search usr-search-icon"></i>
      <input type="text" id="usrSearchInput" class="usr-search-input" placeholder="Search a module or keyword (e.g., register ID, certificate, blessing, wedding, GCash, tracking, calendar)..." aria-label="Search User Guide">
      <button type="button" id="usrSearchClear" class="usr-search-clear" title="Clear search"><i class="fas fa-times"></i></button>
    </div>

    <!-- Quick Navigation Pills -->
    <div class="usr-quick-pills">
      <span class="usr-pill active" data-target="#module1">1. Registration &amp; ID</span>
      <span class="usr-pill" data-target="#module2">2. Profile Settings</span>
      <span class="usr-pill" data-target="#module3">3. Certificates</span>
      <span class="usr-pill" data-target="#module4">4. Blessings &amp; Services</span>
      <span class="usr-pill" data-target="#module5">5. Track &amp; Claim</span>
      <span class="usr-pill" data-target="#module6">6. Calendar &amp; News</span>
      <span class="usr-pill" data-target="#module7">7. AI &amp; Notifications</span>
      <span class="usr-pill" data-target="#module8">8. FAQs</span>
    </div>
  </div>

  <!-- ================= MAIN LAYOUT ================= -->
  <div class="usr-layout">

    <!-- LEFT: Sticky TOC -->
    <aside class="usr-toc-col">
      <div class="usr-toc-card">
        <div class="usr-toc-header">
          <span>Manual Modules</span>
          <i class="fas fa-bars-staggered"></i>
        </div>
        <ul class="usr-toc-list">
          <li class="usr-toc-item">
            <a href="#module1" class="usr-toc-link active">
              <i class="fas fa-id-card"></i>
              <span>1. Registration &amp; ID</span>
            </a>
          </li>
          <li class="usr-toc-item">
            <a href="#module2" class="usr-toc-link">
              <i class="fas fa-user-pen"></i>
              <span>2. Profile Settings</span>
            </a>
          </li>
          <li class="usr-toc-item">
            <a href="#module3" class="usr-toc-link">
              <i class="fas fa-certificate"></i>
              <span>3. Certificates</span>
            </a>
          </li>
          <li class="usr-toc-item">
            <a href="#module4" class="usr-toc-link">
              <i class="fas fa-church"></i>
              <span>4. Blessings &amp; Services</span>
            </a>
          </li>
          <li class="usr-toc-item">
            <a href="#module5" class="usr-toc-link">
              <i class="fas fa-list-check"></i>
              <span>5. Track &amp; Claim</span>
            </a>
          </li>
          <li class="usr-toc-item">
            <a href="#module6" class="usr-toc-link">
              <i class="fas fa-calendar-days"></i>
              <span>6. Calendar &amp; News</span>
            </a>
          </li>
          <li class="usr-toc-item">
            <a href="#module7" class="usr-toc-link">
              <i class="fas fa-robot"></i>
              <span>7. AI &amp; Notifications</span>
            </a>
          </li>
          <li class="usr-toc-item">
            <a href="#module8" class="usr-toc-link">
              <i class="fas fa-circle-question"></i>
              <span>8. FAQs</span>
            </a>
          </li>
        </ul>
      </div>
    </aside>

    <!-- RIGHT: Content Sections -->
    <main class="usr-content-col">

      <!-- Empty Search State -->
      <div id="usrSearchEmpty">
        <i class="fas fa-magnifying-glass" style="font-size:2.4rem; opacity:0.3; margin-bottom:12px;"></i>
        <h5 style="font-weight:800; color:var(--usr-slate-900);">No matching topics found</h5>
        <p style="font-size:0.9rem; margin-bottom:14px;">Try searching for simple words like "register", "ID", "certificate", "blessing", "service", "GCash", "tracking", or "pickup".</p>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetUsrSearch()">Clear Search</button>
      </div>

      <!-- ================= MODULE 1 ================= -->
      <section id="module1" class="usr-section-card active-module">
        <div class="usr-section-header">
          <div class="usr-section-meta">
            <div class="usr-section-icon icon-gold">
              <i class="fas fa-id-card"></i>
            </div>
            <div>
              <h2 class="usr-section-title">Module 1: Registration &amp; ID Verification</h2>
              <p class="usr-section-sub">Create your verified parishioner account using live ID capture and photo verification</p>
            </div>
          </div>
          <span class="usr-section-tag">Account Setup</span>
        </div>

        <p class="usr-intro-text">
          To maintain accurate sacramental records and prevent duplicate entries, TUGON uses real-time ID capture and face verification during registration. Follow these steps to register your account.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">STEP 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Go to Register &amp; Select Valid ID Type</div>
              <p class="step-card-desc">
                Open the <strong>Register</strong> page and select your valid government ID type (e.g. <em>PhilSys National ID</em>, <em>Driver's License</em>, <em>Passport</em>, <em>UMID</em>, <em>Postal ID</em>, <em>Voter's ID</em>, or <em>PRC ID</em>).
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Capture Live Front ID, Back ID, and Live Photo</div>
              <p class="step-card-desc">
                Allow browser camera access, position your physical ID within the frame, and click <span class="action-badge action-badge-blue"><i class="fas fa-camera"></i> Capture Front ID</span>, <span class="action-badge action-badge-blue"><i class="fas fa-camera"></i> Capture Back ID</span>, and <span class="action-badge action-badge-green"><i class="fas fa-user"></i> Capture Live Photo</span>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Review Extracted Information &amp; Complete Details</div>
              <p class="step-card-desc">
                Review the scanned personal details, then fill in your <strong>Full Name</strong>, <strong>Birth Date</strong>, <strong>Birthplace</strong>, <strong>Gender</strong>, <strong>Civil Status</strong>, <strong>Address</strong>, <strong>Chapel / District</strong>, <strong>Contact Number</strong>, and <strong>Email Address</strong>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 4</span>
            <div class="step-card-body">
              <div class="step-card-title">Set Password &amp; Accept Terms and Conditions</div>
              <p class="step-card-desc">
                Enter a secure password, open the <strong>Terms &amp; Conditions</strong> modal, scroll down to read the agreement, check the consent box, and click <span class="action-badge action-badge-green">Complete Registration</span>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 5</span>
            <div class="step-card-body">
              <div class="step-card-title">Await Parish Office Verification</div>
              <p class="step-card-desc">
                Your new account will show <span class="action-badge action-badge-amber"><i class="fas fa-hourglass-half"></i> Pending Verification</span> while staff checks your ID match; once approved, your status turns to <span class="action-badge action-badge-green"><i class="fas fa-circle-check"></i> Verified</span>.
              </p>
            </div>
          </div>
        </div>

        <div class="callout-box callout-tip">
          <i class="fas fa-lightbulb"></i>
          <div>
            <strong>Camera Lighting Tip:</strong>
            Place your ID card on a flat surface with even indoor lighting. Avoid strong shadows and direct glare so the ID scanner and parish staff can clearly read your name and birthdate.
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="usr-module-nav">
          <div></div>
          <button type="button" class="usr-module-nav-btn btn-primary-nav" data-target="#module2">
            <span>Next: Module 2 (Profile Settings)</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </section>

      <!-- ================= MODULE 2 ================= -->
      <section id="module2" class="usr-section-card">
        <div class="usr-section-header">
          <div class="usr-section-meta">
            <div class="usr-section-icon icon-teal">
              <i class="fas fa-user-pen"></i>
            </div>
            <div>
              <h2 class="usr-section-title">Module 2: Profile Settings &amp; Security</h2>
              <p class="usr-section-sub">Manage your personal information, profile photo, contact details, and account password</p>
            </div>
          </div>
          <span class="usr-section-tag">Profile Settings</span>
        </div>

        <p class="usr-intro-text">
          Keep your contact information and residential address updated to receive accurate ceremony reminders, SMS alerts, and document pickup notices.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">STEP 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Go to Profile Settings</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>auth/profile.php"><strong>Profile Settings</strong></a> in the sidebar under the Account section.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Update Your Avatar &amp; Contact Details</div>
              <p class="step-card-desc">
                Upload a new profile picture, update your 11-digit <strong>Contact Number</strong>, <strong>Address</strong>, and <strong>Chapel / District</strong>, then click <span class="action-badge action-badge-green"><i class="fas fa-floppy-disk"></i> Save Profile</span>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Change Your Account Password</div>
              <p class="step-card-desc">
                Switch to the <strong>Security</strong> section, type your current password, enter your new password, confirm it, and click <span class="action-badge action-badge-blue"><i class="fas fa-key"></i> Update Password</span>.
              </p>
            </div>
          </div>
        </div>

        <div class="callout-box callout-note">
          <i class="fas fa-shield-halved"></i>
          <div>
            <strong>Identity Protection:</strong>
            Core verified identity fields (such as your registered Full Name and Birthdate) cannot be modified directly once verified. If you need to correct a legal name spelling, please visit the parish office with your supporting PSA documents.
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="usr-module-nav">
          <button type="button" class="usr-module-nav-btn" data-target="#module1">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 1</span>
          </button>
          <button type="button" class="usr-module-nav-btn btn-primary-nav" data-target="#module3">
            <span>Next: Module 3 (Certificates)</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </section>

      <!-- ================= MODULE 3 ================= -->
      <section id="module3" class="usr-section-card">
        <div class="usr-section-header">
          <div class="usr-section-meta">
            <div class="usr-section-icon icon-blue">
              <i class="fas fa-certificate"></i>
            </div>
            <div>
              <h2 class="usr-section-title">Module 3: Requesting Church Certificates</h2>
              <p class="usr-section-sub">Submit certificate requests with payment selection, release preferences, and document uploads</p>
            </div>
          </div>
          <span class="usr-section-tag">Certificates</span>
        </div>

        <p class="usr-intro-text">
          Request official parish certifications (Baptismal, Confirmation, First Communion, Marriage, or Death/Funeral) directly online with flexible payment and release options.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">STEP 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Go to Certificates</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>users/request-certificate.php"><strong>Certificates</strong></a> under <strong>My Requests</strong> in the sidebar menu.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Select Certificate Type &amp; Purpose</div>
              <p class="step-card-desc">
                Choose your certificate (e.g. <strong>Baptismal Certificate</strong>, <strong>Confirmation Certificate</strong>, <strong>First Communion Certificate</strong>, <strong>Marriage Certificate</strong>, or <strong>Death / Funeral Certification</strong>) and select the purpose (e.g. <em>School Requirement</em>, <em>Marriage Requirement</em>, <em>Sponsor / Godparent</em>, or <em>Personal Copy</em>).
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Fill in Record Details &amp; Upload Requirements</div>
              <p class="step-card-desc">
                Enter the certificate holder's name, sacrament date or approximate year, parents' names, and upload required supporting files (such as a <strong>PSA Birth Certificate</strong> or <strong>Valid ID</strong>).
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 4</span>
            <div class="step-card-body">
              <div class="step-card-title">Choose Delivery Mode: Online Release or Walk-in Release</div>
              <p class="step-card-desc">
                Select <span class="action-badge action-badge-blue"><i class="fas fa-cloud-arrow-down"></i> Online Release</span> to download your official PDF directly from the portal, or <span class="action-badge action-badge-slate"><i class="fas fa-building"></i> Walk-in Release</span> to pick up a physical signed copy at the parish office.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 5</span>
            <div class="step-card-body">
              <div class="step-card-title">Select Payment Method: GCash or Cash</div>
              <p class="step-card-desc">
                Choose <span class="action-badge action-badge-blue"><i class="fas fa-mobile-screen-button"></i> GCash</span> (scan the QR code or send payment to <strong>0997 742 8176 - Agnes Calapaan</strong> and upload receipt screenshot) or <span class="action-badge action-badge-green"><i class="fas fa-money-bill-wave"></i> Cash</span> (pay directly upon claiming at the parish office).
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 6</span>
            <div class="step-card-body">
              <div class="step-card-title">Submit Request &amp; Note Reference Number</div>
              <p class="step-card-desc">
                Click <span class="action-badge action-badge-green"><i class="fas fa-paper-plane"></i> Submit Request</span> and keep your generated tracking reference number (e.g. <code>REQ-2026-XXXXXX</code>) for monitoring.
              </p>
            </div>
          </div>
        </div>

        <div class="callout-box callout-warning">
          <i class="fas fa-triangle-exclamation"></i>
          <div>
            <strong>Active Request Guard:</strong>
            To prevent accidental duplicate records, the system will not allow a new certificate request for the same person if an existing request is still actively pending or processing.
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="usr-module-nav">
          <button type="button" class="usr-module-nav-btn" data-target="#module2">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 2</span>
          </button>
          <button type="button" class="usr-module-nav-btn btn-primary-nav" data-target="#module4">
            <span>Next: Module 4 (Blessings &amp; Services)</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </section>

      <!-- ================= MODULE 4 ================= -->
      <section id="module4" class="usr-section-card">
        <div class="usr-section-header">
          <div class="usr-section-meta">
            <div class="usr-section-icon icon-green">
              <i class="fas fa-church"></i>
            </div>
            <div>
              <h2 class="usr-section-title">Module 4: Blessings &amp; Sacramental Services</h2>
              <p class="usr-section-sub">Book sacred ceremonies, house/vehicle blessings, baptisms, weddings, and funeral masses</p>
            </div>
          </div>
          <span class="usr-section-tag">Services &amp; Blessings</span>
        </div>

        <p class="usr-intro-text">
          Schedule sacramental ceremonies and off-site blessings with coordinated calendar dates, required documents, and priest scheduling.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">STEP 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Choose Blessings or Sacramental Services</div>
              <p class="step-card-desc">
                In the sidebar under <strong>My Requests</strong>, click <a href="<?php echo BASE_URL; ?>users/request-blessing.php"><strong>Blessings</strong></a> for home/vehicle/business blessings, or <a href="<?php echo BASE_URL; ?>users/request-service.php"><strong>Sacramental Services</strong></a> for Baptisms, Weddings, First Communions, or Funeral Masses.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Select Specific Service or Blessing Type</div>
              <p class="step-card-desc">
                For blessings, select <em>House Blessing</em>, <em>Vehicle Blessing</em>, <em>Business Blessing</em>, <em>Office Blessing</em>, <em>Event Blessing</em>, or <em>Other Blessing</em>. For sacramental services, select <em>Baptism</em>, <em>Confirmation</em>, <em>First Communion</em>, <em>Marriage / Wedding</em>, <em>Anointing of the Sick</em>, <em>Funeral Mass</em>, or <em>Patronal Fiesta</em>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Fill Out Ceremony Details &amp; Godparents/Sponsors</div>
              <p class="step-card-desc">
                Fill in the candidate/couple names, parent origins, principal sponsors (Ninong and Ninang), exact location/chapel, and preferred ceremony date and time slot.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 4</span>
            <div class="step-card-body">
              <div class="step-card-title">Upload Mandatory Sacramental Documents</div>
              <p class="step-card-desc">
                Attach the required photocopies based on the service (e.g. <strong>PSA Live Birth Certificate</strong> and <strong>White Cards</strong> for Baptism; <strong>Pre-Cana Certificate</strong>, <strong>Municipal Marriage License</strong>, and <strong>Permit to Marry</strong> for Weddings; <strong>Death Certificate</strong> for Funerals).
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 5</span>
            <div class="step-card-body">
              <div class="step-card-title">Submit Booking Request &amp; Monitor Schedule Confirmation</div>
              <p class="step-card-desc">
                Click <span class="action-badge action-badge-green"><i class="fas fa-calendar-check"></i> Submit Request</span>; if the parish office proposes an adjusted calendar slot, you can accept or decline the proposal directly from your request details page.
              </p>
            </div>
          </div>
        </div>

        <div class="callout-box callout-note">
          <i class="fas fa-calendar-check"></i>
          <div>
            <strong>Schedule Conflict Protection:</strong>
            The TUGON system cross-checks parish calendar reservations in real time. If your chosen date or time has an existing liturgical conflict, parish staff will contact you or issue an official schedule proposal.
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="usr-module-nav">
          <button type="button" class="usr-module-nav-btn" data-target="#module3">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 3</span>
          </button>
          <button type="button" class="usr-module-nav-btn btn-primary-nav" data-target="#module5">
            <span>Next: Module 5 (Track &amp; Claim)</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </section>

      <!-- ================= MODULE 5 ================= -->
      <section id="module5" class="usr-section-card">
        <div class="usr-section-header">
          <div class="usr-section-meta">
            <div class="usr-section-icon icon-amber">
              <i class="fas fa-list-check"></i>
            </div>
            <div>
              <h2 class="usr-section-title">Module 5: Tracking Requests, Online Downloads &amp; Walk-in Claiming</h2>
              <p class="usr-section-sub">Monitor live status changes, download released digital certificates, and claim physical copies</p>
            </div>
          </div>
          <span class="usr-section-tag">Track &amp; Claim</span>
        </div>

        <p class="usr-intro-text">
          Track every sacrament, blessing, and certificate request in real time, view staff remarks, download finalized certificates, or prepare for walk-in claiming.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">STEP 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Go to Track Requests</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>users/my-requests.php"><strong>Track Requests</strong></a> in the sidebar under <strong>My Requests</strong>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Filter &amp; Search Your Requests</div>
              <p class="step-card-desc">
                Use the search box, <strong>All Types</strong> filter (<em>Blessings</em>, <em>Certificates</em>, <em>Sacramental Services</em>), or <strong>All Statuses</strong> filter (<em>Pending</em>, <em>Processing</em>, <em>Completed</em>, <em>Rejected</em>) and click <span class="action-badge action-badge-slate"><i class="fas fa-filter"></i> Filter</span>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Click View Details on Any Request</div>
              <p class="step-card-desc">
                Click <span class="action-badge action-badge-slate"><i class="fas fa-eye"></i> View Details</span> on any row to open the complete timeline, payment receipts, uploaded attachments, and parish remarks.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 4</span>
            <div class="step-card-body">
              <div class="step-card-title">Download Certificate (Online Release)</div>
              <p class="step-card-desc">
                When an Online Release certificate is finalized, an alert banner will appear; click <span class="action-badge action-badge-green"><i class="fas fa-download"></i> Download Certificate</span> or <span class="action-badge action-badge-slate"><i class="fas fa-eye"></i> Preview</span> to view the signed PDF.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 5</span>
            <div class="step-card-body">
              <div class="step-card-title">Claim at Parish Office (Walk-in Release)</div>
              <p class="step-card-desc">
                When your status changes to <span class="action-badge action-badge-green"><i class="fas fa-circle-check"></i> Completed</span> or <span class="action-badge action-badge-green"><i class="fas fa-certificate"></i> Released</span>, visit the parish office during open hours, present your <strong>Reference Number</strong> (e.g. <code>REQ-2026-XXXXXX</code>) and a <strong>valid ID</strong>, and settle any pending cash payment.
              </p>
            </div>
          </div>
        </div>

        <div class="callout-box callout-tip">
          <i class="fas fa-user-check"></i>
          <div>
            <strong>Authorizing a Representative for Walk-in Claiming:</strong>
            If you cannot visit the parish office personally, provide your representative with:
            <ul style="margin: 4px 0 0; padding-left: 18px;">
              <li>A signed <strong>Authorization Letter</strong> indicating your Reference Number.</li>
              <li>A photocopy of your <strong>Valid ID</strong> with signature.</li>
              <li>Their own original <strong>Valid Government ID</strong>.</li>
            </ul>
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="usr-module-nav">
          <button type="button" class="usr-module-nav-btn" data-target="#module4">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 4</span>
          </button>
          <button type="button" class="usr-module-nav-btn btn-primary-nav" data-target="#module6">
            <span>Next: Module 6 (Calendar &amp; News)</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </section>

      <!-- ================= MODULE 6 ================= -->
      <section id="module6" class="usr-section-card">
        <div class="usr-section-header">
          <div class="usr-section-meta">
            <div class="usr-section-icon icon-purple">
              <i class="fas fa-calendar-days"></i>
            </div>
            <div>
              <h2 class="usr-section-title">Module 6: Parish Calendar, Announcements &amp; Organization Chart</h2>
              <p class="usr-section-sub">Stay informed on Mass schedules, upcoming feast days, parish notices, and church leadership</p>
            </div>
          </div>
          <span class="usr-section-tag">Parish Information</span>
        </div>

        <p class="usr-intro-text">
          Access official church announcements, interactive calendar schedules, and the complete organizational leadership hierarchy of the parish.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">STEP 1</span>
            <div class="step-card-body">
              <div class="step-card-title">View the Parish Calendar</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>users/view-schedule.php"><strong>Parish Calendar</strong></a> under the Communication section to view upcoming approved Mass schedules, feast days, confessions, and parish-wide events in month, week, or list views.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Read Parish Announcements</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>users/announcements.php"><strong>Announcements</strong></a> to browse pinned bulletins, monthly schedules, pastoral guidelines, fiesta schedules, and download event circulars.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Explore the Parish Organization Chart</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>users/organization.php"><strong>Parish Organization Chart</strong></a> to view the 5-tier leadership tree: Parish Priest, Parochial Vicar, Parish Pastoral Council officers, Commissions/Ministries, and Chapel/BEC leaders with official contact links.
              </p>
            </div>
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="usr-module-nav">
          <button type="button" class="usr-module-nav-btn" data-target="#module5">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 5</span>
          </button>
          <button type="button" class="usr-module-nav-btn btn-primary-nav" data-target="#module7">
            <span>Next: Module 7 (AI &amp; Notifications)</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </section>

      <!-- ================= MODULE 7 ================= -->
      <section id="module7" class="usr-section-card">
        <div class="usr-section-header">
          <div class="usr-section-meta">
            <div class="usr-section-icon icon-rose">
              <i class="fas fa-robot"></i>
            </div>
            <div>
              <h2 class="usr-section-title">Module 7: Notifications &amp; AI Parish Assistant</h2>
              <p class="usr-section-sub">Get real-time request alerts and ask 24/7 questions about parish requirements and schedules</p>
            </div>
          </div>
          <span class="usr-section-tag">Communication &amp; AI</span>
        </div>

        <p class="usr-intro-text">
          Never miss an update regarding your request approvals or schedule changes, and get instant answers anytime using the TUGON AI Assistant.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">STEP 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Check Notifications for Status Updates</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>users/notifications.php"><strong>Notifications</strong></a> in the sidebar (or the bell icon in the top header) to view real-time notifications about request approvals, payment verifications, and certificate readiness.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Open the AI Assistant</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>users/ai-assistant.php"><strong>AI Assistant</strong></a> in the sidebar under Communication to start an interactive chat session.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">STEP 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Ask Questions or Use Quick Prompts</div>
              <p class="step-card-desc">
                Type any question regarding sacramental requirements, mass schedules, donation channels, or office hours, or click one of the suggested quick prompts to receive instant guided answers.
              </p>
            </div>
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="usr-module-nav">
          <button type="button" class="usr-module-nav-btn" data-target="#module6">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 6</span>
          </button>
          <button type="button" class="usr-module-nav-btn btn-primary-nav" data-target="#module8">
            <span>Next: Module 8 (FAQs)</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </section>

      <!-- ================= MODULE 8 ================= -->
      <section id="module8" class="usr-section-card">
        <div class="usr-section-header">
          <div class="usr-section-meta">
            <div class="usr-section-icon icon-gold">
              <i class="fas fa-circle-question"></i>
            </div>
            <div>
              <h2 class="usr-section-title">Module 8: Frequently Asked Questions (FAQ)</h2>
              <p class="usr-section-sub">Clear answers to common questions about accounts, certificates, payments, and services</p>
            </div>
          </div>
          <span class="usr-section-tag">Help &amp; Answers</span>
        </div>

        <!-- Accordions -->
        <div class="usr-accordion-item">
          <button type="button" class="usr-accordion-btn">
            <span>How long does it take for a certificate request to be processed?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="usr-accordion-body">
            Standard certificate processing usually takes <strong>1 to 3 parish office days</strong>. If the sacramental record is from an older physical register that requires archival lookup, it may take up to 5 days. You will receive an alert as soon as your certificate is ready.
          </div>
        </div>

        <div class="usr-accordion-item">
          <button type="button" class="usr-accordion-btn">
            <span>How do I pay with GCash, and when is my payment verified?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="usr-accordion-body">
            During certificate submission, select <strong>GCash</strong> as your payment method, transfer the required amount to <strong>0997 742 8176 (Agnes Calapaan)</strong> or scan the displayed QR code, and upload a clear screenshot of your transaction receipt. The parish cashier will verify the reference number and mark your payment as verified.
          </div>
        </div>

        <div class="usr-accordion-item">
          <button type="button" class="usr-accordion-btn">
            <span>What is the difference between Online Release and Walk-in Release?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="usr-accordion-body">
            <strong>Online Release</strong> allows you to download an official digital PDF certificate directly from the Track Requests page once approved. <strong>Walk-in Release</strong> prepares a physical, printed copy with the official church dry seal and signature for pickup at the parish office.
          </div>
        </div>

        <div class="usr-accordion-item">
          <button type="button" class="usr-accordion-btn">
            <span>Why does my account say "Pending Verification"? Can I still make requests?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="usr-accordion-body">
            When you first create an account, parish staff compares your live photo and ID upload to confirm your profile. While awaiting verification, you can explore the portal. Once reviewed, your profile will display the green <span class="action-badge action-badge-green"><i class="fas fa-circle-check"></i> Verified</span> badge.
          </div>
        </div>

        <div class="usr-accordion-item">
          <button type="button" class="usr-accordion-btn">
            <span>Can I reschedule a booked baptism or wedding?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="usr-accordion-body">
            Yes. If you need to adjust your ceremony date or time, please notify the parish office at least <strong>7 days prior</strong> to the scheduled date. If the parish proposes a schedule adjustment due to liturgical activities, you will see a proposal banner in your request details where you can click <strong>Accept</strong> or <strong>Reject</strong>.
          </div>
        </div>

        <div class="usr-accordion-item">
          <button type="button" class="usr-accordion-btn">
            <span>What should I bring when picking up documents at the parish office?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="usr-accordion-body">
            Bring your <strong>Reference Number</strong> (e.g. <code>REQ-2026-XXXXXX</code>) and a <strong>valid government ID</strong>. If you selected Cash payment, please prepare the exact fee amount. If sending an authorized representative, provide an authorization letter and a photocopy of your valid ID.
          </div>
        </div>

        <!-- Module Navigation Footer -->
        <div class="usr-module-nav">
          <button type="button" class="usr-module-nav-btn" data-target="#module7">
            <i class="fas fa-arrow-left"></i>
            <span>Previous: Module 7</span>
          </button>
          <a href="<?php echo BASE_URL; ?>index.php" class="usr-module-nav-btn btn-primary-nav">
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

  const searchInput = document.getElementById('usrSearchInput');
  const searchClear = document.getElementById('usrSearchClear');
  const emptyState  = document.getElementById('usrSearchEmpty');
  const cards       = Array.from(document.querySelectorAll('.usr-section-card'));
  const tocLinks    = Array.from(document.querySelectorAll('.usr-toc-link'));
  const pills       = Array.from(document.querySelectorAll('.usr-pill'));
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
    const targetCard = (targetEl && targetEl.classList.contains('usr-section-card'))
      ? targetEl
      : document.getElementById('module1');

    if (!targetCard) return;
    currentActiveId = targetCard.id;

    // Reset search bar if user was searching
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
      const mainCol = document.querySelector('.usr-content-col');
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
  document.querySelectorAll('.usr-module-nav-btn[data-target]').forEach(btn => {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      const targetId = this.getAttribute('data-target');
      switchModule(targetId, true, true);
    });
  });

  // Accordion toggle
  document.querySelectorAll('.usr-accordion-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const body = this.nextElementSibling;
      this.classList.toggle('active');
      if (body) {
        body.classList.toggle('show');
      }
    });
  });

  // Search functionality: when typing, reveals matching modules; when cleared, restores active tab
  function doUsrSearch() {
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
        card.querySelectorAll('.usr-accordion-item').forEach(acc => {
          const accText = acc.textContent.toLowerCase();
          const btn = acc.querySelector('.usr-accordion-btn');
          const body = acc.querySelector('.usr-accordion-body');
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
    searchInput.addEventListener('input', doUsrSearch);
  }

  if (searchClear) {
    searchClear.addEventListener('click', function () {
      searchInput.value = '';
      doUsrSearch();
      searchInput.focus();
    });
  }

  window.resetUsrSearch = function () {
    if (searchInput) {
      searchInput.value = '';
      doUsrSearch();
    }
  };

  // Initialize active tab on load
  initFromHash();

})();
</script>

<?php include '../templates/footer.php'; ?>
