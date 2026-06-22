<?php
/**
 * User Dashboard Module - Presents parishioner request status, schedules, notifications, and quick actions.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
include '../includes/auth.php';

// Check session expiration
initSession();
if (isSessionExpired()) {
    logoutUser();
}

// Require authentication and parishioner role
requireAuth();
requireParishioner();

$user_id = getCurrentUserId();
$user_name = getUserFullName();

$request_counts = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'completed' => 0,
];
$recent_requests = [];
$announcements = [];
$unread_count = getUnreadNotificationCount($conn, $user_id);

$stmt = $conn->prepare("SELECT status, COUNT(*) AS count FROM requests WHERE user_id = ? GROUP BY status");
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $status = $row['status'];
        $count = intval($row['count']);
        $request_counts['total'] += $count;
        if (isset($request_counts[$status])) {
            $request_counts[$status] = $count;
        }
    }
    $stmt->close();
}

$stmt = $conn->prepare("SELECT request_id, request_type, status, reference_number, date_requested FROM requests WHERE user_id = ? ORDER BY date_requested DESC LIMIT 5");
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_requests[] = $row;
    }
    $stmt->close();
}

$stmt = $conn->prepare("SELECT announcement_id, title, content, type, published_date FROM announcements WHERE status = 'active' AND (expiry_date IS NULL OR expiry_date >= NOW()) ORDER BY published_date DESC LIMIT 4");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
    $stmt->close();
}

$page_title = 'User Dashboard';
?>
<?php include '../templates/header.php'; ?>

<section class="premium-admin-hero">
    <div>
        <span class="premium-pill"><i class="fas fa-church"></i> Parish service center</span>
        <h1>Welcome back, <?php echo e($user_name); ?>.</h1>
        <p>Track certificate requests, blessings, sacramental services, schedules, announcements, notifications, and AI guidance from one calm parish workspace.</p>
        <div class="landing-actions">
            <a href="request-certificate.php" class="premium-btn primary"><i class="fas fa-certificate"></i> Request Certificate</a>
            <a href="request-service.php" class="premium-btn ghost"><i class="fas fa-church"></i> Sacramental Service</a>
            <a href="my-requests.php" class="premium-btn ghost"><i class="fas fa-list-check"></i> View Requests</a>
        </div>
    </div>
    <div class="hero-orb" aria-hidden="true">
        <i class="fas fa-church"></i>
    </div>
</section>

<section class="premium-kpi-grid">
    <a href="my-requests.php" class="premium-kpi-card premium-glass">
        <div class="premium-kpi-icon"><i class="fas fa-layer-group"></i></div>
        <div class="premium-kpi-label">Total Requests</div>
        <div class="premium-kpi-value"><?php echo intval($request_counts['total']); ?></div>
        <div class="premium-kpi-note"><i class="fas fa-arrow-up"></i> View all</div>
    </a>
    <a href="my-requests.php?status=pending" class="premium-kpi-card premium-glass">
        <div class="premium-kpi-icon"><i class="fas fa-hourglass-half"></i></div>
        <div class="premium-kpi-label">Pending Review</div>
        <div class="premium-kpi-value"><?php echo intval($request_counts['pending']); ?></div>
        <div class="premium-kpi-note"><i class="fas fa-clock"></i> Needs update</div>
    </a>
    <a href="request-service.php" class="premium-kpi-card premium-glass">
        <div class="premium-kpi-icon"><i class="fas fa-church"></i></div>
        <div class="premium-kpi-label">Sacramental Services</div>
        <div class="premium-kpi-value"><?php echo intval($request_counts['approved']); ?></div>
        <div class="premium-kpi-note"><i class="fas fa-check"></i> Approved requests</div>
    </a>
    <a href="notifications.php" class="premium-kpi-card premium-glass">
        <div class="premium-kpi-icon"><i class="fas fa-bell"></i></div>
        <div class="premium-kpi-label">Notifications</div>
        <div class="premium-kpi-value"><?php echo intval($unread_count); ?></div>
        <div class="premium-kpi-note"><i class="fas fa-envelope"></i> Unread updates</div>
    </a>
</section>

<section class="premium-dashboard-grid">
    <div class="premium-panel premium-glass">
        <div class="premium-panel-header">
            <h2 class="premium-panel-title"><i class="fas fa-bolt"></i> Quick Actions</h2>
        </div>
        <div class="quick-actions">
            <a href="request-certificate.php"><i class="fas fa-certificate"></i> Certificate</a>
            <a href="request-blessing.php"><i class="fas fa-hands-praying"></i> Blessing</a>
            <a href="request-service.php"><i class="fas fa-church"></i> Sacramental Service</a>
            <a href="view-schedule.php"><i class="fas fa-calendar-days"></i> Schedule</a>
            <a href="ai-assistant.php"><i class="fas fa-robot"></i> Ask TUGON AI</a>
        </div>
    </div>

    <div class="premium-panel premium-glass">
        <div class="premium-panel-header">
            <h2 class="premium-panel-title"><i class="fas fa-robot"></i> AI Quick Insights</h2>
            <a href="ai-assistant.php" class="premium-btn secondary" style="min-height:34px; padding:6px 12px; font-size:.82rem;">Open</a>
        </div>
        <?php if ($request_counts['pending'] > 0): ?>
            <p class="text-muted mb-3">You have <?php echo intval($request_counts['pending']); ?> pending parish transaction<?php echo ($request_counts['pending'] === 1) ? '' : 's'; ?>. The assistant can explain next steps and help you search related records.</p>
        <?php else: ?>
            <p class="text-muted mb-3">No pending transactions are waiting right now. The assistant can still guide you through certificates, blessings, sacramental services, and schedules.</p>
        <?php endif; ?>
        <div class="ai-command-card">
            <strong>Ask TUGON AI</strong>
            <span>Get guidance for requests, sacramental services, schedules, and parish announcements.</span>
            <a class="premium-btn primary" href="ai-assistant.php"><i class="fas fa-wand-magic-sparkles"></i> Open Assistant</a>
        </div>
    </div>
</section>

<section class="premium-dashboard-grid mt-3">
    <div class="premium-panel premium-glass">
        <div class="premium-panel-header">
            <h2 class="premium-panel-title"><i class="fas fa-bullhorn"></i> Latest Announcements</h2>
            <a href="announcements.php" class="premium-btn secondary" style="min-height:34px; padding:6px 12px; font-size:.82rem;">View All</a>
        </div>
        <?php if (!empty($announcements)): ?>
            <div class="timeline">
                <?php foreach ($announcements as $announcement): ?>
                    <a class="timeline-item active text-decoration-none" href="announcements.php">
                        <span class="timeline-dot"></span>
                        <div>
                            <strong><?php echo e($announcement['title']); ?></strong><br>
                            <small class="text-muted"><?php echo e(ucfirst($announcement['type'])); ?> - <?php echo formatDate($announcement['published_date']); ?></small>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No active announcements right now.</p>
        <?php endif; ?>
    </div>

    <div class="premium-panel premium-glass">
        <div class="premium-panel-header">
            <h2 class="premium-panel-title"><i class="fas fa-list-check"></i> Recent Requests</h2>
            <a href="my-requests.php" class="premium-btn secondary" style="min-height:34px; padding:6px 12px; font-size:.82rem;">View All</a>
        </div>
        <?php if (!empty($recent_requests)): ?>
            <div class="premium-table-wrap">
                <table class="premium-admin-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_requests as $request): ?>
                            <tr onclick="window.location.href='view-request.php?id=<?php echo intval($request['request_id']); ?>'" style="cursor:pointer;">
                                <td><strong><?php echo e($request['reference_number']); ?></strong></td>
                                <td><?php echo e(ucfirst(str_replace('_', ' ', $request['request_type']))); ?></td>
                                <td><span class="premium-status <?php echo e($request['status']); ?>"><?php echo e(ucfirst($request['status'])); ?></span></td>
                                <td><a href="view-request.php?id=<?php echo intval($request['request_id']); ?>" class="premium-btn secondary" style="min-height:32px; padding:5px 10px; font-size:.78rem;">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">You have not submitted a request yet.</p>
        <?php endif; ?>
    </div>
</section>

<?php include '../templates/footer.php'; ?>
