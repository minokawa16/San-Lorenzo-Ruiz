<?php
/**
 * Sacramental Records Hub
 * Central dashboard for accessing Baptism, First Communion, Confirmation, Marriage, and Funeral registries.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';

requireAdmin();
requirePermission('records.view');

function getSafeRecordCount($conn, $table) {
    if (!$conn) return 0;
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $res = @$conn->query("SELECT COUNT(*) AS c FROM `$table` WHERE LOWER(TRIM(status)) = 'active'");
    if ($res && ($row = $res->fetch_assoc())) {
        return (int)($row['c'] ?? 0);
    }
    $res = @$conn->query("SELECT COUNT(*) AS c FROM `$table`");
    if ($res && ($row = $res->fetch_assoc())) {
        return (int)($row['c'] ?? 0);
    }
    return 0;
}

$registries = [
    [
        'title' => 'Baptism Records',
        'icon' => 'fa-water',
        'href' => 'baptism-records.php',
        'count' => getSafeRecordCount($conn, 'baptism_records'),
        'description' => 'Official baptismal registry entries, certificates, and sacramental books.'
    ],
    [
        'title' => 'First Communion Records',
        'icon' => 'fa-wheat-awn',
        'href' => 'communion-records.php',
        'count' => getSafeRecordCount($conn, 'first_communion_records'),
        'description' => 'First Holy Communion registry entries and parish documentation.'
    ],
    [
        'title' => 'Confirmation Records',
        'icon' => 'fa-dove',
        'href' => 'confirmation-records.php',
        'count' => getSafeRecordCount($conn, 'confirmation_records'),
        'description' => 'Sacrament of Confirmation records, sponsors, and ministers.'
    ],
    [
        'title' => 'Marriage Records',
        'icon' => 'fa-ring',
        'href' => 'marriage-records.php',
        'count' => getSafeRecordCount($conn, 'marriage_records'),
        'description' => 'Matrimony records, marriage contracts, and solemnization registries.'
    ],
    [
        'title' => 'Funeral Records',
        'icon' => 'fa-cross',
        'href' => 'funeral-records.php',
        'count' => getSafeRecordCount($conn, 'funeral_records'),
        'description' => 'Funeral blessing and burial records registered in the parish.'
    ]
];

$total_records = array_sum(array_column($registries, 'count'));
$page_title = 'Sacramental Records';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Sacramental Records' => null
];

include '../templates/header.php';
?>

<style>
    .registry-hub-card {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        height: 100%;
        min-height: 180px;
        padding: 20px;
        border-radius: 14px;
        background: #ffffff;
        border: 1px solid rgba(28, 27, 24, 0.1);
        text-decoration: none;
        color: #1c1b18;
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
    }
    .registry-hub-card:hover {
        transform: translateY(-2px);
        border-color: #C59B27;
        box-shadow: 0 6px 16px rgba(197, 155, 39, 0.15);
        color: #1c1b18;
    }
    .registry-hub-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        background: #E8F0EA;
        color: #0E3321;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        border: 1px solid rgba(20, 61, 40, 0.2);
    }
    .registry-hub-count {
        font-size: 0.78rem;
        font-weight: 700;
        color: #8A6409;
        background: #FAF4E6;
        padding: 4px 12px;
        border-radius: 999px;
        border: 1px solid rgba(197, 155, 39, 0.25);
    }
    .registry-hub-card h2 {
        font-size: 1.1rem;
        font-weight: 700;
        color: #1e293b;
        margin: 14px 0 6px 0;
        font-family: 'Playfair Display', Georgia, serif;
    }
    .registry-hub-card p {
        font-size: 0.82rem;
        color: #64748b;
        line-height: 1.5;
        margin: 0;
    }
    .registry-hub-action {
        margin-top: 16px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.82rem;
        font-weight: 600;
        color: #8A6409;
    }
</style>

<div class="container-fluid px-0">

    <!-- Standardized Section Header -->
    <?php
    $page_header_title = 'Sacramental Records';
    $page_header_subtitle = 'Official parish registry for Baptism, First Communion, Confirmation, Marriage, and Funeral.';
    $page_header_icon = 'fa-book-bible';
    $show_back_button = true;
    $back_button_url = BASE_URL . 'admin/dashboard.php';
    include '../includes/page_header.php';
    ?>

    <!-- Registry Cards Grid -->
    <div class="row g-3">
        <?php foreach ($registries as $registry): ?>
            <div class="col-md-6 col-lg-4">
                <a href="<?php echo htmlspecialchars($registry['href']); ?>" class="registry-hub-card">
                    <div>
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="registry-hub-icon">
                                <i class="fas <?php echo htmlspecialchars($registry['icon']); ?>"></i>
                            </div>
                            <span class="registry-hub-count">
                                <?php echo number_format($registry['count']); ?> active
                            </span>
                        </div>
                        <h2><?php echo htmlspecialchars($registry['title']); ?></h2>
                        <p><?php echo htmlspecialchars($registry['description']); ?></p>
                    </div>
                    <div class="registry-hub-action">
                        <span>Open Registry</span> <i class="fas fa-arrow-right" style="font-size: 0.75rem;"></i>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<?php include '../templates/footer.php'; ?>
