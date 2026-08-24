<?php
/**
 * Manual Certificate Generator - Creates certificate previews from temporary manual input only.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('certificates.manage');

$page_title = 'Manual Certificate Generator';
$certificate_types = [
    'baptism' => 'Baptism Certificate',
    'baptism_certification' => 'Baptismal Certification',
    'confirmation' => 'Confirmation Certificate',
    'confirmation_certification' => 'Confirmation Certification',
    'communion' => 'First Holy Communion Certificate',
    'first_communion_certification' => 'First Communion Certification',
    'marriage' => 'Marriage Certificate',
    'marriage_certification' => 'Marriage Certification',
    'funeral_certification' => 'Funeral Certification',
    'other' => 'Other Parish Certificate'
];

$field_labels = [
    'fullname' => 'Full Name',
    'birth_date' => 'Date of Birth',
    'birth_place' => 'Place of Birth',
    'parents' => "Parents' Names",
    'father_name' => "Father's Name",
    'mother_name' => "Mother's Name",
    'godparents' => 'Sponsors / Godparents',
    'godfather' => 'Godfather',
    'godmother' => 'Godmother',
    'baptism_date' => 'Date of Baptism',
    'confirmation_date' => 'Date of Confirmation',
    'communion_date' => 'Date of First Communion',
    'wedding_date' => 'Date of Marriage',
    'ceremony_place' => 'Place of Ceremony',
    'parish_name' => 'Parish Name',
    'priest' => 'Officiating Priest',
    'officiating_priest' => 'Officiating Priest',
    'bishop_priest' => 'Bishop / Confirming Priest',
    'sponsor' => 'Sponsor',
    'husband_name' => 'Husband / Groom',
    'husband_birth_date' => "Groom's Date of Birth",
    'husband_birth_place' => "Groom's Place of Birth",
    'husband_nationality' => "Groom's Nationality",
    'husband_status' => "Groom's Civil Status Before Marriage",
    'wife_name' => 'Wife / Bride',
    'wife_birth_date' => "Bride's Date of Birth",
    'wife_birth_place' => "Bride's Place of Birth",
    'wife_nationality' => "Bride's Nationality",
    'wife_status' => "Bride's Civil Status Before Marriage",
    'husband_parents' => "Husband's Parents",
    'wife_parents' => "Wife's Parents",
    'husband_residence' => "Husband's Residence",
    'wife_residence' => "Wife's Residence",
    'sponsors' => 'Sponsors / Witnesses',
    'certificate_number' => 'Certificate Number',
    'date_issued' => 'Date Issued',
    'purpose' => 'Purpose',
    'remarks' => 'Additional Remarks',
    'record_reference' => 'Book / Page / Entry Reference',
    'volume_no' => 'Volume Number',
    'book_no' => 'Book Number',
    'page_no' => 'Page Number',
    'entry_no' => 'Entry Number',
    'deceased_name' => 'Name of Deceased',
    'family_name' => 'Family Name',
    'date_of_death' => 'Date of Death',
    'date_of_burial' => 'Date of Burial',
    'civil_status' => 'Civil Status',
    'funeral_rites' => 'Funeral Rites',
    'cause_of_death' => 'Cause of Death',
    'place_of_burial' => 'Place of Burial',
    'minister' => 'Officiating Minister',
    'registry_no' => 'Registry Number'
];

$fields_by_type = [
    'baptism' => ['fullname', 'birth_date', 'birth_place', 'father_name', 'mother_name', 'baptism_date', 'ceremony_place', 'parish_name', 'priest', 'godfather', 'godmother', 'volume_no', 'page_no', 'entry_no', 'certificate_number', 'date_issued', 'purpose', 'remarks'],
    'baptism_certification' => ['fullname', 'birth_date', 'birth_place', 'father_name', 'mother_name', 'baptism_date', 'ceremony_place', 'parish_name', 'priest', 'godfather', 'godmother', 'volume_no', 'page_no', 'entry_no', 'certificate_number', 'date_issued', 'purpose', 'remarks'],
    'confirmation' => ['fullname', 'birth_date', 'birth_place', 'parents', 'sponsor', 'confirmation_date', 'ceremony_place', 'parish_name', 'bishop_priest', 'record_reference', 'certificate_number', 'date_issued', 'purpose', 'remarks'],
    'confirmation_certification' => ['fullname', 'birth_date', 'birth_place', 'father_name', 'mother_name', 'confirmation_date', 'ceremony_place', 'parish_name', 'bishop_priest', 'sponsor', 'volume_no', 'page_no', 'entry_no', 'certificate_number', 'date_issued', 'purpose', 'remarks'],
    'communion' => ['fullname', 'birth_date', 'birth_place', 'parents', 'sponsor', 'communion_date', 'ceremony_place', 'parish_name', 'priest', 'record_reference', 'certificate_number', 'date_issued', 'purpose', 'remarks'],
    'first_communion_certification' => ['fullname', 'birth_date', 'birth_place', 'father_name', 'mother_name', 'communion_date', 'ceremony_place', 'parish_name', 'priest', 'sponsor', 'volume_no', 'page_no', 'entry_no', 'certificate_number', 'date_issued', 'purpose', 'remarks'],
    'marriage' => ['husband_name', 'wife_name', 'wedding_date', 'ceremony_place', 'parish_name', 'officiating_priest', 'husband_parents', 'wife_parents', 'husband_residence', 'wife_residence', 'sponsors', 'record_reference', 'certificate_number', 'date_issued', 'purpose', 'remarks'],
    'marriage_certification' => ['husband_name', 'husband_birth_date', 'husband_birth_place', 'husband_nationality', 'husband_status', 'wife_name', 'wife_birth_date', 'wife_birth_place', 'wife_nationality', 'wife_status', 'wedding_date', 'ceremony_place', 'parish_name', 'officiating_priest', 'sponsors', 'volume_no', 'page_no', 'entry_no', 'certificate_number', 'date_issued', 'purpose', 'remarks'],
    'funeral_certification' => ['deceased_name', 'family_name', 'date_of_death', 'date_of_burial', 'civil_status', 'funeral_rites', 'cause_of_death', 'place_of_burial', 'minister', 'registry_no', 'parish_name', 'certificate_number', 'date_issued', 'purpose', 'remarks'],
    'other' => ['fullname', 'ceremony_place', 'parish_name', 'priest', 'certificate_number', 'date_issued', 'purpose', 'remarks']
];

$required_by_type = [
    'baptism' => ['fullname', 'baptism_date', 'priest', 'date_issued'],
    'baptism_certification' => ['fullname', 'baptism_date', 'priest', 'date_issued'],
    'confirmation' => ['fullname', 'confirmation_date', 'bishop_priest', 'date_issued'],
    'confirmation_certification' => ['fullname', 'confirmation_date', 'bishop_priest', 'date_issued'],
    'communion' => ['fullname', 'communion_date', 'priest', 'date_issued'],
    'first_communion_certification' => ['fullname', 'communion_date', 'priest', 'date_issued'],
    'marriage' => ['husband_name', 'wife_name', 'wedding_date', 'officiating_priest', 'date_issued'],
    'marriage_certification' => ['husband_name', 'wife_name', 'wedding_date', 'officiating_priest', 'date_issued'],
    'funeral_certification' => ['deceased_name', 'date_of_burial', 'minister', 'date_issued'],
    'other' => ['fullname', 'date_issued']
];

if (isset($_GET['new'])) {
    unset($_SESSION['manual_certificate'], $_SESSION['certificate_data'], $_SESSION['cert_type']);
}

$editing_data = !empty($_SESSION['manual_certificate']) && is_array($_SESSION['certificate_data'] ?? null) ? $_SESSION['certificate_data'] : [];
$selected_type = $_POST['cert_type'] ?? ($_SESSION['cert_type'] ?? 'baptism');
if (!isset($certificate_types[$selected_type])) {
    $selected_type = 'baptism';
}

$form_data = [];
foreach ($field_labels as $field => $label) {
    $form_data[$field] = $_POST[$field] ?? ($editing_data[$field] ?? '');
}
$form_data['date_issued'] = $form_data['date_issued'] ?: date('Y-m-d');
$form_data['parish_name'] = $form_data['parish_name'] ?: 'San Lorenzo Ruiz Mission Station';
$form_data['ceremony_place'] = $form_data['ceremony_place'] ?: 'Aleosan, Cotabato';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $missing = [];
    foreach ($required_by_type[$selected_type] as $field) {
        if (trim((string) ($_POST[$field] ?? '')) === '') {
            $missing[] = $field_labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
        }
    }

    if (!empty($missing)) {
        $error = 'Please complete required fields: ' . implode(', ', $missing) . '.';
    } else {
        $manual_data = [];
        foreach ($fields_by_type[$selected_type] as $field) {
            $manual_data[$field] = trim((string) ($_POST[$field] ?? ''));
        }

        $manual_data['manual_entry'] = true;
        $manual_data['status'] = 'manual';
        if ($selected_type === 'marriage' || $selected_type === 'marriage_certification') {
            $manual_data['fullname'] = trim($manual_data['husband_name'] . ' and ' . $manual_data['wife_name']);
            $manual_data['registry_no'] = $manual_data['record_reference'] ?? '';
            $manual_data['officiating_priest'] = $manual_data['officiating_priest'] ?? '';
        } else {
            $manual_data['registry_no'] = $manual_data['record_reference'] ?? '';
        }
        if ($selected_type === 'marriage_certification') {
            $manual_data['book_no'] = $manual_data['volume_no'] ?? '';
            $manual_data['registry_no'] = $manual_data['entry_no'] ?? '';
            $manual_data['husband_birth_origin'] = $manual_data['husband_birth_place'] ?? '';
            $manual_data['wife_birth_origin'] = $manual_data['wife_birth_place'] ?? '';
        }
        if ($selected_type === 'baptism' || $selected_type === 'baptism_certification') {
            $manual_data['parents'] = trim(($manual_data['father_name'] ?? '') . ' / ' . ($manual_data['mother_name'] ?? ''), " /\t\n\r\0\x0B");
            $manual_data['godparents'] = trim(($manual_data['godfather'] ?? '') . ' / ' . ($manual_data['godmother'] ?? ''), " /\t\n\r\0\x0B");
            $manual_data['book_no'] = $manual_data['volume_no'] ?? '';
            $manual_data['registry_no'] = $manual_data['entry_no'] ?? '';
        }
        if ($selected_type === 'confirmation' || $selected_type === 'confirmation_certification') {
            $manual_data['baptismal_place'] = $manual_data['birth_place'] ?? '';
        }
        if ($selected_type === 'confirmation_certification') {
            $manual_data['parents'] = trim(($manual_data['father_name'] ?? '') . ' / ' . ($manual_data['mother_name'] ?? ''), " /\t\n\r\0\x0B");
            $manual_data['book_no'] = $manual_data['volume_no'] ?? '';
            $manual_data['registry_no'] = $manual_data['entry_no'] ?? '';
        }
        if ($selected_type === 'communion') {
            $manual_data['parents'] = $manual_data['parents'] ?? '';
        }
        if ($selected_type === 'first_communion_certification') {
            $manual_data['parents'] = trim(($manual_data['father_name'] ?? '') . ' / ' . ($manual_data['mother_name'] ?? ''), " /\t\n\r\0\x0B");
            $manual_data['book_no'] = $manual_data['volume_no'] ?? '';
            $manual_data['registry_no'] = $manual_data['entry_no'] ?? '';
        }
        if ($selected_type === 'funeral_certification') {
            $manual_data['fullname'] = $manual_data['deceased_name'] ?? '';
            $manual_data['ceremony_place'] = $manual_data['place_of_burial'] ?? '';
        }

        $_SESSION['manual_certificate'] = true;
        $_SESSION['certificate_data'] = $manual_data;
        $_SESSION['cert_type'] = $selected_type;
        header('Location: view-certificate.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?> | San Lorenzo Ruiz Mission Station</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { background: #f3f5f8; }
        .manual-shell { max-width: 1180px; margin: 0 auto; padding: 28px 16px 44px; }
        .manual-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; margin-bottom: 20px; }
        .manual-header h1 { margin: 0; font-size: 1.75rem; font-weight: 850; color: #172033; }
        .manual-header p { margin: 6px 0 0; color: #64748b; max-width: 760px; }
        .manual-panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 16px 36px rgba(15, 23, 42, .08); }
        .manual-panel-header { padding: 18px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; gap: 12px; align-items: center; }
        .manual-panel-header strong { font-size: 1rem; color: #172033; }
        .manual-body { padding: 20px; }
        .manual-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .manual-field.full { grid-column: 1 / -1; }
        .manual-field.is-hidden { display: none; }
        .form-label span { color: #b42318; }
        .manual-actions { display: flex; justify-content: flex-end; gap: 10px; padding: 18px 20px; border-top: 1px solid #e5e7eb; background: #fafafa; }
        .manual-note { color: #475569; background: #eef6ff; border: 1px solid #bfdbfe; padding: 12px 14px; border-radius: 8px; margin-bottom: 18px; }
        .loading-dot { display: none; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.45); border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; }
        .is-generating .loading-dot { display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (max-width: 760px) {
            .manual-header { flex-direction: column; }
            .manual-grid { grid-template-columns: 1fr; }
            .manual-actions { flex-direction: column; }
            .manual-actions .btn { width: 100%; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/responsive-unified.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/responsive-unified.css'); ?>">
</head>
<body class="premium-admin church-theme">
    <div class="premium-admin-shell">
        <?php include '../includes/admin-sidebar.php'; ?>
        <main class="premium-admin-content" id="main-content" tabindex="-1">
        <div class="manual-shell">
        <div class="manual-header">
            <div>
                <h1><i class="fas fa-file-signature"></i> Manual Certificate Generator</h1>
                <p>Create a print-ready church certificate from temporary manual input. This page does not search parish records and does not save the entered certificate data.</p>
            </div>
            <a href="certificate-generator.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back to Generator</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i> <?php echo e($error); ?></div>
        <?php endif; ?>

        <section class="manual-panel">
            <div class="manual-panel-header">
                <strong>Certificate Information</strong>
                <span class="text-muted">Required fields are marked with <span class="text-danger">*</span></span>
            </div>
            <form method="POST" action="" id="manualCertificateForm">
                <?php echo csrfInput(); ?>
                <div class="manual-body">
                    <div class="manual-note">
                        <i class="fas fa-circle-info"></i>
                        Manual entries are used only for this preview session. Print, download, edit, or generate another certificate after previewing.
                    </div>

                    <div class="manual-grid">
                        <div class="manual-field full">
                            <label for="cert_type" class="form-label">Certificate Type <span>*</span></label>
                            <select class="form-select" id="cert_type" name="cert_type" required>
                                <?php foreach ($certificate_types as $type => $label): ?>
                                    <option value="<?php echo e($type); ?>" <?php echo $selected_type === $type ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php foreach ($field_labels as $field => $label): ?>
                            <?php
                                $is_long = in_array($field, ['parents', 'godparents', 'sponsors', 'remarks', 'purpose', 'record_reference', 'husband_parents', 'wife_parents', 'cause_of_death'], true);
                                $is_date = in_array($field, ['birth_date', 'husband_birth_date', 'wife_birth_date', 'baptism_date', 'confirmation_date', 'communion_date', 'wedding_date', 'date_of_death', 'date_of_burial', 'date_issued'], true);
                            ?>
                            <div class="manual-field <?php echo $is_long ? 'full' : ''; ?>" data-field="<?php echo e($field); ?>">
                                <label for="<?php echo e($field); ?>" class="form-label">
                                    <?php echo e($label); ?> <span data-required-marker="<?php echo e($field); ?>">*</span>
                                </label>
                                <?php if ($is_long): ?>
                                    <textarea class="form-control" id="<?php echo e($field); ?>" name="<?php echo e($field); ?>" rows="3"><?php echo e($form_data[$field]); ?></textarea>
                                <?php else: ?>
                                    <input type="<?php echo $is_date ? 'date' : 'text'; ?>" class="form-control" id="<?php echo e($field); ?>" name="<?php echo e($field); ?>" value="<?php echo e($form_data[$field]); ?>">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="manual-actions">
                    <a href="manual-certificate-generator.php?new=1" class="btn btn-outline-secondary"><i class="fas fa-rotate-left"></i> Clear Form</a>
                    <button type="submit" class="btn btn-primary" id="generateManualBtn">
                        <span class="loading-dot" aria-hidden="true"></span>
                        <i class="fas fa-file-pdf"></i> Generate Certificate
                    </button>
                </div>
            </form>
        </section>
        </div>
        </main>
    </div>

    <script>
        const fieldsByType = <?php echo json_encode($fields_by_type); ?>;
        const requiredByType = <?php echo json_encode($required_by_type); ?>;
        const typeSelect = document.getElementById('cert_type');
        const form = document.getElementById('manualCertificateForm');
        const generateButton = document.getElementById('generateManualBtn');

        function updateManualFields() {
            const type = typeSelect.value;
            const visibleFields = new Set(fieldsByType[type] || []);
            const requiredFields = new Set(requiredByType[type] || []);

            document.querySelectorAll('[data-field]').forEach((wrapper) => {
                const field = wrapper.getAttribute('data-field');
                const input = wrapper.querySelector('input, textarea');
                const marker = wrapper.querySelector('[data-required-marker]');
                const visible = visibleFields.has(field);
                wrapper.classList.toggle('is-hidden', !visible);
                if (input) {
                    input.disabled = !visible;
                    input.required = visible && requiredFields.has(field);
                }
                if (marker) {
                    marker.style.display = requiredFields.has(field) ? '' : 'none';
                }
            });
        }

        typeSelect.addEventListener('change', updateManualFields);
        form.addEventListener('submit', () => {
            generateButton.classList.add('is-generating');
            generateButton.disabled = true;
        });
        updateManualFields();
    </script>
</body>
</html>
