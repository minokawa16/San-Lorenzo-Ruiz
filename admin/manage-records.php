<?php
/**
 * Sacramental Records Hub
 * Manual parish registry entry point for Baptism, First Communion, Confirmation, Marriage, and Funeral.
 */

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('records.manage');

if (!defined('BASE_URL')) {
    define('BASE_URL', '/ParishSystem/');
}

// Record Count Function - Documents this helper's role in the parish management workflow.
function record_count($conn, $table) {
    $safe_table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $result = $conn->query("SELECT COUNT(*) AS count FROM $safe_table WHERE status = 'active'");
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return (int)($row['count'] ?? 0);
}

$registries = [
    [
        'title' => 'Baptism Records',
        'description' => 'Manual parish registry for baptized parishioners, birth details, parents, sponsors, parish address, minister, and remarks.',
        'icon' => 'fa-water',
        'href' => 'baptism-records.php',
        'count' => record_count($conn, 'baptism_records')
    ],
    [
        'title' => 'First Communion Records',
        'description' => 'Manual record book for first communicants, domicile, parents, minister, folio, baptism details, and remarks.',
        'icon' => 'fa-bread-slice',
        'href' => 'communion-records.php',
        'count' => record_count($conn, 'first_communion_records')
    ],
    [
        'title' => 'Confirmation Records',
        'description' => 'Manual registry for confirmed parishioners, confirmation name, sponsor, date, and bishop or priest.',
        'icon' => 'fa-dove',
        'href' => 'confirmation-records.php',
        'count' => record_count($conn, 'confirmation_records')
    ],
    [
        'title' => 'Marriage Records',
        'description' => 'Manual registry for marriages, contracting parties, status, age, birth origin, residence, parents, witnesses, minister, and remarks.',
        'icon' => 'fa-ring',
        'href' => 'marriage-records.php',
        'count' => record_count($conn, 'marriage_records')
    ],
    [
        'title' => 'Funeral Records',
        'description' => 'Manual burial registry for deceased name, family name, death and burial dates, civil status, funeral rites, cause of death, burial place, minister, and remarks.',
        'icon' => 'fa-book-open',
        'href' => 'funeral-records.php',
        'count' => record_count($conn, 'funeral_records')
    ]
];

$total_records = array_sum(array_column($registries, 'count'));
$page_title = 'Sacramental Records - Parish Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/holy-theme.css">
    <link rel="stylesheet" href="../assets/css/premium-parish.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/premium-parish.css') ? filemtime(__DIR__ . '/../assets/css/premium-parish.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/parish-design-system.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/parish-design-system.css') ? filemtime(__DIR__ . '/../assets/css/parish-design-system.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/admin-sidebar.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/admin-sidebar.css') ? filemtime(__DIR__ . '/../assets/css/admin-sidebar.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
    <style>
        .sacramental-hub-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            padding: 16px 20px;
            background: #ffffff;
            border: 1px solid rgba(28, 27, 24, 0.08);
            border-radius: 12px;
            margin-bottom: 18px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
        }
        .sacramental-hub-heading h1 {
            font-size: 1.35rem;
            font-weight: 700;
            color: #1c1b18;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sacramental-hub-heading p {
            font-size: 0.84rem;
            color: #6b7280;
            margin: 3px 0 0 0;
        }
        .sacramental-stat-chip {
            background: rgba(200, 155, 60, 0.08);
            border: 1px solid rgba(200, 155, 60, 0.25);
            padding: 6px 16px;
            border-radius: 8px;
            text-align: right;
        }
        .sacramental-stat-chip strong {
            display: block;
            font-size: 1.25rem;
            font-weight: 700;
            color: #8c6427;
            line-height: 1.1;
        }
        .sacramental-stat-chip span {
            font-size: 0.72rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .sacramental-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            padding: 12px 18px;
            background: #ffffff;
            border: 1px solid rgba(28, 27, 24, 0.08);
            border-left: 4px solid #c89b3c;
            border-radius: 10px;
            margin-bottom: 18px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .sacramental-banner-text {
            font-size: 0.84rem;
            color: #374151;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sacramental-banner-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .registry-hub-card {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
            min-height: 180px;
            padding: 18px 20px;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid rgba(28, 27, 24, 0.1);
            text-decoration: none;
            color: #1c1b18;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
        }
        .registry-hub-card:hover {
            transform: translateY(-2px);
            border-color: #c89b3c;
            box-shadow: 0 6px 16px rgba(200, 155, 60, 0.12);
            color: #1c1b18;
        }
        .registry-hub-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: rgba(200, 155, 60, 0.12);
            color: #8c6427;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        .registry-hub-count {
            font-size: 0.78rem;
            font-weight: 700;
            color: #8c6427;
            background: rgba(200, 155, 60, 0.12);
            padding: 4px 10px;
            border-radius: 999px;
        }
        .registry-hub-card h2 {
            font-size: 1.05rem;
            font-weight: 700;
            color: #1c1b18;
            margin: 12px 0 6px 0;
        }
        .registry-hub-card p {
            font-size: 0.82rem;
            color: #6b7280;
            line-height: 1.5;
            margin: 0;
        }
        .registry-hub-action {
            margin-top: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #8c6427;
        }
    </style>
</head>
<body class="premium-admin">
    <div class="premium-admin-shell">
        <!-- Include Admin Sidebar -->
        <?php include '../includes/admin-sidebar.php'; ?>

        <!-- Main Content -->
        <div class="premium-admin-content pds-page-container">
            
            <!-- Hub Topbar -->
            <header class="sacramental-hub-topbar">
                <div class="sacramental-hub-heading">
                    <h1><i class="fas fa-book-bible" style="color: #c89b3c;"></i> Sacramental Records</h1>
                    <p>Official parish registry for Baptism, First Communion, Confirmation, Marriage, and Funeral.</p>
                </div>
                <div class="sacramental-stat-chip">
                    <strong><?php echo number_format($total_records); ?></strong>
                    <span>Active Records</span>
                </div>
            </header>

            <!-- Info & Actions Banner -->
            <div class="sacramental-banner">
                <div class="sacramental-banner-text">
                    <i class="fas fa-circle-info" style="color: #c89b3c; font-size: 1.1rem;"></i>
                    <span><strong>Records and certificates are separate.</strong> Add or update parish registry books here. To generate or release official certificates, use Certificates.</span>
                </div>
                <div class="sacramental-banner-actions">
                    <a class="btn btn-sm pds-btn pds-btn-ghost-outline" href="record-corrections.php">
                        <i class="fas fa-pen-to-square"></i> Corrections
                    </a>
                    <a class="btn btn-sm pds-btn pds-btn-ghost-outline" href="sacramental-import.php">
                        <i class="fas fa-file-csv"></i> CSV Import
                    </a>
                    <a class="btn btn-sm pds-btn pds-btn-primary-gold" href="certificate-generator.php">
                        <i class="fas fa-certificate"></i> Certificates
                    </a>
                </div>
            </div>

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

        </div><!-- /.premium-admin-content -->
    </div><!-- /.premium-admin-shell -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/components.js"></script>
</body>
</html>
