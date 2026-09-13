<?php
/**
 * Certificate Preview Module - Simplified Single-Paragraph Flowing Format
 * Renders sacramental certificates for Baptism, First Communion, Confirmation, Marriage, and Funeral.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/CertificateTemplateManager.php';

requireAdmin();
requirePermission('certificates.manage');

// Helper to sanitize officiating priest display (strips leading "Rev. Fr.", "Fr.", etc. so "officiated by Rev. Fr." is never duplicated)
if (!function_exists('cleanOfficiatingPriest')) {
    function cleanOfficiatingPriest($priest) {
        $p = trim((string)$priest);
        $p = preg_replace('/^(?:officiated\s+by\s+)?(?:by\s+the\s+)?(?:rev\.?\s*fr\.?\s*|father\s+|fr\.?\s*)/i', '', $p);
        return trim($p);
    }
}

// Helper to format Parish Priest signature block (always "REV. FR. [NAME]")
if (!function_exists('formatParishPriestSignature')) {
    function formatParishPriestSignature($priest, $default = 'REV. FR. HERIBERTO C. VILLAS, O.M.I.') {
        $p = trim((string)$priest);
        if ($p === '') {
            $p = $default;
        }
        $p = preg_replace('/^(?:by\s+the\s+)?(?:rev\.?\s*fr\.?\s*|father\s+|fr\.?\s*)/i', '', $p);
        return 'REV. FR. ' . strtoupper(trim($p));
    }
}

// Certificate Record Meta Function - Returns table, id key, prefix, and official certification title
if (!function_exists('certificateRecordMeta')) {
    function certificateRecordMeta($cert_type) {
        if ($cert_type === 'baptism' || $cert_type === 'baptism_certification') {
            return ['table' => 'baptism_records', 'id' => 'baptism_id', 'prefix' => 'BCF', 'title' => 'BAPTISMAL CERTIFICATION', 'sacrament' => 'baptism'];
        }
        if (in_array($cert_type, ['communion', 'first_communion', 'first_communion_certificate', 'first_communion_certification'], true)) {
            return ['table' => 'first_communion_records', 'id' => 'communion_id', 'prefix' => 'FCF', 'title' => 'FIRST COMMUNION CERTIFICATION', 'sacrament' => 'communion'];
        }
        if (in_array($cert_type, ['confirmation', 'confirmation_certification'], true)) {
            return ['table' => 'confirmation_records', 'id' => 'confirmation_id', 'prefix' => 'CCF', 'title' => 'CONFIRMATION CERTIFICATION', 'sacrament' => 'confirmation'];
        }
        if ($cert_type === 'marriage' || $cert_type === 'marriage_certification') {
            return ['table' => 'marriage_records', 'id' => 'marriage_id', 'prefix' => 'MCF', 'title' => 'MARRIAGE CERTIFICATION', 'sacrament' => 'marriage'];
        }
        if ($cert_type === 'funeral' || $cert_type === 'funeral_certification') {
            return ['table' => 'funeral_records', 'id' => 'funeral_id', 'prefix' => 'FNC', 'title' => 'FUNERAL / BURIAL CERTIFICATION', 'sacrament' => 'funeral'];
        }
        return ['table' => 'baptism_records', 'id' => 'baptism_id', 'prefix' => 'BCF', 'title' => 'BAPTISMAL CERTIFICATION', 'sacrament' => 'baptism'];
    }
}

// Helper to display dates
if (!function_exists('displayDate')) {
    function displayDate($value, $format = 'F j, Y') {
        if (empty($value) || $value === '0000-00-00') {
            return '';
        }
        $time = strtotime($value);
        return $time ? date($format, $time) : '';
    }
}

// Split parents into father and mother
if (!function_exists('splitParents')) {
    function splitParents($parents) {
        $result = ['father' => '', 'mother' => ''];
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
}

if (!function_exists('certificateAssetUrl')) {
    function certificateAssetUrl($relative_path, $fallback = '') {
        $root = dirname(__DIR__);
        $path = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative_path);
        if (is_file($path)) {
            return '../' . str_replace('\\', '/', $relative_path);
        }
        return $fallback;
    }
}

// Fetch record if GET parameters provided
if (isset($_GET['id'])) {
    $rec_id = intval($_GET['id']);
    $rec_type = $_GET['type'] ?? 'baptism';
    $meta = certificateRecordMeta($rec_type);
    
    $stmt = $conn->prepare("SELECT * FROM {$meta['table']} WHERE {$meta['id']} = ?");
    if ($stmt) {
        $stmt->bind_param('i', $rec_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $rec = $res->fetch_assoc();
            // Parse parents if father/mother are empty
            if (empty($rec['father_name']) || empty($rec['mother_name'])) {
                $p = splitParents($rec['parents'] ?? '');
                if (empty($rec['father_name']) && !empty($p['father'])) $rec['father_name'] = $p['father'];
                if (empty($rec['mother_name']) && !empty($p['mother'])) $rec['mother_name'] = $p['mother'];
            }
            unset($_SESSION['manual_certificate']);
            $_SESSION['certificate_data'] = $rec;
            $_SESSION['cert_type'] = $rec_type;
        }
        $stmt->close();
    }
}

// Fallback if no session
if (!isset($_SESSION['certificate_data']) || !isset($_SESSION['cert_type'])) {
    $fallback_stmt = $conn->query("SELECT * FROM baptism_records WHERE status = 'active' ORDER BY baptism_id ASC LIMIT 1");
    if ($fallback_stmt && $fb = $fallback_stmt->fetch_assoc()) {
        unset($_SESSION['manual_certificate']);
        $_SESSION['certificate_data'] = $fb;
        $_SESSION['cert_type'] = 'baptism';
    } else {
        header('Location: certificate-generator.php');
        exit;
    }
}

// Handle details edit form submission
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_certificate_details') {
    requireValidCsrfToken();
    $rec_type = $_POST['cert_type'] ?? ($_SESSION['cert_type'] ?? 'baptism');
    $meta = certificateRecordMeta($rec_type);
    $target_id = intval($_POST['rec_id'] ?? ($_SESSION['certificate_data'][$meta['id']] ?? 0));

    if ($meta['sacrament'] === 'baptism') {
        $fn = trim($_POST['fullname'] ?? '');
        $fa = trim($_POST['father_name'] ?? '');
        $mo = trim($_POST['mother_name'] ?? '');
        $dt = trim($_POST['baptism_date'] ?? '');
        $pr = trim($_POST['priest'] ?? '');
        $pp = trim($_POST['parish_priest'] ?? '');

        $_SESSION['certificate_data']['fullname'] = $fn;
        $_SESSION['certificate_data']['father_name'] = $fa;
        $_SESSION['certificate_data']['mother_name'] = $mo;
        $_SESSION['certificate_data']['baptism_date'] = $dt;
        $_SESSION['certificate_data']['priest'] = $pr;
        $_SESSION['certificate_data']['parish_priest'] = $pp;

        if ($target_id > 0) {
            $up = $conn->prepare("UPDATE baptism_records SET fullname=?, father_name=?, mother_name=?, baptism_date=?, priest=?, parish_priest=? WHERE baptism_id=?");
            if ($up) {
                $up->bind_param('ssssssi', $fn, $fa, $mo, $dt, $pr, $pp, $target_id);
                $up->execute();
                $up->close();
            }
        }
    } elseif ($meta['sacrament'] === 'communion') {
        $fn = trim($_POST['fullname'] ?? '');
        $fa = trim($_POST['father_name'] ?? '');
        $mo = trim($_POST['mother_name'] ?? '');
        $dt = trim($_POST['communion_date'] ?? '');
        $pr = trim($_POST['priest'] ?? '');
        $pp = trim($_POST['parish_priest'] ?? '');

        $_SESSION['certificate_data']['fullname'] = $fn;
        $_SESSION['certificate_data']['father_name'] = $fa;
        $_SESSION['certificate_data']['mother_name'] = $mo;
        $_SESSION['certificate_data']['communion_date'] = $dt;
        $_SESSION['certificate_data']['priest'] = $pr;
        $_SESSION['certificate_data']['parish_priest'] = $pp;

        if ($target_id > 0) {
            $up = $conn->prepare("UPDATE first_communion_records SET fullname=?, father_name=?, mother_name=?, communion_date=?, priest=?, parish_priest=? WHERE communion_id=?");
            if ($up) {
                $up->bind_param('ssssssi', $fn, $fa, $mo, $dt, $pr, $pp, $target_id);
                $up->execute();
                $up->close();
            }
        }
    } elseif ($meta['sacrament'] === 'confirmation') {
        $fn = trim($_POST['fullname'] ?? '');
        $fa = trim($_POST['father_name'] ?? '');
        $mo = trim($_POST['mother_name'] ?? '');
        $dt = trim($_POST['confirmation_date'] ?? '');
        $pr = trim($_POST['bishop_priest'] ?? '');
        $pp = trim($_POST['parish_priest'] ?? '');

        $_SESSION['certificate_data']['fullname'] = $fn;
        $_SESSION['certificate_data']['father_name'] = $fa;
        $_SESSION['certificate_data']['mother_name'] = $mo;
        $_SESSION['certificate_data']['confirmation_date'] = $dt;
        $_SESSION['certificate_data']['bishop_priest'] = $pr;
        $_SESSION['certificate_data']['parish_priest'] = $pp;

        if ($target_id > 0) {
            $up = $conn->prepare("UPDATE confirmation_records SET fullname=?, father_name=?, mother_name=?, confirmation_date=?, bishop_priest=?, parish_priest=? WHERE confirmation_id=?");
            if ($up) {
                $up->bind_param('ssssssi', $fn, $fa, $mo, $dt, $pr, $pp, $target_id);
                $up->execute();
                $up->close();
            }
        }
    } elseif ($meta['sacrament'] === 'marriage') {
        $hn = trim($_POST['husband_name'] ?? '');
        $wn = trim($_POST['wife_name'] ?? '');
        $dt = trim($_POST['wedding_date'] ?? '');
        $pr = trim($_POST['officiating_priest'] ?? '');
        $pp = trim($_POST['parish_priest'] ?? '');

        $_SESSION['certificate_data']['husband_name'] = $hn;
        $_SESSION['certificate_data']['wife_name'] = $wn;
        $_SESSION['certificate_data']['wedding_date'] = $dt;
        $_SESSION['certificate_data']['officiating_priest'] = $pr;
        $_SESSION['certificate_data']['parish_priest'] = $pp;

        if ($target_id > 0) {
            $up = $conn->prepare("UPDATE marriage_records SET husband_name=?, wife_name=?, wedding_date=?, officiating_priest=?, parish_priest=? WHERE marriage_id=?");
            if ($up) {
                $up->bind_param('ssssssi', $hn, $wn, $dt, $pr, $pp, $target_id);
                $up->execute();
                $up->close();
            }
        }
    } elseif ($meta['sacrament'] === 'funeral') {
        $dn = trim($_POST['deceased_name'] ?? '');
        $fa = trim($_POST['father_name'] ?? '');
        $mo = trim($_POST['mother_name'] ?? '');
        $dt = trim($_POST['date_of_burial'] ?? '');
        $pr = trim($_POST['minister'] ?? '');
        $pp = trim($_POST['parish_priest'] ?? '');

        $_SESSION['certificate_data']['deceased_name'] = $dn;
        $_SESSION['certificate_data']['father_name'] = $fa;
        $_SESSION['certificate_data']['mother_name'] = $mo;
        $_SESSION['certificate_data']['date_of_burial'] = $dt;
        $_SESSION['certificate_data']['minister'] = $pr;
        $_SESSION['certificate_data']['parish_priest'] = $pp;

        if ($target_id > 0) {
            $up = $conn->prepare("UPDATE funeral_records SET deceased_name=?, father_name=?, mother_name=?, date_of_burial=?, minister=?, parish_priest=? WHERE funeral_id=?");
            if ($up) {
                $up->bind_param('ssssssi', $dn, $fa, $mo, $dt, $pr, $pp, $target_id);
                $up->execute();
                $up->close();
            }
        }
    }

    header('Location: view-certificate.php?id=' . $target_id . '&type=' . urlencode($rec_type));
    exit;
}

$data = $_SESSION['certificate_data'];
$cert_type = $_SESSION['cert_type'];
$meta = certificateRecordMeta($cert_type);
$sacrament = $meta['sacrament'];
$current_id = intval($data[$meta['id']] ?? 0);

// Active priests roster for modal dropdown
$active_priests = getActivePriestsRoster($conn);

// Header elements from layout settings or defaults
$current_layout = getCertificateLayout($conn, $cert_type);
$certificate_layout_settings = $current_layout['settings'];
$layout_text = $certificate_layout_settings['static_text'];
$layout_images = $certificate_layout_settings['images'];

$layout_church_title = !empty($layout_text['church_title']) ? $layout_text['church_title'] : 'ROMAN CATHOLIC CHURCH';
$layout_diocese_name = !empty($layout_text['diocese_name']) ? $layout_text['diocese_name'] : 'ARCHDIOCESE OF COTABATO';
$display_parish_name = !empty($layout_text['parish_name']) ? $layout_text['parish_name'] : (trim((string)($data['parish_name'] ?? '')) ?: 'SAN LORENZO RUIZ MISSION STATION');
$display_ceremony_place = !empty($layout_text['parish_address']) ? $layout_text['parish_address'] : (trim((string)($data['ceremony_place'] ?? '')) ?: 'ALEOSAN, COTABATO');
$layout_certificate_title = $meta['title'];

$archdiocese_logo = !empty($layout_images['diocese_logo']) ? certificateLayoutAssetUrl($layout_images['diocese_logo']) : certificateAssetUrl('assets/img/archdiocese-crest.jfif', certificateAssetUrl('assets/img/archdiocese-crest.jpg'));
$mission_logo = !empty($layout_images['parish_logo']) ? certificateLayoutAssetUrl($layout_images['parish_logo']) : certificateAssetUrl('assets/img/san-lorenzo-logo-final.jfif', certificateAssetUrl('assets/img/san-lorenzo-logo.png', '../church image.png'));

// Background template layer
$certificate_backgrounds = [
    'baptism' => certificateAssetUrl('baptism.webp', $mission_logo),
    'communion' => certificateAssetUrl('first communion.jpg', $mission_logo),
    'confirmation' => certificateAssetUrl('confirmation.jfif', $mission_logo),
    'marriage' => certificateAssetUrl('church image.png', $mission_logo),
    'funeral' => certificateAssetUrl('church image.png', $mission_logo),
];
$certificate_background = $certificate_backgrounds[$sacrament] ?? $mission_logo;
if (!function_exists('renderCertificateTemplateLayer')) {
    function renderCertificateTemplateLayer($fallback_url, $cert_type) {
        $class_type = e($cert_type);
        return '<img class="certificate-design-bg ' . $class_type . '" src="' . e($fallback_url) . '" alt="" aria-hidden="true">';
    }
}
$certificate_template_layer = renderCertificateTemplateLayer($certificate_background, $cert_type);

// Parish Priest Name & Signature Block
$parish_priest_raw = trim((string)($data['parish_priest'] ?? ($layout_text['priest_name'] ?? 'REV. FR. HERIBERTO C. VILLAS, O.M.I.')));
if ($parish_priest_raw === '') {
    $parish_priest_raw = 'REV. FR. HERIBERTO C. VILLAS, O.M.I.';
}
$parish_priest_display = formatParishPriestSignature($parish_priest_raw);

// Extract Essential Fields & Check Required Validations per Sacrament
$missing_fields = [];

if ($sacrament === 'baptism') {
    $parents = splitParents($data['parents'] ?? '');
    $sub_name = trim((string)($data['fullname'] ?? ''));
    $sub_father = trim((string)($data['father_name'] ?? '')) ?: $parents['father'];
    $sub_mother = trim((string)($data['mother_name'] ?? '')) ?: $parents['mother'];
    $sub_date = !empty($data['baptism_date']) && $data['baptism_date'] !== '0000-00-00' ? displayDate($data['baptism_date'], 'F j, Y') : '';
    $sub_priest_raw = trim((string)($data['priest'] ?? ''));
    $sub_priest_clean = cleanOfficiatingPriest($sub_priest_raw);

    if ($sub_name === '' || $sub_name === 'N/A') $missing_fields[] = 'Full Name of the Baptized';
    if ($sub_father === '' || $sub_father === 'N/A') $missing_fields[] = "Father's Name";
    if ($sub_mother === '' || $sub_mother === 'N/A') $missing_fields[] = "Mother's Name";
    if ($sub_date === '' || $sub_date === 'N/A') $missing_fields[] = 'Date of Baptism';
    if ($sub_priest_clean === '' || $sub_priest_clean === 'N/A') $missing_fields[] = 'Officiating Priest';
    if ($parish_priest_raw === '' || $parish_priest_raw === 'N/A') $missing_fields[] = 'Parish Priest';

} elseif ($sacrament === 'communion') {
    $parents = splitParents($data['parents'] ?? '');
    $sub_name = trim((string)($data['fullname'] ?? ''));
    $sub_father = trim((string)($data['father_name'] ?? '')) ?: $parents['father'];
    $sub_mother = trim((string)($data['mother_name'] ?? '')) ?: $parents['mother'];
    $sub_date = !empty($data['communion_date']) && $data['communion_date'] !== '0000-00-00' ? displayDate($data['communion_date'], 'F j, Y') : '';
    $sub_priest_raw = trim((string)($data['priest'] ?? ''));
    $sub_priest_clean = cleanOfficiatingPriest($sub_priest_raw);

    if ($sub_name === '' || $sub_name === 'N/A') $missing_fields[] = 'Full Name';
    if ($sub_father === '' || $sub_father === 'N/A') $missing_fields[] = "Father's Name";
    if ($sub_mother === '' || $sub_mother === 'N/A') $missing_fields[] = "Mother's Name";
    if ($sub_date === '' || $sub_date === 'N/A') $missing_fields[] = 'Date of First Holy Communion';
    if ($sub_priest_clean === '' || $sub_priest_clean === 'N/A') $missing_fields[] = 'Officiating Priest';
    if ($parish_priest_raw === '' || $parish_priest_raw === 'N/A') $missing_fields[] = 'Parish Priest';

} elseif ($sacrament === 'confirmation') {
    $parents = splitParents($data['parents'] ?? '');
    $sub_name = trim((string)($data['fullname'] ?? ''));
    $sub_father = trim((string)($data['father_name'] ?? '')) ?: $parents['father'];
    $sub_mother = trim((string)($data['mother_name'] ?? '')) ?: $parents['mother'];
    $sub_date = !empty($data['confirmation_date']) && $data['confirmation_date'] !== '0000-00-00' ? displayDate($data['confirmation_date'], 'F j, Y') : '';
    $sub_minister = trim((string)($data['bishop_priest'] ?? ''));

    if ($sub_name === '' || $sub_name === 'N/A') $missing_fields[] = 'Full Name';
    if ($sub_father === '' || $sub_father === 'N/A') $missing_fields[] = "Father's Name";
    if ($sub_mother === '' || $sub_mother === 'N/A') $missing_fields[] = "Mother's Name";
    if ($sub_date === '' || $sub_date === 'N/A') $missing_fields[] = 'Date of Confirmation';
    if ($sub_minister === '' || $sub_minister === 'N/A') $missing_fields[] = 'Confirming Minister';
    if ($parish_priest_raw === '' || $parish_priest_raw === 'N/A') $missing_fields[] = 'Parish Priest';

} elseif ($sacrament === 'marriage') {
    $sub_husband = trim((string)($data['husband_name'] ?? ''));
    $sub_wife = trim((string)($data['wife_name'] ?? ''));
    $sub_date = !empty($data['wedding_date']) && $data['wedding_date'] !== '0000-00-00' ? displayDate($data['wedding_date'], 'F j, Y') : '';
    $sub_priest_raw = trim((string)($data['officiating_priest'] ?? ''));
    $sub_priest_clean = cleanOfficiatingPriest($sub_priest_raw);

    if ($sub_husband === '' || $sub_husband === 'N/A') $missing_fields[] = "Groom's Full Name";
    if ($sub_wife === '' || $sub_wife === 'N/A') $missing_fields[] = "Bride's Full Name";
    if ($sub_date === '' || $sub_date === 'N/A') $missing_fields[] = 'Date of Marriage';
    if ($sub_priest_clean === '' || $sub_priest_clean === 'N/A') $missing_fields[] = 'Officiating Priest';
    if ($parish_priest_raw === '' || $parish_priest_raw === 'N/A') $missing_fields[] = 'Parish Priest';

} elseif ($sacrament === 'funeral') {
    $parents = splitParents($data['parents'] ?? '');
    $sub_name = trim((string)($data['deceased_name'] ?? ''));
    $sub_father = trim((string)($data['father_name'] ?? '')) ?: $parents['father'];
    $sub_mother = trim((string)($data['mother_name'] ?? '')) ?: $parents['mother'];
    $sub_date = !empty($data['date_of_burial']) && $data['date_of_burial'] !== '0000-00-00' ? displayDate($data['date_of_burial'], 'F j, Y') : '';
    $sub_priest_raw = trim((string)($data['minister'] ?? ''));
    $sub_priest_clean = cleanOfficiatingPriest($sub_priest_raw);

    if ($sub_name === '' || $sub_name === 'N/A') $missing_fields[] = 'Full Name of the Deceased';
    if ($sub_father === '' || $sub_father === 'N/A') $missing_fields[] = "Father's Name";
    if ($sub_mother === '' || $sub_mother === 'N/A') $missing_fields[] = "Mother's Name";
    if ($sub_date === '' || $sub_date === 'N/A') $missing_fields[] = 'Date of Burial/Funeral Rites';
    if ($sub_priest_clean === '' || $sub_priest_clean === 'N/A') $missing_fields[] = 'Officiating Priest';
    if ($parish_priest_raw === '' || $parish_priest_raw === 'N/A') $missing_fields[] = 'Parish Priest';
}

$page_title = $layout_certificate_title;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400..700;1,400..700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #151515;
            --cert-width: 6.5in; /* 165.1mm - Half of Long Bond Paper (8.5in x 13in cut in half) */
            --cert-height: 8.5in; /* 215.9mm */
            --line: #bfa15f;
            --accent-line: #bfa15f;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { background: #eef1f5; color: var(--ink); margin: 0; padding: 0; }
        .cert-toolbar { max-width: var(--cert-width); margin: 18px auto; display: flex; justify-content: space-between; gap: 12px; align-items: center; }
        
        .certificate-page {
            width: var(--cert-width);
            height: var(--cert-height);
            margin: 0 auto 24px;
            background: #ffffff;
            padding: 3.5mm;
            box-shadow: 0 18px 42px rgba(15, 23, 42, .18);
            overflow: hidden;
            box-sizing: border-box;
        }

        .certificate-sheet {
            height: 100%;
            border: 2px solid #bfa15f;
            padding: 5mm 6mm 6mm;
            position: relative;
            overflow: hidden;
            box-shadow: inset 0 0 0 1mm rgba(0, 0, 0, .03);
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
        .certificate-sheet::before {
            content: "";
            position: absolute;
            inset: 2.4mm;
            border: 1px solid var(--line);
            outline: 1px solid rgba(0, 0, 0, .15);
            outline-offset: 1.2mm;
            pointer-events: none;
            z-index: 2;
        }

        .certificate-design-bg {
            position: absolute;
            left: 50%;
            top: 55%;
            width: 104mm;
            height: 150mm;
            transform: translate(-50%, -50%);
            object-fit: contain;
            object-position: center;
            opacity: .07;
            filter: saturate(.9) contrast(1.05);
            pointer-events: none;
            z-index: 0;
        }

        .cert-content {
            position: relative;
            z-index: 3;
            height: 100%;
        }

        .cert-header {
            display: grid;
            grid-template-columns: 20mm 1fr 20mm;
            align-items: center;
            gap: 2mm;
            text-align: center;
            margin-bottom: 3mm;
            min-height: 25mm;
        }

        .certificate-logo-slot {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .certificate-logo {
            width: 19mm;
            height: 19mm;
            object-fit: contain;
            display: block;
        }
        .certificate-logo.archdiocese-logo {
            width: 21mm;
            height: 21mm;
        }

        .church-title {
            font-family: Arial, sans-serif;
            font-size: 7pt;
            font-weight: 600;
            color: #475569;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            margin-bottom: 1.5px;
            line-height: 1.1;
        }
        .diocese-title {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 11pt;
            font-weight: 800;
            color: #1e3a8a;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 2px;
            line-height: 1.15;
        }
        .parish-title {
            font-family: Arial, sans-serif;
            font-size: 7.2pt;
            font-weight: 600;
            color: #334155;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 1px;
            line-height: 1.1;
        }
        .location-title {
            font-family: Arial, sans-serif;
            font-size: 6.8pt;
            font-weight: 500;
            color: #475569;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 2.5mm;
            line-height: 1.1;
        }
        .cert-main-heading {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 14pt;
            font-weight: 700;
            color: #852219;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            line-height: 1.15;
            margin-bottom: 1.8mm;
        }
        .cert-title-divider {
            width: 76mm;
            height: 1.2px;
            background: #8c733e;
            margin: 0 auto 5mm;
        }

        /* Flowing Certification Paragraph */
        .simple-cert-intro {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 8.8pt;
            font-weight: 700;
            color: #852219;
            letter-spacing: 2px;
            text-transform: uppercase;
            text-align: center;
            margin: 5mm auto 4.5mm;
        }

        .simple-cert-name {
            font-family: 'EB Garamond', Georgia, 'Times New Roman', serif;
            font-size: 17pt;
            font-weight: 700;
            font-style: italic;
            color: #1e3a8a;
            text-align: center;
            margin-bottom: 5.5mm;
            line-height: 1.25;
        }
        .simple-cert-name.underline {
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .simple-cert-body {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 10.8pt;
            line-height: 2.15;
            color: #222222;
            text-align: center;
            max-width: 138mm;
            margin: 0 auto;
        }

        .simple-cert-fill {
            font-family: 'EB Garamond', Georgia, 'Times New Roman', serif;
            font-size: 12.2pt;
            font-weight: 700;
            font-style: italic;
            color: #1e3a8a;
            text-decoration: underline;
            text-underline-offset: 3px;
            padding: 0 2px;
            white-space: nowrap;
        }

        /* Footer: Seal bottom-left and Priest Signature bottom-right */
        .simple-cert-footer {
            position: absolute;
            bottom: 10mm;
            left: 12mm;
            right: 12mm;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .simple-cert-seal {
            width: 24mm;
            height: 24mm;
            border: 1.2px dashed #8c733e;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 6.8pt;
            color: #8c733e;
            line-height: 1.2;
            padding: 1.5mm;
        }

        .simple-cert-sign {
            min-width: 65mm;
            text-align: center;
        }

        .simple-cert-sign-line {
            border-top: 1.2px solid #222222;
            width: 100%;
            margin-bottom: 2mm;
        }

        .simple-cert-priest-name {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 8pt;
            font-weight: 700;
            color: #222222;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }

        .simple-cert-priest-title {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 7.5pt;
            font-style: italic;
            color: #666666;
            margin-top: 1px;
        }

        @page {
            size: 6.5in 8.5in;
            margin: 0;
        }

        @media print {
            html, body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                height: auto !important;
            }
            .cert-toolbar, .alert, .btn, button, nav, footer, .modal {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                border: 0 !important;
            }
            .certificate-page {
                width: var(--cert-width) !important;
                height: var(--cert-height) !important;
                margin: 0 auto !important;
                padding: 0 !important;
                box-shadow: none !important;
                page-break-before: avoid !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .certificate-sheet {
                height: 100% !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
        }

        @media (max-width: 900px) {
            .certificate-page {
                transform: scale(0.92);
                transform-origin: top center;
                margin-bottom: -15mm;
            }
            .cert-toolbar {
                padding: 0 14px;
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>

    <!-- Toolbar -->
    <div class="cert-toolbar">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <button class="btn btn-primary" id="btnPrintCertificate" onclick="printCertificate()">
                <i class="fas fa-print me-1"></i> Print Certificate
            </button>
            <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#editDetailsModal">
                <i class="fas fa-pen-to-square me-1"></i> Edit Certificate Details
            </button>
            <a href="certificate-generator.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
        <div>
            <span class="badge bg-dark px-3 py-2 text-uppercase" style="letter-spacing: 0.8px;">
                <?php echo e($layout_certificate_title); ?>
            </span>
        </div>
    </div>

    <!-- Missing Fields Alert Banner -->
    <?php if (!empty($missing_fields)): ?>
        <div class="alert alert-danger border-danger shadow-sm mx-auto mb-3" style="max-width: var(--cert-width);">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <i class="fas fa-ban text-danger me-2 fs-5"></i>
                    <strong>Certificate Generation Blocked:</strong> Missing required fields: <?php echo e(implode(', ', $missing_fields)); ?>.
                </div>
                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#editDetailsModal">
                    <i class="fas fa-pen me-1"></i> Complete Missing Fields
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-success border-success shadow-sm mx-auto mb-3 py-2" style="max-width: var(--cert-width); font-size: 0.88rem;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <i class="fas fa-check-circle text-success me-2"></i>
                    All essential sacramental record fields verified and ready for official printing.
                </div>
                <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#editDetailsModal">
                    <i class="fas fa-edit me-1"></i> Edit Details
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Certificate Document Container -->
    <main class="certificate-page" id="certificateDocument">
        <section class="certificate-sheet">
            <?php echo $certificate_template_layer; ?>

            <div class="cert-content">
                <!-- Header: Church Hierarchy & Logos -->
                <header class="cert-header">
                    <div class="certificate-logo-slot">
                        <?php if ($archdiocese_logo): ?>
                            <img class="certificate-logo archdiocese-logo" src="<?php echo e($archdiocese_logo); ?>" alt="Official Archdiocese of Cotabato crest">
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="church-title"><?php echo e(strtoupper($layout_church_title)); ?></div>
                        <div class="diocese-title"><?php echo e(strtoupper($layout_diocese_name)); ?></div>
                        <div class="parish-title"><?php echo e(strtoupper($display_parish_name)); ?></div>
                        <div class="location-title"><?php echo e(strtoupper($display_ceremony_place)); ?></div>
                        <div class="cert-main-heading"><?php echo e($layout_certificate_title); ?></div>
                        <div class="cert-title-divider"></div>
                    </div>
                    <div class="certificate-logo-slot">
                        <img class="certificate-logo" src="<?php echo e($mission_logo); ?>" alt="San Lorenzo Ruiz Mission Station logo">
                    </div>
                </header>

                <!-- Certification Intro -->
                <div class="simple-cert-intro">THIS IS TO CERTIFY THAT</div>

                <!-- Sacrament Specific Flowing Certification Paragraph -->
                <?php if ($sacrament === 'baptism'): ?>
                    <div class="simple-cert-name underline"><?php echo e($sub_name); ?></div>
                    <div class="simple-cert-body">
                        child of <span class="simple-cert-fill"><?php echo e($sub_father); ?></span> and <span class="simple-cert-fill"><?php echo e($sub_mother); ?></span> ,<br>
                        received the Sacrament of Baptism on <span class="simple-cert-fill"><?php echo e($sub_date); ?></span> ,<br>
                        officiated by Rev. Fr. <span class="simple-cert-fill"><?php echo e($sub_priest_clean); ?></span> .
                    </div>

                <?php elseif ($sacrament === 'communion'): ?>
                    <div class="simple-cert-name underline"><?php echo e($sub_name); ?></div>
                    <div class="simple-cert-body">
                        child of <span class="simple-cert-fill"><?php echo e($sub_father); ?></span> and <span class="simple-cert-fill"><?php echo e($sub_mother); ?></span> ,<br>
                        received the Sacrament of First Holy Communion on <span class="simple-cert-fill"><?php echo e($sub_date); ?></span> , officiated by Rev. Fr. <span class="simple-cert-fill"><?php echo e($sub_priest_clean); ?></span> .
                    </div>

                <?php elseif ($sacrament === 'confirmation'): ?>
                    <div class="simple-cert-name underline"><?php echo e($sub_name); ?></div>
                    <div class="simple-cert-body">
                        child of <span class="simple-cert-fill"><?php echo e($sub_father); ?></span> and <span class="simple-cert-fill"><?php echo e($sub_mother); ?></span> ,<br>
                        received the Sacrament of Confirmation on <span class="simple-cert-fill"><?php echo e($sub_date); ?></span> , administered by <span class="simple-cert-fill"><?php echo e($sub_minister); ?></span> .
                    </div>

                <?php elseif ($sacrament === 'marriage'): ?>
                    <div class="simple-cert-name">
                        <span class="simple-cert-fill"><?php echo e($sub_husband); ?></span> and <span class="simple-cert-fill"><?php echo e($sub_wife); ?></span>
                    </div>
                    <div class="simple-cert-body">
                        were joined in the Sacrament of Holy Matrimony on <span class="simple-cert-fill"><?php echo e($sub_date); ?></span> ,<br>
                        officiated by Rev. Fr. <span class="simple-cert-fill"><?php echo e($sub_priest_clean); ?></span> .
                    </div>

                <?php elseif ($sacrament === 'funeral'): ?>
                    <div class="simple-cert-name underline"><?php echo e($sub_name); ?></div>
                    <div class="simple-cert-body">
                        child of <span class="simple-cert-fill"><?php echo e($sub_father); ?></span> and <span class="simple-cert-fill"><?php echo e($sub_mother); ?></span> ,<br>
                        was given Christian Burial on <span class="simple-cert-fill"><?php echo e($sub_date); ?></span> ,<br>
                        officiated by Rev. Fr. <span class="simple-cert-fill"><?php echo e($sub_priest_clean); ?></span> .
                    </div>
                <?php endif; ?>

                <!-- Footer: Seal & Parish Priest Signature -->
                <div class="simple-cert-footer">
                    <div class="simple-cert-seal">
                        Official<br>Parish Seal
                    </div>
                    <div class="simple-cert-sign">
                        <div class="simple-cert-sign-line"></div>
                        <div class="simple-cert-priest-name"><?php echo e($parish_priest_display); ?></div>
                        <div class="simple-cert-priest-title">Parish Priest</div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Unified Edit Details Modal for Essential Fields -->
    <div class="modal fade" id="editDetailsModal" tabindex="-1" aria-labelledby="editDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form method="POST" action="" id="editCertificateForm">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="update_certificate_details">
                    <input type="hidden" name="cert_type" value="<?php echo e($cert_type); ?>">
                    <input type="hidden" name="rec_id" value="<?php echo (int)$current_id; ?>">

                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title" id="editDetailsModalLabel">
                            <i class="fas fa-pen-to-square me-2 text-warning"></i> Edit <?php echo e($layout_certificate_title); ?> Details
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                            <i class="fas fa-circle-info fs-5"></i>
                            <div>All essential fields are bound to the sacramental record. Complete or update any field below to reflect on the certificate.</div>
                        </div>

                        <div class="row g-3">
                            <?php if ($sacrament === 'baptism'): ?>
                                <div class="col-md-12">
                                    <label class="form-label small fw-bold">Full Name of Baptized <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="fullname" value="<?php echo e($sub_name); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Father's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="father_name" value="<?php echo e($sub_father); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Mother's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="mother_name" value="<?php echo e($sub_mother); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Date of Baptism <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="baptism_date" value="<?php echo e($data['baptism_date'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Officiating Priest <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="priest" value="<?php echo e($sub_priest_clean); ?>" placeholder="e.g. Heriberto C. Villas, O.M.I." required>
                                </div>

                            <?php elseif ($sacrament === 'communion'): ?>
                                <div class="col-md-12">
                                    <label class="form-label small fw-bold">Recipient's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="fullname" value="<?php echo e($sub_name); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Father's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="father_name" value="<?php echo e($sub_father); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Mother's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="mother_name" value="<?php echo e($sub_mother); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Date of First Holy Communion <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="communion_date" value="<?php echo e($data['communion_date'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Officiating Priest <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="priest" value="<?php echo e($sub_priest_clean); ?>" placeholder="e.g. Heriberto C. Villas, O.M.I." required>
                                </div>

                            <?php elseif ($sacrament === 'confirmation'): ?>
                                <div class="col-md-12">
                                    <label class="form-label small fw-bold">Recipient's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="fullname" value="<?php echo e($sub_name); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Father's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="father_name" value="<?php echo e($sub_father); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Mother's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="mother_name" value="<?php echo e($sub_mother); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Date of Confirmation <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="confirmation_date" value="<?php echo e($data['confirmation_date'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Confirming Minister <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="bishop_priest" value="<?php echo e($sub_minister); ?>" placeholder="e.g. Most Rev. Angelito R. Lampon, O.M.I." required>
                                </div>

                            <?php elseif ($sacrament === 'marriage'): ?>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Groom's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="husband_name" value="<?php echo e($sub_husband); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Bride's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="wife_name" value="<?php echo e($sub_wife); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Date of Marriage <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="wedding_date" value="<?php echo e($data['wedding_date'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Officiating Priest <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="officiating_priest" value="<?php echo e($sub_priest_clean); ?>" placeholder="e.g. Heriberto C. Villas, O.M.I." required>
                                </div>

                            <?php elseif ($sacrament === 'funeral'): ?>
                                <div class="col-md-12">
                                    <label class="form-label small fw-bold">Full Name of the Deceased <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="deceased_name" value="<?php echo e($sub_name); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Father's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="father_name" value="<?php echo e($sub_father); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Mother's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="mother_name" value="<?php echo e($sub_mother); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Date of Burial / Funeral Rites <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="date_of_burial" value="<?php echo e($data['date_of_burial'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Officiating Priest <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="minister" value="<?php echo e($sub_priest_clean); ?>" placeholder="e.g. Heriberto C. Villas, O.M.I." required>
                                </div>
                            <?php endif; ?>

                            <!-- Parish Priest (Common to all) -->
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">Parish Priest (Signatory) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="parish_priest" value="<?php echo e($parish_priest_raw); ?>" placeholder="e.g. REV. FR. HERIBERTO C. VILLAS, O.M.I." required>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-check me-1"></i> Save & Update Certificate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Print Validation Script -->
    <script>
    function printCertificate() {
        <?php if (!empty($missing_fields)): ?>
        alert("Cannot generate or print certificate. Please complete all required fields first:\n\n- " + <?php echo json_encode(implode("\n- ", $missing_fields)); ?>);
        const modalEl = document.getElementById('editDetailsModal');
        if (modalEl) {
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }
        return;
        <?php endif; ?>
        window.print();
    }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
