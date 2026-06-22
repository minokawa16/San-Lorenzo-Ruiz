<?php
/**
 * Public homepage
 * Logged-in users continue to their role-based workspace.
 */

include 'includes/session.php';
include 'includes/helpers.php';

if (isLoggedIn()) {
    if (isAdmin()) {
        header("Location: admin/dashboard.php");
    } else {
        header("Location: users/index.php");
    }
    exit;
}

$announcements = [
    ['title' => 'Parish Renewal Weekend', 'meta' => 'May 24, 2026', 'copy' => 'A quiet day of prayer, confession, and community reflection for all chapel districts.'],
    ['title' => 'Certificate Office Advisory', 'meta' => 'Open weekdays', 'copy' => 'Baptism, confirmation, marriage, and funeral certificate requests are now available online.'],
    ['title' => 'Youth Ministry Service Drive', 'meta' => 'Sunday after Mass', 'copy' => 'Volunteers may register through the parish office or online service portal.'],
];

$events = [
    ['day' => '19', 'title' => 'Novena and Evening Mass', 'time' => '5:30 PM'],
    ['day' => '22', 'title' => 'Catechism Orientation', 'time' => '9:00 AM'],
    ['day' => '26', 'title' => 'Wedding Reservation Review', 'time' => '2:00 PM'],
];

$services = [
    ['icon' => 'fa-file-signature', 'title' => 'Certificate Requests', 'copy' => 'Request official parish certificates with secure review, status updates, and PDF-ready release.'],
    ['icon' => 'fa-book-bible', 'title' => 'Sacramental Records', 'copy' => 'Digitized baptism, confirmation, marriage, and funeral records with searchable audit trails.'],
    ['icon' => 'fa-calendar-check', 'title' => 'Reservations', 'copy' => 'Book weddings, baptisms, confirmations, funeral services, and parish venues with clear availability.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>San Lorenzo Ruiz Mission Station | AI Parish Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/premium-parish.css">
</head>
<body class="premium-public">
    <div class="landing-shell">
        <nav class="landing-nav premium-glass" aria-label="Primary navigation">
            <a href="#home" class="landing-brand">
                <span class="landing-brand-mark"><i class="fas fa-cross"></i></span>
                <span>San Lorenzo Ruiz Mission Station</span>
            </a>
            <div class="landing-nav-links">
                <a href="#mission">Mission</a>
                <a href="#announcements">Announcements</a>
                <a href="#events">Events</a>
                <a href="#services">Services</a>
                <a href="#contact">Contact</a>
                <a href="auth/login.php" class="nav-login">Login</a>
            </div>
        </nav>

        <header class="landing-hero" id="home">
            <div class="landing-container landing-hero-grid">
                <div>
                    <span class="premium-pill landing-eyebrow"><i class="fas fa-wand-magic-sparkles"></i> AI-powered parish management</span>
                    <h1>San Lorenzo Ruiz Mission Station</h1>
                    <p>A peaceful digital home for parish service. Request certificates, reserve parish services, follow announcements, and help administrators manage sacramental records through a secure modern Catholic church platform.</p>
                    <div class="landing-actions">
                        <a href="auth/register.php" class="premium-btn primary"><i class="fas fa-user-plus"></i> Register</a>
                        <a href="auth/login.php" class="premium-btn secondary"><i class="fas fa-right-to-bracket"></i> Login</a>
                        <a href="#services" class="premium-btn ghost"><i class="fas fa-table-cells-large"></i> View Services</a>
                    </div>
                </div>

                <aside class="hero-service-panel premium-glass" aria-label="Quick parish actions">
                    <h2>Quick Parish Actions</h2>
                    <div class="hero-service-list">
                        <a href="auth/login.php"><span><i class="fas fa-file-signature"></i> Request Certificate</span><i class="fas fa-arrow-right"></i></a>
                        <a href="auth/login.php"><span><i class="fas fa-calendar-plus"></i> Book Reservation</span><i class="fas fa-arrow-right"></i></a>
                        <a href="#contact"><span><i class="fas fa-envelope"></i> Contact Parish</span><i class="fas fa-arrow-right"></i></a>
                    </div>
                </aside>
            </div>
        </header>

        <main id="main-content">
            <section class="landing-section" id="mission">
                <div class="landing-container split-section">
                    <div>
                        <span class="premium-pill section-kicker"><i class="fas fa-church"></i> Parish Introduction</span>
                        <h2 class="section-heading">Sacred service, organized with modern care.</h2>
                        <p class="section-copy">TUGON helps the parish office serve families with clarity, transparency, and dignity. The experience is calm for parishioners and operationally strong for administrators.</p>
                    </div>
                    <div class="landing-grid mission-grid">
                        <article class="landing-card premium-glass">
                            <div class="landing-card-icon"><i class="fas fa-dove"></i></div>
                            <h3>Mission</h3>
                            <p>To make parish services easier to access while preserving reverence, verification, and pastoral care.</p>
                        </article>
                        <article class="landing-card premium-glass">
                            <div class="landing-card-icon"><i class="fas fa-sun"></i></div>
                            <h3>Vision</h3>
                            <p>A trusted digital parish office where every request is visible, secure, and gracefully handled.</p>
                        </article>
                    </div>
                </div>
            </section>

            <section class="landing-section landing-band" id="announcements">
                <div class="landing-container">
                    <span class="premium-pill section-kicker"><i class="fas fa-bullhorn"></i> Parish Announcements</span>
                    <h2 class="section-heading">Current notices from the parish office.</h2>
                    <div class="landing-grid">
                        <?php foreach ($announcements as $announcement): ?>
                            <article class="landing-card premium-glass">
                                <div class="landing-card-icon"><i class="fas fa-bell"></i></div>
                                <h3><?php echo e($announcement['title']); ?></h3>
                                <p><strong><?php echo e($announcement['meta']); ?></strong></p>
                                <p><?php echo e($announcement['copy']); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="landing-section" id="events">
                <div class="landing-container split-section">
                    <div>
                        <span class="premium-pill section-kicker"><i class="fas fa-calendar-days"></i> Upcoming Events</span>
                        <h2 class="section-heading">Mass schedules and parish activities, beautifully organized.</h2>
                        <p class="section-copy">Administrators can publish Mass schedules, event calendars, and reservation availability while parishioners see the latest status in one place.</p>
                        <div class="landing-actions">
                            <a href="auth/login.php" class="premium-btn primary"><i class="fas fa-calendar-check"></i> Book Reservation</a>
                            <a href="#contact" class="premium-btn secondary"><i class="fas fa-phone"></i> Contact Parish</a>
                        </div>
                    </div>
                    <div class="landing-card premium-glass">
                        <h3>Mass Schedule</h3>
                        <div class="schedule-list">
                            <div class="schedule-row"><span class="date-tile"><i class="fas fa-sun"></i></span><div><strong>Sunday Mass</strong><br><span>6:00 AM, 8:00 AM, 5:00 PM</span></div></div>
                            <div class="schedule-row"><span class="date-tile"><i class="fas fa-cross"></i></span><div><strong>Weekday Mass</strong><br><span>Monday to Friday, 5:30 PM</span></div></div>
                            <?php foreach ($events as $event): ?>
                                <div class="event-row"><span class="date-tile"><?php echo e($event['day']); ?></span><div><strong><?php echo e($event['title']); ?></strong><br><span><?php echo e($event['time']); ?></span></div></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="landing-section landing-band" id="services">
                <div class="landing-container">
                    <span class="premium-pill section-kicker"><i class="fas fa-hands-praying"></i> Sacrament Services</span>
                    <h2 class="section-heading">A complete parish workflow, from request to record.</h2>
                    <div class="landing-grid">
                        <?php foreach ($services as $service): ?>
                            <article class="landing-card premium-glass">
                                <div class="landing-card-icon"><i class="fas <?php echo e($service['icon']); ?>"></i></div>
                                <h3><?php echo e($service['title']); ?></h3>
                                <p><?php echo e($service['copy']); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="landing-section">
                <div class="landing-container landing-grid">
                    <article class="landing-card premium-glass">
                        <div class="landing-card-icon"><i class="fas fa-robot"></i></div>
                        <h3>AI Assistant</h3>
                        <p>Smart search, auto-fill suggestions, OCR-ready document review, and respectful help for common parish workflows.</p>
                    </article>
                    <article class="landing-card premium-glass">
                        <div class="landing-card-icon"><i class="fas fa-shield-halved"></i></div>
                        <h3>Trusted Records</h3>
                        <p>Audit trails, verification statuses, digital signatures, QR certificate validation, and secure administrator review.</p>
                    </article>
                    <article class="landing-card premium-glass">
                        <div class="landing-card-icon"><i class="fas fa-quote-left"></i></div>
                        <h3>Parishioner Care</h3>
                        <p>"The online request system made the parish office feel closer, clearer, and easier to reach."</p>
                    </article>
                </div>
            </section>

            <section class="landing-section landing-band" id="contact">
                <div class="landing-container contact-panel">
                    <div>
                        <span class="premium-pill section-kicker"><i class="fas fa-envelope-open-text"></i> Contact Section</span>
                        <h2 class="section-heading">Need help with a parish request?</h2>
                        <p class="section-copy">Reach the parish office for sacramental records, event reservations, Mass intentions, announcements, and account verification.</p>
                    </div>
                    <form class="landing-card premium-glass contact-form">
                        <input type="text" placeholder="Full name" aria-label="Full name" autocomplete="name">
                        <input type="email" placeholder="Email address" aria-label="Email address" autocomplete="email">
                        <textarea rows="4" placeholder="How can the parish office help?" aria-label="Message"></textarea>
                        <a href="auth/register.php" class="premium-btn primary"><i class="fas fa-paper-plane"></i> Contact Parish</a>
                    </form>
                </div>
            </section>
        </main>

        <footer class="landing-footer">
            <div class="landing-container">
                <span>San Lorenzo Ruiz Mission Station</span>
                <span>AI-Powered Parish Management System</span>
            </div>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
