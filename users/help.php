<?php
/**
 * User Help Module & Documentation Guide (/users/help.php)
 * Comprehensive manual for Parishioners explaining registration, OCR scanning,
 * certificate applications, sacramental service bookings, calendar scheduling, and tracking.
 */

require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';
require_once '../includes/permissions.php';

requireLogin();
if (!isUser()) {
    redirect('../auth/login.php');
}

$page_title = 'Help & User Manual';
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
   USER HELP & DOCUMENTATION MODULE STYLES
   Palette: Forest Green (#2E3A2D), Gold (#C89B3C), Teal (#0d9488), Slate
   -------------------------------------------------------------------------- */
:root {
  --docs-green:      #2E3A2D;
  --docs-green-mid:  #3D5C3A;
  --docs-green-dim:  rgba(46, 58, 45, 0.08);
  --docs-gold:       #C89B3C;
  --docs-gold-dim:   rgba(200, 155, 60, 0.12);
  --docs-teal:       #0d9488;
  --docs-teal-dim:   rgba(13, 148, 136, 0.10);
  --docs-blue:       #2563eb;
  --docs-blue-dim:   rgba(37, 99, 235, 0.10);
  --docs-amber:      #d97706;
  --docs-amber-dim:  rgba(217, 119, 6, 0.10);
  --docs-rose:       #e11d48;
  --docs-rose-dim:   rgba(225, 29, 72, 0.10);
  --docs-slate-50:   #f8fafc;
  --docs-slate-100:  #f1f5f9;
  --docs-slate-200:  #e2e8f0;
  --docs-slate-300:  #cbd5e1;
  --docs-slate-600:  #475569;
  --docs-slate-700:  #334155;
  --docs-slate-800:  #1e293b;
  --docs-radius:     14px;
  --docs-shadow-sm:  0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
  --docs-shadow-md:  0 4px 14px rgba(0,0,0,0.07), 0 2px 5px rgba(0,0,0,0.03);
  --docs-shadow-lg:  0 10px 30px rgba(0,0,0,0.08);
}

.docs-wrapper {
  font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
  color: var(--docs-slate-700);
  padding-bottom: 50px;
}

/* --- Hero Banner --- */
.docs-hero {
  background: linear-gradient(135deg, #1e293b 0%, #2E3A2D 60%, #1c2e22 100%);
  border-radius: var(--docs-radius);
  padding: 32px 36px;
  color: #ffffff;
  margin-bottom: 28px;
  position: relative;
  overflow: hidden;
  box-shadow: var(--docs-shadow-md);
}
.docs-hero::after {
  content: '';
  position: absolute;
  top: -40px;
  right: -40px;
  width: 260px;
  height: 260px;
  background: radial-gradient(circle, rgba(200,155,60,0.22) 0%, rgba(200,155,60,0) 70%);
  pointer-events: none;
}
.docs-hero-title {
  font-size: 1.65rem;
  font-weight: 800;
  letter-spacing: -0.02em;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.docs-hero-badge {
  background: var(--docs-gold);
  color: #1e293b;
  font-size: 0.72rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  padding: 4px 10px;
  border-radius: 20px;
  vertical-align: middle;
}
.docs-hero-sub {
  font-size: 0.92rem;
  color: rgba(255,255,255,0.82);
  max-width: 680px;
  margin-bottom: 22px;
  line-height: 1.5;
}

/* Search bar inside Hero */
.docs-search-wrap {
  position: relative;
  max-width: 620px;
}
.docs-search-input {
  width: 100%;
  padding: 13px 44px 13px 46px;
  border-radius: 12px;
  border: 1.5px solid rgba(255,255,255,0.18);
  background: rgba(255,255,255,0.95);
  font-size: 0.92rem;
  color: var(--docs-slate-800);
  outline: none;
  box-shadow: 0 4px 18px rgba(0,0,0,0.15);
  transition: all 0.2s ease;
}
.docs-search-input:focus {
  background: #ffffff;
  border-color: var(--docs-gold);
  box-shadow: 0 0 0 4px rgba(200,155,60,0.30);
}
.docs-search-icon {
  position: absolute;
  left: 16px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--docs-slate-600);
  font-size: 1rem;
  pointer-events: none;
}
.docs-search-clear {
  position: absolute;
  right: 14px;
  top: 50%;
  transform: translateY(-50%);
  background: transparent;
  border: none;
  color: var(--docs-slate-600);
  cursor: pointer;
  display: none;
  font-size: 0.9rem;
}
.docs-quick-pills {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 14px;
}
.docs-pill {
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
.docs-pill:hover {
  background: var(--docs-gold);
  color: #1e293b;
  border-color: var(--docs-gold);
}

/* --- Layout Grid --- */
.docs-layout {
  display: grid;
  grid-template-columns: 280px minmax(0, 1fr);
  gap: 28px;
  align-items: flex-start;
}
@media (max-width: 991px) {
  .docs-layout {
    grid-template-columns: 1fr;
  }
  .docs-toc-col {
    position: static !important;
  }
}

/* --- Left TOC Column --- */
.docs-toc-card {
  background: #ffffff;
  border: 1px solid var(--docs-slate-200);
  border-radius: var(--docs-radius);
  padding: 18px 16px;
  box-shadow: var(--docs-shadow-sm);
  position: sticky;
  top: 90px;
  max-height: calc(100vh - 110px);
  overflow-y: auto;
  scrollbar-width: thin;
}
.docs-toc-header {
  font-size: 0.72rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--docs-slate-600);
  padding: 0 8px 10px;
  border-bottom: 1px solid var(--docs-slate-100);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.docs-toc-list {
  list-style: none;
  padding: 0;
  margin: 0;
}
.docs-toc-item {
  margin-bottom: 3px;
}
.docs-toc-link {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--docs-slate-700);
  text-decoration: none;
  transition: all 0.15s;
}
.docs-toc-link i {
  width: 18px;
  text-align: center;
  font-size: 0.88rem;
  color: var(--docs-slate-600);
  flex-shrink: 0;
}
.docs-toc-link:hover {
  background: var(--docs-slate-100);
  color: var(--docs-green);
}
.docs-toc-link.active {
  background: var(--docs-green-dim);
  color: var(--docs-green);
  font-weight: 700;
  border-left: 3px solid var(--docs-green);
}
.docs-toc-link.active i {
  color: var(--docs-gold);
}
.docs-toc-shortcuts {
  margin-top: 20px;
  padding-top: 14px;
  border-top: 1px solid var(--docs-slate-100);
}
.docs-shortcut-btn {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 0.78rem;
  font-weight: 600;
  background: var(--docs-slate-50);
  border: 1px solid var(--docs-slate-200);
  color: var(--docs-slate-700);
  text-decoration: none;
  margin-bottom: 6px;
  transition: all 0.15s;
}
.docs-shortcut-btn:hover {
  background: var(--docs-gold-dim);
  border-color: var(--docs-gold);
  color: var(--docs-green);
}

/* --- Content Cards --- */
.docs-card {
  background: #ffffff;
  border: 1px solid var(--docs-slate-200);
  border-radius: var(--docs-radius);
  padding: 28px 32px;
  margin-bottom: 24px;
  box-shadow: var(--docs-shadow-sm);
  transition: box-shadow 0.2s;
  scroll-margin-top: 90px;
}
.docs-card:hover {
  box-shadow: var(--docs-shadow-md);
}
.docs-card-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  border-bottom: 1.5px solid var(--docs-slate-100);
  padding-bottom: 16px;
  margin-bottom: 20px;
}
.docs-card-meta {
  display: flex;
  align-items: center;
  gap: 14px;
}
.docs-card-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.15rem;
  flex-shrink: 0;
}
.icon-green { background: var(--docs-green-dim); color: var(--docs-green); }
.icon-gold  { background: var(--docs-gold-dim);  color: var(--docs-gold); }
.icon-teal  { background: var(--docs-teal-dim);  color: var(--docs-teal); }
.icon-blue  { background: var(--docs-blue-dim);  color: var(--docs-blue); }
.icon-amber { background: var(--docs-amber-dim); color: var(--docs-amber); }

.docs-card-title {
  font-size: 1.25rem;
  font-weight: 800;
  color: var(--docs-slate-800);
  margin: 0 0 2px;
  letter-spacing: -0.01em;
}
.docs-card-sub {
  font-size: 0.8rem;
  color: var(--docs-slate-600);
  margin: 0;
}
.docs-card-tag {
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 3px 10px;
  border-radius: 20px;
  background: var(--docs-slate-100);
  color: var(--docs-slate-600);
}

/* Step-by-step numbers */
.docs-step-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin: 18px 0;
}
.docs-step-item {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  padding: 14px 16px;
  border-radius: 10px;
  background: var(--docs-slate-50);
  border: 1px solid var(--docs-slate-200);
}
.docs-step-num {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--docs-green);
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.82rem;
  font-weight: 800;
  flex-shrink: 0;
  margin-top: 1px;
}
.docs-step-content {
  flex: 1;
}
.docs-step-title {
  font-size: 0.9rem;
  font-weight: 700;
  color: var(--docs-slate-800);
  margin-bottom: 3px;
}
.docs-step-desc {
  font-size: 0.83rem;
  color: var(--docs-slate-600);
  margin: 0;
  line-height: 1.5;
}

/* Callout Boxes */
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
.callout-danger {
  background: #fff1f2;
  border-left: 4px solid #f43f5e;
  color: #9f1239;
}
.callout-tip {
  background: #f0fdf4;
  border-left: 4px solid #10b981;
  color: #166534;
}

/* Accordion UI */
.docs-accordion-item {
  border: 1px solid var(--docs-slate-200);
  border-radius: 10px;
  margin-bottom: 10px;
  overflow: hidden;
}
.docs-accordion-btn {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  background: #fff;
  border: none;
  font-size: 0.87rem;
  font-weight: 700;
  color: var(--docs-slate-800);
  cursor: pointer;
  text-align: left;
  transition: background 0.15s;
}
.docs-accordion-btn:hover {
  background: var(--docs-slate-50);
}
.docs-accordion-btn i.fa-chevron-down {
  transition: transform 0.2s;
  font-size: 0.8rem;
  color: var(--docs-slate-600);
}
.docs-accordion-btn.active i.fa-chevron-down {
  transform: rotate(180deg);
}
.docs-accordion-body {
  display: none;
  padding: 14px 18px;
  background: var(--docs-slate-50);
  border-top: 1px solid var(--docs-slate-200);
  font-size: 0.84rem;
  line-height: 1.55;
  color: var(--docs-slate-700);
}
.docs-accordion-body.show {
  display: block;
}

/* Checklist */
.docs-checklist {
  list-style: none;
  padding: 0;
  margin: 10px 0;
}
.docs-checklist li {
  position: relative;
  padding-left: 24px;
  margin-bottom: 8px;
  font-size: 0.84rem;
  color: var(--docs-slate-700);
}
.docs-checklist li::before {
  content: '\f00c';
  font-family: 'Font Awesome 6 Free', 'Font Awesome 5 Free';
  font-weight: 900;
  position: absolute;
  left: 0;
  color: var(--docs-teal);
  font-size: 0.8rem;
}

/* Highlights */
.docs-badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 6px;
  font-size: 0.72rem;
  font-weight: 700;
}
.docs-badge-gold { background: var(--docs-gold-dim); color: #854d0e; }
.docs-badge-green { background: #dcfce7; color: #166534; }
.docs-badge-blue { background: #dbeafe; color: #1e40af; }
.docs-badge-red { background: #ffe4e6; color: #9f1239; }

/* Empty Search Results State */
#docsSearchEmpty {
  display: none;
  text-align: center;
  padding: 50px 20px;
  background: #fff;
  border: 1px dashed var(--docs-slate-300);
  border-radius: var(--docs-radius);
  color: var(--docs-slate-600);
}
</style>

<div class="docs-wrapper container-fluid px-0">

  <!-- ================= HERO BANNER ================= -->
  <div class="docs-hero">
    <div class="docs-hero-title">
      <i class="fas fa-book-open"></i>
      Parishioner User Manual &amp; Guide
      <span class="docs-hero-badge">TUGON System</span>
    </div>
    <p class="docs-hero-sub">
      Step-by-step instructions, guidelines, and troubleshooting tips for registering your account, requesting official sacramental certificates, booking ceremonies, and tracking requests.
    </p>

    <!-- Search bar -->
    <div class="docs-search-wrap">
      <i class="fas fa-search docs-search-icon"></i>
      <input type="text" id="docsSearchInput" class="docs-search-input" placeholder="Search topics, steps, certificates, ID requirements..." aria-label="Search User Guide">
      <button type="button" id="docsSearchClear" class="docs-search-clear" title="Clear search"><i class="fas fa-times"></i></button>
    </div>

    <!-- Quick Filter Pills -->
    <div class="docs-quick-pills">
      <span class="docs-pill" data-target="#module1">ID Registration &amp; OCR</span>
      <span class="docs-pill" data-target="#module2">Profile Settings</span>
      <span class="docs-pill" data-target="#module3">Certificate Requests</span>
      <span class="docs-pill" data-target="#module4">Booking Sacraments</span>
      <span class="docs-pill" data-target="#module5">Tracking &amp; Status</span>
      <span class="docs-pill" data-target="#module6">Frequently Asked Questions</span>
    </div>
  </div>

  <!-- ================= MAIN LAYOUT ================= -->
  <div class="docs-layout">

    <!-- LEFT: Sticky Table of Contents -->
    <aside class="docs-toc-col">
      <div class="docs-toc-card">
        <div class="docs-toc-header">
          <span>Table of Contents</span>
          <i class="fas fa-list-ul"></i>
        </div>
        <ul class="docs-toc-list">
          <li class="docs-toc-item">
            <a href="#module1" class="docs-toc-link active">
              <i class="fas fa-id-card"></i>
              <span>1. Registration &amp; OCR</span>
            </a>
          </li>
          <li class="docs-toc-item">
            <a href="#module2" class="docs-toc-link">
              <i class="fas fa-user-gear"></i>
              <span>2. Managing Profile</span>
            </a>
          </li>
          <li class="docs-toc-item">
            <a href="#module3" class="docs-toc-link">
              <i class="fas fa-certificate"></i>
              <span>3. Sacramental Certificates</span>
            </a>
          </li>
          <li class="docs-toc-item">
            <a href="#module4" class="docs-toc-link">
              <i class="fas fa-church"></i>
              <span>4. Booking Sacraments</span>
            </a>
          </li>
          <li class="docs-toc-item">
            <a href="#module5" class="docs-toc-link">
              <i class="fas fa-route"></i>
              <span>5. Tracking &amp; Status</span>
            </a>
          </li>
          <li class="docs-toc-item">
            <a href="#module6" class="docs-toc-link">
              <i class="fas fa-circle-question"></i>
              <span>6. FAQs &amp; Helpdesk</span>
            </a>
          </li>
        </ul>

        <!-- Direct Portal Shortcuts -->
        <div class="docs-toc-shortcuts">
          <div class="docs-toc-header" style="padding-left:0; margin-bottom:8px;">
            <span>Quick Shortcuts</span>
            <i class="fas fa-arrow-up-right-from-square"></i>
          </div>
          <a href="<?php echo BASE_URL; ?>users/request-certificate.php" class="docs-shortcut-btn">
            <span><i class="fas fa-file-invoice" style="margin-right:6px; color:var(--docs-gold);"></i> Request Certificate</span>
            <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
          </a>
          <a href="<?php echo BASE_URL; ?>users/request-service.php" class="docs-shortcut-btn">
            <span><i class="fas fa-calendar-plus" style="margin-right:6px; color:var(--docs-teal);"></i> Book Service</span>
            <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
          </a>
          <a href="<?php echo BASE_URL; ?>users/my-requests.php" class="docs-shortcut-btn">
            <span><i class="fas fa-list-check" style="margin-right:6px; color:var(--docs-blue);"></i> Track Requests</span>
            <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
          </a>
          <a href="<?php echo BASE_URL; ?>users/view-schedule.php" class="docs-shortcut-btn">
            <span><i class="fas fa-calendar-days" style="margin-right:6px; color:var(--docs-green);"></i> Parish Calendar</span>
            <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
          </a>
        </div>
      </div>
    </aside>

    <!-- RIGHT: Content Modules -->
    <main class="docs-content-col">

      <!-- Empty Search Result Container -->
      <div id="docsSearchEmpty">
        <i class="fas fa-magnifying-glass" style="font-size:2.4rem; opacity:0.3; margin-bottom:12px;"></i>
        <h5 style="font-weight:800; color:var(--docs-slate-800);">No matching topics found</h5>
        <p style="font-size:0.85rem; margin-bottom:14px;">Try searching for different keywords like "baptism", "certificate", "camera", or "schedule".</p>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetDocsSearch()">Clear Search</button>
      </div>

      <!-- ================= MODULE 1 ================= -->
      <section id="module1" class="docs-card">
        <div class="docs-card-header">
          <div class="docs-card-meta">
            <div class="docs-card-icon icon-gold">
              <i class="fas fa-id-card"></i>
            </div>
            <div>
              <h2 class="docs-card-title">Module 1: Registration &amp; ID Verification</h2>
              <p class="docs-card-sub">Live camera capture, automated OCR extraction, and account activation</p>
            </div>
          </div>
          <span class="docs-card-tag">Account Setup</span>
        </div>

        <p style="font-size:0.87rem; line-height:1.6;">
          To safeguard the integrity of sacred canonical records and prevent identity theft, TUGON enforces a <strong>Live Camera Capture</strong> policy during registration. Uploading pre-saved gallery images is disabled to guarantee that the applicant is physically presenting an authentic government ID.
        </p>

        <!-- Steps List -->
        <div class="docs-step-list">
          <div class="docs-step-item">
            <div class="docs-step-num">1</div>
            <div class="docs-step-content">
              <div class="docs-step-title">Allow Camera Permissions</div>
              <p class="docs-step-desc">When prompted by your browser or smartphone, click <strong>Allow</strong> to grant camera access. The live viewfinder will activate on your screen.</p>
            </div>
          </div>

          <div class="docs-step-item">
            <div class="docs-step-num">2</div>
            <div class="docs-step-content">
              <div class="docs-step-title">Position Your Government ID</div>
              <p class="docs-step-desc">Place your Philippine Government ID (PhilSys National ID, Driver's License, UMID, Postal ID, Passport, PRC ID, or Voter's ID) flat inside the on-screen alignment frame. Ensure all text and the photo are clearly visible without glare or shadow.</p>
            </div>
          </div>

          <div class="docs-step-item">
            <div class="docs-step-num">3</div>
            <div class="docs-step-content">
              <div class="docs-step-title">OCR Auto-Extraction</div>
              <p class="docs-step-desc">Click <strong>Capture ID</strong>. The TUGON OCR engine will automatically scan the card and extract your Full Name, Date of Birth, Gender, Address, and ID Card Number directly into the form.</p>
            </div>
          </div>

          <div class="docs-step-item">
            <div class="docs-step-num">4</div>
            <div class="docs-step-content">
              <div class="docs-step-title">Review &amp; Correct Details</div>
              <p class="docs-step-desc">Carefully check the auto-filled fields. You can refine any slight typos, add your active mobile number, and enter a secure password.</p>
            </div>
          </div>

          <div class="docs-step-item">
            <div class="docs-step-num">5</div>
            <div class="docs-step-content">
              <div class="docs-step-title">Read Terms to Bottom &amp; Acknowledge</div>
              <p class="docs-step-desc">Scroll the Terms and Conditions box all the way to the very bottom. Once the scroll position reaches the bottom, the <em>"I accept and acknowledge the Terms"</em> checkbox unlocks for checking.</p>
            </div>
          </div>
        </div>

        <div class="callout callout-warning">
          <i class="fas fa-exclamation-triangle"></i>
          <div>
            <strong>Camera &amp; Lighting Tips:</strong>
            Avoid direct overhead fluorescent bulbs or reflections on laminated ID cards. Place the card on a dark, flat surface with gentle natural lighting for optimal OCR scan accuracy.
          </div>
        </div>

        <!-- Accordion for Troubleshooting -->
        <div class="docs-accordion-item">
          <button type="button" class="docs-accordion-btn">
            <span><i class="fas fa-video-slash" style="margin-right:8px; color:var(--docs-rose);"></i> Camera Permission Denied or Stuck on Loading?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="docs-accordion-body">
            <ul class="docs-checklist">
              <li><strong>On Google Chrome (Desktop):</strong> Click the padlock or settings icon on the left side of the address bar, toggle <em>Camera</em> to <strong>Allow</strong>, and refresh the page.</li>
              <li><strong>On Safari / iOS:</strong> Go to <em>Settings &gt; Safari &gt; Camera</em> and choose <strong>Allow</strong>. Ensure your device is not in Low Power Mode that suspends media streams.</li>
              <li><strong>On Android:</strong> Tap the lock icon in the URL bar, go to <em>Permissions &gt; Camera</em>, select <strong>Allow</strong>, and reload.</li>
            </ul>
          </div>
        </div>
      </section>

      <!-- ================= MODULE 2 ================= -->
      <section id="module2" class="docs-card">
        <div class="docs-card-header">
          <div class="docs-card-meta">
            <div class="docs-card-icon icon-teal">
              <i class="fas fa-user-gear"></i>
            </div>
            <div>
              <h2 class="docs-card-title">Module 2: Managing Your Profile</h2>
              <p class="docs-card-sub">Updating contact info, profile photos, and account security</p>
            </div>
          </div>
          <span class="docs-card-tag">Profile &amp; Settings</span>
        </div>

        <p style="font-size:0.87rem; line-height:1.6;">
          Your parishioner profile maintains your verified contact channels. Keeping your mobile phone and email address current guarantees you receive instant SMS alerts and notifications whenever your requests are updated.
        </p>

        <div class="docs-step-list">
          <div class="docs-step-item">
            <div class="docs-step-num"><i class="fas fa-camera"></i></div>
            <div class="docs-step-content">
              <div class="docs-step-title">Profile Picture Avatar</div>
              <p class="docs-step-desc">Navigate to <a href="<?php echo BASE_URL; ?>auth/profile.php">Profile Settings</a>. Click the camera badge over your avatar to upload a clean portrait photo (PNG or JPG, max 5MB). This image helps parish staff verify identity upon office visits.</p>
            </div>
          </div>

          <div class="docs-step-item">
            <div class="docs-step-num"><i class="fas fa-envelope"></i></div>
            <div class="docs-step-content">
              <div class="docs-step-title">Contact &amp; Address Updates</div>
              <p class="docs-step-desc">Keep your 11-digit mobile number (e.g. <code>0917XXXXXXX</code>) and residential address updated. Critical SMS delivery notifications and schedule reminders are sent directly to this number.</p>
            </div>
          </div>

          <div class="docs-step-item">
            <div class="docs-step-num"><i class="fas fa-lock"></i></div>
            <div class="docs-step-content">
              <div class="docs-step-title">Changing Your Password</div>
              <p class="docs-step-desc">Go to the Security tab in your profile. Provide your current password, followed by your new password (minimum 8 characters with letters, numbers, and symbols). Never share your password with anyone.</p>
            </div>
          </div>
        </div>

        <div class="callout callout-tip">
          <i class="fas fa-shield-halved"></i>
          <div>
            <strong>Parishioner Verification Badge:</strong>
            Accounts displaying a green <code><i class="fas fa-circle-check"></i> Verified</code> badge have been authenticated against official registry archives by parish administration.
          </div>
        </div>
      </section>

      <!-- ================= MODULE 3 ================= -->
      <section id="module3" class="docs-card">
        <div class="docs-card-header">
          <div class="docs-card-meta">
            <div class="docs-card-icon icon-blue">
              <i class="fas fa-certificate"></i>
            </div>
            <div>
              <h2 class="docs-card-title">Module 3: Requesting Sacramental Certificates</h2>
              <p class="docs-card-sub">Applying for official certificates with anti-spam protection rules</p>
            </div>
          </div>
          <span class="docs-card-tag">Certificates</span>
        </div>

        <p style="font-size:0.87rem; line-height:1.6;">
          Parishioners can request official canonical copies of <strong>Baptismal</strong>, <strong>Confirmation</strong>, <strong>First Communion</strong>, and <strong>Marriage</strong> certificates directly through the system without waiting in long parish office lines.
        </p>

        <!-- Steps List -->
        <div class="docs-step-list">
          <div class="docs-step-item">
            <div class="docs-step-num">1</div>
            <div class="docs-step-content">
              <div class="docs-step-title">Select Certificate Type</div>
              <p class="docs-step-desc">Go to <a href="<?php echo BASE_URL; ?>users/request-certificate.php">Request Certificate</a>. Choose the sacrament for which you need official documentation.</p>
            </div>
          </div>

          <div class="docs-step-item">
            <div class="docs-step-num">2</div>
            <div class="docs-step-content">
              <div class="docs-step-title">State the Purpose of Request</div>
              <p class="docs-step-desc">Select or specify the canonical purpose (e.g., School Requirement, Marriage License / Pre-Cana, Confirmation Sponsor, Passport / DFA, Employment, or Personal Archive).</p>
            </div>
          </div>

          <div class="docs-step-item">
            <div class="docs-step-num">3</div>
            <div class="docs-step-content">
              <div class="docs-step-title">Provide Approximate Date &amp; Details</div>
              <p class="docs-step-desc">Enter the approximate year of the sacrament, the officiating priest (if remembered), and parent names. This allows the archivist to quickly pull up the Book and Page number from the registry archives.</p>
            </div>
          </div>

          <div class="docs-step-item">
            <div class="docs-step-num">4</div>
            <div class="docs-step-content">
              <div class="docs-step-title">Upload Supporting Documents</div>
              <p class="docs-step-desc">Attach a legible photo of your PSA Birth Certificate or valid government ID to verify parentage and identity.</p>
            </div>
          </div>
        </div>

        <!-- Anti-Spam Policy Warning Box -->
        <div class="callout callout-danger">
          <i class="fas fa-shield-xmark"></i>
          <div>
            <strong>Strict Anti-Spam Duplicate Rule:</strong><br>
            To prevent system backlog and redundant archival searches, <strong>duplicate requests for the same person and certificate type cannot be submitted</strong> while an earlier request remains in <code>Pending</code> or <code>In Processing</code> status. Please wait for the current request to be completed or released before filing another one.
          </div>
        </div>
      </section>

      <!-- ================= MODULE 4 ================= -->
      <section id="module4" class="docs-card">
        <div class="docs-card-header">
          <div class="docs-card-meta">
            <div class="docs-card-icon icon-green">
              <i class="fas fa-church"></i>
            </div>
            <div>
              <h2 class="docs-card-title">Module 4: Booking Sacramental Services</h2>
              <p class="docs-card-sub">Pre-Baptismal &amp; Pre-Nuptial investigation sheets and automated calendar scheduling</p>
            </div>
          </div>
          <span class="docs-card-tag">Sacraments &amp; Liturgy</span>
        </div>

        <p style="font-size:0.87rem; line-height:1.6;">
          Booking services such as <strong>Baptism</strong>, <strong>Weddings (Matrimony)</strong>, and <strong>Funeral Masses / Blessings</strong> requires filling out formal canonical investigation sheets and locking in ceremony times on the parish master schedule.
        </p>

        <!-- Subsections -->
        <div class="docs-accordion-item">
          <button type="button" class="docs-accordion-btn">
            <span><i class="fas fa-water" style="margin-right:8px; color:var(--docs-gold);"></i> 1. Holy Baptism Booking Workflow</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="docs-accordion-body">
            <p><strong>Required Information &amp; Documents:</strong></p>
            <ul class="docs-checklist">
              <li><strong>Child's Details:</strong> Full legal name, date and place of birth as stated on PSA Birth Certificate.</li>
              <li><strong>Parents' Information:</strong> Father and Mother's maiden name, residence, and marriage status (Church or Civil).</li>
              <li><strong>Sponsors (Ninong &amp; Ninang):</strong> At least one practicing Catholic sponsor who has received Confirmation.</li>
              <li><strong>Document Upload:</strong> PSA Birth Certificate and Marriage Certificate of parents (if married).</li>
              <li><strong>Pre-Jordan Seminar:</strong> Parents and primary godparents must attend the required catechetical seminar before the ceremony.</li>
            </ul>
          </div>
        </div>

        <div class="docs-accordion-item">
          <button type="button" class="docs-accordion-btn">
            <span><i class="fas fa-rings-wedding" style="margin-right:8px; color:var(--docs-teal);"></i> 2. Holy Matrimony (Wedding) Booking Workflow</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="docs-accordion-body">
            <p><strong>Canonical Investigation Requirements:</strong></p>
            <ul class="docs-checklist">
              <li><strong>Groom &amp; Bride Profiles:</strong> Complete legal background, religious affiliation, and canonical freedom to marry.</li>
              <li><strong>Documents:</strong> Recently issued PSA Birth Certificate, Certificate of No Marriage (CENOMAR), and Baptismal/Confirmation Certificates marked <em>"For Marriage Purposes"</em> (valid within 6 months).</li>
              <li><strong>Canonical Interview &amp; Pre-Cana:</strong> Both parties must complete the Pre-Nuptial interview with the Parish Priest and the Pre-Cana seminar.</li>
            </ul>
          </div>
        </div>

        <!-- Calendar Locking Callout -->
        <div class="callout callout-info">
          <i class="fas fa-calendar-check"></i>
          <div>
            <strong>Real-Time Calendar Synchronization &amp; Slot Locking:</strong><br>
            The scheduling dropdown connects directly to the parish master calendar. <strong>Dates and times that are already occupied by existing liturgies, masses, or previously confirmed services will be automatically disabled</strong>. Once your booking is approved by the parish office, your time slot is officially locked into the parish master schedule.
          </div>
        </div>
      </section>

      <!-- ================= MODULE 5 ================= -->
      <section id="module5" class="docs-card">
        <div class="docs-card-header">
          <div class="docs-card-meta">
            <div class="docs-card-icon icon-amber">
              <i class="fas fa-route"></i>
            </div>
            <div>
              <h2 class="docs-card-title">Module 5: Tracking &amp; Notifications</h2>
              <p class="docs-card-sub">Tracking request status timelines and claiming completed documents</p>
            </div>
          </div>
          <span class="docs-card-tag">Monitoring</span>
        </div>

        <p style="font-size:0.87rem; line-height:1.6;">
          You can monitor every request in real time on the <a href="<?php echo BASE_URL; ?>users/my-requests.php">Track Requests</a> dashboard. Whenever the parish staff updates your request, an instant notification is dispatched.
        </p>

        <!-- Status Lifecycle -->
        <div class="docs-step-list">
          <div class="docs-step-item">
            <div class="docs-step-num"><i class="fas fa-paper-plane"></i></div>
            <div class="docs-step-content">
              <div class="docs-step-title"><span class="docs-badge docs-badge-gold">Submitted / Pending</span></div>
              <p class="docs-step-desc">Your request is queued for parish administrative review. A tracking number (e.g. <code>REQ-2026-0042</code>) has been assigned.</p>
            </div>
          </div>

          <div class="docs-step-item">
            <div class="docs-step-num"><i class="fas fa-magnifying-glass"></i></div>
            <div class="docs-step-content">
              <div class="docs-step-title"><span class="docs-badge docs-badge-blue">Requirements Review</span></div>
              <p class="docs-step-desc">Parish staff is validating your uploaded documents against physical baptismal or marriage books in the archive vault.</p>
            </div>
          </div>

          <div class="docs-step-item">
            <div class="docs-step-num"><i class="fas fa-spinner"></i></div>
            <div class="docs-step-content">
              <div class="docs-step-title"><span class="docs-badge docs-badge-blue">In Processing</span></div>
              <p class="docs-step-desc">The record has been verified. The certificate is being encoded and prepared for formal pastoral signature and dry seal.</p>
            </div>
          </div>

          <div class="docs-step-item">
            <div class="docs-step-num"><i class="fas fa-check-double"></i></div>
            <div class="docs-step-content">
              <div class="docs-step-title"><span class="docs-badge docs-badge-green">Ready for Pickup / Completed</span></div>
              <p class="docs-step-desc">Your document is signed, sealed, and ready for release at the Parish Office. You will receive an SMS and email with pickup instructions.</p>
            </div>
          </div>
        </div>

        <div class="callout callout-tip">
          <i class="fas fa-building-columns"></i>
          <div>
            <strong>What to Bring on Pickup Day:</strong>
            1. Your valid Government ID.<br>
            2. Tracking Code (e.g. <code>REQ-2026-0042</code>).<br>
            3. If claiming via representative: Signed Authorization Letter and photocopy of both IDs.
          </div>
        </div>
      </section>

      <!-- ================= MODULE 6 ================= -->
      <section id="module6" class="docs-card">
        <div class="docs-card-header">
          <div class="docs-card-meta">
            <div class="docs-card-icon icon-gold">
              <i class="fas fa-circle-question"></i>
            </div>
            <div>
              <h2 class="docs-card-title">Module 6: Frequently Asked Questions (FAQ)</h2>
              <p class="docs-card-sub">Quick answers to common questions about services and accounts</p>
            </div>
          </div>
          <span class="docs-card-tag">Helpdesk</span>
        </div>

        <div class="docs-accordion-item">
          <button type="button" class="docs-accordion-btn">
            <span>How long does certificate processing take?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="docs-accordion-body">
            Standard turnaround time is <strong>2 to 3 parish working days</strong>. Archival records requiring physical book retrieval from earlier decades may take up to 5 working days. You can track real-time progress on your <em>Track Requests</em> dashboard.
          </div>
        </div>

        <div class="docs-accordion-item">
          <button type="button" class="docs-accordion-btn">
            <span>Can I reschedule a booked baptism or wedding?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="docs-accordion-body">
            Yes. Please contact or visit the Parish Office at least <strong>7 days before</strong> the ceremony date so the staff can adjust the calendar slot and verify priest availability.
          </div>
        </div>

        <div class="docs-accordion-item">
          <button type="button" class="docs-accordion-btn">
            <span>Why is my account still in "Pending Verification"?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="docs-accordion-body">
            Newly registered accounts undergo verification by parish administrators to confirm that the captured ID matches church census records. You can still submit requests while pending verification; requests will be processed once verified.
          </div>
        </div>

        <div class="docs-accordion-item">
          <button type="button" class="docs-accordion-btn">
            <span>Can someone else claim my certificate for me?</span>
            <i class="fas fa-chevron-down"></i>
          </button>
          <div class="docs-accordion-body">
            Yes. Your representative must present: (1) an Authorization Letter signed by you, (2) a photocopy of your valid ID, and (3) their own original valid ID.
          </div>
        </div>
      </section>

    </main>
  </div>
</div>

<script>
(function () {
  'use strict';

  // Quick search filter implementation
  const searchInput = document.getElementById('docsSearchInput');
  const searchClear = document.getElementById('docsSearchClear');
  const emptyState  = document.getElementById('docsSearchEmpty');
  const cards       = Array.from(document.querySelectorAll('.docs-card'));

  function doSearch() {
    const q = (searchInput.value || '').trim().toLowerCase();
    searchClear.style.display = q ? 'block' : 'none';

    let matchCount = 0;
    cards.forEach(card => {
      const text = card.textContent.toLowerCase();
      if (!q || text.includes(q)) {
        card.style.display = 'block';
        matchCount++;

        // Auto-open accordions matching search query
        if (q) {
          card.querySelectorAll('.docs-accordion-item').forEach(acc => {
            const accText = acc.textContent.toLowerCase();
            const btn = acc.querySelector('.docs-accordion-btn');
            const body = acc.querySelector('.docs-accordion-body');
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
    searchInput.addEventListener('input', doSearch);
  }

  if (searchClear) {
    searchClear.addEventListener('click', function () {
      searchInput.value = '';
      doSearch();
      searchInput.focus();
    });
  }

  window.resetDocsSearch = function () {
    if (searchInput) {
      searchInput.value = '';
      doSearch();
    }
  };

  // Quick filter pills
  document.querySelectorAll('.docs-pill').forEach(pill => {
    pill.addEventListener('click', function () {
      const targetId = this.getAttribute('data-target');
      const targetEl = document.querySelector(targetId);
      if (targetEl) {
        if (searchInput && searchInput.value) {
          searchInput.value = '';
          doSearch();
        }
        targetEl.scrollIntoView({ behavior: 'smooth' });
      }
    });
  });

  // Accordion toggle mechanics
  document.querySelectorAll('.docs-accordion-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const body = this.nextElementSibling;
      this.classList.toggle('active');
      if (body) {
        body.classList.toggle('show');
      }
    });
  });

  // TOC active scrollspy highlight
  const tocLinks = Array.from(document.querySelectorAll('.docs-toc-link'));
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
