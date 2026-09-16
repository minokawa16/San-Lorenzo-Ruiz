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

$total_active_notices = 0;
$total_count_res = $conn->query("SELECT COUNT(*) AS total FROM announcements WHERE status = 'active' AND lifecycle_status = 'published' AND deleted_at IS NULL AND publish_at <= NOW() AND (expires_at IS NULL OR expires_at > NOW())");
if ($total_count_res && $t_row = $total_count_res->fetch_assoc()) {
    $total_active_notices = (int) $t_row['total'];
}
if ($total_active_notices === 0) {
    $total_active_notices = count($announcements);
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

    /* Synchronized Hero and Smart Insight Cards */
    .announcements-hero {
        display: grid;
        grid-template-columns: minmax(0, 1.25fr) minmax(280px, 0.75fr);
        gap: 18px;
        align-items: stretch;
        margin-bottom: 22px;
    }

    .announcement-hero-main,
    .announcement-insight {
        background: #FFFFFF;
        border: 1px solid #EBE4D8;
        border-radius: 16px;
        padding: 24px 26px;
        box-shadow: 0 2px 10px rgba(46, 58, 45, 0.03);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .announcement-kicker {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 9999px;
        background: #FAF7F2;
        border: 1px solid #E5DEC9;
        color: #8A681B;
        font-size: 0.76rem;
        font-weight: 700;
        width: fit-content;
    }

    .announcement-hero-main h1 {
        margin: 10px 0 6px 0;
        font-family: "Playfair Display", Georgia, serif;
        font-weight: 700;
        font-size: clamp(1.5rem, 2.2vw, 1.95rem);
        color: #1E252B;
        line-height: 1.2;
    }

    .announcement-hero-main p {
        margin: 0;
        color: #5F6672;
        font-size: 0.92rem;
        line-height: 1.5;
    }

    .announcement-hero-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 16px;
    }

    .hero-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 12px;
        border-radius: 9999px;
        background: #FAF7F2;
        border: 1px solid #E5DEC9;
        color: #374151;
        font-size: 0.78rem;
        font-weight: 600;
    }

    .announcement-insight {
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 12px;
    }

    .insight-icon-box {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: #EEF5FB;
        color: #2563EB;
        border: 1px solid #DBEAFE;
        display: grid;
        place-items: center;
        font-size: 1.15rem;
    }

    .insight-title {
        font-size: 1.05rem;
        font-weight: 700;
        color: #1E252B;
        margin: 0;
    }

    .insight-desc {
        margin: 0;
        color: #5F6672;
        font-size: 0.9rem;
        line-height: 1.5;
    }

    /* Redesigned Filter Toolbar (Clean Single Control Panel) */
    .announcement-toolbar-card {
        background: #FFFFFF;
        border: 1px solid #EBE4D8;
        border-radius: 16px;
        padding: 20px 22px;
        margin-bottom: 24px;
        box-shadow: 0 2px 10px rgba(46, 58, 45, 0.03);
    }

    .announcement-filters-grid {
        display: grid;
        grid-template-columns: 2fr 1.2fr 1.1fr 1.1fr auto;
        gap: 14px;
        align-items: end;
    }

    @media (max-width: 991px) {
        .announcement-filters-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
    }

    @media (max-width: 575px) {
        .announcement-filters-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }
    }

    .filter-field {
        display: flex;
        flex-direction: column;
    }

    .filter-label {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        color: #64748B;
        letter-spacing: 0.05em;
        margin-bottom: 6px;
        text-transform: uppercase;
    }

    .filter-input-wrap {
        position: relative;
        width: 100%;
    }

    .filter-input-wrap i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #64748B;
        font-size: 0.88rem;
        pointer-events: none;
        z-index: 2;
    }

    .filter-control {
        width: 100%;
        height: 44px;
        background: #FAF7F2;
        border: 1px solid #E5DEC9;
        border-radius: 10px;
        font-size: 0.9rem;
        color: #1F2937;
        font-weight: 500;
        padding: 8px 14px;
        transition: all 0.18s ease;
    }

    .filter-input-wrap input.filter-control {
        padding-left: 38px;
    }

    .filter-control:hover {
        border-color: #C89B3C;
        background: #FDFBF8;
    }

    .filter-control:focus {
        background: #FFFFFF;
        border-color: #C89B3C;
        box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.15);
        outline: none;
    }

    .form-select.filter-control {
        background-color: #FAF7F2;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%2364748B' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 14px center;
        background-size: 12px 10px;
        padding-right: 36px;
        cursor: pointer;
    }

    .btn-filter-submit {
        height: 44px;
        width: 44px;
        min-width: 44px;
        display: grid;
        place-items: center;
        border-radius: 10px;
        background: #C89B3C;
        border: 1px solid #A97F24;
        color: #FFFFFF;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 0.18s ease;
        box-shadow: 0 2px 6px rgba(200, 155, 60, 0.2);
    }

    .btn-filter-submit:hover {
        background: #A97F24;
        border-color: #8C6819;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(200, 155, 60, 0.28);
    }

    /* Section Header with "1 of 6" Counter */
    .announcements-section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 16px;
        gap: 12px;
        flex-wrap: wrap;
    }

    .section-heading-group {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .section-heading {
        font-family: "Playfair Display", Georgia, serif;
        font-weight: 700;
        font-size: 1.25rem;
        color: #1E252B;
        margin: 0;
    }

    .section-counter-badge {
        font-size: 0.76rem;
        font-weight: 700;
        color: #8C6A30;
        background: #F3EFE6;
        border: 1px solid #DFD7C7;
        padding: 3px 10px;
        border-radius: 9999px;
        letter-spacing: 0.02em;
    }

    /* Rebuilt Announcement Cards */
    .announcement-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 16px;
    }

    .announcement-card {
        background: #FFFFFF;
        border: 1px solid #EBE4D8;
        border-radius: 16px;
        padding: 22px 24px;
        box-shadow: 0 2px 10px rgba(46, 58, 45, 0.03);
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        display: flex;
        flex-direction: column;
    }

    .announcement-card:hover {
        transform: translateY(-2px);
        border-color: #C89B3C;
        box-shadow: 0 8px 24px rgba(46, 58, 45, 0.08);
    }

    .announcement-card-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 10px;
    }

    .announcement-badge-row {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .category-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 11px;
        border-radius: 9999px;
        font-size: 0.78rem;
        font-weight: 650;
        border: 1px solid transparent;
    }
    .category-chip.general { background: #EEF5FB; color: #1D4ED8; border-color: #DBEAFE; }
    .category-chip.schedule { background: #FEF9C3; color: #A16207; border-color: #FEF08A; }
    .category-chip.mass { background: #F0FDF4; color: #15803D; border-color: #BBF7D0; }
    .category-chip.event { background: #F5F3FF; color: #6D28D9; border-color: #DDD6FE; }
    .category-chip.fiesta { background: #FDF2F8; color: #BE185D; border-color: #FBCFE8; }
    .category-chip.sacrament { background: #F5F3FF; color: #7C3AED; border-color: #E9D5FF; }
    .category-chip.important { background: #FEF2F2; color: #B91C1C; border-color: #FECACA; }

    .badge-pinned {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 9px;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 700;
        color: #854D0E;
        background: #FEF08A;
        border: 1px solid #FDE047;
    }

    .badge-new {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 9px;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 700;
        color: #15803D;
        background: #DCFCE7;
        border: 1px solid #BBF7D0;
    }

    .event-countdown-pill {
        font-size: 0.76rem;
        font-weight: 650;
        color: #64748B;
        background: #FAF7F2;
        border: 1px solid #E5DEC9;
        padding: 3px 10px;
        border-radius: 9999px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .announcement-card-title {
        font-family: "Playfair Display", Georgia, serif;
        font-size: 1.35rem;
        font-weight: 700;
        color: #1E252B;
        margin: 0 0 12px 0;
        line-height: 1.3;
    }

    .announcement-plain-desc {
        color: #5F6672;
        font-size: 0.92rem;
        line-height: 1.6;
        margin: 0 0 8px 0;
    }

    /* 5W1H Aligned Details Row with Icons */
    .announcement-details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 10px 16px;
        margin-bottom: 12px;
        background: #FAF7F2;
        border: 1px solid #EBE4D8;
        border-radius: 12px;
        padding: 12px 16px;
    }

    .detail-item {
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }

    .detail-icon {
        width: 28px;
        height: 28px;
        border-radius: 8px;
        display: grid;
        place-items: center;
        font-size: 0.8rem;
        flex-shrink: 0;
        margin-top: 2px;
    }

    .detail-icon.what { background: #EEF5FB; color: #2563EB; border: 1px solid #DBEAFE; }
    .detail-icon.when { background: #FEF9C3; color: #CA8A04; border: 1px solid #FEF08A; }
    .detail-icon.where { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; }
    .detail-icon.who { background: #DCFCE7; color: #16A34A; border: 1px solid #BBF7D0; }

    .detail-content {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .detail-label {
        font-size: 0.66rem;
        font-weight: 750;
        letter-spacing: 0.05em;
        color: #64748B;
        text-transform: uppercase;
        margin-bottom: 1px;
    }

    .detail-value {
        font-size: 0.86rem;
        color: #1F2937;
        font-weight: 500;
        line-height: 1.35;
        word-break: break-word;
    }

    .announcement-inline-image {
        max-height: 220px;
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid #EBE4D8;
        margin-bottom: 12px;
    }

    .announcement-inline-image img {
        width: 100%;
        height: 100%;
        max-height: 220px;
        object-fit: cover;
    }

    .announcement-card-divider {
        border: 0;
        border-top: 1px solid #EBE4D8;
        margin: 14px 0 12px 0;
    }

    .announcement-card-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: auto;
    }

    .announcement-meta-info {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        color: #64748B;
        font-size: 0.84rem;
    }

    .announcement-meta-info span {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-parish-gold {
        background: #C89B3C !important;
        border-color: #A97F24 !important;
        color: #FFFFFF !important;
        font-weight: 650 !important;
        padding: 7px 16px !important;
        border-radius: 9px !important;
        font-size: 0.86rem !important;
        box-shadow: 0 2px 6px rgba(200, 155, 60, 0.18) !important;
        transition: all 0.18s ease !important;
    }

    .btn-parish-gold:hover {
        background: #A97F24 !important;
        border-color: #8C6819 !important;
        transform: translateY(-1px) !important;
        box-shadow: 0 4px 10px rgba(200, 155, 60, 0.28) !important;
    }

    .announcement-empty {
        background: #FFFFFF;
        border: 1px dashed #E5DEC9;
        border-radius: 16px;
        padding: 48px 24px;
        text-align: center;
        color: #64748B;
    }

    .announcement-empty-icon {
        width: 56px;
        height: 56px;
        border-radius: 14px;
        background: #FAF7F2;
        border: 1px solid #E5DEC9;
        color: #8C6A30;
        display: grid;
        place-items: center;
        font-size: 1.4rem;
        margin: 0 auto 16px auto;
    }

    .modal-announcement-content {
        white-space: pre-wrap;
        color: #334155;
        line-height: 1.7;
    }

    @media (max-width: 768px) {
        .announcements-hero {
            grid-template-columns: 1fr;
        }

        .announcement-card {
            padding: 18px 16px;
        }

        .announcement-card-title {
            font-size: 1.15rem;
        }

        .announcement-details-grid {
            grid-template-columns: 1fr;
            padding: 10px 12px;
        }
    }
</style>

<div class="container-fluid mt-4">
    <div class="announcements-page">
        <section class="announcements-hero">
            <div class="announcement-hero-main">
                <div class="hero-header">
                    <span class="announcement-kicker"><i class="fas fa-bullhorn"></i> Parish Communication Hub</span>
                    <h1>Parish Announcements</h1>
                    <p>Stay updated with parish activities, liturgical schedules, events, and community notices from San Lorenzo Ruiz Mission Station.</p>
                </div>
                <div class="announcement-hero-badges">
                    <span class="hero-badge"><i class="far fa-bell"></i> <?php echo count($announcements); ?> active notices</span>
                    <span class="hero-badge"><i class="fas fa-wand-magic-sparkles"></i> AI summary ready</span>
                    <span class="hero-badge"><i class="far fa-calendar-check"></i> Event-aware updates</span>
                </div>
            </div>
            <aside class="announcement-insight">
                <div class="insight-icon-box"><i class="fas fa-robot"></i></div>
                <strong class="insight-title">Smart parish update</strong>
                <p class="insight-desc"><?php echo !empty($announcements) ? 'Latest parish communication: ' . e($announcements[0]['title']) : 'No active parish announcements are available right now.'; ?></p>
            </aside>
        </section>

        <!-- Redesigned Filter Toolbar Card -->
        <form method="GET" class="announcement-toolbar-card">
            <div class="announcement-filters-grid">
                <div class="filter-field">
                    <label class="filter-label">Search announcements</label>
                    <div class="filter-input-wrap">
                        <i class="fas fa-magnifying-glass"></i>
                        <input type="search" class="form-control filter-control" name="q" value="<?php echo e($search); ?>" placeholder="Search announcements, events, or schedules...">
                    </div>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Category</label>
                    <div class="filter-input-wrap">
                        <select name="type" class="form-select filter-control">
                            <option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <?php foreach ($announcement_types as $value => $label): ?>
                                <option value="<?php echo e($value); ?>" <?php echo $type === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Sort</label>
                    <div class="filter-input-wrap">
                        <select name="sort" class="form-select filter-control">
                            <option value="latest" <?php echo $sort === 'latest' ? 'selected' : ''; ?>>Latest first</option>
                            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                            <option value="event_date" <?php echo $sort === 'event_date' ? 'selected' : ''; ?>>Event date</option>
                        </select>
                    </div>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Event date</label>
                    <div class="filter-input-wrap">
                        <input type="date" class="form-control filter-control" name="event_date" value="<?php echo e($event_date); ?>">
                    </div>
                </div>
                <div class="filter-field">
                    <button class="btn-filter-submit" type="submit" title="Apply Filters">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
            </div>
        </form>

        <!-- Section Header with "1 of 6" Counter -->
        <div class="announcements-section-header">
            <div class="section-heading-group">
                <h2 class="section-heading">Parish Notices</h2>
                <span class="section-counter-badge"><?php echo count($announcements); ?> of <?php echo max($total_active_notices, count($announcements)); ?></span>
            </div>
            <?php if ($search !== '' || $type !== 'all' || $sort !== 'latest' || $event_date !== ''): ?>
                <a href="announcements.php" class="btn btn-sm btn-outline-secondary rounded-pill" style="font-size: 0.8rem;">
                    <i class="fas fa-xmark me-1"></i> Clear Filters
                </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($announcements)): ?>
            <section class="announcement-grid">
                <?php foreach ($announcements as $announcement): ?>
                    <?php 
                        $meta = announcementMeta($announcement['type'], $announcement_type_meta);
                        $parsed_5w = parse5W1HAnnouncement($announcement['content']);
                    ?>
                    <article class="announcement-card">
                        <div class="announcement-card-top">
                            <div class="announcement-badge-row">
                                <span class="category-chip <?php echo e($meta['tone']); ?>">
                                    <i class="fas <?php echo e($meta['icon']); ?>"></i> <?php echo e($meta['label']); ?>
                                </span>
                                <?php if (intval($announcement['is_pinned'] ?? 0) === 1): ?>
                                    <span class="badge-pinned"><i class="fas fa-thumbtack"></i> Pinned</span>
                                <?php endif; ?>
                                <?php if (strtotime($announcement['published_date']) >= strtotime('-3 days')): ?>
                                    <span class="badge-new"><i class="fas fa-circle-dot"></i> New</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($announcement['event_date'])): ?>
                                <span class="event-countdown-pill">
                                    <i class="far fa-clock"></i> <?php echo e(announcementCountdown($announcement['event_date'])); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <h3 class="announcement-card-title"><?php echo e($announcement['title']); ?></h3>

                        <?php if (!empty($announcement['attachment_path']) && isAnnouncementImageAttachment($announcement['attachment_mime_type'] ?? '')): ?>
                            <div class="announcement-inline-image">
                                <img src="../announcement-attachment.php?id=<?php echo intval($announcement['announcement_id']); ?>" alt="<?php echo e($announcement['attachment_original_name'] ?: 'Announcement image'); ?>">
                            </div>
                        <?php endif; ?>

                        <?php if ($parsed_5w['is_structured']): ?>
                            <div class="announcement-details-grid">
                                <?php if (!empty($parsed_5w['what'])): ?>
                                    <div class="detail-item">
                                        <span class="detail-icon what"><i class="fas fa-bullhorn"></i></span>
                                        <div class="detail-content">
                                            <span class="detail-label">WHAT</span>
                                            <span class="detail-value"><?php echo e($parsed_5w['what']); ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($parsed_5w['when'])): ?>
                                    <div class="detail-item">
                                        <span class="detail-icon when"><i class="far fa-calendar-alt"></i></span>
                                        <div class="detail-content">
                                            <span class="detail-label">WHEN</span>
                                            <span class="detail-value"><?php echo e($parsed_5w['when']); ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($parsed_5w['where'])): ?>
                                    <div class="detail-item">
                                        <span class="detail-icon where"><i class="fas fa-location-dot"></i></span>
                                        <div class="detail-content">
                                            <span class="detail-label">WHERE</span>
                                            <span class="detail-value"><?php echo e($parsed_5w['where']); ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($parsed_5w['who'])): ?>
                                    <div class="detail-item">
                                        <span class="detail-icon who"><i class="fas fa-users"></i></span>
                                        <div class="detail-content">
                                            <span class="detail-label">WHO</span>
                                            <span class="detail-value"><?php echo e($parsed_5w['who']); ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="announcement-plain-desc"><?php echo e(announcementPreview($announcement['content'], 220)); ?></p>
                        <?php endif; ?>

                        <hr class="announcement-card-divider">

                        <div class="announcement-card-footer">
                            <div class="announcement-meta-info">
                                <span><i class="far fa-calendar"></i> <?php echo e(formatDate($announcement['published_date'])); ?></span>
                                <span><i class="far fa-user"></i> <?php echo e($announcement['posted_by']); ?></span>
                                <?php if (!empty($announcement['event_date'])): ?>
                                    <span><i class="far fa-calendar-check"></i> Event: <?php echo e(formatDate($announcement['event_date'])); ?></span>
                                <?php endif; ?>
                            </div>
                            <button class="btn btn-parish-gold btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#announcementModal-<?php echo intval($announcement['announcement_id']); ?>">
                                <i class="fas fa-book-open"></i> Read More
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <div class="announcement-empty">
                <div class="announcement-empty-icon"><i class="fas fa-bullhorn"></i></div>
                <h5 class="fw-bold text-dark">No announcements available at the moment.</h5>
                <p class="mb-0 text-muted">Please check again later for parish activities, schedules, and community notices.</p>
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
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php include '../templates/footer.php'; ?>
