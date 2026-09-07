<?php
/**
 * User Help Module & Documentation Guide (/users/help.php)
 * Simple, easy-to-understand step-by-step guide for Parishioners.
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

/* --- Hero Banner --- */
.usr-hero {
  background: linear-gradient(135deg, #1e293b 0%, #2E3A2D 60%, #172a1e 100%);
  border-radius: var(--usr-radius);
  padding: 34px 38px;
  color: #ffffff;
  margin-bottom: 28px;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 18px rgba(0,0,0,0.08);
}
.usr-hero::after {
  content: '';
  position: absolute;
  top: -40px;
  right: -40px;
  width: 260px;
  height: 260px;
  background: radial-gradient(circle, rgba(200,155,60,0.22) 0%, rgba(200,155,60,0) 70%);
  pointer-events: none;
}
.usr-hero-title {
  font-size: 1.65rem;
  font-weight: 800;
  letter-spacing: -0.02em;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.usr-hero-badge {
  background: var(--usr-gold);
  color: #1e293b;
  font-size: 0.72rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  padding: 4px 12px;
  border-radius: 20px;
  vertical-align: middle;
}
.usr-hero-sub {
  font-size: 0.95rem;
  color: rgba(255,255,255,0.88);
  max-width: 720px;
  margin-bottom: 22px;
  line-height: 1.55;
}

/* Search bar */
.usr-search-wrap {
  position: relative;
  max-width: 620px;
}
.usr-search-input {
  width: 100%;
  padding: 13px 44px 13px 46px;
  border-radius: 12px;
  border: 1.5px solid rgba(255,255,255,0.18);
  background: rgba(255,255,255,0.98);
  font-size: 0.93rem;
  color: var(--usr-slate-900);
  outline: none;
  box-shadow: 0 4px 18px rgba(0,0,0,0.12);
  transition: all 0.2s ease;
}
.usr-search-input:focus {
  background: #ffffff;
  border-color: var(--usr-gold);
  box-shadow: 0 0 0 4px rgba(200,155,60,0.30);
}
.usr-search-icon {
  position: absolute;
  left: 16px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--usr-slate-600);
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
  color: var(--usr-slate-600);
  cursor: pointer;
  display: none;
  font-size: 0.9rem;
}
.usr-quick-pills {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 14px;
}
.usr-pill {
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
.usr-pill:hover {
  background: var(--usr-gold);
  color: #1e293b;
  border-color: var(--usr-gold);
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
  padding: 9px 12px;
  border-radius: 8px;
  font-size: 0.84rem;
  font-weight: 600;
  color: var(--usr-slate-700);
  text-decoration: none;
  transition: all 0.15s;
}
.usr-toc-link i {
  width: 18px;
  text-align: center;
  font-size: 0.9rem;
  color: var(--usr-slate-600);
  flex-shrink: 0;
}
.usr-toc-link:hover {
  background: var(--usr-slate-100);
  color: var(--usr-green);
}
.usr-toc-link.active {
  background: var(--usr-green-light);
  color: var(--usr-green);
  font-weight: 700;
  border-left: 3px solid var(--usr-green);
}
.usr-toc-link.active i {
  color: var(--usr-gold);
}
.usr-toc-shortcuts {
  margin-top: 20px;
  padding-top: 14px;
  border-top: 1px solid var(--usr-slate-100);
}
.usr-shortcut-btn {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 9px 12px;
  border-radius: 8px;
  font-size: 0.8rem;
  font-weight: 600;
  background: var(--usr-slate-50);
  border: 1px solid var(--usr-slate-200);
  color: var(--usr-slate-700);
  text-decoration: none;
  margin-bottom: 6px;
  transition: all 0.15s;
}
.usr-shortcut-btn:hover {
  background: var(--usr-gold-light);
  border-color: var(--usr-gold);
  color: var(--usr-green);
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
      Parishioner User Guide &amp; Manual
      <span class="usr-hero-badge">TUGON System</span>
    </div>
    <p class="usr-hero-sub">
      A simple, step-by-step guide to help you register with your ID, request official church certificates, book baptisms or weddings, and track your requests.
    </p>

    <!-- Quick Search -->
    <div class="usr-search-wrap">
      <i class="fas fa-search usr-search-icon"></i>
      <input type="text" id="usrSearchInput" class="usr-search-input" placeholder="Search a topic or step (e.g., register ID, request certificate, baptism, calendar)..." aria-label="Search User Guide">
      <button type="button" id="usrSearchClear" class="usr-search-clear" title="Clear search"><i class="fas fa-times"></i></button>
    </div>

    <!-- Quick Navigation Pills -->
    <div class="usr-quick-pills">
      <span class="usr-pill" data-target="#module1">1. Register with an ID</span>
      <span class="usr-pill" data-target="#module2">2. Update Profile</span>
      <span class="usr-pill" data-target="#module3">3. Request Certificate</span>
      <span class="usr-pill" data-target="#module4">4. Book a Church Service</span>
      <span class="usr-pill" data-target="#module5">5. Track &amp; Claim</span>
      <span class="usr-pill" data-target="#module6">6. FAQs</span>
    </div>
  </div>

  <!-- ================= MAIN LAYOUT ================= -->
  <div class="usr-layout">

    <!-- LEFT: Sticky TOC -->
    <aside class="usr-toc-col">
      <div class="usr-toc-card">
        <div class="usr-toc-header">
          <span>Guide Topics</span>
          <i class="fas fa-bars-staggered"></i>
        </div>
        <ul class="usr-toc-list">
          <li class="usr-toc-item">
            <a href="#module1" class="usr-toc-link active">
              <i class="fas fa-id-card"></i>
              <span>1. Register with an ID</span>
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
              <span>3. Request Certificate</span>
            </a>
          </li>
          <li class="usr-toc-item">
            <a href="#module4" class="usr-toc-link">
              <i class="fas fa-church"></i>
              <span>4. Book Church Service</span>
            </a>
          </li>
          <li class="usr-toc-item">
            <a href="#module5" class="usr-toc-link">
              <i class="fas fa-route"></i>
              <span>5. Track &amp; Claim</span>
            </a>
          </li>
          <li class="usr-toc-item">
            <a href="#module6" class="usr-toc-link">
              <i class="fas fa-circle-question"></i>
              <span>6. Questions &amp; Answers</span>
            </a>
          </li>
        </ul>

        <!-- Direct Parishioner Shortcuts -->
        <div class="usr-toc-shortcuts">
          <div class="usr-toc-header" style="padding-left:0; margin-bottom:8px;">
            <span>Quick Shortcuts</span>
            <i class="fas fa-arrow-up-right-from-square"></i>
          </div>
          <a href="<?php echo BASE_URL; ?>users/request-certificate.php" class="usr-shortcut-btn">
            <span><i class="fas fa-file-invoice" style="margin-right:6px; color:var(--usr-gold);"></i> Request Certificate</span>
            <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
          </a>
          <a href="<?php echo BASE_URL; ?>users/request-service.php" class="usr-shortcut-btn">
            <span><i class="fas fa-calendar-plus" style="margin-right:6px; color:var(--usr-teal);"></i> Book Service</span>
            <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
          </a>
          <a href="<?php echo BASE_URL; ?>users/my-requests.php" class="usr-shortcut-btn">
            <span><i class="fas fa-list-check" style="margin-right:6px; color:var(--usr-blue);"></i> Track My Requests</span>
            <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
          </a>
          <a href="<?php echo BASE_URL; ?>users/view-schedule.php" class="usr-shortcut-btn">
            <span><i class="fas fa-calendar-days" style="margin-right:6px; color:var(--usr-green);"></i> Parish Calendar</span>
            <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
          </a>
        </div>
      </div>
    </aside>

    <!-- RIGHT: Content Sections -->
    <main class="usr-content-col">

      <!-- Empty Search State -->
      <div id="usrSearchEmpty">
        <i class="fas fa-magnifying-glass" style="font-size:2.4rem; opacity:0.3; margin-bottom:12px;"></i>
        <h5 style="font-weight:800; color:var(--usr-slate-900);">No matching topics found</h5>
        <p style="font-size:0.9rem; margin-bottom:14px;">Try searching for simple words like "register", "ID", "baptism", "wedding", "status", or "pickup".</p>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetUsrSearch()">Clear Search</button>
      </div>

      <!-- ================= MODULE 1 ================= -->
      <section id="module1" class="usr-section-card">
        <div class="usr-section-header">
          <div class="usr-section-meta">
            <div class="usr-section-icon icon-gold">
              <i class="fas fa-id-card"></i>
            </div>
            <div>
              <h2 class="usr-section-title">Module 1: How to Register &amp; Take an ID Photo</h2>
              <p class="usr-section-sub">Create your account by taking a quick photo of your government ID</p>
            </div>
          </div>
          <span class="usr-section-tag">Account Setup</span>
        </div>

        <p class="usr-intro-text">
          To protect church records and keep our parish community safe, TUGON uses a live camera photo of your ID during registration. Follow these 5 easy steps to register your account.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Allow Camera Access</div>
              <p class="step-card-desc">
                When you click Register, your browser or phone will ask to use your camera. Click <span class="action-badge action-badge-green"><i class="fas fa-video"></i> Allow</span> so the live camera opens.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Position Your ID Inside the Frame</div>
              <p class="step-card-desc">
                Hold your Government ID (PhilSys National ID, Driver's License, UMID, Postal ID, Passport, PRC ID, or Voter's ID) flat inside the box on your screen. Make sure your name and photo are clearly visible.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Click Capture ID</div>
              <p class="step-card-desc">
                Press <span class="action-badge action-badge-blue"><i class="fas fa-camera"></i> Capture ID</span>. The system will read your ID card and automatically type your full name, birthdate, and address into the form.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 4</span>
            <div class="step-card-body">
              <div class="step-card-title">Check Your Details &amp; Add Mobile Phone</div>
              <p class="step-card-desc">
                Review the information on screen. Fix any small spelling mistakes, enter your active mobile phone number, and choose a password for your account.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 5</span>
            <div class="step-card-body">
              <div class="step-card-title">Accept Terms &amp; Create Account</div>
              <p class="step-card-desc">
                Scroll the Terms &amp; Conditions box all the way to the bottom. Click the checkbox to agree, then press <span class="action-badge action-badge-green">Create Account</span>. You're done!
              </p>
            </div>
          </div>
        </div>

        <!-- Tip Box -->
        <div class="callout-box callout-tip">
          <i class="fas fa-lightbulb"></i>
          <div>
            <strong>Photo Tip:</strong>
            Place your ID flat on a table in a well-lit room. Avoid glare or strong light bouncing directly off the plastic card so the system can read your name clearly.
          </div>
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
              <h2 class="usr-section-title">Module 2: How to Update Your Profile &amp; Password</h2>
              <p class="usr-section-sub">Change your profile picture, update your phone number, or change your password</p>
            </div>
          </div>
          <span class="usr-section-tag">Profile Settings</span>
        </div>

        <p class="usr-intro-text">
          Keep your contact information up to date so you never miss important church announcements, ceremony reminders, or pickup alerts.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Upload a Profile Picture</div>
              <p class="step-card-desc">
                Go to <a href="<?php echo BASE_URL; ?>auth/profile.php"><strong>Profile Settings</strong></a>. Click the camera icon on your avatar to upload a friendly photo of yourself.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Update Your Phone and Address</div>
              <p class="step-card-desc">
                Enter your current 11-digit cellphone number (e.g., <code>09171234567</code>) and home address. Click <span class="action-badge action-badge-green">Save Profile</span>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Change Your Password</div>
              <p class="step-card-desc">
                Click the <strong>Security</strong> tab. Type your old password, then type your new password twice. Click <span class="action-badge action-badge-blue">Update Password</span>.
              </p>
            </div>
          </div>
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
              <h2 class="usr-section-title">Module 3: How to Request a Church Certificate</h2>
              <p class="usr-section-sub">Apply for official Baptism, Confirmation, First Communion, or Marriage certificates</p>
            </div>
          </div>
          <span class="usr-section-tag">Certificates</span>
        </div>

        <p class="usr-intro-text">
          Need an official church certificate for school enrollment, a wedding requirement, or sponsor duties? You can request it right from your phone or computer.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Go to Request Certificate</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>users/request-certificate.php"><strong>Request Certificate</strong></a> in the menu.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Pick the Certificate You Need</div>
              <p class="step-card-desc">
                Select your sacrament:
                <span class="action-badge action-badge-blue">Baptismal Certificate</span>,
                <span class="action-badge action-badge-amber">Confirmation Certificate</span>,
                <span class="action-badge action-badge-green">Marriage Certificate</span>, or
                <span class="action-badge action-badge-purple">First Communion</span>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Select the Reason for Request</div>
              <p class="step-card-desc">
                Choose why you need the certificate (e.g., <em>School Requirement</em>, <em>Marriage Requirement</em>, <em>Sponsor / Godparent</em>, or <em>Personal Copy</em>).
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 4</span>
            <div class="step-card-body">
              <div class="step-card-title">Enter Details &amp; Upload Requirements</div>
              <p class="step-card-desc">
                Type the approximate year of the sacrament and your parents' names. Attach a clear photo of your PSA Birth Certificate or valid ID, then click <span class="action-badge action-badge-green">Send Request</span>.
              </p>
            </div>
          </div>
        </div>

        <!-- Warning Callout (Anti-Duplicate Rule) -->
        <div class="callout-box callout-danger">
          <i class="fas fa-shield-xmark"></i>
          <div>
            <strong>Important Rule (No Duplicate Requests):</strong>
            You cannot submit a second request for the same person while an earlier request is still pending or being processed. Please wait for the current request to be completed before making another one.
          </div>
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
              <h2 class="usr-section-title">Module 4: How to Book a Church Service (Baptism, Wedding, Funeral)</h2>
              <p class="usr-section-sub">Fill out the simple form and pick an available date and time on the calendar</p>
            </div>
          </div>
          <span class="usr-section-tag">Services &amp; Calendar</span>
        </div>

        <p class="usr-intro-text">
          Follow these steps to schedule a sacred ceremony at our parish with real-time calendar availability.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Go to Book Service</div>
              <p class="step-card-desc">
                Click <a href="<?php echo BASE_URL; ?>users/request-service.php"><strong>Sacramental Services</strong></a> (or <em>Blessings</em>) in the menu. Choose whether you want to book a <strong>Baptism</strong>, <strong>Wedding</strong>, <strong>Funeral Mass</strong>, or <strong>Blessing</strong>.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Fill Out the Information Form</div>
              <p class="step-card-desc">
                Enter the names of the child/couple, parent details, and chosen godparents (ninong/ninang). Upload your PSA Birth Certificate or Marriage Certificate.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Choose Your Ceremony Date and Time Slot</div>
              <p class="step-card-desc">
                Select your preferred date from the calendar. The system will show you only available time slots. Click <span class="action-badge action-badge-green">Submit Booking</span>.
              </p>
            </div>
          </div>
        </div>

        <!-- Real-Time Slot Locking Callout -->
        <div class="callout-box callout-note">
          <i class="fas fa-calendar-check"></i>
          <div>
            <strong>Calendar Slot Protection:</strong>
            Our calendar is directly linked to the parish schedule. Any date or time slot that is already reserved by another church service is automatically blocked so no two events are ever double-booked. Once the church office approves your booking, your slot is officially locked!
          </div>
        </div>
      </section>

      <!-- ================= MODULE 5 ================= -->
      <section id="module5" class="usr-section-card">
        <div class="usr-section-header">
          <div class="usr-section-meta">
            <div class="usr-section-icon icon-amber">
              <i class="fas fa-route"></i>
            </div>
            <div>
              <h2 class="usr-section-title">Module 5: How to Track Your Request &amp; Pick Up Documents</h2>
              <p class="usr-section-sub">Monitor live status progress and know what to bring to the parish office</p>
            </div>
          </div>
          <span class="usr-section-tag">Track &amp; Claim</span>
        </div>

        <p class="usr-intro-text">
          You never have to guess whether your request is ready. You can check its progress anytime from your account.
        </p>

        <!-- Visual Step Cards -->
        <div class="step-cards-grid">
          <div class="step-card">
            <span class="step-badge">Step 1</span>
            <div class="step-card-body">
              <div class="step-card-title">Click Track Requests</div>
              <p class="step-card-desc">
                Go to <a href="<?php echo BASE_URL; ?>users/my-requests.php"><strong>Track Requests</strong></a> in the sidebar menu.
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 2</span>
            <div class="step-card-body">
              <div class="step-card-title">Understand Your Request Status</div>
              <p class="step-card-desc">
                &bull; <span class="action-badge action-badge-amber">Submitted / Pending</span>: We received your request and it is waiting for staff review.
                <br>&bull; <span class="action-badge action-badge-blue">In Processing</span>: Church staff is checking the physical church record books and printing the certificate.
                <br>&bull; <span class="action-badge action-badge-green">Ready for Pickup / Completed</span>: Your certificate is signed, sealed, and ready at the parish office!
              </p>
            </div>
          </div>

          <div class="step-card">
            <span class="step-badge">Step 3</span>
            <div class="step-card-body">
              <div class="step-card-title">Pick Up Your Certificate at the Parish Office</div>
              <p class="step-card-desc">
                Visit the parish office during open office hours. Bring your <strong>Tracking Code</strong> (e.g. <code>REQ-2026-0042</code>) and a <strong>valid ID</strong>.
              </p>
            </div>
          </div>
        </div>

        <!-- Pickup Reminder Box -->
        <div class="callout-box callout-tip">
          <i class="fas fa-user-check"></i>
          <div>
            <strong>Sending a Representative to Claim for You?</strong>
            If you cannot visit personally, give your representative:
            <ul style="margin: 4px 0 0; padding-left: 18px;">
              <li>A short Authorization Letter signed by you.</li>
              <li>A photocopy of your valid ID.</li>
              <li>Their own original valid ID.</li>
            </ul>
          </div>
        </div>
      </section>

      <!-- ================= MODULE 6 ================= -->
      <section id="module6" class="usr-section-card">
        <div class="usr-section-header">
          <div class="usr-section-meta">
            <div class="usr-section-icon icon-purple">
              <i class="fas fa-circle-question"></i>
            </div>
            <div>
              <h2 class="usr-section-title">Module 6: Common Questions &amp; Answers (FAQ)</h2>
              <p class="usr-section-sub">Quick answers to frequently asked parishioner questions</p>
            </div>
          </div>
          <span class="usr-section-tag">Help &amp; Answers</span>
        </div>

        <!-- Accordions -->
        <div class="usr-accordion-item">
          <button type="button" class="usr-accordion-btn">
            <span>How long does it take to get my certificate?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="usr-accordion-body">
            Standard processing takes <strong>2 to 3 parish office days</strong>. If the record is from many years ago, looking through the physical archive books may take up to 5 days. You will receive an SMS and email as soon as it is ready!
          </div>
        </div>

        <div class="usr-accordion-item">
          <button type="button" class="usr-accordion-btn">
            <span>Can I change the date of a booked wedding or baptism?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="usr-accordion-body">
            Yes. Please contact or visit the parish office at least <strong>7 days before</strong> the ceremony so our staff can check priest availability and move your slot on the calendar.
          </div>
        </div>

        <div class="usr-accordion-item">
          <button type="button" class="usr-accordion-btn">
            <span>Why does my account say "Pending Verification"?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="usr-accordion-body">
            When you first sign up, church staff reviews your ID photo to confirm your profile. You can still submit requests while waiting. Once verified, a green <span class="action-badge action-badge-green"><i class="fas fa-circle-check"></i> Verified</span> badge will appear on your profile.
          </div>
        </div>

        <div class="usr-accordion-item">
          <button type="button" class="usr-accordion-btn">
            <span>What if my phone camera will not turn on during registration?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="usr-accordion-body">
            Check your browser settings (Chrome or Safari) and make sure Camera permissions are set to <strong>Allow</strong>. If your phone is in Low Power mode, turn it off and refresh the page.
          </div>
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

  function doUsrSearch() {
    const q = (searchInput.value || '').trim().toLowerCase();
    searchClear.style.display = q ? 'block' : 'none';

    let matchCount = 0;
    cards.forEach(card => {
      const text = card.textContent.toLowerCase();
      if (!q || text.includes(q)) {
        card.style.display = 'block';
        matchCount++;

        if (q) {
          card.querySelectorAll('.usr-accordion-item').forEach(acc => {
            const accText = acc.textContent.toLowerCase();
            const btn = acc.querySelector('.usr-accordion-btn');
            const body = acc.querySelector('.usr-accordion-body');
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

  // Quick pills scroll
  document.querySelectorAll('.usr-pill').forEach(pill => {
    pill.addEventListener('click', function () {
      const targetId = this.getAttribute('data-target');
      const targetEl = document.querySelector(targetId);
      if (targetEl) {
        if (searchInput && searchInput.value) {
          searchInput.value = '';
          doUsrSearch();
        }
        targetEl.scrollIntoView({ behavior: 'smooth' });
      }
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

  // TOC scrollspy
  const tocLinks = Array.from(document.querySelectorAll('.usr-toc-link'));
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
