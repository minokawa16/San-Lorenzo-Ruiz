<?php
/**
 * Announcements Module - Shows parish notices and updates to authenticated parishioners.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
if (!isUser()) {
    redirect('../auth/login.php');
}

$page_title = 'Announcements';
ensureExpandedAnnouncementTypeSchema($conn);
ensureAnnouncementAttachmentSchema($conn);
requireSchemaColumns($conn, 'announcements', [
    'event_date', 'deleted_at', 'is_pinned', 'scheduled_at'
], 'parishioner announcements');

$announcement_types = [
    'announcement' => 'General Announcements',
    'monthly_schedule' => 'Monthly Schedules',
    'mass_schedule' => 'Schedule Calendar',
    'parish_event' => 'Parish Events',
    'patronal_fiesta_schedule' => 'Patronal Fiesta Schedules',
    'sacramental_activity' => 'Sacramental Activities',
    'important_notice' => 'Important Notices'
];
$announcement_type_meta = [
    'announcement' => ['icon' => 'fa-bullhorn', 'tone' => 'general', 'label' => 'General Announcement'],
    'monthly_schedule' => ['icon' => 'fa-calendar-days', 'tone' => 'schedule', 'label' => 'Monthly Schedule'],
    'mass_schedule' => ['icon' => 'fa-church', 'tone' => 'mass', 'label' => 'Mass Schedule'],
    'parish_event' => ['icon' => 'fa-people-group', 'tone' => 'event', 'label' => 'Parish Event'],
    'patronal_fiesta_schedule' => ['icon' => 'fa-star', 'tone' => 'fiesta', 'label' => 'Fiesta Celebration'],
    'sacramental_activity' => ['icon' => 'fa-hands-praying', 'tone' => 'sacrament', 'label' => 'Sacramental Activities'],
    'important_notice' => ['icon' => 'fa-circle-exclamation', 'tone' => 'important', 'label' => 'Important Notice']
];

$breadcrumbs = [
    'Dashboard' => 'index.php',
    'Announcements' => null
];

$type = $_GET['type'] ?? 'all';
$allowed_types = array_merge(['all'], array_keys($announcement_types));
if (!in_array($type, $allowed_types, true)) {
    $type = 'all';
}

$search = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'latest';
$event_date = trim($_GET['event_date'] ?? '');
$allowed_sorts = ['latest', 'oldest', 'event_date'];
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'latest';
}
if ($event_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date)) {
    $event_date = '';
}

$audience_stmt=$conn->prepare('SELECT chapel_district FROM users WHERE id=?');$audience_stmt->bind_param('i',$_SESSION['user_id']);$audience_stmt->execute();$audience_user=$audience_stmt->get_result()->fetch_assoc();$audience_stmt->close();$chapel_district=(string)($audience_user['chapel_district']??'');
$where = ["a.status = 'active'", "a.lifecycle_status='published'", "a.deleted_at IS NULL", "a.publish_at <= NOW()", "(a.expires_at IS NULL OR a.expires_at > NOW())", "(a.audience_type='everyone' OR EXISTS(SELECT 1 FROM announcement_audiences aa WHERE aa.announcement_id=a.announcement_id AND ((aa.audience_type='selected_user' AND aa.user_id=?) OR (aa.audience_type IN('district','chapel') AND aa.audience_value=?))))"];
$params = [(int)$_SESSION['user_id'],$chapel_district];
$param_types = 'is';

if ($type !== 'all') {
    $where[] = 'a.type = ?';
    $params[] = $type;
    $param_types .= 's';
}

if ($search !== '') {
    $where[] = '(a.title LIKE ? OR a.content LIKE ? OR a.type LIKE ?)';
    $search_like = '%' . $search . '%';
    array_push($params, $search_like, $search_like, $search_like);
    $param_types .= 'sss';
}

if ($event_date !== '') {
    $where[] = 'a.event_date = ?';
    $params[] = $event_date;
    $param_types .= 's';
}

$order_sql = "a.published_date DESC, a.announcement_id DESC";
if ($sort === 'oldest') {
    $order_sql = "a.published_date ASC, a.announcement_id ASC";
} elseif ($sort === 'event_date') {
    $order_sql = "a.event_date IS NULL, a.event_date ASC, a.published_date DESC, a.announcement_id DESC";
}

$announcements = [];
$stmt = $conn->prepare("SELECT a.announcement_id, a.title, a.content, a.type, a.published_date, a.expiry_date, a.event_date, a.is_pinned, a.attachment_path, a.attachment_original_name, a.attachment_mime_type, a.attachment_size, COALESCE(u.fullname, 'Parish Office') AS posted_by
    FROM announcements a
    LEFT JOIN users u ON a.published_by = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY $order_sql");

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
    $stmt->close();
}

// Announcement Meta Function - Documents this helper's role in the parish management workflow.
function announcementMeta($type, $meta) {
    return $meta[$type] ?? ['icon' => 'fa-bullhorn', 'tone' => 'general', 'label' => ucfirst(str_replace('_', ' ', (string) $type))];
}

// Announcement Preview Function - Documents this helper's role in the parish management workflow.
function announcementPreview($content, $length = 180) {
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string) $content)));
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($plain, 0, $length, '...');
    }
    return strlen($plain) > $length ? substr($plain, 0, $length - 3) . '...' : $plain;
}

// Announcement Countdown Function - Documents this helper's role in the parish management workflow.
function announcementCountdown($event_date) {
    if (empty($event_date)) {
        return '';
    }
    $today = new DateTime(date('Y-m-d'));
    $event = new DateTime($event_date);
    $days = (int) $today->diff($event)->format('%r%a');
    if ($days > 0) {
        return $days . ' day' . ($days === 1 ? '' : 's') . ' to go';
    }
    if ($days === 0) {
        return 'Today';
    }
    return abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' ago';
}
?>
<?php include '../templates/header.php'; ?>

<?php include '../includes/breadcrumb.php'; ?>
<?php include '../includes/back_button.php'; ?>

<style>
    .announcements-page {
        max-width: 1440px;
        margin: 0 auto;
    }

    .announcements-hero {
        display: grid;
        grid-template-columns: minmax(0, 1.1fr) minmax(280px, 0.9fr);
        gap: 18px;
        align-items: stretch;
        margin-bottom: 18px;
    }

    .announcement-hero-main,
    .announcement-insight,
    .announcement-toolbar,
    .featured-announcement,
    .announcement-card,
    .announcement-empty {
        border: 1px solid rgba(23, 32, 51, 0.1);
        border-radius: 8px;
        background: #ffffff;
        box-shadow: 0 14px 34px rgba(30, 41, 59, 0.08);
    }

    .announcement-hero-main {
        position: relative;
        overflow: hidden;
        padding: 26px;
        border-top: 4px solid #d7ad43;
        background: linear-gradient(135deg, #ffffff, #fff8df 54%, #eef5fb);
    }

    .announcement-hero-main::after {
        content: "\f51d";
        font-family: "Font Awesome 6 Free";
        font-weight: 900;
        position: absolute;
        right: 22px;
        bottom: -14px;
        color: rgba(23, 68, 106, 0.08);
        font-size: 7rem;
        pointer-events: none;
    }

    .announcement-hero-main h1 {
        margin: 8px 0;
        color: #172033;
        font-size: 1.9rem;
        font-weight: 900;
    }

    .announcement-hero-main p,
    .announcement-insight p {
        margin: 0;
        color: #667085;
        line-height: 1.6;
    }

    .announcement-kicker,
    .category-chip,
    .new-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 32px;
        padding: 6px 11px;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 850;
    }

    .announcement-kicker {
        color: #80611b;
        background: #fff8df;
        border: 1px solid rgba(215, 173, 67, 0.28);
    }

    .announcement-hero-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 16px;
    }

    .announcement-insight {
        padding: 20px;
        display: grid;
        align-content: center;
        gap: 10px;
    }

    .announcement-insight i,
    .announcement-empty i {
        width: 48px;
        height: 48px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        color: #17446a;
        background: #eef5fb;
        font-size: 1.2rem;
    }

    .announcement-insight strong {
        color: #172033;
        font-size: 1.05rem;
    }

    .announcement-toolbar {
        padding: 16px;
        margin-bottom: 18px;
    }

    .input-with-icon {
        position: relative;
    }

    .input-with-icon i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        z-index: 1;
    }

    .input-with-icon .form-control {
        padding-left: 42px;
    }

    .toolbar-control {
        min-height: 46px;
        border-radius: 8px;
        border-color: #dfe4ea;
    }

    .category-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }

    .category-tabs a,
    .category-chip {
        border: 1px solid #dfe4ea;
        color: #334155;
        background: #ffffff;
        text-decoration: none;
        transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease;
    }

    .category-tabs a {
        min-height: 36px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 12px;
        border-radius: 999px;
        font-size: 0.84rem;
        font-weight: 850;
    }

    .category-tabs a.active,
    .category-tabs a:hover,
    .category-chip:hover {
        transform: translateY(-1px);
        color: #171205;
        background: #fff8df;
        border-color: rgba(215, 173, 67, 0.48);
    }

    .category-chip.general { background: #eef5fb; color: #17446a; }
    .category-chip.schedule { background: #fff8df; color: #80611b; }
    .category-chip.mass { background: #f0fdf4; color: #166534; }
    .category-chip.event { background: #f5f3ff; color: #5b21b6; }
    .category-chip.fiesta { background: #fff1f2; color: #9f1239; }
    .category-chip.sacrament { background: #f5f3ff; color: #5b21b6; }
    .category-chip.important { background: #fff1f2; color: #9f1239; }

    .featured-announcement {
        overflow: hidden;
        margin-bottom: 18px;
    }

    .featured-grid {
        display: grid;
        grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.05fr);
        gap: 0;
    }

    .featured-media {
        min-height: 300px;
        background: linear-gradient(135deg, #eef5fb, #fff8df);
        display: grid;
        place-items: center;
        color: #17446a;
        overflow: hidden;
    }

    .featured-media img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .featured-media i {
        font-size: 4rem;
        opacity: 0.8;
    }

    .featured-body {
        padding: 24px;
        display: grid;
        align-content: center;
        gap: 12px;
    }

    .featured-body h2,
    .announcement-card h3 {
        color: #172033;
        font-weight: 900;
        letter-spacing: 0;
    }

    .featured-body h2 {
        font-size: 1.55rem;
        margin: 0;
    }

    .featured-body p,
    .announcement-card p {
        color: #667085;
        line-height: 1.6;
    }

    .announcement-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 10px 14px;
        color: #667085;
        font-size: 0.86rem;
    }

    .announcement-meta span {
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }

    .announcement-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 18px;
    }

    .announcement-card {
        overflow: hidden;
        display: grid;
        grid-template-columns: minmax(280px, 0.95fr) minmax(0, 1.05fr);
        min-height: 300px;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .announcement-card:hover {
        transform: translateY(-4px);
        border-color: rgba(215, 173, 67, 0.5);
        box-shadow: 0 18px 42px rgba(30, 41, 59, 0.12);
    }

    .announcement-thumb {
        height: 100%;
        min-height: 300px;
        background: linear-gradient(135deg, #f8fafc, #eef5fb);
        display: grid;
        place-items: center;
        color: #17446a;
        overflow: hidden;
    }

    .announcement-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .announcement-thumb i {
        font-size: 4rem;
        opacity: 0.8;
    }

    .announcement-card-body {
        padding: 26px;
        display: grid;
        align-content: center;
        gap: 12px;
    }

    .announcement-card h3 {
        margin: 0;
        font-size: 1.5rem;
    }

    .announcement-card p {
        margin: 0;
    }

    .new-pill {
        color: #166534;
        background: #dcfce7;
    }

    .announcement-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 4px;
    }

    .announcement-empty {
        padding: 46px 18px;
        text-align: center;
        color: #667085;
    }

    .announcement-empty i {
        width: 64px;
        height: 64px;
        margin-bottom: 14px;
        font-size: 1.7rem;
    }

    .modal-announcement-content {
        white-space: pre-wrap;
        color: #334155;
        line-height: 1.7;
    }

    @media (max-width: 768px) {
        .announcements-hero,
        .featured-grid,
        .announcement-grid {
            grid-template-columns: 1fr;
        }

        .featured-media {
            min-height: 220px;
        }

        .announcement-card {
            grid-template-columns: 1fr;
            min-height: 0;
        }

        .announcement-thumb {
            height: 180px;
            min-height: 180px;
        }

        .announcement-thumb i {
            font-size: 2.5rem;
        }

        .announcement-card-body {
            padding: 16px;
            align-content: start;
        }

        .announcement-card h3 {
            font-size: 1.05rem;
        }
    }
</style>

<div class="container-fluid mt-4">
    <div class="announcements-page">
        <section class="announcements-hero">
            <div class="announcement-hero-main">
                <span class="announcement-kicker"><i class="fas fa-bullhorn"></i> Parish Communication Hub</span>
                <h1>Parish Announcements</h1>
                <p>Stay updated with parish activities, liturgical schedules, events, and community notices from San Lorenzo Ruiz Mission Station.</p>
                <div class="announcement-hero-badges">
                    <span class="announcement-kicker"><i class="fas fa-bell"></i> <?php echo count($announcements); ?> active notices</span>
                    <span class="announcement-kicker"><i class="fas fa-wand-magic-sparkles"></i> AI summary ready</span>
                    <span class="announcement-kicker"><i class="fas fa-calendar-check"></i> Event-aware updates</span>
                </div>
            </div>
            <aside class="announcement-insight">
                <i class="fas fa-robot"></i>
                <strong>Smart parish update</strong>
                <p><?php echo !empty($announcements) ? 'Latest parish communication: ' . e($announcements[0]['title']) : 'No active parish announcements are available right now.'; ?></p>
            </aside>
        </section>

        <form method="GET" class="announcement-toolbar">
            <div class="row g-2 align-items-end">
                <div class="col-lg-5">
                    <label class="form-label">Search announcements</label>
                    <div class="input-with-icon">
                        <i class="fas fa-search"></i>
                        <input type="search" class="form-control toolbar-control" name="q" value="<?php echo e($search); ?>" placeholder="Search announcements, events, or schedules...">
                    </div>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label">Category</label>
                    <select name="type" class="form-select toolbar-control">
                        <option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <?php foreach ($announcement_types as $value => $label): ?>
                            <option value="<?php echo e($value); ?>" <?php echo $type === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label">Sort</label>
                    <select name="sort" class="form-select toolbar-control">
                        <option value="latest" <?php echo $sort === 'latest' ? 'selected' : ''; ?>>Latest first</option>
                        <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                        <option value="event_date" <?php echo $sort === 'event_date' ? 'selected' : ''; ?>>Event date</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label">Event date</label>
                    <input type="date" class="form-control toolbar-control" name="event_date" value="<?php echo e($event_date); ?>">
                </div>
                <div class="col-lg-1 d-grid">
                    <button class="btn btn-primary toolbar-control" type="submit"><i class="fas fa-filter"></i></button>
                </div>
            </div>

            <div class="category-tabs">
                <a href="announcements.php?q=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>&event_date=<?php echo urlencode($event_date); ?>" class="<?php echo $type === 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-layer-group"></i> All
                </a>
                <?php foreach ($announcement_types as $value => $label): ?>
                    <?php $meta = announcementMeta($value, $announcement_type_meta); ?>
                    <a href="?type=<?php echo urlencode($value); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>&event_date=<?php echo urlencode($event_date); ?>" class="<?php echo $type === $value ? 'active' : ''; ?>">
                        <i class="fas <?php echo e($meta['icon']); ?>"></i> <?php echo e($meta['label']); ?>
                    </a>
                <?php endforeach; ?>
                <?php if ($search !== '' || $type !== 'all' || $sort !== 'latest' || $event_date !== ''): ?>
                    <a href="announcements.php"><i class="fas fa-xmark"></i> Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if (!empty($announcements)): ?>
            <section class="announcement-grid">
                <?php foreach ($announcements as $announcement): ?>
                    <?php $meta = announcementMeta($announcement['type'], $announcement_type_meta); ?>
                    <article class="announcement-card">
                        <div class="announcement-thumb">
                            <?php if (!empty($announcement['attachment_path']) && isAnnouncementImageAttachment($announcement['attachment_mime_type'] ?? '')): ?>
                                <img src="../announcement-attachment.php?id=<?php echo intval($announcement['announcement_id']); ?>" alt="<?php echo e($announcement['attachment_original_name'] ?: 'Announcement image'); ?>">
                            <?php else: ?>
                                <i class="fas <?php echo e($meta['icon']); ?>"></i>
                            <?php endif; ?>
                        </div>
                        <div class="announcement-card-body">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="category-chip <?php echo e($meta['tone']); ?>"><i class="fas <?php echo e($meta['icon']); ?>"></i> <?php echo e($meta['label']); ?></span>
                                <?php if (intval($announcement['is_pinned'] ?? 0) === 1): ?>
                                    <span class="new-pill"><i class="fas fa-thumbtack"></i> Pinned</span>
                                <?php endif; ?>
                                <?php if (strtotime($announcement['published_date']) >= strtotime('-3 days')): ?>
                                    <span class="new-pill"><i class="fas fa-circle"></i> New</span>
                                <?php endif; ?>
                            </div>
                            <h3><?php echo e($announcement['title']); ?></h3>
                            <p><?php echo e(announcementPreview($announcement['content'])); ?></p>
                            <div class="announcement-meta">
                                <span><i class="fas fa-calendar"></i> <?php echo e(formatDate($announcement['published_date'])); ?></span>
                                <span><i class="fas fa-user"></i> <?php echo e($announcement['posted_by']); ?></span>
                                <?php if (!empty($announcement['event_date'])): ?>
                                    <span><i class="fas fa-calendar-check"></i> <?php echo e(formatDate($announcement['event_date'])); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="announcement-actions">
                                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#announcementModal-<?php echo intval($announcement['announcement_id']); ?>">
                                    <i class="fas fa-book-open"></i> Read More
                                </button>
                                <?php if (!empty($announcement['attachment_path'])): ?>
                                    <a class="btn btn-outline-secondary btn-sm" href="../announcement-attachment.php?id=<?php echo intval($announcement['announcement_id']); ?>" target="_blank">
                                        <i class="fas fa-paperclip"></i> Attachment
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <div class="announcement-empty">
                <i class="fas fa-bullhorn"></i>
                <h5>No announcements available at the moment.</h5>
                <p class="mb-0">Please check again later for parish activities, schedules, and community notices.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($announcements as $announcement): ?>
    <?php $meta = announcementMeta($announcement['type'], $announcement_type_meta); ?>
    <div class="modal fade" id="announcementModal-<?php echo intval($announcement['announcement_id']); ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <span class="category-chip <?php echo e($meta['tone']); ?>"><i class="fas <?php echo e($meta['icon']); ?>"></i> <?php echo e($meta['label']); ?></span>
                        <h5 class="modal-title mt-2"><?php echo e($announcement['title']); ?></h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($announcement['attachment_path']) && isAnnouncementImageAttachment($announcement['attachment_mime_type'] ?? '')): ?>
                        <img src="../announcement-attachment.php?id=<?php echo intval($announcement['announcement_id']); ?>" alt="<?php echo e($announcement['attachment_original_name'] ?: 'Announcement image'); ?>" class="img-fluid rounded border mb-3">
                    <?php endif; ?>
                    <div class="announcement-meta mb-3">
                        <span><i class="fas fa-calendar"></i> Posted <?php echo e(formatDateTime($announcement['published_date'])); ?></span>
                        <span><i class="fas fa-user"></i> <?php echo e($announcement['posted_by']); ?></span>
                        <?php if (!empty($announcement['event_date'])): ?>
                            <span><i class="fas fa-calendar-check"></i> Event: <?php echo e(formatDate($announcement['event_date'])); ?></span>
                            <span><i class="fas fa-hourglass-half"></i> <?php echo e(announcementCountdown($announcement['event_date'])); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="modal-announcement-content"><?php echo renderStructuredAnnouncementHtml($announcement['content']); ?></div>
                </div>
                <div class="modal-footer">
                    <?php if (!empty($announcement['attachment_path'])): ?>
                        <a class="btn btn-outline-primary" href="../announcement-attachment.php?id=<?php echo intval($announcement['announcement_id']); ?>" target="_blank">
                            <i class="fas fa-download"></i> Open Attachment
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($announcement['event_date'])): ?>
                        <a class="btn btn-outline-secondary" href="https://calendar.google.com/calendar/render?action=TEMPLATE&text=<?php echo urlencode($announcement['title']); ?>&dates=<?php echo date('Ymd', strtotime($announcement['event_date'])); ?>/<?php echo date('Ymd', strtotime($announcement['event_date'] . ' +1 day')); ?>" target="_blank">
                            <i class="fas fa-calendar-plus"></i> Add to Calendar
                        </a>
                    <?php endif; ?>
                    <button class="btn btn-outline-secondary announcement-share-btn" type="button" data-title="<?php echo e($announcement['title']); ?>">
                        <i class="fas fa-share-nodes"></i> Share
                    </button>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
    document.querySelectorAll('.announcement-share-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            const text = button.dataset.title + ' - ' + window.location.href;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text);
                button.innerHTML = '<i class="fas fa-check"></i> Copied';
                setTimeout(function() {
                    button.innerHTML = '<i class="fas fa-share-nodes"></i> Share';
                }, 1600);
            }
        });
    });
</script>

<?php include '../templates/footer.php'; ?>
