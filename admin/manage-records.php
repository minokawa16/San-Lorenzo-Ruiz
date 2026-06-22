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
    define('BASE_URL', 'http://localhost/ParishSystem/');
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
    <style>
        :root {
            --records-navy: #111827;
            --records-gold: #d4af37;
            --records-muted: #64748b;
            --records-surface: rgba(255, 255, 255, 0.92);
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at 18% 12%, rgba(212, 175, 55, 0.16), transparent 28%),
                linear-gradient(135deg, #f5f7fa 0%, #e8eef5 100%);
            font-family: 'Inter', sans-serif;
        }

        .admin-content {
            margin-left: 280px;
            padding: 24px;
            transition: margin-left 0.3s;
        }

        body.admin-sidebar-collapsed .admin-content {
            margin-left: 108px;
        }

        .records-hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 20px;
            padding: 24px;
            margin-bottom: 20px;
            border-radius: 18px;
            color: #ffffff;
            background:
                radial-gradient(circle at 8% 10%, rgba(212, 175, 55, 0.32), transparent 26%),
                linear-gradient(135deg, rgba(17, 24, 39, 0.96), rgba(26, 31, 58, 0.92));
            box-shadow: 0 20px 46px rgba(17, 24, 39, 0.14);
        }

        .records-hero h1 {
            margin: 0;
            font-size: clamp(1.8rem, 3vw, 2.4rem);
            font-weight: 850;
            color: #ffffff;
        }

        .records-hero p {
            max-width: 820px;
            margin: 10px 0 0;
            color: rgba(255, 255, 255, 0.78);
            line-height: 1.65;
        }

        .records-total {
            min-width: 160px;
            padding: 16px;
            border-radius: 14px;
            text-align: center;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.16);
        }

        .records-total strong {
            display: block;
            font-size: 2rem;
            line-height: 1;
            color: #f4dc82;
        }

        .records-note {
            display: flex;
            gap: 12px;
            padding: 15px 16px;
            margin-bottom: 20px;
            border-radius: 14px;
            color: #334155;
            background: rgba(255, 255, 255, 0.82);
            border: 1px solid rgba(212, 175, 55, 0.22);
        }

        .records-note i {
            color: var(--records-gold);
            margin-top: 3px;
        }

        .registry-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .registry-card {
            display: grid;
            gap: 16px;
            min-height: 230px;
            padding: 22px;
            border-radius: 16px;
            color: var(--records-navy);
            background: var(--records-surface);
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 14px 34px rgba(17, 24, 39, 0.08);
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }

        .registry-card:hover,
        .registry-card:focus-visible {
            transform: translateY(-4px);
            border-color: rgba(212, 175, 55, 0.42);
            box-shadow: 0 22px 46px rgba(17, 24, 39, 0.13);
            outline: none;
        }

        .registry-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .registry-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--records-navy);
            background: linear-gradient(135deg, #fff8eb, #f4dc82, var(--records-gold));
            font-size: 1.45rem;
            box-shadow: 0 12px 24px rgba(212, 175, 55, 0.2);
        }

        .registry-count {
            padding: 7px 11px;
            border-radius: 999px;
            color: #7c5f12;
            background: rgba(212, 175, 55, 0.14);
            font-size: 0.82rem;
            font-weight: 800;
        }

        .registry-card h2 {
            margin: 0;
            color: var(--records-navy);
            font-size: 1.25rem;
            font-weight: 850;
        }

        .registry-card p {
            margin: 0;
            color: var(--records-muted);
            line-height: 1.58;
        }

        .registry-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: fit-content;
            margin-top: auto;
            padding: 10px 14px;
            border-radius: 999px;
            color: var(--records-navy);
            background: linear-gradient(135deg, #fff8eb, #f4dc82);
            font-weight: 850;
        }

        .certificate-link {
            margin-top: 22px;
            padding: 18px;
            border-radius: 16px;
            background: rgba(17, 24, 39, 0.92);
            color: rgba(255, 255, 255, 0.78);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .certificate-link strong {
            display: block;
            color: #ffffff;
            margin-bottom: 4px;
        }

        .certificate-link .btn {
            white-space: nowrap;
        }

        @media (max-width: 900px) {
            .admin-content {
                margin-left: 0;
                padding: 18px;
            }

            .records-hero,
            .certificate-link {
                grid-template-columns: 1fr;
                flex-direction: column;
                align-items: flex-start;
            }

            .registry-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="admin-content">
        <section class="records-hero">
            <div>
                <h1><i class="fas fa-book-bible"></i> Sacramental Records</h1>
                <p>
                    This area is the manual parish registry for parishioners who received Baptism, First Communion,
                    Confirmation, Marriage, and Funeral. Admins encode the record-book information here directly.
                </p>
            </div>
            <div class="records-total">
                <strong><?php echo $total_records; ?></strong>
                <span>Active Records</span>
            </div>
        </section>

        <div class="records-note">
            <i class="fas fa-circle-info"></i>
            <div>
                <strong>Records and certificates are separate.</strong>
                Add or update parish registry information here. Generate or print certificates only from the
                Generate Certificates area.
            </div>
        </div>

        <section class="registry-grid" aria-label="Manual sacramental registry sections">
            <?php foreach ($registries as $registry): ?>
                <a class="registry-card" href="<?php echo htmlspecialchars($registry['href']); ?>">
                    <div class="registry-card-header">
                        <span class="registry-icon"><i class="fas <?php echo htmlspecialchars($registry['icon']); ?>"></i></span>
                        <span class="registry-count"><?php echo $registry['count']; ?> active</span>
                    </div>
                    <div>
                        <h2><?php echo htmlspecialchars($registry['title']); ?></h2>
                        <p><?php echo htmlspecialchars($registry['description']); ?></p>
                    </div>
                    <span class="registry-action">
                        Open Manual Registry <i class="fas fa-arrow-right"></i>
                    </span>
                </a>
            <?php endforeach; ?>
        </section>

        <section class="certificate-link">
            <div>
                <strong><i class="fas fa-certificate"></i> Need to make a certificate?</strong>
                <span>Use the certificate module only for generating certificate documents. It does not replace manual record encoding.</span>
            </div>
            <a href="certificate-generator.php" class="btn btn-warning fw-bold">
                Go to Certificates
            </a>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/components.js"></script>
</body>
</html>
