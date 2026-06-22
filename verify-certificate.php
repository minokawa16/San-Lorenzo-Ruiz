<?php
/**
 * Certificate Verification Module - Publicly validates issued certificates by verification code.
 */
include 'database/config.php';
include 'includes/helpers.php';

$code = trim($_GET['code'] ?? '');
$certificate = null;
$record = null;
$error = '';

if ($code === '') {
    $error = 'No verification code was provided.';
} else {
    $stmt = $conn->prepare("SELECT * FROM certificate_issuances WHERE verification_code = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $certificate = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$certificate) {
        $error = 'Certificate verification code was not found.';
    } else {
        $allowed_tables = [
            'baptism_records' => 'baptism_id',
            'first_communion_records' => 'communion_id',
            'confirmation_records' => 'confirmation_id'
        ];
        $table = $certificate['record_table'];
        if (isset($allowed_tables[$table])) {
            $id_column = $allowed_tables[$table];
            $record_id = intval($certificate['record_id']);
            $result = $conn->query("SELECT * FROM `$table` WHERE `$id_column` = $record_id LIMIT 1");
            if ($result) {
                $record = $result->fetch_assoc();
            }
        }
    }
}

// Verify Date Function - Documents this helper's role in the parish management workflow.
function verifyDate($value) {
    if (empty($value) || $value === '0000-00-00') {
        return 'N/A';
    }
    $time = strtotime($value);
    return $time ? date('F d, Y', $time) : 'N/A';
}

$status = $certificate['status'] ?? 'invalid';
$is_valid = $certificate && $status === 'valid';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Verification | San Lorenzo Ruiz Mission Station</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #f7f8fb, #e9eef5);
            color: #172033;
            padding: 24px;
        }
        .verify-card {
            width: min(720px, 100%);
            background: #fff;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            box-shadow: 0 22px 58px rgba(16, 24, 40, .14);
            overflow: hidden;
        }
        .verify-header {
            background: #101828;
            color: #fff;
            padding: 24px;
            display: flex;
            gap: 16px;
            align-items: center;
        }
        .verify-icon {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: <?php echo $is_valid ? '#12b76a' : '#f04438'; ?>;
            font-size: 24px;
            flex: 0 0 auto;
        }
        .verify-header h1 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 800;
        }
        .verify-body { padding: 24px; }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 7px 12px;
            font-weight: 800;
            background: <?php echo $is_valid ? '#ecfdf3' : '#fef3f2'; ?>;
            color: <?php echo $is_valid ? '#027a48' : '#b42318'; ?>;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 20px;
        }
        .detail {
            border: 1px solid #eaecf0;
            border-radius: 8px;
            padding: 12px;
            background: #fcfcfd;
        }
        .detail span {
            display: block;
            color: #667085;
            font-size: .82rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .detail strong {
            display: block;
            margin-top: 4px;
            color: #101828;
            word-break: break-word;
        }
        @media (max-width: 640px) {
            .detail-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <main class="verify-card">
        <header class="verify-header">
            <div class="verify-icon">
                <i class="fas <?php echo $is_valid ? 'fa-shield-check' : 'fa-triangle-exclamation'; ?>"></i>
            </div>
            <div>
                <h1>Certificate Verification</h1>
                <div>San Lorenzo Ruiz Mission Station, Alfonsan, Cotabato</div>
            </div>
        </header>
        <section class="verify-body">
            <?php if ($error): ?>
                <span class="status-pill"><i class="fas fa-circle-xmark"></i> Invalid Certificate</span>
                <p class="mt-3 mb-0"><?php echo e($error); ?></p>
            <?php else: ?>
                <span class="status-pill"><i class="fas fa-circle-check"></i> <?php echo e(ucfirst($status)); ?> Certificate</span>
                <div class="detail-grid">
                    <div class="detail"><span>Certificate Number</span><strong><?php echo e($certificate['certificate_number']); ?></strong></div>
                    <div class="detail"><span>Verification Code</span><strong><?php echo e($certificate['verification_code']); ?></strong></div>
                    <div class="detail"><span>Certificate Type</span><strong><?php echo e(ucfirst(str_replace('_', ' ', $certificate['certificate_type']))); ?></strong></div>
                    <div class="detail"><span>Date Issued</span><strong><?php echo e(verifyDate($certificate['issued_at'])); ?></strong></div>
                    <div class="detail"><span>Name on Record</span><strong><?php echo e($record['fullname'] ?? $certificate['issued_to'] ?? 'N/A'); ?></strong></div>
                    <div class="detail"><span>Sacramental Date</span><strong><?php echo e(verifyDate($record['baptism_date'] ?? $record['communion_date'] ?? $record['confirmation_date'] ?? '')); ?></strong></div>
                </div>
                <p class="text-muted mt-3 mb-0">This verification confirms that the certificate reference exists in the Tugon Parish Management System. Any alteration to the printed document invalidates the certificate.</p>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
