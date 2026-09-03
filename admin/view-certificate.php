<?php
/**
 * Certificate Preview Module - Renders generated sacramental certificates for review, printing, and verification.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
include '../includes/CertificateTemplateManager.php';

requireAdmin();
requirePermission('certificates.manage');

if (!isset($_SESSION['certificate_data']) || !isset($_SESSION['cert_type'])) {
    header('Location: certificate-generator.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_certificate_details') {
    requireValidCsrfToken();
    if (isset($_POST['purpose'])) {
        $_SESSION['certificate_data']['purpose'] = trim((string)$_POST['purpose']);
    }
    if (isset($_POST['date_issued']) && $_POST['date_issued'] !== '') {
        $_SESSION['certificate_data']['date_issued'] = trim((string)$_POST['date_issued']);
        $_SESSION['certificate_data']['issued_at'] = trim((string)$_POST['date_issued']);
    }
    if (isset($_POST['residence'])) {
        $_SESSION['certificate_data']['residence'] = trim((string)$_POST['residence']);
        $_SESSION['certificate_data']['domicile'] = trim((string)$_POST['residence']);
    }
    if (isset($_POST['husband_residence'])) {
        $_SESSION['certificate_data']['husband_residence'] = trim((string)$_POST['husband_residence']);
    }
    if (isset($_POST['wife_residence'])) {
        $_SESSION['certificate_data']['wife_residence'] = trim((string)$_POST['wife_residence']);
    }
    if (isset($_POST['parents'])) {
        $_SESSION['certificate_data']['parents'] = trim((string)$_POST['parents']);
    }
    if (isset($_POST['husband_parents'])) {
        $_SESSION['certificate_data']['husband_parents'] = trim((string)$_POST['husband_parents']);
    }
    if (isset($_POST['wife_parents'])) {
        $_SESSION['certificate_data']['wife_parents'] = trim((string)$_POST['wife_parents']);
    }
    if (isset($_POST['father_name'])) {
        $_SESSION['certificate_data']['father_name'] = trim((string)$_POST['father_name']);
    }
    if (isset($_POST['mother_name'])) {
        $_SESSION['certificate_data']['mother_name'] = trim((string)$_POST['mother_name']);
    }
    if (isset($_POST['volume_no'])) {
        $_SESSION['certificate_data']['volume_no'] = trim((string)$_POST['volume_no']);
        $_SESSION['certificate_data']['book_no'] = trim((string)$_POST['volume_no']);
    }
    if (isset($_POST['page_no'])) {
        $_SESSION['certificate_data']['page_no'] = trim((string)$_POST['page_no']);
    }
    if (isset($_POST['entry_no'])) {
        $_SESSION['certificate_data']['entry_no'] = trim((string)$_POST['entry_no']);
        $_SESSION['certificate_data']['registry_no'] = trim((string)$_POST['entry_no']);
    }
    if (isset($_POST['remarks'])) {
        $_SESSION['certificate_data']['remarks'] = trim((string)$_POST['remarks']);
    }
    header('Location: view-certificate.php');
    exit;
}

$data = $_SESSION['certificate_data'];
$cert_type = $_SESSION['cert_type'];
$is_manual_certificate = !empty($_SESSION['manual_certificate']);
$is_baptism_certification = $cert_type === 'baptism_certification';
$is_marriage_certification = $cert_type === 'marriage_certification';
$is_confirmation_certification = $cert_type === 'confirmation_certification';
$is_first_communion_certification = $cert_type === 'first_communion_certification';
$is_funeral_certification = $cert_type === 'funeral_certification';
$is_certification = $is_baptism_certification || $is_marriage_certification || $is_confirmation_certification || $is_first_communion_certification || $is_funeral_certification;

// Ensure Certificate Schema Function - Documents this helper's role in the parish management workflow.
function ensureCertificateSchema($conn) {
    return requireSchemaColumns($conn, 'certificate_issuances', [
        'certificate_id', 'certificate_type', 'record_table', 'record_id',
        'template_id', 'layout_snapshot', 'certificate_number', 'verification_code',
        'issued_by', 'issued_to', 'status', 'issued_at', 'updated_at'
    ], 'certificate issuance')
        && ensureCertificateTemplateSchema($conn)
        && requireSchemaColumns($conn, 'baptism_records', [
            'book_no', 'page_no', 'entry_no'
        ], 'baptism certificate registry');
}

// Certificate Record Meta Function - Documents this helper's role in the parish management workflow.
function certificateRecordMeta($cert_type) {
    if ($cert_type === 'baptism') {
        return ['table' => 'baptism_records', 'id' => 'baptism_id', 'prefix' => 'BAP', 'title' => 'CERTIFICATE OF BAPTISM'];
    }
    if ($cert_type === 'baptism_certification') {
        return ['table' => 'baptism_records', 'id' => 'baptism_id', 'prefix' => 'BCF', 'title' => 'CERTIFICATION OF BAPTISM'];
    }
    if ($cert_type === 'communion') {
        return ['table' => 'first_communion_records', 'id' => 'communion_id', 'prefix' => 'COM', 'title' => 'FIRST HOLY COMMUNION CERTIFICATE'];
    }
    if ($cert_type === 'first_communion_certification') {
        return ['table' => 'first_communion_records', 'id' => 'communion_id', 'prefix' => 'FCF', 'title' => 'CERTIFICATION OF FIRST HOLY COMMUNION'];
    }
    if ($cert_type === 'confirmation_certification') {
        return ['table' => 'confirmation_records', 'id' => 'confirmation_id', 'prefix' => 'CCF', 'title' => 'CERTIFICATION OF CONFIRMATION'];
    }
    if ($cert_type === 'marriage') {
        return ['table' => 'marriage_records', 'id' => 'marriage_id', 'prefix' => 'MAR', 'title' => 'CERTIFICATE OF MARRIAGE'];
    }
    if ($cert_type === 'marriage_certification') {
        return ['table' => 'marriage_records', 'id' => 'marriage_id', 'prefix' => 'MCF', 'title' => 'CERTIFICATION OF MARRIAGE'];
    }
    if ($cert_type === 'funeral_certification') {
        return ['table' => 'funeral_records', 'id' => 'funeral_id', 'prefix' => 'FNC', 'title' => 'FUNERAL CERTIFICATION'];
    }
    if ($cert_type === 'other') {
        return ['table' => 'manual_certificates', 'id' => 'manual_id', 'prefix' => 'GEN', 'title' => 'PARISH CERTIFICATE'];
    }
    return ['table' => 'confirmation_records', 'id' => 'confirmation_id', 'prefix' => 'CON', 'title' => 'CONFIRMATION CERTIFICATE'];
}

// Display Date Function - Documents this helper's role in the parish management workflow.
function displayDate($value, $format = 'F d, Y') {
    if (empty($value) || $value === '0000-00-00') {
        return 'N/A';
    }
    $time = strtotime($value);
    return $time ? date($format, $time) : 'N/A';
}

// Split Parents Function - Documents this helper's role in the parish management workflow.
function splitParents($parents) {
    $result = ['father' => 'N/A', 'mother' => 'N/A'];
    $parents = trim((string) $parents);
    if ($parents === '') {
        return $result;
    }

    if (preg_match('/father\s*[:\-]\s*(.+?)(?:\s*(?:mother|and)\s*[:\-]\s*|\s+\/\s+)(.+)$/i', $parents, $matches)) {
        $result['father'] = trim($matches[1]);
        $result['mother'] = trim($matches[2]);
        return $result;
    }

    $parts = preg_split('/\s+(?:and|&)\s+|\s*\/\s*|\s*,\s*/i', $parents);
    $parts = array_values(array_filter(array_map('trim', $parts)));
    if (count($parts) >= 2) {
        $result['father'] = $parts[0];
        $result['mother'] = $parts[1];
    } else {
        $result['father'] = $parents;
    }
    return $result;
}

// Site Base URL Function - Documents this helper's role in the parish management workflow.
function siteBaseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return appUrl();
}

function splitSponsors($sponsors) {
    $result = ['godfather' => 'N/A', 'godmother' => 'N/A'];
    $sponsors = trim((string) $sponsors);
    if ($sponsors === '') {
        return $result;
    }

    if (preg_match('/godfather\s*[:\-]\s*(.+?)(?:\s*(?:godmother|and)\s*[:\-]\s*|\s+\/\s+)(.+)$/i', $sponsors, $matches)) {
        $result['godfather'] = trim($matches[1]);
        $result['godmother'] = trim($matches[2]);
        return $result;
    }

    $parts = preg_split('/\s+(?:and|&)\s+|\s*\/\s*|\s*,\s*/i', $sponsors);
    $parts = array_values(array_filter(array_map('trim', $parts)));
    if (count($parts) >= 2) {
        $result['godfather'] = $parts[0];
        $result['godmother'] = $parts[1];
    } else {
        $result['godfather'] = $sponsors;
    }
    return $result;
}

function certificateAssetUrl($relative_path, $fallback = '') {
    $root = dirname(__DIR__);
    $path = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative_path);
    if (is_file($path)) {
        return '../' . str_replace('\\', '/', $relative_path);
    }
    return $fallback;
}

$meta = certificateRecordMeta($cert_type);
if ($is_manual_certificate) {
    $manual_number = trim((string) ($data['certificate_number'] ?? ''));
    $issue = [
        'certificate_id' => 0,
        'certificate_type' => $cert_type,
        'record_table' => 'manual_entry',
        'record_id' => 0,
        'certificate_number' => $manual_number !== '' ? $manual_number : strtoupper($meta['prefix'] . '-' . date('Ymd-His')),
        'verification_code' => 'MANUAL-CERTIFICATE',
        'issued_by' => intval($_SESSION['user_id'] ?? 0),
        'issued_to' => $data['fullname'] ?? ($data['deceased_name'] ?? trim(($data['husband_name'] ?? '') . ' and ' . ($data['wife_name'] ?? ''))),
        'status' => 'manual',
        'issued_at' => $data['date_issued'] ?? date('Y-m-d'),
        'template_id' => null,
        'layout_snapshot' => null
    ];
} else {
    ensureCertificateSchema($conn);
    // Preview is deliberately non-persistent. Number, token, snapshot, PDF and
    // record lock are created only by CertificateService::issue().
    $issue = [
        'certificate_id' => 0,
        'certificate_type' => $cert_type,
        'record_table' => $meta['table'],
        'record_id' => (int)($data[$meta['id']] ?? 0),
        'certificate_number' => 'PREVIEW - NOT ISSUED',
        'verification_code' => '',
        'issued_by' => (int)($_SESSION['user_id'] ?? 0),
        'issued_to' => $data['fullname'] ?? ($data['deceased_name'] ?? trim(($data['husband_name'] ?? '') . ' and ' . ($data['wife_name'] ?? ''))),
        'status' => 'draft-preview',
        'issued_at' => date('Y-m-d'),
        'template_id' => null,
        'layout_snapshot' => null,
    ];
}
$parents = splitParents($data['parents'] ?? '');
$sponsors = splitSponsors($data['godparents'] ?? ($data['sponsors'] ?? ''));
$father_name = trim((string) ($data['father_name'] ?? '')) ?: $parents['father'];
$mother_name = trim((string) ($data['mother_name'] ?? '')) ?: $parents['mother'];
$godfather = trim((string) ($data['godfather'] ?? '')) ?: $sponsors['godfather'];
$godmother = trim((string) ($data['godmother'] ?? '')) ?: $sponsors['godmother'];
$volume_no = trim((string) ($data['volume_no'] ?? '')) ?: (trim((string) ($data['book_no'] ?? '')) ?: (trim((string) ($data['folio'] ?? '')) ?: 'N/A'));
$page_no = trim((string) ($data['page_no'] ?? '')) ?: 'N/A';
$entry_no = trim((string) ($data['entry_no'] ?? '')) ?: (trim((string) ($data['registry_no'] ?? '')) ?: (trim((string) ($data['baptism_id'] ?? ($data['communion_id'] ?? ($data['confirmation_id'] ?? '')))) ?: 'N/A'));
$issued_timestamp = strtotime($issue['issued_at'] ?? date('Y-m-d')) ?: time();
$issued_day = date('jS', $issued_timestamp);
$issued_month = date('F', $issued_timestamp);
$issued_year = date('Y', $issued_timestamp);
$birth_timestamp = strtotime($data['birth_date'] ?? '');
$birth_day = $birth_timestamp ? date('jS', $birth_timestamp) : 'N/A';
$birth_month = $birth_timestamp ? date('F', $birth_timestamp) : 'N/A';
$birth_year = $birth_timestamp ? date('Y', $birth_timestamp) : 'N/A';
$baptism_timestamp = strtotime($data['baptism_date'] ?? '');
$baptism_day = $baptism_timestamp ? date('jS', $baptism_timestamp) : 'N/A';
$baptism_month = $baptism_timestamp ? date('F', $baptism_timestamp) : 'N/A';
$baptism_year = $baptism_timestamp ? date('Y', $baptism_timestamp) : 'N/A';
$communion_timestamp = strtotime($data['communion_date'] ?? '');
$communion_day = $communion_timestamp ? date('jS', $communion_timestamp) : 'N/A';
$communion_month = $communion_timestamp ? date('F', $communion_timestamp) : 'N/A';
$communion_year = $communion_timestamp ? date('Y', $communion_timestamp) : 'N/A';
$confirmation_timestamp = strtotime($data['confirmation_date'] ?? '');
$confirmation_day = $confirmation_timestamp ? date('jS', $confirmation_timestamp) : 'N/A';
$confirmation_month = $confirmation_timestamp ? date('F', $confirmation_timestamp) : 'N/A';
$confirmation_year = $confirmation_timestamp ? date('Y', $confirmation_timestamp) : 'N/A';
$wedding_timestamp = strtotime($data['wedding_date'] ?? '');
$wedding_day = $wedding_timestamp ? date('jS', $wedding_timestamp) : 'N/A';
$wedding_month = $wedding_timestamp ? date('F', $wedding_timestamp) : 'N/A';
$wedding_year = $wedding_timestamp ? date('Y', $wedding_timestamp) : 'N/A';
$verification_url = (!$is_manual_certificate && !empty($issue['verification_code'])) ? siteBaseUrl() . 'verify-certificate.php?code=' . urlencode($issue['verification_code']) : '';
$page_title = ucfirst($cert_type) . ' Certificate';
$certificate_subject = $data['fullname'] ?? ($data['deceased_name'] ?? trim(($data['husband_name'] ?? '') . ' and ' . ($data['wife_name'] ?? '')));
$certificate_subject = $certificate_subject !== '' ? $certificate_subject : 'N/A';
$current_layout = getCertificateLayout($conn, $cert_type);
$certificate_layout_settings = $current_layout['settings'];
if (!empty($issue['layout_snapshot'])) {
    $snapshot = json_decode($issue['layout_snapshot'], true);
    if (is_array($snapshot)) {
        $certificate_layout_settings = mergeCertificateLayoutSettings($snapshot, defaultCertificateLayoutSettings($cert_type));
    }
}
$layout_text = $certificate_layout_settings['static_text'];
$layout_typography = $certificate_layout_settings['typography'];
$layout_border = $certificate_layout_settings['border'];
$layout_images = $certificate_layout_settings['images'];
$display_parish_name = trim((string) ($layout_text['parish_name'] ?? '')) ?: (trim((string) ($data['parish_name'] ?? '')) ?: 'SAN LORENZO RUIZ MISSION STATION');
$display_ceremony_place = trim((string) ($layout_text['parish_address'] ?? '')) ?: (trim((string) ($data['ceremony_place'] ?? '')) ?: 'ALEOSAN, COTABATO');
$archdiocese_logo = !empty($layout_images['diocese_logo']) ? certificateLayoutAssetUrl($layout_images['diocese_logo']) : certificateAssetUrl('assets/img/archdiocese-crest.jfif', certificateAssetUrl('assets/img/archdiocese-crest.jpg'));
$mission_logo = !empty($layout_images['parish_logo']) ? certificateLayoutAssetUrl($layout_images['parish_logo']) : certificateAssetUrl('assets/img/san-lorenzo-logo-final.jfif', certificateAssetUrl('assets/img/san-lorenzo-logo.png', '../church image.png'));
$parish_logo = $mission_logo;
$certificate_backgrounds = [
    'baptism' => certificateAssetUrl('baptism.webp', $parish_logo),
    'baptism_certification' => certificateAssetUrl('baptism.webp', $parish_logo),
    'confirmation' => certificateAssetUrl('confirmation.jfif', $parish_logo),
    'confirmation_certification' => certificateAssetUrl('confirmation.jfif', $parish_logo),
    'communion' => certificateAssetUrl('first communion.jpg', $parish_logo),
    'first_communion_certification' => certificateAssetUrl('first communion.jpg', $parish_logo),
    'marriage' => certificateAssetUrl('church image.png', $parish_logo),
    'marriage_certification' => certificateAssetUrl('church image.png', $parish_logo),
    'funeral_certification' => certificateAssetUrl('church image.png', $parish_logo),
    'other' => $parish_logo
];
$certificate_background = $certificate_backgrounds[$cert_type] ?? $parish_logo;
$certificate_template = null;

function certificateTemplateFileUrl($template) {
    if (!$template) {
        return '';
    }
    return 'certificate-template-file.php?id=' . intval($template['template_id']);
}

function renderCertificateTemplateLayer($template, $fallback_url, $cert_type) {
    $class_type = e($cert_type);
    if ($template) {
        $url = certificateTemplateFileUrl($template);
        if (strpos((string) $template['mime_type'], 'image/') === 0) {
            return '<img class="certificate-design-bg certificate-template-layer ' . $class_type . '" src="' . e($url) . '" alt="" aria-hidden="true">';
        }
        if ($template['mime_type'] === 'application/pdf') {
            return '<object class="certificate-pdf-template certificate-template-layer ' . $class_type . '" data="' . e($url) . '" type="application/pdf" aria-hidden="true"></object>';
        }
    }
    return '<img class="certificate-design-bg ' . $class_type . '" src="' . e($fallback_url) . '" alt="" aria-hidden="true">';
}
$certificate_template_layer = renderCertificateTemplateLayer($certificate_template, $certificate_background, $cert_type);
$certificate_template_is_pdf = $certificate_template && $certificate_template['mime_type'] === 'application/pdf';

function layoutCssValue($value, $fallback = '') {
    $value = trim((string) $value);
    return $value !== '' ? $value : $fallback;
}

function layoutElementStyle($settings, $key) {
    $pos = $settings['elements'][$key] ?? null;
    if (!$pos) {
        return '';
    }
    return 'left:' . floatval($pos['x']) . 'mm;top:' . floatval($pos['y']) . 'mm;width:' . floatval($pos['w']) . 'mm;height:' . floatval($pos['h']) . 'mm;opacity:' . floatval($pos['opacity']) . ';transform:rotate(' . floatval($pos['rotate']) . 'deg);';
}

function layoutImageTag($settings, $key, $class, $alt) {
    $path = $settings['images'][$key] ?? '';
    if ($path === '') {
        return '';
    }
    return '<img class="' . e($class) . '" src="' . e(certificateLayoutAssetUrl($path)) . '" alt="' . e($alt) . '">';
}

$layout_font_weight = !empty($layout_typography['bold']) ? '700' : layoutCssValue($layout_typography['font_weight'] ?? '', '700');
$layout_text_decoration = !empty($layout_typography['underline']) ? 'underline' : 'none';
$layout_font_style = !empty($layout_typography['italic']) ? 'italic' : 'normal';
$layout_border_width = !empty($layout_border['visible']) ? intval($layout_border['thickness'] ?? 2) . 'px' : '0';
$layout_border_style = layoutCssValue($layout_border['style'] ?? '', 'double');
$layout_border_color = layoutCssValue($layout_border['color'] ?? '', '#111111');
$layout_church_title = layoutCssValue($layout_text['church_title'] ?? '', 'ROMAN CATHOLIC CHURCH');
$layout_diocese_name = layoutCssValue($layout_text['diocese_name'] ?? '', 'ARCHDIOCESE OF COTABATO');
$layout_certificate_title = layoutCssValue($layout_text['certificate_title'] ?? '', $meta['title']);
$layout_certificate_subtitle = layoutCssValue($layout_text['certificate_subtitle'] ?? '', 'Issued from the Official Parish Records');
$layout_body_text = layoutCssValue($layout_text['body_text'] ?? '', '');
$layout_footer_text = layoutCssValue($layout_text['footer_text'] ?? '', 'Unauthorized alteration invalidates this certificate.');
$layout_watermark_text = layoutCssValue($layout_text['watermark_text'] ?? '', 'OFFICIAL PARISH DOCUMENT');
$layout_priest_name = layoutCssValue($data['parish_priest'] ?? '', layoutCssValue($layout_text['priest_name'] ?? '', 'REV. FR. ROGELIO C. CAALIM, OMJ'));
$layout_priest_position = layoutCssValue($layout_text['priest_position'] ?? '', 'Parish Priest');
$layout_secretary_name = layoutCssValue($data['parish_secretary'] ?? '', layoutCssValue($layout_text['secretary_name'] ?? '', 'PARISH SECRETARY'));
$layout_secretary_position = layoutCssValue($layout_text['secretary_position'] ?? '', 'Parish Secretary');
if (strcasecmp($layout_priest_position, 'Mission Station Priest') === 0) {
    $layout_priest_position = 'Parish Priest';
}
if (strcasecmp($layout_secretary_position, 'Signature / Parish Stamp') === 0) {
    $layout_secretary_position = 'Parish Secretary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --ink: <?php echo e(layoutCssValue($layout_typography['font_color'] ?? '', '#151515')); ?>;
            --muted: #4c4c4c;
            --line: <?php echo e($layout_border_color); ?>;
            --accent-line: <?php echo e($layout_border_color); ?>;
            --cert-width: 152.4mm;
            --cert-height: 228.6mm;
            --layout-font-family: "<?php echo e(layoutCssValue($layout_typography['font_family'] ?? '', 'Times New Roman')); ?>", Georgia, serif;
            --layout-font-size: <?php echo floatval($layout_typography['font_size'] ?? 8.5); ?>pt;
            --layout-font-weight: <?php echo e($layout_font_weight); ?>;
            --layout-font-style: <?php echo e($layout_font_style); ?>;
            --layout-text-decoration: <?php echo e($layout_text_decoration); ?>;
            --layout-text-align: <?php echo e(layoutCssValue($layout_typography['text_align'] ?? '', 'center')); ?>;
            --layout-letter-spacing: <?php echo floatval($layout_typography['letter_spacing'] ?? 0); ?>pt;
            --layout-line-height: <?php echo floatval($layout_typography['line_height'] ?? 1.2); ?>;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { background: #eef1f5; color: var(--ink); }
        .cert-toolbar { max-width: 900px; margin: 18px auto; display: flex; justify-content: space-between; gap: 12px; align-items: center; }
        .cert-toolbar h1 { font-size: 1.2rem; margin: 0; font-weight: 800; }
        .certificate-page { width: var(--cert-width); height: var(--cert-height); margin: 0 auto 24px; background: #fff; padding: 4mm; box-shadow: 0 18px 42px rgba(15, 23, 42, .18); overflow: hidden; }
        .certificate-sheet {
            height: 100%;
            border: <?php echo e($layout_border_width . ' ' . $layout_border_style . ' ' . $layout_border_color); ?>;
            padding: 4mm 5.5mm 5mm;
            position: relative;
            overflow: hidden;
            font-family: var(--layout-font-family);
            font-size: var(--layout-font-size);
            font-weight: var(--layout-font-weight);
            font-style: var(--layout-font-style);
            text-decoration: var(--layout-text-decoration);
            text-align: var(--layout-text-align);
            letter-spacing: var(--layout-letter-spacing);
            line-height: var(--layout-line-height);
            box-shadow: inset 0 0 0 1mm rgba(0, 0, 0, .06);
            background:
                linear-gradient(var(--accent-line), var(--accent-line)) left 4mm top 4mm / 18mm 1px no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) left 4mm top 4mm / 1px 18mm no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) right 4mm top 4mm / 18mm 1px no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) right 4mm top 4mm / 1px 18mm no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) left 4mm bottom 4mm / 18mm 1px no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) left 4mm bottom 4mm / 1px 18mm no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) right 4mm bottom 4mm / 18mm 1px no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) right 4mm bottom 4mm / 1px 18mm no-repeat,
                #ffffff;
        }
        .certificate-sheet::before { content: ""; position: absolute; inset: 2.4mm; border: 1px solid var(--line); outline: 1px solid rgba(0, 0, 0, .22); outline-offset: 1.2mm; pointer-events: none; z-index: 2; }
        <?php if (empty($layout_border['decorative_corners'])): ?>
        .certificate-sheet { background: #ffffff; }
        <?php endif; ?>
        <?php if (empty($layout_border['visible'])): ?>
        .certificate-sheet::before { display: none; }
        <?php endif; ?>
        .certificate-design-bg { position: absolute; left: 50%; top: 55%; width: 104mm; height: 150mm; transform: translate(-50%, -50%); object-fit: contain; object-position: center; opacity: .12; filter: saturate(.9) contrast(1.05); pointer-events: none; z-index: 0; }
        .certificate-template-layer.certificate-design-bg { inset: 0; left: 0; top: 0; width: 100%; height: 100%; transform: none; object-fit: cover; opacity: 1; filter: none; }
        .certificate-pdf-template { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; opacity: 1; pointer-events: none; z-index: 0; background: #fff; }
        .certificate-design-bg.baptism { width: 98mm; height: 160mm; opacity: .13; }
        .certificate-design-bg.confirmation { width: 104mm; height: 164mm; top: 56%; opacity: .12; }
        .certificate-design-bg.communion { width: 116mm; height: 116mm; top: 53%; opacity: .14; }
        .certificate-design-bg.marriage, .certificate-design-bg.other { width: 98mm; height: 98mm; opacity: .08; }
        .certificate-template-layer.certificate-design-bg.baptism,
        .certificate-template-layer.certificate-design-bg.confirmation,
        .certificate-template-layer.certificate-design-bg.communion,
        .certificate-template-layer.certificate-design-bg.marriage,
        .certificate-template-layer.certificate-design-bg.other { inset: 0; left: 0; top: 0; width: 100%; height: 100%; transform: none; opacity: 1; }
        .watermark-text { position: absolute; top: 132mm; left: -20mm; right: -20mm; text-align: center; transform: rotate(-29deg); font-size: 23px; font-weight: 900; letter-spacing: 5px; color: rgba(0,0,0,.045); pointer-events: none; z-index: 1; }
        .cert-content { position: relative; z-index: 3; }
        .cert-header { display: grid; grid-template-columns: 20mm 1fr 20mm; align-items: center; gap: 2.5mm; text-align: center; margin-bottom: 2.5mm; min-height: 23mm; }
        .certificate-logo-slot { display: flex; align-items: center; justify-content: center; min-width: 0; }
        .certificate-logo { width: 17mm; height: 17mm; object-fit: contain; object-position: center; display: block; background: transparent; }
        .certificate-logo.archdiocese-logo { width: 21mm; height: 21mm; }
        .seal { width: 24mm; height: 24mm; border: 1.5px solid #111; border-radius: 50%; object-fit: cover; padding: 1.5mm; background: #fff; }
        .seal-emblem { margin: 0 auto; display: flex; align-items: center; justify-content: center; flex-direction: column; font-size: 6px; line-height: 1.05; font-weight: 900; text-align: center; }
        .seal-emblem i { font-size: 15px; margin-bottom: 1mm; color: #6f1d1b; }
        .diocese { font-size: 11px; font-weight: 900; letter-spacing: .2px; line-height: 1.05; }
        .parish { font-size: 8.5px; font-weight: 800; margin-top: 1mm; letter-spacing: 0; }
        .location { font-size: 8px; font-weight: 700; margin-top: 1mm; }
        .cert-title { text-align: center; font-weight: 900; font-size: 13px; letter-spacing: .3px; text-decoration: underline; margin: 1.5mm 0 0; line-height: 1.05; }
        .cert-subline { margin-top: .8mm; color: #24436a; font-size: 6.9px; font-weight: 700; letter-spacing: .18px; }
        .cert-meta-row { display: grid; grid-template-columns: 1fr 1fr; gap: 3mm; margin: 2mm auto 2.4mm; max-width: 90mm; font-size: 7.4px; }
        .cert-meta-row div { border-bottom: 1px solid rgba(17,17,17,.35); padding-bottom: .6mm; }
        .cert-meta-row strong { color: #203a5c; }
        .certification-body { max-width: 96mm; margin: 0 auto; font-size: 8.4px; line-height: 1.35; text-align: justify; }
        .certification-body p { margin: 0 0 1.6mm; }
        .certification-body strong { color: #111; }
        .field-sections { max-width: 96mm; margin: 2mm auto 0; display: grid; gap: 1.4mm; }
        .field-section { border: 1px solid rgba(32, 58, 92, .35); background: rgba(255,255,255,.42); padding: 1.6mm 2mm; }
        .field-section-title { margin: 0 0 .8mm; color: #203a5c; font-size: 7.1px; font-weight: 900; letter-spacing: .2px; text-transform: uppercase; }
        .field-grid { display: grid; grid-template-columns: 21mm 1fr 21mm 1fr; gap: .7mm 1.5mm; font-size: 7.15px; }
        .field-grid .label { color: #333; font-weight: 800; text-align: right; }
        .field-grid .value { min-height: 3.6mm; border-bottom: 1px dotted rgba(17,17,17,.42); font-weight: 700; }
        .registry-strip { max-width: 96mm; margin: 1.5mm auto 0; display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.3mm; font-size: 7.2px; }
        .registry-strip div { border: 1px solid rgba(17,17,17,.58); background: rgba(255,255,255,.44); padding: 1.2mm; text-align: center; }
        .registry-strip strong { display: block; color: #203a5c; font-size: 6.7px; text-transform: uppercase; }
        .issued-line { max-width: 96mm; margin: 2.2mm auto 0; font-size: 7.9px; text-align: center; }
        .recommendation-form { max-width: 96mm; margin: 1.5mm auto 0; font-size: 8.7px; line-height: 1.25; }
        .recommendation-heading { text-align: center; font-weight: 900; margin: 1.4mm 0; text-transform: uppercase; font-size: 9px; }
        .form-line { display: flex; align-items: end; gap: 1.2mm; margin-bottom: 1mm; }
        .form-line .prompt { color: #106aa3; font-weight: 900; white-space: nowrap; }
        .form-line .fill { flex: 1; min-height: 4mm; border-bottom: 1px solid #333; text-align: center; font-weight: 800; padding: 0 .8mm .3mm; }
        .form-line .fill.small { flex: 0 0 16mm; }
        .form-line .fill.medium { flex: 0 0 28mm; }
        .form-line .plain { font-weight: 800; white-space: nowrap; }
        .registry-line-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 2mm; margin: 2mm 0 1.2mm; }
        .registry-line-item { display: flex; align-items: end; gap: 1mm; }
        .registry-line-item .prompt { color: #106aa3; font-weight: 900; white-space: nowrap; }
        .registry-line-item .fill { flex: 1; border-bottom: 1px solid #333; text-align: center; font-weight: 800; min-height: 4mm; }
        .recommendation-purpose { display: grid; grid-template-columns: auto 1fr auto 1fr; gap: 1mm; align-items: end; margin-top: 1.4mm; }
        .recommendation-purpose .prompt { color: #106aa3; font-weight: 900; }
        .recommendation-purpose .fill { border-bottom: 1px solid #333; text-align: center; font-weight: 800; min-height: 4mm; }
        .seal-signature-row { max-width: 96mm; margin: 3mm auto 0; display: grid; grid-template-columns: 20mm 1fr 1fr; gap: 3.5mm; align-items: end; }
        .official-seal-area { width: 22mm; height: 22mm; border: 1px dashed #777; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 6.2px; color: #555; background: rgba(255,255,255,.32); }
        .certified-block { text-align: center; font-size: 7.2px; display: grid; grid-template-rows: 10mm auto 4mm; align-items: end; }
        .certified-label { text-align: center; font-weight: 800; align-self: start; }
        .certified-line { border-bottom: 1px solid #111; padding-bottom: .35mm; font-weight: 900; min-height: 0; display: flex; align-items: flex-end; justify-content: center; line-height: 1.15; }
        .certified-block span { display: block; margin-top: .4mm; font-size: 6.7px; color: #333; align-self: start; }
        .recipient { text-align: center; font-size: 12.5px; font-weight: 900; letter-spacing: .25px; text-transform: uppercase; margin-bottom: 2mm; }
        .statement { max-width: 82mm; margin: 0 auto 2mm; text-align: center; font-size: 9.2px; line-height: 1.22; }
        .details { display: grid; grid-template-columns: 23mm 1fr; column-gap: 2.2mm; row-gap: .8mm; max-width: 82mm; margin: 0 auto; font-size: 8.7px; }
        .details .label { font-weight: 700; color: #333; text-align: right; }
        .details .value { border-bottom: 1px dotted #999; min-height: 12px; font-weight: 700; }
        .church-line { text-align: center; margin: 2mm auto 1mm; font-weight: 800; font-size: 9px; max-width: 80mm; }
        .roman { display: block; font-size: 11px; font-weight: 950; letter-spacing: .35px; margin-top: .3mm; }
        .minister { text-align: center; margin: 1mm 0 2mm; font-size: 8.5px; }
        .minister strong { display: block; font-size: 10px; font-style: italic; text-decoration: underline; }
        .lower-grid { display: grid; grid-template-columns: 1fr; gap: 2mm; margin-top: 2mm; }
        .sponsors, .remarks, .auth-box { font-size: 8.3px; }
        .sponsors strong, .remarks strong, .auth-box strong { font-size: 8.7px; }
        .sponsor-lines { white-space: pre-line; border-bottom: 1px dotted #bbb; min-height: 10mm; padding-top: .7mm; }
        .registry-box { border: 1px solid #222; padding: 1.8mm; margin-top: 2mm; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1.1mm; font-size: 7.5px; }
        .issued { text-align: right; font-size: 8px; margin-top: 0; }
        .qr-row { display: flex; align-items: center; justify-content: flex-end; gap: 2mm; margin-top: 1.5mm; }
        .seal-area { width: 22mm; height: 14mm; border: 1px dashed #777; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 6.6px; color: #555; margin-left: auto; }
        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 7mm; margin-top: 4mm; align-items: end; }
        .signature { text-align: center; font-size: 7.5px; }
        .signature-line { border-bottom: 1px solid #111; padding-bottom: .35mm; font-weight: 900; min-height: 0; line-height: 1.15; }
        .signature span { display: block; font-size: 6.8px; color: #333; font-weight: 500; margin-top: .3mm; }
        .certificate-number { position: absolute; top: 4mm; right: 5mm; font-family: Arial, sans-serif; font-size: 7px; font-weight: 800; }
        .layout-watermark-image { position: absolute; left: 50%; top: 55%; width: 104mm; height: 120mm; transform: translate(-50%, -50%); object-fit: contain; pointer-events: none; z-index: 1; opacity: .14; }
        .verification-code { position: absolute; bottom: 2.8mm; left: 5mm; right: 5mm; font-family: Arial, sans-serif; font-size: 6.4px; display: flex; justify-content: space-between; gap: 2mm; color: #333; z-index: 3; }
        .simple-preview {
            width: var(--cert-width);
            min-height: var(--cert-height);
            margin: 0 auto 24px;
            position: relative;
            overflow: hidden;
            background:
                linear-gradient(var(--accent-line), var(--accent-line)) left 6mm top 6mm / 22mm 1px no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) left 6mm top 6mm / 1px 22mm no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) right 6mm top 6mm / 22mm 1px no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) right 6mm top 6mm / 1px 22mm no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) left 6mm bottom 6mm / 22mm 1px no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) left 6mm bottom 6mm / 1px 22mm no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) right 6mm bottom 6mm / 22mm 1px no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) right 6mm bottom 6mm / 1px 22mm no-repeat,
                #ffffff;
            padding: 6mm;
            border: 2px double #111;
            outline: 1px solid rgba(0, 0, 0, .22);
            outline-offset: -3mm;
            font-family: Georgia, serif;
            text-align: center;
            box-shadow: 0 18px 42px rgba(15, 23, 42, .18), inset 0 0 0 1mm rgba(0, 0, 0, .06);
        }
        .simple-preview > :not(.certificate-design-bg) { position: relative; z-index: 1; }
        .simple-preview .cert-header { max-width: 760px; margin: 0 auto 26px; }
        .confirmation-page { width: var(--cert-width); height: var(--cert-height); margin: 0 auto 24px; background: #fff; padding: 4mm; box-shadow: 0 18px 42px rgba(15, 23, 42, .18); overflow: hidden; }
        .confirmation-sheet {
            height: 100%;
            border: 2px double #1f2933;
            padding: 4mm 5.5mm 5mm;
            position: relative;
            overflow: hidden;
            font-family: "Times New Roman", Georgia, serif;
            box-shadow: inset 0 0 0 1mm rgba(0, 0, 0, .06);
            background:
                linear-gradient(var(--accent-line), var(--accent-line)) left 4mm top 4mm / 18mm 1px no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) left 4mm top 4mm / 1px 18mm no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) right 4mm top 4mm / 18mm 1px no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) right 4mm top 4mm / 1px 18mm no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) left 4mm bottom 4mm / 18mm 1px no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) left 4mm bottom 4mm / 1px 18mm no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) right 4mm bottom 4mm / 18mm 1px no-repeat,
                linear-gradient(var(--accent-line), var(--accent-line)) right 4mm bottom 4mm / 1px 18mm no-repeat,
                #ffffff;
        }
        .confirmation-sheet::before { content: ""; position: absolute; inset: 2.8mm; border: 1px solid #1f2933; outline: 1px solid rgba(0, 0, 0, .22); outline-offset: 1.2mm; pointer-events: none; z-index: 2; }
        .confirmation-content { position: relative; z-index: 3; min-height: 184mm; display: flex; flex-direction: column; }
        .confirmation-header { display: grid; grid-template-columns: 20mm 1fr 18mm; gap: 2mm; align-items: center; text-align: center; margin-top: 1mm; min-height: 23mm; }
        .confirmation-logo { width: 16mm; height: 16mm; object-fit: contain; object-position: center; display: block; background: transparent; }
        .confirmation-logo.archdiocese-logo { width: 20mm; height: 20mm; }
        .confirmation-seal { width: 18mm; height: 18mm; border: 1px solid #1a1a1a; border-radius: 50%; object-fit: cover; background: #fff; padding: 1mm; }
        .confirmation-seal.seal-emblem { width: 18mm; height: 18mm; font-size: 4.4px; line-height: 1.05; color: #1a1a1a; }
        .confirmation-seal.seal-emblem i { font-size: 10px; color: #7a1d1d; margin-bottom: .5mm; }
        .confirmation-diocese { font-size: 10px; font-weight: 900; letter-spacing: .1px; line-height: 1.05; }
        .confirmation-parish { font-size: 8px; font-weight: 900; margin-top: .8mm; }
        .confirmation-location { font-size: 7.5px; font-weight: 700; margin-top: .8mm; }
        .confirmation-title { font-size: 9px; font-weight: 950; letter-spacing: .2px; text-decoration: underline; margin-top: 1mm; }
        .confirmation-name { margin-top: 12mm; text-align: center; font-size: 12px; font-weight: 900; letter-spacing: .2px; text-decoration: underline; text-transform: uppercase; }
        .confirmation-facts { width: 66mm; margin: 5mm auto 0; font-size: 8.3px; line-height: 1.18; }
        .confirmation-facts .rowline { display: grid; grid-template-columns: 20mm 1fr; }
        .confirmation-facts strong { font-weight: 900; }
        .confirmation-rite { margin: 4mm auto 0; text-align: center; font-size: 9.5px; line-height: 1.15; }
        .confirmation-rite strong { display: block; font-size: 11px; font-weight: 950; letter-spacing: .35px; }
        .confirmation-event { margin-top: 1.5mm; text-align: center; font-size: 8.8px; line-height: 1.15; }
        .confirmation-event .minister-name { font-size: 9.2px; font-weight: 900; color: #14385e; }
        .confirmation-note { width: 70mm; margin: 1.5mm auto 0; text-align: left; font-size: 7.6px; line-height: 1.08; color: #454545; }
        .confirmation-registry { width: 70mm; margin: 1.5mm auto 0; display: grid; grid-template-columns: repeat(4, 1fr); gap: .8mm; font-size: 6.8px; color: #222; }
        .confirmation-purpose { width: 70mm; margin: 1mm auto 0; font-size: 7.6px; font-weight: 900; }
        .confirmation-issue { width: 42mm; margin: 7mm 8mm 0 auto; font-size: 7px; line-height: 1.2; }
        .confirmation-issue .issuer { margin-top: 2mm; text-align: center; }
        .confirmation-issue .issuer strong { display: block; border-bottom: 1px solid #333; font-size: 8.8px; }
        .confirmation-signature { margin: auto auto 3mm; width: 64mm; text-align: center; font-size: 7.5px; }
        .confirmation-signature strong { display: block; border-bottom: 1px solid #1f2933; padding-bottom: .6mm; font-size: 8.8px; letter-spacing: .1px; }
        .confirmation-signature span { display: block; margin-top: .6mm; font-size: 6.8px; }
        .confirmation-left-line, .confirmation-right-line { position: absolute; top: 60mm; bottom: 19mm; width: 1px; background: #1f2933; opacity: .75; }
        .confirmation-left-line { left: 5mm; }
        .confirmation-right-line { right: 5mm; }
        @page { size: 6in 9in; margin: 0; }
        @media print {
            html, body { background: #fff !important; margin: 0 !important; padding: 0 !important; width: 100% !important; height: auto !important; }
            .cert-toolbar, .cert-toolbar *, .alert, .alert-warning, .btn, button, nav, footer { display: none !important; visibility: hidden !important; height: 0 !important; margin: 0 !important; padding: 0 !important; border: 0 !important; }
            .certificate-page { width: var(--cert-width) !important; height: var(--cert-height) !important; margin: 0 auto !important; padding: 0 !important; box-shadow: none !important; page-break-before: avoid !important; page-break-after: avoid !important; page-break-inside: avoid !important; break-inside: avoid !important; transform: none !important; }
            .certificate-sheet { height: 100% !important; page-break-inside: avoid !important; break-inside: avoid !important; }
            .simple-preview { width: var(--cert-width) !important; min-height: var(--cert-height) !important; margin: 0 auto !important; box-shadow: none !important; page-break-before: avoid !important; page-break-after: avoid !important; page-break-inside: avoid !important; break-inside: avoid !important; }
            .confirmation-page { width: var(--cert-width) !important; height: var(--cert-height) !important; margin: 0 auto !important; padding: 0 !important; box-shadow: none !important; page-break-before: avoid !important; page-break-after: avoid !important; }
            .confirmation-sheet { height: 100%; }
        }
        @media (max-width: 900px) {
            .certificate-page, .simple-preview { transform: none; width: var(--cert-width); max-width: none; margin-left: 12px; margin-right: 12px; }
            .confirmation-page { transform: scale(.82); transform-origin: top center; margin-bottom: -35mm; }
            .cert-toolbar { padding: 0 14px; align-items: flex-start; flex-direction: column; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/responsive-unified.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/responsive-unified.css'); ?>">
</head>
<body>
    <div class="cert-toolbar">
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Certificate</button>
            <?php if ($is_certification): ?>
                <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#editPurposeModal"><i class="fas fa-pen-to-square"></i> Edit Purpose & Details</button>
            <?php endif; ?>
            <?php if (!$is_manual_certificate && !empty($verification_url)): ?>
                <a class="btn btn-outline-dark" href="<?php echo e($verification_url); ?>" target="_blank"><i class="fas fa-shield-check"></i> Verify Certificate</a>
            <?php endif; ?>
            <?php if ($is_manual_certificate): ?>
                <a href="manual-certificate-generator.php" class="btn btn-outline-secondary"><i class="fas fa-sliders"></i> Full Edit</a>
                <a href="manual-certificate-generator.php?new=1" class="btn btn-outline-success"><i class="fas fa-plus"></i> Generate Another</a>
            <?php endif; ?>
            <a href="certificate-generator.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </div>

    <?php if ($cert_type === 'baptism' || $cert_type === 'baptism_certification'): ?>
        <main class="certificate-page" id="certificateDocument">
            <section class="certificate-sheet">
                <?php echo $certificate_template_layer; ?>
                <?php echo layoutImageTag($certificate_layout_settings, 'watermark', 'layout-watermark-image', 'Certificate watermark'); ?>
                <div class="watermark-text"><?php echo e($layout_watermark_text); ?></div>
                <div class="certificate-number"><?php echo e($issue['certificate_number']); ?></div>
                <div class="cert-content">
                    <header class="cert-header">
                        <div class="certificate-logo-slot">
                            <?php if ($archdiocese_logo): ?>
                                <img class="certificate-logo archdiocese-logo" src="<?php echo e($archdiocese_logo); ?>" alt="Official Archdiocese of Cotabato crest">
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="parish"><?php echo e(strtoupper($layout_church_title)); ?></div>
                            <div class="diocese"><?php echo e(strtoupper($layout_diocese_name)); ?></div>
                            <div class="parish"><?php echo e(strtoupper($display_parish_name)); ?></div>
                            <div class="location"><?php echo e(strtoupper($display_ceremony_place)); ?></div>
                            <div class="cert-title"><?php echo e($layout_certificate_title); ?></div>
                            <div class="cert-subline"><?php echo e($layout_certificate_subtitle); ?></div>
                        </div>
                        <div class="certificate-logo-slot">
                            <img class="certificate-logo" src="<?php echo e($mission_logo); ?>" alt="San Lorenzo Ruiz Mission Station logo">
                        </div>
                    </header>

                    <?php if ($is_baptism_certification): ?>
                        <div class="cert-meta-row">
                            <div><strong>Certificate No.:</strong> <?php echo e($issue['certificate_number']); ?></div>
                            <div><strong>Date Issued:</strong> <?php echo e(displayDate($issue['issued_at'] ?? date('Y-m-d'))); ?></div>
                        </div>

                        <div class="recommendation-form">
                            <div class="recommendation-heading">This is to certify</div>
                            <div class="form-line">
                                <span class="prompt">That</span>
                                <span class="fill"><?php echo e($data['fullname'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="form-line">
                                <span class="prompt">Child of</span>
                                <span class="fill"><?php echo e($father_name); ?></span>
                            </div>
                            <div class="form-line">
                                <span class="prompt">and</span>
                                <span class="fill"><?php echo e($mother_name); ?></span>
                            </div>
                            <div class="form-line">
                                <span class="prompt">born on the</span>
                                <span class="fill small"><?php echo e($birth_day); ?></span>
                                <span class="plain">day of</span>
                                <span class="fill medium"><?php echo e($birth_month); ?></span>
                                <span class="plain"><?php echo e($birth_year); ?></span>
                            </div>
                            <div class="form-line">
                                <span class="prompt">in</span>
                                <span class="fill"><?php echo e($data['birth_place'] ?? 'N/A'); ?></span>
                            </div>

                            <div class="recommendation-heading">
                                Was solemnly baptized<br>
                                <span style="font-size: 7.7px;">according to the rite of the Roman Catholic Church</span>
                            </div>

                            <div class="form-line">
                                <span class="prompt">on the</span>
                                <span class="fill small"><?php echo e($baptism_day); ?></span>
                                <span class="plain">day of</span>
                                <span class="fill medium"><?php echo e($baptism_month); ?></span>
                                <span class="plain"><?php echo e($baptism_year); ?></span>
                            </div>
                            <div class="form-line">
                                <span class="prompt">by the Rev. Fr.</span>
                                <span class="fill"><?php echo e($data['priest'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="form-line">
                                <span class="prompt">the Sponsors being</span>
                                <span class="fill"><?php echo e(trim($godfather . ' / ' . $godmother, " /\t\n\r\0\x0B") ?: 'N/A'); ?></span>
                            </div>
                            <div class="form-line">
                                <span class="prompt">at</span>
                                <span class="fill"><?php echo e($display_parish_name . ', ' . $display_ceremony_place); ?></span>
                            </div>

                            <div class="recommendation-heading" style="font-size: 8.2px;">
                                as appears from the Book of Baptism
                            </div>

                            <div class="registry-line-grid">
                                <div class="registry-line-item"><span class="prompt">Vol. No.</span><span class="fill"><?php echo e($volume_no); ?></span></div>
                                <div class="registry-line-item"><span class="prompt">Page</span><span class="fill"><?php echo e($page_no); ?></span></div>
                                <div class="registry-line-item"><span class="prompt">Entry No.</span><span class="fill"><?php echo e($entry_no); ?></span></div>
                                <div class="registry-line-item"><span class="prompt">Year</span><span class="fill"><?php echo e($baptism_year); ?></span></div>
                            </div>

                            <div class="recommendation-purpose">
                                <span class="prompt">This is issued upon request for</span>
                                <span class="fill"><?php echo e($data['purpose'] ?? 'whatever lawful purpose it may serve'); ?></span>
                                <span class="prompt">this</span>
                                <span class="fill"><?php echo e($issued_day . ' day of ' . $issued_month . ', ' . $issued_year); ?></span>
                            </div>
                            <div class="form-line" style="margin-top: 1mm;">
                                <span class="prompt">in the Lord</span>
                                <span class="fill"><?php echo e($display_ceremony_place); ?></span>
                            </div>
                            <?php if (!empty($data['remarks'])): ?>
                                <div class="form-line">
                                    <span class="prompt">Remarks</span>
                                    <span class="fill"><?php echo e($data['remarks']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="seal-signature-row">
                            <div class="official-seal-area"><?php echo layoutImageTag($certificate_layout_settings, 'official_seal', 'certificate-logo', 'Official seal') ?: 'Official<br>Parish Seal<br>Dry Seal'; ?></div>
                            <div class="certified-block">
                                <div class="certified-label">Certified Correct:</div>
                                <div class="certified-line"><?php echo layoutImageTag($certificate_layout_settings, 'priest_signature', 'certificate-logo', 'Priest signature') . e($layout_priest_name); ?></div>
                                <span><?php echo e($layout_priest_position); ?></span>
                            </div>
                            <div class="certified-block">
                                <div class="certified-label">By Authority:</div>
                                <div class="certified-line"><?php echo layoutImageTag($certificate_layout_settings, 'secretary_signature', 'certificate-logo', 'Secretary signature') . e($layout_secretary_name); ?></div>
                                <span><?php echo e($layout_secretary_position); ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="recipient"><?php echo e($data['fullname'] ?? 'N/A'); ?></div>

                        <p class="statement">
                            This is to certify that <?php echo $is_manual_certificate ? 'the above-named person was solemnly baptized' : 'according to the records of this parish, the above-named person was solemnly baptized'; ?> according to the rites of the
                            <strong>ROMAN CATHOLIC CHURCH</strong>.
                        </p>

                        <div class="details">
                            <div class="label">Full Name:</div><div class="value"><?php echo e($data['fullname'] ?? 'N/A'); ?></div>
                            <div class="label">Date of Birth:</div><div class="value"><?php echo e(displayDate($data['birth_date'] ?? '')); ?></div>
                            <div class="label">Place of Birth:</div><div class="value"><?php echo e($data['birth_place'] ?? 'N/A'); ?></div>
                            <div class="label">Date of Baptism:</div><div class="value"><?php echo e(displayDate($data['baptism_date'] ?? '')); ?></div>
                            <div class="label">Book No.:</div><div class="value"><?php echo e($volume_no); ?></div>
                            <div class="label">Page No.:</div><div class="value"><?php echo e($page_no); ?></div>
                            <div class="label">Father:</div><div class="value"><?php echo e($father_name); ?></div>
                            <div class="label">Mother:</div><div class="value"><?php echo e($mother_name); ?></div>
                            <div class="label">Residence:</div><div class="value"><?php echo e($data['parent_address'] ?? $data['parish_address'] ?? 'N/A'); ?></div>
                        </div>

                        <div class="church-line">
                            Was Solemnly Baptized according to the Rites of the
                            <span class="roman">ROMAN CATHOLIC CHURCH</span>
                        </div>
                        <div class="minister">
                            By:
                            <strong><?php echo e($data['priest'] ?? 'N/A'); ?></strong>
                            Minister / Celebrant
                        </div>

                        <div class="lower-grid">
                            <div>
                                <div class="sponsors">
                                    <strong>Sponsors / Godparents:</strong>
                                    <div class="sponsor-lines"><?php echo e($data['godparents'] ?? trim($godfather . ' / ' . $godmother, " /\t\n\r\0\x0B") ?: 'N/A'); ?></div>
                                </div>
                                <div class="registry-box">
                                    <div><strong>Book No.</strong><br><?php echo e($volume_no); ?></div>
                                    <div><strong>Page No.</strong><br><?php echo e($page_no); ?></div>
                                    <div><strong>Entry No.</strong><br><?php echo e($entry_no); ?></div>
                                    <div><strong>Baptism Date</strong><br><?php echo e(displayDate($data['baptism_date'] ?? '', 'm/d/Y')); ?></div>
                                    <div><strong>Reference</strong><br><?php echo e($issue['certificate_number']); ?></div>
                                    <div><strong>Status</strong><br><?php echo e(ucfirst($issue['status'])); ?></div>
                                </div>
                                <div class="remarks mt-2">
                                    <strong>Remarks:</strong> <?php echo e($data['remarks'] ?? 'Issued for parish record purposes.'); ?>
                                </div>
                            </div>
                            <div class="auth-box">
                                <div class="issued">
                                    <strong>Date Issued:</strong><br><?php echo e(displayDate($issue['issued_at'] ?? date('Y-m-d'))); ?><br>
                                    <strong>Certificate No.:</strong><br><?php echo e($issue['certificate_number']); ?>
                                </div>
                                <div class="qr-row">
                                    <div class="seal-area">Official<br>Dry Seal<br>Area</div>
                                </div>
                            </div>
                        </div>

                        <div class="signature-grid">
                            <div class="signature">
                                <div class="signature-line"><?php echo layoutImageTag($certificate_layout_settings, 'priest_signature', 'certificate-logo', 'Priest signature') . e($layout_priest_name); ?></div>
                                <span><?php echo e($layout_priest_position); ?></span>
                            </div>
                            <div class="signature">
                                <div class="signature-line"><?php echo layoutImageTag($certificate_layout_settings, 'secretary_signature', 'certificate-logo', 'Secretary signature') . e($layout_secretary_name); ?></div>
                                <span><?php echo e($layout_secretary_position); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!$is_manual_certificate): ?>
                    <div class="verification-code">
                        <span>Verify: <?php echo e($verification_url); ?></span>
                        <span>Unauthorized alteration invalidates this certificate.</span>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    <?php elseif ($cert_type === 'communion'): ?>
        <main class="certificate-page" id="certificateDocument">
            <section class="certificate-sheet">
                <?php echo $certificate_template_layer; ?>
                <?php echo layoutImageTag($certificate_layout_settings, 'watermark', 'layout-watermark-image', 'Certificate watermark'); ?>
                <div class="watermark-text"><?php echo e($layout_watermark_text); ?></div>
                <div class="certificate-number"><?php echo e($issue['certificate_number']); ?></div>
                <div class="cert-content">
                    <header class="cert-header">
                        <div class="certificate-logo-slot">
                            <?php if ($archdiocese_logo): ?>
                                <img class="certificate-logo archdiocese-logo" src="<?php echo e($archdiocese_logo); ?>" alt="Official Archdiocese of Cotabato crest">
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="parish"><?php echo e(strtoupper($layout_church_title)); ?></div>
                            <div class="diocese"><?php echo e(strtoupper($layout_diocese_name)); ?></div>
                            <div class="parish"><?php echo e(strtoupper($display_parish_name)); ?></div>
                            <div class="location"><?php echo e(strtoupper($display_ceremony_place)); ?></div>
                            <div class="cert-title"><?php echo e($layout_certificate_title); ?></div>
                            <div class="cert-subline"><?php echo e($layout_certificate_subtitle); ?></div>
                        </div>
                        <div class="certificate-logo-slot">
                            <img class="certificate-logo" src="<?php echo e($mission_logo); ?>" alt="San Lorenzo Ruiz Mission Station logo">
                        </div>
                    </header>

                    <div class="recipient"><?php echo e($data['fullname'] ?? 'N/A'); ?></div>

                    <p class="statement">
                        This is to certify that <?php echo $is_manual_certificate ? 'the above-named person received First Holy Communion' : 'according to the records of this parish, the above-named person received First Holy Communion'; ?> according to the rite of the
                        <strong>ROMAN CATHOLIC CHURCH</strong>.
                    </p>

                    <div class="details">
                        <div class="label">Full Name:</div><div class="value"><?php echo e($data['fullname'] ?? 'N/A'); ?></div>
                        <div class="label">Date of Birth:</div><div class="value"><?php echo e(displayDate($data['birth_date'] ?? '')); ?></div>
                        <div class="label">Date of First Communion:</div><div class="value"><?php echo e(displayDate($data['communion_date'] ?? '')); ?></div>
                        <div class="label">Place:</div><div class="value"><?php echo e($display_parish_name . ', ' . $display_ceremony_place); ?></div>
                        <div class="label">Parents:</div><div class="value"><?php echo e($data['parents'] ?? 'N/A'); ?></div>
                        <div class="label">Residence:</div><div class="value"><?php echo e($data['domicile'] ?? 'N/A'); ?></div>
                        <div class="label">Baptismal Date:</div><div class="value"><?php echo e(displayDate($data['baptismal_date'] ?? '')); ?></div>
                        <div class="label">Baptismal Place:</div><div class="value"><?php echo e($data['baptismal_place'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="church-line">
                        Received First Holy Communion according to the Rite of the
                        <span class="roman">ROMAN CATHOLIC CHURCH</span>
                    </div>
                    <div class="minister">
                        Minister:
                        <strong><?php echo e($data['priest'] ?? 'N/A'); ?></strong>
                    </div>

                    <div class="lower-grid">
                        <div>
                            <div class="sponsors">
                                <strong>Sponsor:</strong>
                                <div class="sponsor-lines"><?php echo e($data['sponsor'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="registry-box">
                                <div><strong>Book/Folio</strong><br><?php echo e($volume_no); ?></div>
                                <div><strong>Page No.</strong><br><?php echo e($page_no); ?></div>
                                <div><strong>Entry No.</strong><br><?php echo e($entry_no); ?></div>
                                <div><strong>Communion Date</strong><br><?php echo e(displayDate($data['communion_date'] ?? '', 'm/d/Y')); ?></div>
                                <div><strong>Reference</strong><br><?php echo e($issue['certificate_number']); ?></div>
                                <div><strong>Status</strong><br><?php echo e(ucfirst($issue['status'])); ?></div>
                            </div>
                            <div class="remarks mt-2">
                                <strong>Remarks:</strong> <?php echo e($data['remarks'] ?? 'Issued for parish record purposes.'); ?>
                            </div>
                        </div>
                        <div class="auth-box">
                            <div class="issued">
                                <strong>Date Issued:</strong><br><?php echo e(displayDate($issue['issued_at'] ?? date('Y-m-d'))); ?><br>
                                <strong>Certificate No.:</strong><br><?php echo e($issue['certificate_number']); ?>
                            </div>
                            <div class="qr-row">
                                <div class="seal-area">Official<br>Dry Seal<br>Area</div>
                            </div>
                        </div>
                    </div>

                    <div class="signature-grid">
                        <div class="signature">
                            <div class="signature-line"><?php echo layoutImageTag($certificate_layout_settings, 'priest_signature', 'certificate-logo', 'Priest signature') . e($layout_priest_name); ?></div>
                            <span><?php echo e($layout_priest_position); ?></span>
                        </div>
                        <div class="signature">
                            <div class="signature-line"><?php echo layoutImageTag($certificate_layout_settings, 'secretary_signature', 'certificate-logo', 'Secretary signature') . e($layout_secretary_name); ?></div>
                            <span><?php echo e($layout_secretary_position); ?></span>
                        </div>
                    </div>
                </div>
                <?php if (!$is_manual_certificate): ?>
                    <div class="verification-code">
                        <span>Verify: <?php echo e($verification_url); ?></span>
                        <span>Unauthorized alteration invalidates this certificate.</span>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    <?php elseif (in_array($cert_type, ['marriage_certification', 'first_communion_certification', 'confirmation_certification', 'funeral_certification'], true)): ?>
        <?php
            $recommendation = [
                'event_heading' => '',
                'event_subheading' => 'according to the rite of the Roman Catholic Church',
                'book_label' => '',
                'event_day' => 'N/A',
                'event_month' => 'N/A',
                'event_year' => 'N/A',
                'minister_label' => 'by the Rev. Fr.',
                'minister_name' => 'N/A',
                'sponsor_label' => 'the Sponsors being',
                'sponsor_value' => 'N/A',
                'place_value' => $display_parish_name . ', ' . $display_ceremony_place,
                'extra_lines' => [],
            ];

            if ($cert_type === 'marriage_certification') {
                $recommendation['event_heading'] = 'Were solemnly united in Holy Matrimony';
                $recommendation['book_label'] = 'Marriage';
                $recommendation['event_day'] = $wedding_day;
                $recommendation['event_month'] = $wedding_month;
                $recommendation['event_year'] = $wedding_year;
                $recommendation['minister_name'] = $data['officiating_priest'] ?? 'N/A';
                $recommendation['sponsor_label'] = 'the Witnesses being';
                $recommendation['sponsor_value'] = $data['sponsors'] ?? 'N/A';
                $recommendation['extra_lines'] = [
                    ['prompt' => 'That', 'value' => $data['husband_name'] ?? 'N/A'],
                    ['prompt' => 'and', 'value' => $data['wife_name'] ?? 'N/A'],
                    ['prompt' => 'Residence', 'value' => trim(($data['husband_residence'] ?? '') . ' / ' . ($data['wife_residence'] ?? ''), " /\t\n\r\0\x0B") ?: 'N/A'],
                    ['prompt' => 'Parents', 'value' => trim(($data['husband_parents'] ?? '') . ' / ' . ($data['wife_parents'] ?? ''), " /\t\n\r\0\x0B") ?: 'N/A'],
                ];
            } elseif ($cert_type === 'first_communion_certification') {
                $recommendation['event_heading'] = 'Received First Holy Communion';
                $recommendation['book_label'] = 'First Holy Communion';
                $recommendation['event_day'] = $communion_day;
                $recommendation['event_month'] = $communion_month;
                $recommendation['event_year'] = $communion_year;
                $recommendation['minister_name'] = $data['priest'] ?? 'N/A';
                $recommendation['sponsor_label'] = 'the Sponsor being';
                $recommendation['sponsor_value'] = $data['sponsor'] ?? 'N/A';
                $recommendation['extra_lines'] = [
                    ['prompt' => 'That', 'value' => $data['fullname'] ?? 'N/A'],
                    ['prompt' => 'Child of', 'value' => $father_name],
                    ['prompt' => 'and', 'value' => $mother_name],
                    ['prompt' => 'born on the', 'value' => $birth_day . ' day of ' . $birth_month . ' ' . $birth_year],
                    ['prompt' => 'in', 'value' => $data['birth_place'] ?? $data['domicile'] ?? 'N/A'],
                    ['prompt' => 'Baptized at', 'value' => trim(displayDate($data['baptismal_date'] ?? '') . ' ' . ($data['baptismal_place'] ?? '')) ?: 'N/A'],
                ];
            } elseif ($cert_type === 'funeral_certification') {
                $burial_timestamp = strtotime($data['date_of_burial'] ?? '');
                $recommendation['event_heading'] = $data['funeral_rites'] ?? 'Received the Funeral Rites of the Roman Catholic Church';
                $recommendation['book_label'] = 'Funeral';
                $recommendation['event_day'] = $burial_timestamp ? date('jS', $burial_timestamp) : 'N/A';
                $recommendation['event_month'] = $burial_timestamp ? date('F', $burial_timestamp) : 'N/A';
                $recommendation['event_year'] = $burial_timestamp ? date('Y', $burial_timestamp) : 'N/A';
                $recommendation['minister_label'] = 'officiated by';
                $recommendation['minister_name'] = $data['minister'] ?? 'N/A';
                $recommendation['sponsor_label'] = 'Cause of death';
                $recommendation['sponsor_value'] = $data['cause_of_death'] ?? 'N/A';
                $recommendation['place_value'] = $data['place_of_burial'] ?? $display_ceremony_place;
                $recommendation['extra_lines'] = [
                    ['prompt' => 'That', 'value' => $data['deceased_name'] ?? 'N/A'],
                    ['prompt' => 'Family name', 'value' => $data['family_name'] ?? 'N/A'],
                    ['prompt' => 'Civil status', 'value' => $data['civil_status'] ?? 'N/A'],
                    ['prompt' => 'Died on', 'value' => displayDate($data['date_of_death'] ?? '')],
                ];
            } else {
                $recommendation['event_heading'] = 'Received the Sacrament of Confirmation';
                $recommendation['book_label'] = 'Confirmation';
                $recommendation['event_day'] = $confirmation_day;
                $recommendation['event_month'] = $confirmation_month;
                $recommendation['event_year'] = $confirmation_year;
                $recommendation['minister_name'] = $data['bishop_priest'] ?? 'N/A';
                $recommendation['sponsor_label'] = 'the Sponsor being';
                $recommendation['sponsor_value'] = $data['sponsor'] ?? 'N/A';
                $recommendation['extra_lines'] = [
                    ['prompt' => 'That', 'value' => $data['fullname'] ?? 'N/A'],
                    ['prompt' => 'Child of', 'value' => $father_name],
                    ['prompt' => 'and', 'value' => $mother_name],
                    ['prompt' => 'born on the', 'value' => $birth_day . ' day of ' . $birth_month . ' ' . $birth_year],
                    ['prompt' => 'in', 'value' => $data['birth_place'] ?? $data['baptismal_place'] ?? 'N/A'],
                    ['prompt' => 'Parish of Origin', 'value' => trim(($data['origin_parish'] ?? '') . ' ' . ($data['origin_province'] ?? '')) ?: 'N/A'],
                ];
            }
        ?>
        <main class="certificate-page" id="certificateDocument">
            <section class="certificate-sheet">
                <?php echo $certificate_template_layer; ?>
                <?php echo layoutImageTag($certificate_layout_settings, 'watermark', 'layout-watermark-image', 'Certificate watermark'); ?>
                <div class="watermark-text"><?php echo e($layout_watermark_text); ?></div>
                <div class="certificate-number"><?php echo e($issue['certificate_number']); ?></div>
                <div class="cert-content">
                    <header class="cert-header">
                        <div class="certificate-logo-slot">
                            <?php if ($archdiocese_logo): ?>
                                <img class="certificate-logo archdiocese-logo" src="<?php echo e($archdiocese_logo); ?>" alt="Official Archdiocese of Cotabato crest">
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="parish"><?php echo e(strtoupper($layout_church_title)); ?></div>
                            <div class="diocese"><?php echo e(strtoupper($layout_diocese_name)); ?></div>
                            <div class="parish"><?php echo e(strtoupper($display_parish_name)); ?></div>
                            <div class="location"><?php echo e(strtoupper($display_ceremony_place)); ?></div>
                            <div class="cert-title"><?php echo e($layout_certificate_title); ?></div>
                            <div class="cert-subline"><?php echo e($layout_certificate_subtitle); ?></div>
                        </div>
                        <div class="certificate-logo-slot">
                            <img class="certificate-logo" src="<?php echo e($mission_logo); ?>" alt="San Lorenzo Ruiz Mission Station logo">
                        </div>
                    </header>

                    <div class="cert-meta-row">
                        <div><strong>Certificate No.:</strong> <?php echo e($issue['certificate_number']); ?></div>
                        <div><strong>Date Issued:</strong> <?php echo e(displayDate($issue['issued_at'] ?? date('Y-m-d'))); ?></div>
                    </div>

                    <div class="recommendation-form">
                        <div class="recommendation-heading">This is to certify</div>
                        <?php foreach ($recommendation['extra_lines'] as $line): ?>
                            <div class="form-line">
                                <span class="prompt"><?php echo e($line['prompt']); ?></span>
                                <span class="fill"><?php echo e($line['value']); ?></span>
                            </div>
                        <?php endforeach; ?>

                        <div class="recommendation-heading">
                            <?php echo e($recommendation['event_heading']); ?><br>
                            <span style="font-size: 7.7px;"><?php echo e($recommendation['event_subheading']); ?></span>
                        </div>

                        <div class="form-line">
                            <span class="prompt">on the</span>
                            <span class="fill small"><?php echo e($recommendation['event_day']); ?></span>
                            <span class="plain">day of</span>
                            <span class="fill medium"><?php echo e($recommendation['event_month']); ?></span>
                            <span class="plain"><?php echo e($recommendation['event_year']); ?></span>
                        </div>
                        <div class="form-line">
                            <span class="prompt"><?php echo e($recommendation['minister_label']); ?></span>
                            <span class="fill"><?php echo e($recommendation['minister_name']); ?></span>
                        </div>
                        <div class="form-line">
                            <span class="prompt"><?php echo e($recommendation['sponsor_label']); ?></span>
                            <span class="fill"><?php echo e($recommendation['sponsor_value']); ?></span>
                        </div>
                        <div class="form-line">
                            <span class="prompt">at</span>
                            <span class="fill"><?php echo e($recommendation['place_value']); ?></span>
                        </div>

                        <div class="recommendation-heading" style="font-size: 8.2px;">
                            as appears from the Book of <?php echo e($recommendation['book_label']); ?>
                        </div>

                        <div class="registry-line-grid">
                            <div class="registry-line-item"><span class="prompt">Vol. No.</span><span class="fill"><?php echo e($volume_no); ?></span></div>
                            <div class="registry-line-item"><span class="prompt">Page</span><span class="fill"><?php echo e($page_no); ?></span></div>
                            <div class="registry-line-item"><span class="prompt">Entry No.</span><span class="fill"><?php echo e($entry_no); ?></span></div>
                            <div class="registry-line-item"><span class="prompt">Year</span><span class="fill"><?php echo e($recommendation['event_year']); ?></span></div>
                        </div>

                        <div class="recommendation-purpose">
                            <span class="prompt">This is issued upon request for</span>
                            <span class="fill"><?php echo e($data['purpose'] ?? 'whatever lawful purpose it may serve'); ?></span>
                            <span class="prompt">this</span>
                            <span class="fill"><?php echo e($issued_day . ' day of ' . $issued_month . ', ' . $issued_year); ?></span>
                        </div>
                        <div class="form-line" style="margin-top: 1mm;">
                            <span class="prompt">in the Lord</span>
                            <span class="fill"><?php echo e($display_ceremony_place); ?></span>
                        </div>
                        <?php if (!empty($data['remarks'])): ?>
                            <div class="form-line">
                                <span class="prompt">Remarks</span>
                                <span class="fill"><?php echo e($data['remarks']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="seal-signature-row">
                        <div class="official-seal-area"><?php echo layoutImageTag($certificate_layout_settings, 'official_seal', 'certificate-logo', 'Official seal') ?: 'Official<br>Parish Seal<br>Dry Seal'; ?></div>
                        <div class="certified-block">
                            <div class="certified-label">Certified Correct:</div>
                            <div class="certified-line"><?php echo layoutImageTag($certificate_layout_settings, 'priest_signature', 'certificate-logo', 'Priest signature') . e($layout_priest_name); ?></div>
                            <span><?php echo e($layout_priest_position); ?></span>
                        </div>
                        <div class="certified-block">
                            <div class="certified-label">By Authority:</div>
                            <div class="certified-line"><?php echo layoutImageTag($certificate_layout_settings, 'secretary_signature', 'certificate-logo', 'Secretary signature') . e($layout_secretary_name); ?></div>
                            <span><?php echo e($layout_secretary_position); ?></span>
                        </div>
                    </div>
                </div>
                <?php if (!$is_manual_certificate): ?>
                    <div class="verification-code">
                        <span>Verify: <?php echo e($verification_url); ?></span>
                        <span>Unauthorized alteration invalidates this certificate.</span>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    <?php elseif ($cert_type === 'confirmation'): ?>
        <main class="certificate-page" id="certificateDocument">
            <section class="certificate-sheet">
                <?php echo $certificate_template_layer; ?>
                <?php echo layoutImageTag($certificate_layout_settings, 'watermark', 'layout-watermark-image', 'Certificate watermark'); ?>
                <div class="watermark-text"><?php echo e($layout_watermark_text); ?></div>
                <div class="certificate-number"><?php echo e($issue['certificate_number']); ?></div>
                <div class="cert-content">
                    <header class="cert-header">
                        <div class="certificate-logo-slot">
                            <?php if ($archdiocese_logo): ?>
                                <img class="certificate-logo archdiocese-logo" src="<?php echo e($archdiocese_logo); ?>" alt="Official Archdiocese of Cotabato crest">
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="parish"><?php echo e(strtoupper($layout_church_title)); ?></div>
                            <div class="diocese"><?php echo e(strtoupper($layout_diocese_name)); ?></div>
                            <div class="parish"><?php echo e(strtoupper($display_parish_name)); ?></div>
                            <div class="location"><?php echo e(strtoupper($display_ceremony_place)); ?></div>
                            <div class="cert-title"><?php echo e($layout_certificate_title); ?></div>
                            <div class="cert-subline"><?php echo e($layout_certificate_subtitle); ?></div>
                        </div>
                        <div class="certificate-logo-slot">
                            <img class="certificate-logo" src="<?php echo e($mission_logo); ?>" alt="San Lorenzo Ruiz Mission Station logo">
                        </div>
                    </header>

                    <div class="recipient"><?php echo e($data['fullname'] ?? 'N/A'); ?></div>

                    <p class="statement">
                        This is to certify that <?php echo $is_manual_certificate ? 'the above-named person received the Sacrament of Confirmation' : 'according to the records of this parish, the above-named person received the Sacrament of Confirmation'; ?> according to the rite of the
                        <strong>ROMAN CATHOLIC CHURCH</strong>.
                    </p>

                    <div class="details">
                        <div class="label">Full Name:</div><div class="value"><?php echo e($data['fullname'] ?? 'N/A'); ?></div>
                        <div class="label">Confirmation Name:</div><div class="value"><?php echo e($data['confirmation_name'] ?? 'N/A'); ?></div>
                        <div class="label">Date of Birth:</div><div class="value"><?php echo e(displayDate($data['birth_date'] ?? '')); ?></div>
                        <div class="label">Date of Confirmation:</div><div class="value"><?php echo e(displayDate($data['confirmation_date'] ?? '')); ?></div>
                        <div class="label">Parents:</div><div class="value"><?php echo e($data['parents'] ?? 'N/A'); ?></div>
                        <div class="label">Parish of Origin:</div><div class="value"><?php echo e($data['origin_parish'] ?? 'N/A'); ?></div>
                        <div class="label">Province:</div><div class="value"><?php echo e($data['origin_province'] ?? 'N/A'); ?></div>
                        <div class="label">Place of Baptism:</div><div class="value"><?php echo e($data['baptismal_place'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="church-line">
                        Was Solemnly Confirmed according to the Rite of the
                        <span class="roman">ROMAN CATHOLIC CHURCH</span>
                    </div>
                    <div class="minister">
                        Minister:
                        <strong><?php echo e($data['bishop_priest'] ?? 'N/A'); ?></strong>
                    </div>

                    <div class="lower-grid">
                        <div>
                            <div class="sponsors">
                                <strong>Sponsor / Godparent:</strong>
                                <div class="sponsor-lines"><?php echo e($data['sponsor'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="registry-box">
                                <div><strong>Book No.</strong><br><?php echo e($volume_no); ?></div>
                                <div><strong>Page No.</strong><br><?php echo e($page_no); ?></div>
                                <div><strong>Entry No.</strong><br><?php echo e($entry_no); ?></div>
                                <div><strong>Confirmation Date</strong><br><?php echo e(displayDate($data['confirmation_date'] ?? '', 'm/d/Y')); ?></div>
                                <div><strong>Reference</strong><br><?php echo e($issue['certificate_number']); ?></div>
                                <div><strong>Status</strong><br><?php echo e(ucfirst($issue['status'])); ?></div>
                            </div>
                            <div class="remarks mt-2">
                                <strong>Remarks:</strong> <?php echo e($data['observations'] ?? ($data['remarks'] ?? 'Issued for parish record purposes.')); ?>
                            </div>
                        </div>
                        <div class="auth-box">
                            <div class="issued">
                                <strong>Date Issued:</strong><br><?php echo e(displayDate($issue['issued_at'] ?? date('Y-m-d'))); ?><br>
                                <strong>Certificate No.:</strong><br><?php echo e($issue['certificate_number']); ?>
                            </div>
                            <div class="qr-row">
                                <div class="seal-area">Official<br>Dry Seal<br>Area</div>
                            </div>
                        </div>
                    </div>

                    <div class="signature-grid">
                        <div class="signature">
                            <div class="signature-line"><?php echo layoutImageTag($certificate_layout_settings, 'priest_signature', 'certificate-logo', 'Priest signature') . e($layout_priest_name); ?></div>
                            <span><?php echo e($layout_priest_position); ?></span>
                        </div>
                        <div class="signature">
                            <div class="signature-line"><?php echo layoutImageTag($certificate_layout_settings, 'secretary_signature', 'certificate-logo', 'Secretary signature') . e($layout_secretary_name); ?></div>
                            <span><?php echo e($layout_secretary_position); ?></span>
                        </div>
                    </div>
                </div>
                <?php if (!$is_manual_certificate): ?>
                    <div class="verification-code">
                        <span>Verify: <?php echo e($verification_url); ?></span>
                        <span>Unauthorized alteration invalidates this certificate.</span>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    <?php else: ?>
        <div class="simple-preview" id="certificateDocument">
            <?php echo $certificate_template_layer; ?>
            <header class="cert-header">
                <div class="certificate-logo-slot">
                    <?php if ($archdiocese_logo): ?>
                        <img class="certificate-logo archdiocese-logo" src="<?php echo e($archdiocese_logo); ?>" alt="Official Archdiocese of Cotabato crest">
                    <?php endif; ?>
                </div>
                <div>
                    <div class="diocese"><?php echo e(strtoupper($layout_diocese_name)); ?></div>
                    <div class="parish"><?php echo e(strtoupper($display_parish_name)); ?></div>
                    <div class="location"><?php echo e(strtoupper($display_ceremony_place)); ?></div>
                    <div class="cert-title"><?php echo e($layout_certificate_title); ?></div>
                </div>
                <div class="certificate-logo-slot">
                    <img class="certificate-logo" src="<?php echo e($mission_logo); ?>" alt="San Lorenzo Ruiz Mission Station logo">
                </div>
            </header>
            <h2><?php echo e($certificate_subject); ?></h2>
            <p><?php echo $is_manual_certificate ? 'This sacramental certificate is generated from manual parish office entry.' : 'This sacramental certificate is generated from parish records.'; ?></p>
            <?php if ($cert_type === 'communion'): ?>
                <p><strong>Date of First Communion:</strong> <?php echo e(displayDate($data['communion_date'] ?? '')); ?></p>
                <p><strong>Parents:</strong> <?php echo e($data['parents'] ?? 'N/A'); ?></p>
                <p><strong>Priest:</strong> <?php echo e($data['priest'] ?? 'N/A'); ?></p>
            <?php elseif ($cert_type === 'marriage'): ?>
                <p><strong>Date of Marriage:</strong> <?php echo e(displayDate($data['wedding_date'] ?? '')); ?></p>
                <p><strong>Officiating Priest:</strong> <?php echo e($data['officiating_priest'] ?? 'N/A'); ?></p>
                <p><strong>Sponsors/Witnesses:</strong> <?php echo e($data['sponsors'] ?? 'N/A'); ?></p>
            <?php endif; ?>
            <p><strong>Certificate No:</strong> <?php echo e($issue['certificate_number']); ?></p>
            <p><strong>Verification Code:</strong> <?php echo e($issue['verification_code']); ?></p>
        </div>
    <?php endif; ?>
    <?php if ($is_certification): ?>
    <!-- Edit Purpose & Details Modal (Only for Sacramental Certifications) -->
    <div class="modal fade" id="editPurposeModal" tabindex="-1" aria-labelledby="editPurposeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow">
                <form method="POST" action="">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="update_certificate_details">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title" id="editPurposeModalLabel"><i class="fas fa-pen-to-square me-2"></i> Edit Certification Purpose & Details</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label for="edit_purpose" class="form-label fw-bold">Purpose of Certification</label>
                            <input type="text" class="form-control" id="edit_purpose" name="purpose" value="<?php echo e(!empty($data['purpose']) ? $data['purpose'] : 'whatever lawful purpose it may serve'); ?>" placeholder="e.g., whatever lawful purpose it may serve">
                            <div class="d-flex flex-wrap gap-1 mt-2">
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="document.getElementById('edit_purpose').value='whatever lawful purpose it may serve'">Default (Lawful purpose)</span>
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="document.getElementById('edit_purpose').value='For Marriage Requirements'">For Marriage</span>
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="document.getElementById('edit_purpose').value='For School Enrollment / Educational Purposes'">For School</span>
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="document.getElementById('edit_purpose').value='For Employment Requirements'">For Employment</span>
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="document.getElementById('edit_purpose').value='For Passport / Visa Application'">For Passport/Visa</span>
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="document.getElementById('edit_purpose').value='For Legal Reference'">For Legal Reference</span>
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="document.getElementById('edit_purpose').value='For Confirmation Requirements'">For Confirmation</span>
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="document.getElementById('edit_purpose').value='For First Holy Communion Requirements'">For First Communion</span>
                            </div>
                        </div>

                        <div class="row g-3">
                            <?php if ($cert_type === 'marriage' || $cert_type === 'marriage_certification'): ?>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Groom's Residence</label>
                                    <input type="text" class="form-control" name="husband_residence" value="<?php echo e($data['husband_residence'] ?? ''); ?>" placeholder="Barangay, Municipality, Province">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Bride's Residence</label>
                                    <input type="text" class="form-control" name="wife_residence" value="<?php echo e($data['wife_residence'] ?? ''); ?>" placeholder="Barangay, Municipality, Province">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Groom's Parents</label>
                                    <input type="text" class="form-control" name="husband_parents" value="<?php echo e($data['husband_parents'] ?? ''); ?>" placeholder="Mother / Father">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Bride's Parents</label>
                                    <input type="text" class="form-control" name="wife_parents" value="<?php echo e($data['wife_parents'] ?? ''); ?>" placeholder="Mother / Father">
                                </div>
                            <?php else: ?>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Father's Name</label>
                                    <input type="text" class="form-control" name="father_name" value="<?php echo e($father_name); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Mother's Name</label>
                                    <input type="text" class="form-control" name="mother_name" value="<?php echo e($mother_name); ?>">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-bold">Residence / Address</label>
                                    <input type="text" class="form-control" name="residence" value="<?php echo e($data['residence'] ?? ($data['domicile'] ?? ($data['parent_address'] ?? ''))); ?>" placeholder="Sitio / Barangay, Municipality, Province">
                                </div>
                            <?php endif; ?>

                            <div class="col-md-4">
                                <label class="form-label fw-bold">Vol. / Book No.</label>
                                <input type="text" class="form-control" name="volume_no" value="<?php echo e($volume_no !== 'N/A' ? $volume_no : ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Page No.</label>
                                <input type="text" class="form-control" name="page_no" value="<?php echo e($page_no !== 'N/A' ? $page_no : ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Entry No.</label>
                                <input type="text" class="form-control" name="entry_no" value="<?php echo e($entry_no !== 'N/A' ? $entry_no : ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Date Issued</label>
                                <input type="date" class="form-control" name="date_issued" value="<?php echo e($data['date_issued'] ?? date('Y-m-d')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Additional Remarks</label>
                                <input type="text" class="form-control" name="remarks" value="<?php echo e($data['remarks'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Save & Update Certificate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
