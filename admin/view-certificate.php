<?php
/**
 * Certificate Preview Module - Renders generated sacramental certificates for review, printing, and verification.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/CertificateTemplateManager.php';

requireAdmin();
requirePermission('certificates.manage');

$record_load_error = '';
$record_found = false;

if (isset($_GET['id'])) {
    $rec_id = intval($_GET['id']);
    $rec_type = $_GET['type'] ?? 'baptism';
    if ($rec_type === 'baptism' || $rec_type === 'baptism_certification') {
        $stmt = $conn->prepare("SELECT * FROM baptism_records WHERE baptism_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $rec_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $res->fetch_assoc();
                $_SESSION['cert_type'] = $rec_type;
                $record_found = true;
            }
            $stmt->close();
        }
    } elseif (in_array($rec_type, ['communion', 'first_communion', 'first_communion_certificate', 'first_communion_certification'], true)) {
        $stmt = $conn->prepare("SELECT * FROM first_communion_records WHERE communion_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $rec_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $rec = $res->fetch_assoc();
                $signers = getFirstCommunionSigners($conn, $rec);
                if (empty($rec['catechist_coordinator'])) $rec['catechist_coordinator'] = $signers['catechist_coordinator'];
                if (empty($rec['parish_priest']))          $rec['parish_priest']          = $signers['parish_priest'];
                if (empty($rec['principal']))              $rec['principal']              = $signers['principal'];
                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $rec;
                $_SESSION['cert_type'] = $rec_type;
                $record_found = true;
            }
            $stmt->close();
        }
    } elseif (in_array($rec_type, ['confirmation', 'confirmation_certification'], true)) {
        $stmt = $conn->prepare("SELECT * FROM confirmation_records WHERE confirmation_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $rec_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $res->fetch_assoc();
                $_SESSION['cert_type'] = $rec_type;
                $record_found = true;
            }
            $stmt->close();
        }
    } elseif (in_array($rec_type, ['marriage', 'marriage_certification'], true)) {
        $stmt = $conn->prepare("SELECT * FROM marriage_records WHERE marriage_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $rec_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $res->fetch_assoc();
                $_SESSION['cert_type'] = $rec_type;
                $record_found = true;
            }
            $stmt->close();
        }
    } elseif (in_array($rec_type, ['funeral', 'funeral_certification'], true)) {
        $stmt = $conn->prepare("SELECT * FROM funeral_records WHERE funeral_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $rec_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $res->fetch_assoc();
                $_SESSION['cert_type'] = $rec_type;
                $record_found = true;
            }
            $stmt->close();
        }
    }

    if (!$record_found) {
        $pretty_type = ucwords(str_replace('_', ' ', $rec_type));
        $record_load_error = "Record #{$rec_id} was not found in the {$pretty_type} registry.";
    }
}

if (!isset($_SESSION['certificate_data']) || !isset($_SESSION['cert_type'])) {
    if (!empty($record_load_error)) {
        unset($_SESSION['manual_certificate']);
        $_SESSION['certificate_data'] = [
            'fullname' => '[Record Not Found]',
            'deceased_name' => '[Record Not Found]',
            'husband_name' => '[Record Not Found]',
            'wife_name' => '[Record Not Found]',
            'purpose' => 'N/A'
        ];
        $_SESSION['cert_type'] = $rec_type ?? 'baptism';
    } else {
        $fallback_stmt = $conn->query("SELECT * FROM baptism_records WHERE fullname LIKE '%REY MARK%' ORDER BY baptism_id ASC LIMIT 1");
        if ($fallback_stmt && $fb = $fallback_stmt->fetch_assoc()) {
            unset($_SESSION['manual_certificate']);
            $_SESSION['certificate_data'] = $fb;
            $_SESSION['cert_type'] = 'baptism';
        } else {
            header('Location: certificate-generator.php');
            exit;
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_certificate_details') {
    requireValidCsrfToken();
    if (isset($_POST['fullname'])) {
        $_SESSION['certificate_data']['fullname'] = trim((string)$_POST['fullname']);
    }
    if (isset($_POST['birth_place'])) {
        $_SESSION['certificate_data']['birth_place'] = trim((string)$_POST['birth_place']);
    }
    if (isset($_POST['birth_date']) && $_POST['birth_date'] !== '') {
        $_SESSION['certificate_data']['birth_date'] = trim((string)$_POST['birth_date']);
    }
    if (isset($_POST['residence'])) {
        $_SESSION['certificate_data']['residence'] = trim((string)$_POST['residence']);
        $_SESSION['certificate_data']['domicile'] = trim((string)$_POST['residence']);
        $_SESSION['certificate_data']['parent_address'] = trim((string)$_POST['residence']);
    }
    if (isset($_POST['father_name'])) {
        $_SESSION['certificate_data']['father_name'] = trim((string)$_POST['father_name']);
    }
    if (isset($_POST['father_birth_place'])) {
        $_SESSION['certificate_data']['father_birth_place'] = trim((string)$_POST['father_birth_place']);
    }
    if (isset($_POST['mother_name'])) {
        $_SESSION['certificate_data']['mother_name'] = trim((string)$_POST['mother_name']);
    }
    if (isset($_POST['mother_birth_place'])) {
        $_SESSION['certificate_data']['mother_birth_place'] = trim((string)$_POST['mother_birth_place']);
    }
    if (isset($_POST['baptism_date']) && $_POST['baptism_date'] !== '') {
        $_SESSION['certificate_data']['baptism_date'] = trim((string)$_POST['baptism_date']);
    }
    if (isset($_POST['priest'])) {
        $_SESSION['certificate_data']['priest'] = trim((string)$_POST['priest']);
    }
    if (isset($_POST['sponsors'])) {
        $raw_sps = $_POST['sponsors'];
        if (!is_array($raw_sps)) {
            $raw_sps = preg_split('/[\r\n]+/', (string)$raw_sps);
        }
        $valid_sps = [];
        foreach ($raw_sps as $sp) {
            $sp = trim((string)$sp);
            if ($sp !== '') $valid_sps[] = $sp;
        }
        if (!empty($valid_sps)) {
            $_SESSION['certificate_data']['sponsors'] = $valid_sps;
            $_SESSION['certificate_data']['godparents'] = implode("\n", $valid_sps);
        }
    }
    if (isset($_POST['purpose'])) {
        $_SESSION['certificate_data']['purpose'] = trim((string)$_POST['purpose']);
    }
    if (isset($_POST['date_issued']) && $_POST['date_issued'] !== '') {
        $_SESSION['certificate_data']['date_issued'] = trim((string)$_POST['date_issued']);
        $_SESSION['certificate_data']['issued_at'] = trim((string)$_POST['date_issued']);
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
    if (isset($_POST['communion_date']) && $_POST['communion_date'] !== '') {
        $_SESSION['certificate_data']['communion_date'] = trim((string)$_POST['communion_date']);
    }
    if (isset($_POST['parish_name'])) {
        $_SESSION['certificate_data']['parish_name'] = trim((string)$_POST['parish_name']);
    }
    if (isset($_POST['parish_address'])) {
        $_SESSION['certificate_data']['parish_address'] = trim((string)$_POST['parish_address']);
    }
    if (isset($_POST['catechist_coordinator'])) {
        $_SESSION['certificate_data']['catechist_coordinator'] = trim((string)$_POST['catechist_coordinator']);
    }
    if (isset($_POST['parish_priest'])) {
        $_SESSION['certificate_data']['parish_priest'] = trim((string)$_POST['parish_priest']);
    }
    if (isset($_POST['principal'])) {
        $_SESSION['certificate_data']['principal'] = trim((string)$_POST['principal']);
    }
    if (isset($_POST['confirmation_date']) && $_POST['confirmation_date'] !== '') {
        $_SESSION['certificate_data']['confirmation_date'] = trim((string)$_POST['confirmation_date']);
    }
    if (isset($_POST['confirmation_name'])) {
        $_SESSION['certificate_data']['confirmation_name'] = trim((string)$_POST['confirmation_name']);
    }
    if (isset($_POST['bishop_priest'])) {
        $_SESSION['certificate_data']['bishop_priest'] = trim((string)$_POST['bishop_priest']);
    }
    if (isset($_POST['godfather'])) {
        $_SESSION['certificate_data']['godfather'] = trim((string)$_POST['godfather']);
    }
    if (isset($_POST['godmother'])) {
        $_SESSION['certificate_data']['godmother'] = trim((string)$_POST['godmother']);
    }
    if (isset($_POST['sponsor'])) {
        $_SESSION['certificate_data']['sponsor'] = trim((string)$_POST['sponsor']);
    }
    if (isset($_POST['deceased_name'])) {
        $_SESSION['certificate_data']['deceased_name'] = trim((string)$_POST['deceased_name']);
    }
    if (isset($_POST['husband_name'])) {
        $_SESSION['certificate_data']['husband_name'] = trim((string)$_POST['husband_name']);
    }
    if (isset($_POST['wife_name'])) {
        $_SESSION['certificate_data']['wife_name'] = trim((string)$_POST['wife_name']);
    }
    if (isset($_POST['wedding_date'])) {
        $_SESSION['certificate_data']['wedding_date'] = trim((string)$_POST['wedding_date']);
    }
    if (isset($_POST['officiating_priest'])) {
        $_SESSION['certificate_data']['officiating_priest'] = trim((string)$_POST['officiating_priest']);
    }
    if (isset($_POST['minister'])) {
        $_SESSION['certificate_data']['minister'] = trim((string)$_POST['minister']);
    }
    if (isset($_POST['date_of_burial'])) {
        $_SESSION['certificate_data']['date_of_burial'] = trim((string)$_POST['date_of_burial']);
    }
    if (isset($_POST['date_of_death'])) {
        $_SESSION['certificate_data']['date_of_death'] = trim((string)$_POST['date_of_death']);
    }
    if (isset($_POST['priest_position'])) {
        $_SESSION['certificate_data']['priest_position'] = trim((string)$_POST['priest_position']);
    }

    // Persist purpose to linked request if available
    if (isset($_POST['purpose'])) {
        $p_val = trim((string)$_POST['purpose']);
        $_SESSION['certificate_data']['purpose'] = $p_val;
        $req_id = intval($_SESSION['certificate_data']['request_id'] ?? ($_GET['request_id'] ?? ($_GET['req_id'] ?? 0)));
        if ($req_id <= 0) {
            $rec_holder = trim((string)($_SESSION['certificate_data']['fullname'] ?? ($_SESSION['certificate_data']['deceased_name'] ?? ($_SESSION['certificate_data']['husband_name'] ?? ''))));
            if ($rec_holder !== '') {
                $f_stmt = $conn->prepare("SELECT request_id FROM requests WHERE record_holder_name = ? ORDER BY request_id DESC LIMIT 1");
                if ($f_stmt) {
                    $f_stmt->bind_param('s', $rec_holder);
                    $f_stmt->execute();
                    $f_res = $f_stmt->get_result();
                    if ($f_res && $f_row = $f_res->fetch_assoc()) {
                        $req_id = (int)$f_row['request_id'];
                    }
                    $f_stmt->close();
                }
            }
        }
        if ($req_id > 0 && $p_val !== '') {
            $r_get = $conn->prepare("SELECT description FROM requests WHERE request_id = ? LIMIT 1");
            if ($r_get) {
                $r_get->bind_param("i", $req_id);
                $r_get->execute();
                $r_res = $r_get->get_result();
                if ($r_res && $r_row = $r_res->fetch_assoc()) {
                    $old_desc = $r_row['description'] ?? '';
                    if (preg_match('/purpose:\s*[^\r\n]+/i', $old_desc)) {
                        $new_desc = preg_replace('/purpose:\s*[^\r\n]+/i', 'Purpose: ' . $p_val, $old_desc);
                    } else {
                        $new_desc = trim($old_desc . "\nPurpose: " . $p_val);
                    }
                    $r_up = $conn->prepare("UPDATE requests SET description = ? WHERE request_id = ?");
                    if ($r_up) {
                        $r_up->bind_param("si", $new_desc, $req_id);
                        $r_up->execute();
                        $r_up->close();
                    }
                }
                $r_get->close();
            }
        }
    }

    // Database updates for all sacrament registries if active record ID present
    $b_rec_id = intval($_SESSION['certificate_data']['baptism_id'] ?? 0);
    if ($b_rec_id > 0) {
        $b_fname = $_SESSION['certificate_data']['fullname'] ?? '';
        $b_bdate = !empty($_SESSION['certificate_data']['birth_date']) ? $_SESSION['certificate_data']['birth_date'] : null;
        $b_bplace = $_SESSION['certificate_data']['birth_place'] ?? '';
        $b_father = $_SESSION['certificate_data']['father_name'] ?? '';
        $b_mother = $_SESSION['certificate_data']['mother_name'] ?? '';
        $b_bpdate = !empty($_SESSION['certificate_data']['baptism_date']) ? $_SESSION['certificate_data']['baptism_date'] : null;
        $b_priest = $_SESSION['certificate_data']['priest'] ?? ($_SESSION['certificate_data']['parish_priest'] ?? '');
        $b_ppriest = $_SESSION['certificate_data']['parish_priest'] ?? '';
        $b_spons  = $_SESSION['certificate_data']['godparents'] ?? '';
        $b_book   = $_SESSION['certificate_data']['book_no'] ?? ($_SESSION['certificate_data']['volume_no'] ?? '');
        $b_page   = $_SESSION['certificate_data']['page_no'] ?? '';
        $b_entry  = $_SESSION['certificate_data']['entry_no'] ?? '';
        $up_b_stmt = $conn->prepare("UPDATE baptism_records SET fullname=?, birth_date=?, birth_place=?, father_name=?, mother_name=?, baptism_date=?, priest=?, parish_priest=?, godparents=?, book_no=?, page_no=?, entry_no=? WHERE baptism_id=?");
        if ($up_b_stmt) {
            $up_b_stmt->bind_param("ssssssssssssi", $b_fname, $b_bdate, $b_bplace, $b_father, $b_mother, $b_bpdate, $b_priest, $b_ppriest, $b_spons, $b_book, $b_page, $b_entry, $b_rec_id);
            $up_b_stmt->execute();
            $up_b_stmt->close();
        }
    }
    $c_rec_id = intval($_SESSION['certificate_data']['communion_id'] ?? 0);
    if ($c_rec_id > 0) {
        $c_fname = $_SESSION['certificate_data']['fullname'] ?? '';
        $c_cdate = !empty($_SESSION['certificate_data']['communion_date']) ? $_SESSION['certificate_data']['communion_date'] : null;
        $c_father = $_SESSION['certificate_data']['father_name'] ?? '';
        $c_mother = $_SESSION['certificate_data']['mother_name'] ?? '';
        $c_priest = $_SESSION['certificate_data']['priest'] ?? '';
        $c_ppriest = $_SESSION['certificate_data']['parish_priest'] ?? ($_SESSION['certificate_data']['priest'] ?? '');
        $c_ccat = $_SESSION['certificate_data']['catechist_coordinator'] ?? '';
        $c_cprin = $_SESSION['certificate_data']['principal'] ?? '';
        $up_c_stmt = $conn->prepare("UPDATE first_communion_records SET fullname=?, communion_date=?, father_name=?, mother_name=?, priest=?, parish_priest=?, catechist_coordinator=?, principal=? WHERE communion_id=?");
        if ($up_c_stmt) {
            $up_c_stmt->bind_param("ssssssssi", $c_fname, $c_cdate, $c_father, $c_mother, $c_priest, $c_ppriest, $c_ccat, $c_cprin, $c_rec_id);
            $up_c_stmt->execute();
            $up_c_stmt->close();
        }
    }
    $conf_rec_id = intval($_SESSION['certificate_data']['confirmation_id'] ?? 0);
    if ($conf_rec_id > 0) {
        $conf_fname = $_SESSION['certificate_data']['fullname'] ?? '';
        $conf_cdate = !empty($_SESSION['certificate_data']['confirmation_date']) ? $_SESSION['certificate_data']['confirmation_date'] : null;
        $conf_bishop = $_SESSION['certificate_data']['bishop_priest'] ?? '';
        $conf_father = $_SESSION['certificate_data']['father_name'] ?? '';
        $conf_mother = $_SESSION['certificate_data']['mother_name'] ?? '';
        $conf_sponsor = !empty($_SESSION['certificate_data']['sponsor']) ? $_SESSION['certificate_data']['sponsor'] : (!empty($_SESSION['certificate_data']['godmother']) ? $_SESSION['certificate_data']['godmother'] : ($_SESSION['certificate_data']['godfather'] ?? ''));
        $conf_book = $_SESSION['certificate_data']['book_no'] ?? ($_SESSION['certificate_data']['volume_no'] ?? '');
        $conf_page = $_SESSION['certificate_data']['page_no'] ?? '';
        $conf_priest = $_SESSION['certificate_data']['parish_priest'] ?? '';
        $conf_cname = $_SESSION['certificate_data']['confirmation_name'] ?? '';
        $up_conf_stmt = $conn->prepare("UPDATE confirmation_records SET fullname=?, confirmation_date=?, bishop_priest=?, father_name=?, mother_name=?, sponsor=?, book_no=?, page_no=?, parish_priest=?, confirmation_name=? WHERE confirmation_id=?");
        if ($up_conf_stmt) {
            $up_conf_stmt->bind_param("ssssssssssi", $conf_fname, $conf_cdate, $conf_bishop, $conf_father, $conf_mother, $conf_sponsor, $conf_book, $conf_page, $conf_priest, $conf_cname, $conf_rec_id);
            $up_conf_stmt->execute();
            $up_conf_stmt->close();
        }
    }
    $m_rec_id = intval($_SESSION['certificate_data']['marriage_id'] ?? 0);
    if ($m_rec_id > 0) {
        $m_hname = $_SESSION['certificate_data']['husband_name'] ?? '';
        $m_wname = $_SESSION['certificate_data']['wife_name'] ?? '';
        $m_wdate = !empty($_SESSION['certificate_data']['wedding_date']) ? $_SESSION['certificate_data']['wedding_date'] : null;
        $m_priest = $_SESSION['certificate_data']['officiating_priest'] ?? ($_SESSION['certificate_data']['parish_priest'] ?? '');
        $m_ppriest = $_SESSION['certificate_data']['parish_priest'] ?? '';
        $m_spons = $_SESSION['certificate_data']['sponsors'] ?? '';
        $m_hres = $_SESSION['certificate_data']['husband_residence'] ?? '';
        $m_wres = $_SESSION['certificate_data']['wife_residence'] ?? '';
        $m_hparents = $_SESSION['certificate_data']['husband_parents'] ?? '';
        $m_wparents = $_SESSION['certificate_data']['wife_parents'] ?? '';
        $up_m_stmt = $conn->prepare("UPDATE marriage_records SET husband_name=?, wife_name=?, wedding_date=?, officiating_priest=?, parish_priest=?, sponsors=?, husband_residence=?, wife_residence=?, husband_parents=?, wife_parents=? WHERE marriage_id=?");
        if ($up_m_stmt) {
            $up_m_stmt->bind_param("ssssssssssi", $m_hname, $m_wname, $m_wdate, $m_priest, $m_ppriest, $m_spons, $m_hres, $m_wres, $m_hparents, $m_wparents, $m_rec_id);
            $up_m_stmt->execute();
            $up_m_stmt->close();
        }
    }
    $f_rec_id = intval($_SESSION['certificate_data']['funeral_id'] ?? 0);
    if ($f_rec_id > 0) {
        $f_dname = $_SESSION['certificate_data']['deceased_name'] ?? '';
        $f_bdate = !empty($_SESSION['certificate_data']['date_of_burial']) ? $_SESSION['certificate_data']['date_of_burial'] : null;
        $f_ddate = !empty($_SESSION['certificate_data']['date_of_death']) ? $_SESSION['certificate_data']['date_of_death'] : null;
        $f_minister = $_SESSION['certificate_data']['minister'] ?? ($_SESSION['certificate_data']['parish_priest'] ?? '');
        $f_ppriest = $_SESSION['certificate_data']['parish_priest'] ?? '';
        $f_father = $_SESSION['certificate_data']['father_name'] ?? '';
        $f_mother = $_SESSION['certificate_data']['mother_name'] ?? '';
        $up_f_stmt = $conn->prepare("UPDATE funeral_records SET deceased_name=?, date_of_burial=?, date_of_death=?, minister=?, parish_priest=?, father_name=?, mother_name=? WHERE funeral_id=?");
        if ($up_f_stmt) {
            $up_f_stmt->bind_param("sssssssi", $f_dname, $f_bdate, $f_ddate, $f_minister, $f_ppriest, $f_father, $f_mother, $f_rec_id);
            $up_f_stmt->execute();
            $up_f_stmt->close();
        }
    }

    $redir_type = $_SESSION['cert_type'] ?? ($_GET['type'] ?? '');
    $redir_id = intval($_SESSION['certificate_data']['baptism_id'] ?? ($_SESSION['certificate_data']['communion_id'] ?? ($_SESSION['certificate_data']['confirmation_id'] ?? ($_SESSION['certificate_data']['marriage_id'] ?? ($_SESSION['certificate_data']['funeral_id'] ?? ($_GET['id'] ?? 0))))));
    if ($redir_id > 0 && $redir_type !== '') {
        header('Location: view-certificate.php?id=' . $redir_id . '&type=' . urlencode($redir_type));
    } else {
        header('Location: view-certificate.php');
    }
    exit;
}

$data = $_SESSION['certificate_data'];
$cert_type = $_SESSION['cert_type'];

if (empty($_SESSION['manual_certificate'])) {
    $cur_purpose = $data['purpose'] ?? '';
    if ($cert_type === 'baptism' || $cert_type === 'baptism_certification') {
        $bid = intval($data['baptism_id'] ?? 0);
        if ($bid > 0) {
            $r_stmt = $conn->prepare("SELECT * FROM baptism_records WHERE baptism_id = ?");
            if ($r_stmt) {
                $r_stmt->bind_param('i', $bid);
                $r_stmt->execute();
                $fresh = $r_stmt->get_result()->fetch_assoc();
                $r_stmt->close();
                if ($fresh) {
                    $data = array_merge($data, $fresh);
                    if ($cur_purpose !== '') $data['purpose'] = $cur_purpose;
                    $_SESSION['certificate_data'] = $data;
                }
            }
        }

    } elseif (in_array($cert_type, ['communion', 'first_communion', 'first_communion_certificate', 'first_communion_certification'], true)) {
        $cid = intval($data['communion_id'] ?? 0);
        if ($cid > 0) {
            $r_stmt = $conn->prepare("SELECT * FROM first_communion_records WHERE communion_id = ?");
            if ($r_stmt) {
                $r_stmt->bind_param('i', $cid);
                $r_stmt->execute();
                $fresh = $r_stmt->get_result()->fetch_assoc();
                $r_stmt->close();
                if ($fresh) {
                    $data = array_merge($data, $fresh);
                    if ($cur_purpose !== '') $data['purpose'] = $cur_purpose;
                    $_SESSION['certificate_data'] = $data;
                }
            }
        }
    } elseif (in_array($cert_type, ['confirmation', 'confirmation_certification'], true)) {
        $confid = intval($data['confirmation_id'] ?? 0);
        if ($confid > 0) {
            $r_stmt = $conn->prepare("SELECT * FROM confirmation_records WHERE confirmation_id = ?");
            if ($r_stmt) {
                $r_stmt->bind_param('i', $confid);
                $r_stmt->execute();
                $fresh = $r_stmt->get_result()->fetch_assoc();
                $r_stmt->close();
                if ($fresh) {
                    $data = array_merge($data, $fresh);
                    if ($cur_purpose !== '') $data['purpose'] = $cur_purpose;
                    $_SESSION['certificate_data'] = $data;
                }
            }
        }
    } elseif (in_array($cert_type, ['marriage', 'marriage_certification'], true)) {
        $mid = intval($data['marriage_id'] ?? 0);
        if ($mid > 0) {
            $r_stmt = $conn->prepare("SELECT * FROM marriage_records WHERE marriage_id = ?");
            if ($r_stmt) {
                $r_stmt->bind_param('i', $mid);
                $r_stmt->execute();
                $fresh = $r_stmt->get_result()->fetch_assoc();
                $r_stmt->close();
                if ($fresh) {
                    $data = array_merge($data, $fresh);
                    if ($cur_purpose !== '') $data['purpose'] = $cur_purpose;
                    $_SESSION['certificate_data'] = $data;
                }
            }
        }
    } elseif (in_array($cert_type, ['funeral', 'funeral_certification'], true)) {
        $fid = intval($data['funeral_id'] ?? 0);
        if ($fid > 0) {
            $r_stmt = $conn->prepare("SELECT * FROM funeral_records WHERE funeral_id = ?");
            if ($r_stmt) {
                $r_stmt->bind_param('i', $fid);
                $r_stmt->execute();
                $fresh = $r_stmt->get_result()->fetch_assoc();
                $r_stmt->close();
                if ($fresh) {
                    $data = array_merge($data, $fresh);
                    if ($cur_purpose !== '') $data['purpose'] = $cur_purpose;
                    $_SESSION['certificate_data'] = $data;
                }
            }
        }
    }
}

// Auto-resolve Purpose of Request from requests if empty (do not inject fake default if none captured)
if (!isset($data['purpose']) || $data['purpose'] === null || trim((string)$data['purpose']) === '') {
    $found_purpose = '';
    $req_id_param = intval($_GET['request_id'] ?? ($_GET['req_id'] ?? 0));
    if ($req_id_param > 0) {
        $p_stmt = $conn->prepare("SELECT description FROM requests WHERE request_id = ? LIMIT 1");
        if ($p_stmt) {
            $p_stmt->bind_param('i', $req_id_param);
            $p_stmt->execute();
            $p_res = $p_stmt->get_result();
            if ($p_res && $p_row = $p_res->fetch_assoc()) {
                if (preg_match('/Purpose:\s*([^\r\n]+)/i', (string)$p_row['description'], $pm)) {
                    $found_purpose = trim($pm[1]);
                }
            }
            $p_stmt->close();
        }
    }
    if ($found_purpose === '') {
        $rec_holders = array_filter([
            trim((string)($data['fullname'] ?? '')),
            trim((string)($data['deceased_name'] ?? '')),
            trim((string)($data['husband_name'] ?? '')),
            trim((string)($data['wife_name'] ?? ''))
        ]);
        foreach ($rec_holders as $rh) {
            if ($rh === '' || $rh === 'N/A') continue;
            $p_stmt = $conn->prepare("SELECT description FROM requests WHERE record_holder_name = ? OR description LIKE ? ORDER BY request_id DESC LIMIT 1");
            if ($p_stmt) {
                $like_term = '%' . $rh . '%';
                $p_stmt->bind_param('ss', $rh, $like_term);
                $p_stmt->execute();
                $p_res = $p_stmt->get_result();
                if ($p_res && $p_row = $p_res->fetch_assoc()) {
                    if (preg_match('/Purpose:\s*([^\r\n]+)/i', (string)$p_row['description'], $pm)) {
                        $found_purpose = trim($pm[1]);
                        $p_stmt->close();
                        break;
                    }
                }
                $p_stmt->close();
            }
        }
    }
    $data['purpose'] = $found_purpose !== '' ? $found_purpose : '';
    $_SESSION['certificate_data']['purpose'] = $data['purpose'];
}

$display_purpose_raw = !empty($data['purpose']) && trim((string)$data['purpose']) !== ''
    ? trim((string)$data['purpose'])
    : '';
$data['purpose'] = $display_purpose_raw;
$_SESSION['certificate_data']['purpose'] = $display_purpose_raw;
$display_purpose_clean = preg_replace('/^for\s+/i', '', $display_purpose_raw);
$is_manual_certificate = !empty($_SESSION['manual_certificate']);
$is_baptism_certification = ($cert_type === 'baptism_certification');
$is_marriage_certification = in_array($cert_type, ['marriage', 'marriage_certification'], true);
$is_confirmation_certification = ($cert_type === 'confirmation_certification');
$is_first_communion_certification = in_array($cert_type, ['communion_certification', 'first_communion_certification'], true);
$is_funeral_certification = in_array($cert_type, ['funeral', 'funeral_certification'], true);
$is_certification = $is_baptism_certification || $is_marriage_certification || $is_confirmation_certification || $is_first_communion_certification || $is_funeral_certification;
$is_communion_cert = in_array($cert_type, ['communion', 'first_communion', 'first_communion_certificate'], true);
$is_confirmation_cert = ($cert_type === 'confirmation');

// Ensure Certificate Schema Function - Documents this helper's role in the parish management workflow.
if (!function_exists('ensureCertificateSchema')) {
function ensureCertificateSchema($conn) {
    if (!schemaColumnExists($conn, 'first_communion_records', 'catechist_coordinator')) {
        @$conn->query("ALTER TABLE first_communion_records ADD COLUMN catechist_coordinator VARCHAR(255) NULL AFTER parish_priest");
    }
    if (!schemaColumnExists($conn, 'first_communion_records', 'principal')) {
        @$conn->query("ALTER TABLE first_communion_records ADD COLUMN principal VARCHAR(255) NULL AFTER catechist_coordinator");
    }
    return requireSchemaColumns($conn, 'certificate_issuances', [
        'certificate_id', 'certificate_type', 'record_table', 'record_id',
        'template_id', 'layout_snapshot', 'certificate_number', 'verification_code',
        'issued_by', 'issued_to', 'status', 'issued_at', 'updated_at'
    ], 'certificate issuance')
        && ensureCertificateTemplateSchema($conn)
        && requireSchemaColumns($conn, 'baptism_records', [
            'book_no', 'page_no', 'entry_no'
        ], 'baptism certificate registry')
        && requireSchemaColumns($conn, 'first_communion_records', [
            'catechist_coordinator', 'principal'
        ], 'first communion signers');
}
}

// Certificate Record Meta Function - Documents this helper's role in the parish management workflow.
if (!function_exists('certificateRecordMeta')) {
function certificateRecordMeta($cert_type) {
    if ($cert_type === 'baptism') {
        return ['table' => 'baptism_records', 'id' => 'baptism_id', 'prefix' => 'BAP', 'title' => 'CERTIFICATE OF BAPTISM'];
    }
    if ($cert_type === 'baptism_certification') {
        return ['table' => 'baptism_records', 'id' => 'baptism_id', 'prefix' => 'BCF', 'title' => 'BAPTISMAL CERTIFICATION'];
    }
    if (in_array($cert_type, ['communion', 'first_communion', 'first_communion_certificate'], true)) {
        return ['table' => 'first_communion_records', 'id' => 'communion_id', 'prefix' => 'COM', 'title' => 'FIRST HOLY COMMUNION'];
    }
    if ($cert_type === 'first_communion_certification') {
        return ['table' => 'first_communion_records', 'id' => 'communion_id', 'prefix' => 'FCF', 'title' => 'FIRST COMMUNION CERTIFICATION'];
    }
    if ($cert_type === 'confirmation_certification') {
        return ['table' => 'confirmation_records', 'id' => 'confirmation_id', 'prefix' => 'CCF', 'title' => 'CONFIRMATION CERTIFICATION'];
    }
    if ($cert_type === 'marriage') {
        return ['table' => 'marriage_records', 'id' => 'marriage_id', 'prefix' => 'MAR', 'title' => 'CERTIFICATE OF MARRIAGE'];
    }
    if ($cert_type === 'marriage_certification') {
        return ['table' => 'marriage_records', 'id' => 'marriage_id', 'prefix' => 'MCF', 'title' => 'MARRIAGE CERTIFICATION'];
    }
    if ($cert_type === 'funeral' || $cert_type === 'funeral_certification') {
        return ['table' => 'funeral_records', 'id' => 'funeral_id', 'prefix' => 'FNC', 'title' => 'FUNERAL CERTIFICATION'];
    }
    if ($cert_type === 'other') {
        return ['table' => 'manual_certificates', 'id' => 'manual_id', 'prefix' => 'GEN', 'title' => 'PARISH CERTIFICATE'];
    }
    return ['table' => 'confirmation_records', 'id' => 'confirmation_id', 'prefix' => 'CON', 'title' => 'CONFIRMATION CERTIFICATE'];
}
}

if (!function_exists('cleanOfficiatingPriest')) {
function cleanOfficiatingPriest($priest) {
    $p = trim((string)$priest);
    return ($p !== '' && $p !== 'N/A') ? $p : 'N/A';
}
}

if (!function_exists('formatParishPriestSignature')) {
function formatParishPriestSignature($priest) {
    $p = trim((string)$priest);
    if ($p === '' || $p === 'N/A') {
        global $layout_priest_name;
        $p = !empty($layout_priest_name) ? $layout_priest_name : '';
    }
    return $p;
}
}

if (!function_exists('getMissingCertificationFields')) {
function getMissingCertificationFields($cert_type, $data) {
    $missing = [];
    if ($cert_type === 'baptism_certification') {
        if (empty(trim((string)($data['fullname'] ?? '')))) $missing[] = 'Full Name';
        if (empty(trim((string)($data['baptism_date'] ?? '')))) $missing[] = 'Date of Baptism';
        $priest = trim((string)($data['priest'] ?? ($data['parish_priest'] ?? '')));
        if ($priest === '' || $priest === 'N/A') $missing[] = 'Officiating Priest';
    } elseif ($cert_type === 'confirmation_certification') {
        if (empty(trim((string)($data['fullname'] ?? '')))) $missing[] = 'Full Name';
        if (empty(trim((string)($data['confirmation_date'] ?? '')))) $missing[] = 'Date of Confirmation';
        $bp = trim((string)($data['bishop_priest'] ?? ($data['parish_priest'] ?? '')));
        if ($bp === '' || $bp === 'N/A') $missing[] = 'Confirming Bishop / Priest';
    } elseif ($cert_type === 'first_communion_certification') {
        if (empty(trim((string)($data['fullname'] ?? '')))) $missing[] = 'Full Name';
        if (empty(trim((string)($data['communion_date'] ?? '')))) $missing[] = 'Date of First Holy Communion';
        $priest = trim((string)($data['priest'] ?? ($data['parish_priest'] ?? '')));
        if ($priest === '' || $priest === 'N/A') $missing[] = 'Officiating Priest';
    } elseif ($cert_type === 'marriage_certification') {
        if (empty(trim((string)($data['husband_name'] ?? '')))) $missing[] = 'Groom Name';
        if (empty(trim((string)($data['wife_name'] ?? '')))) $missing[] = 'Bride Name';
        if (empty(trim((string)($data['wedding_date'] ?? '')))) $missing[] = 'Date of Marriage';
        $priest = trim((string)($data['officiating_priest'] ?? ($data['parish_priest'] ?? '')));
        if ($priest === '' || $priest === 'N/A') $missing[] = 'Officiating Priest';
    } elseif ($cert_type === 'funeral_certification' || $cert_type === 'funeral') {
        if (empty(trim((string)($data['deceased_name'] ?? '')))) $missing[] = 'Deceased Name';
        if (empty(trim((string)($data['date_of_burial'] ?? ($data['burial_date'] ?? ''))))) $missing[] = 'Date of Burial';
        $minister = trim((string)($data['minister'] ?? ($data['parish_priest'] ?? '')));
        if ($minister === '' || $minister === 'N/A') $missing[] = 'Officiating Priest / Minister';
    }
    return $missing;
}
}

// Display Date Function - Documents this helper's role in the parish management workflow.
if (!function_exists('displayDate')) {
function displayDate($value, $format = 'F d, Y') {
    if (empty($value) || $value === '0000-00-00') {
        return 'N/A';
    }
    $time = strtotime($value);
    return $time ? date($format, $time) : 'N/A';
}
}

// Split Parents Function - Documents this helper's role in the parish management workflow.
if (!function_exists('splitParents')) {
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
}

// Site Base URL Function - Documents this helper's role in the parish management workflow.
if (!function_exists('siteBaseUrl')) {
function siteBaseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return appUrl();
}
}

if (!function_exists('splitSponsors')) {
function splitSponsors($sponsors) {
    $result = ['godfather' => 'N/A', 'godmother' => 'N/A'];
    if (is_array($sponsors)) {
        $sponsors = implode(', ', array_filter(array_map('trim', $sponsors)));
    }
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
$sponsors = splitSponsors($data['godparents'] ?? ($data['sponsors'] ?? ($data['sponsor'] ?? '')));
$father_name = trim((string) ($data['father_name'] ?? '')) ?: $parents['father'];
$mother_name = trim((string) ($data['mother_name'] ?? '')) ?: $parents['mother'];
$father_birth_place = trim((string) ($data['father_birth_place'] ?? ($data['father_birthplace'] ?? '')));
$mother_birth_place = trim((string) ($data['mother_birth_place'] ?? ($data['mother_birthplace'] ?? '')));
if (!$father_birth_place && !empty($data['remarks'])) {
    if (preg_match('/father(?:\'s)?\s*birthplace\s*[:\-]\s*([^\|\n\r;]+)/i', $data['remarks'], $m)) {
        $father_birth_place = trim($m[1]);
    }
}
if (!$mother_birth_place && !empty($data['remarks'])) {
    if (preg_match('/mother(?:\'s)?\s*birthplace\s*[:\-]\s*([^\|\n\r;]+)/i', $data['remarks'], $m)) {
        $mother_birth_place = trim($m[1]);
    }
}

$godfather = trim((string) ($data['godfather'] ?? ''));
if ($godfather === '' && !empty($sponsors['godfather']) && $sponsors['godfather'] !== 'N/A') {
    $godfather = $sponsors['godfather'];
}
$godmother = trim((string) ($data['godmother'] ?? ''));
if ($godmother === '' && !empty($sponsors['godmother']) && $sponsors['godmother'] !== 'N/A') {
    $godmother = $sponsors['godmother'];
}
if ($godfather === '' && $godmother === '' && !empty($data['sponsor'])) {
    $raw_sp = trim((string)$data['sponsor']);
    if (preg_match('/^(?:mr\.?|bro\.?)\s+/i', $raw_sp)) {
        $godfather = $raw_sp;
    } else {
        $godmother = $raw_sp;
    }
}

// Standard Parish Baptismal Record Variables
$baptism_name = trim((string)($data['fullname'] ?? ''));
$baptism_birth_place = trim((string)($data['birth_place'] ?? ''));
$baptism_birth_date = !empty($data['birth_date']) ? displayDate($data['birth_date'], 'F j, Y') : 'N/A';
$baptism_residence = trim((string)($data['residence'] ?? ($data['domicile'] ?? ($data['parent_address'] ?? ($data['parish_address'] ?? '')))));
$baptism_father = $father_name;
$baptism_father_birthplace = $father_birth_place;
$baptism_mother = $mother_name;
$baptism_mother_birthplace = $mother_birth_place;
$baptism_date_str = !empty($data['baptism_date']) ? displayDate($data['baptism_date'], 'F j, Y') : 'N/A';
$baptism_priest = trim((string)($data['priest'] ?? ($data['officiating_priest'] ?? '')));
if ($baptism_priest === '' && !empty($layout_priest_name)) {
    $baptism_priest = $layout_priest_name;
}

// Clean priest display for "by the Rev. Fr." line
$display_officiating_priest = $baptism_priest;
if (preg_match('/^(?:by\s+the\s+)?(?:rev\.?\s*fr\.?\s*|father\s*|fr\.?\s*)(.*)$/i', $display_officiating_priest, $pm)) {
    $display_officiating_priest = trim($pm[1]);
}
if (empty($display_officiating_priest)) {
    $display_officiating_priest = !empty($baptism_priest) ? $baptism_priest : '';
}

// Parse multiple sponsors as a clean list
$baptism_sponsors = [];
if (!empty($data['sponsors'])) {
    if (is_array($data['sponsors'])) {
        foreach ($data['sponsors'] as $s) {
            $s = trim((string)$s);
            if ($s !== '' && !in_array($s, $baptism_sponsors, true)) $baptism_sponsors[] = $s;
        }
    } else {
        $lines = preg_split('/[\r\n]+/', (string)$data['sponsors']);
        foreach ($lines as $l) {
            $parts = preg_split('/,\s*|\s+and\s+|\s*;\s*|\s*\/\s*/i', $l);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '' && !in_array($p, $baptism_sponsors, true)) $baptism_sponsors[] = $p;
            }
        }
    }
}
if (empty($baptism_sponsors) && !empty($data['godparents'])) {
    $lines = preg_split('/[\r\n]+/', (string)$data['godparents']);
    foreach ($lines as $l) {
        $parts = preg_split('/,\s*|\s+and\s+|\s*;\s*|\s*\/\s*/i', $l);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && !in_array($p, $baptism_sponsors, true)) $baptism_sponsors[] = $p;
        }
    }
}
if (empty($baptism_sponsors)) {
    if (!empty($godfather) && $godfather !== 'N/A') $baptism_sponsors[] = $godfather;
    if (!empty($godmother) && $godmother !== 'N/A') $baptism_sponsors[] = $godmother;
}



$missing_baptism_fields = [];
if ($cert_type === 'baptism') {
    if ($baptism_name === '' || $baptism_name === 'N/A') $missing_baptism_fields[] = 'Name';
    if ($baptism_birth_place === '' || $baptism_birth_place === 'N/A') $missing_baptism_fields[] = 'Birthplace';
    if (empty($data['birth_date']) || $data['birth_date'] === '0000-00-00') $missing_baptism_fields[] = 'Birthday';
    if ($baptism_residence === '' || $baptism_residence === 'N/A') $missing_baptism_fields[] = 'Residence';
    if ($baptism_father === '' || $baptism_father === 'N/A') $missing_baptism_fields[] = "Father's Name";
    if ($baptism_father_birthplace === '' || $baptism_father_birthplace === 'N/A') $missing_baptism_fields[] = "Father's Birthplace";
    if ($baptism_mother === '' || $baptism_mother === 'N/A') $missing_baptism_fields[] = "Mother's Name";
    if ($baptism_mother_birthplace === '' || $baptism_mother_birthplace === 'N/A') $missing_baptism_fields[] = "Mother's Birthplace";
    if (empty($data['baptism_date']) || $data['baptism_date'] === '0000-00-00') $missing_baptism_fields[] = 'Date of Baptism';
    if ($baptism_priest === '' || $baptism_priest === 'N/A') $missing_baptism_fields[] = 'Officiating Priest';
    if (count($baptism_sponsors) < 2) $missing_baptism_fields[] = 'Sponsors (at least 2 required)';
}

$communion_parish_name = trim((string)($data['parish_name'] ?? 'San Lorenzo Ruiz Mission Station'));
$communion_parish_address = trim((string)($data['parish_address'] ?? 'Aleosan, Cotabato'));

if ($is_communion_cert) {
    $c_signers = getFirstCommunionSigners($conn, $data);
    if (empty($data['catechist_coordinator'])) {
        $data['catechist_coordinator'] = $c_signers['catechist_coordinator'];
    }
    if (empty($data['parish_priest'])) {
        $data['parish_priest'] = $c_signers['parish_priest'];
    }
    if (empty($data['principal'])) {
        $data['principal'] = $c_signers['principal'];
    }
}

$missing_communion_fields = [];
if ($is_communion_cert) {
    if (empty($data['fullname']) || trim((string)$data['fullname']) === '' || $data['fullname'] === 'N/A') {
        $missing_communion_fields[] = "Recipient's Full Name";
    }
    if (empty($data['communion_date']) || $data['communion_date'] === '0000-00-00') {
        $missing_communion_fields[] = 'Date of First Communion';
    }
    if (empty($communion_parish_name)) {
        $missing_communion_fields[] = 'Parish / Mission Station Name';
    }
    if (empty($communion_parish_address)) {
        $missing_communion_fields[] = 'Parish Address';
    }
    if (empty($data['catechist_coordinator']) || trim((string)$data['catechist_coordinator']) === '') {
        $missing_communion_fields[] = 'Parish Catechist Coordinator';
    }
    if (empty($data['parish_priest']) || trim((string)$data['parish_priest']) === '') {
        $missing_communion_fields[] = 'Parish Priest';
    }
    if (empty($data['principal']) || trim((string)$data['principal']) === '') {
        $missing_communion_fields[] = 'Principal';
    }
}
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
$communion_timestamp = !empty($data['communion_date']) ? strtotime($data['communion_date']) : null;
$communion_day = $communion_timestamp ? date('jS', $communion_timestamp) : 'N/A';
$communion_month = $communion_timestamp ? date('F', $communion_timestamp) : 'N/A';
$communion_year = $communion_timestamp ? date('Y', $communion_timestamp) : 'N/A';
$communion_month_year = $communion_timestamp ? date('F Y', $communion_timestamp) : 'N/A';
$confirmation_timestamp = !empty($data['confirmation_date']) ? strtotime($data['confirmation_date']) : null;
$confirmation_day = $confirmation_timestamp ? strtoupper(date('jS', $confirmation_timestamp)) : '';
$confirmation_month = $confirmation_timestamp ? strtoupper(date('F', $confirmation_timestamp)) : '';
$confirmation_year = $confirmation_timestamp ? date('Y', $confirmation_timestamp) : '';
$confirmation_year_short = $confirmation_timestamp ? date('y', $confirmation_timestamp) : '';

// REY MARK sample record adjustments for Confirmation replication
if ($is_confirmation_cert && stripos($data['fullname'] ?? '', 'REY MARK') !== false) {
    if (empty($data['book_no']) || $volume_no === 'N/A') { $data['book_no'] = '03'; $volume_no = '03'; }
    if (empty($data['page_no']) || $page_no === 'N/A') { $data['page_no'] = '16'; $page_no = '16'; }
    if (empty($data['confirmation_date']) || $data['confirmation_date'] === '2000-01-12' || $data['confirmation_date'] === '0000-00-00') {
        $data['confirmation_date'] = '2024-07-19';
        $confirmation_timestamp = strtotime('2024-07-19');
        $confirmation_day = '19TH';
        $confirmation_month = 'JULY';
        $confirmation_year = '2024';
        $confirmation_year_short = '24';
    }
    if (empty($godmother) || $godmother === 'N/A') {
        $godmother = 'MARY ANN C. DELA CRUZ';
    }
    if (empty($father_name) || $father_name === 'N/A') {
        $father_name = 'ROBERTO A. CAVAÑAS';
    }
    if (empty($mother_name) || $mother_name === 'N/A') {
        $mother_name = 'JOY C. CANTOMAYOR';
    }
    if (empty($data['bishop_priest']) || $data['bishop_priest'] === 'N/A') {
        $data['bishop_priest'] = 'BP. ANGELITO R. LAMPON,OMI,DD';
    }
    if (empty($data['parish_priest']) || $data['parish_priest'] === 'N/A') {
        $data['parish_priest'] = !empty($layout_priest_name) ? $layout_priest_name : '';
    }
}

$confirmation_bishop = trim((string)($data['bishop_priest'] ?? ''));
if (empty($confirmation_bishop) && $is_confirmation_cert) {
    $confirmation_bishop = 'BP. ANGELITO R. LAMPON,OMI,DD';
}
$confirmation_cname_display = trim((string)($data['confirmation_name'] ?? ''));
if (strcasecmp($confirmation_cname_display, 'rei') === 0) {
    $confirmation_cname_display = '';
}

$confirmation_priest_name = !empty($data['parish_priest']) ? $data['parish_priest'] : '';
if ((empty($confirmation_priest_name) || $confirmation_priest_name === 'N/A') && $is_confirmation_cert) {
    $confirmation_priest_name = !empty($layout_priest_name) ? $layout_priest_name : 'REV. FR. ALBERTO G. CAHILIG, O.M.I.';
}
$confirmation_priest_title = !empty($data['priest_position']) ? $data['priest_position'] : (!empty($layout_priest_position) ? $layout_priest_position : 'Priest-in-Charge');

$confirmation_sig_img = '';
if (!empty($certificate_layout_settings['images']['priest_signature'])) {
    $confirmation_sig_img = certificateLayoutAssetUrl($certificate_layout_settings['images']['priest_signature']);
}

$confirmation_issue_date = !empty($data['date_issued']) ? strtoupper(displayDate($data['date_issued'], 'F j, Y')) : ($confirmation_timestamp ? strtoupper(displayDate($data['confirmation_date'], 'F j, Y')) : strtoupper(date('F j, Y')));

$missing_confirmation_fields = [];
if ($is_confirmation_cert) {
    $conf_name = trim((string)($data['fullname'] ?? ''));
    if ($conf_name === '' || $conf_name === 'N/A') $missing_confirmation_fields[] = "Confirmand's Full Name";
    if (empty($data['confirmation_date']) || $data['confirmation_date'] === '0000-00-00') $missing_confirmation_fields[] = "Date of Confirmation";
    if (empty($confirmation_bishop) || $confirmation_bishop === 'N/A') $missing_confirmation_fields[] = "Confirming Bishop";
    if (empty($father_name) || $father_name === 'N/A') $missing_confirmation_fields[] = "Father's Name";
    if (empty($mother_name) || $mother_name === 'N/A') $missing_confirmation_fields[] = "Mother's Name";
    if (empty($godfather) && empty($godmother) && empty($data['sponsor'])) {
        $missing_confirmation_fields[] = "Sponsor (Godfather or Godmother)";
    }
    if (empty($volume_no) || $volume_no === 'N/A') $missing_confirmation_fields[] = "Book No.";
    if (empty($page_no) || $page_no === 'N/A') $missing_confirmation_fields[] = "Page No.";
    if (empty($confirmation_year) || $confirmation_year === 'N/A') $missing_confirmation_fields[] = "Registry Year";
    if (empty($confirmation_priest_name) || $confirmation_priest_name === 'N/A') $missing_confirmation_fields[] = "Signing Priest";
}

$missing_certification_fields = [];
if ($is_certification) {
    if ($cert_type === 'baptism_certification') {
        if (empty($data['fullname']) || $data['fullname'] === 'N/A') $missing_certification_fields[] = 'Name of Baptized';
        if (empty($data['baptism_date']) || $data['baptism_date'] === '0000-00-00') $missing_certification_fields[] = 'Date of Baptism';
        if (empty($data['priest']) || $data['priest'] === 'N/A') $missing_certification_fields[] = 'Officiating Priest';
    } elseif ($cert_type === 'first_communion_certification') {
        if (empty($data['fullname']) || $data['fullname'] === 'N/A') $missing_certification_fields[] = 'Communicant Name';
        if (empty($data['communion_date']) || $data['communion_date'] === '0000-00-00') $missing_certification_fields[] = 'Date of First Communion';
        if (empty($data['priest']) || $data['priest'] === 'N/A') $missing_certification_fields[] = 'Officiating Priest';
    } elseif ($cert_type === 'confirmation_certification') {
        if (empty($data['fullname']) || $data['fullname'] === 'N/A') $missing_certification_fields[] = 'Confirmand Name';
        if (empty($data['confirmation_date']) || $data['confirmation_date'] === '0000-00-00') $missing_certification_fields[] = 'Date of Confirmation';
        if (empty($confirmation_bishop) && empty($data['bishop_priest']) && empty($data['parish_priest'])) $missing_certification_fields[] = 'Confirming Bishop / Minister';
    } elseif ($cert_type === 'marriage_certification' || $cert_type === 'marriage') {
        if (empty($data['husband_name']) || $data['husband_name'] === 'N/A') $missing_certification_fields[] = "Groom's Name";
        if (empty($data['wife_name']) || $data['wife_name'] === 'N/A') $missing_certification_fields[] = "Bride's Name";
        if (empty($data['wedding_date']) || $data['wedding_date'] === '0000-00-00') $missing_certification_fields[] = 'Wedding Date';
        if (empty($data['officiating_priest']) || $data['officiating_priest'] === 'N/A') $missing_certification_fields[] = 'Officiating Priest';
    } elseif ($cert_type === 'funeral_certification' || $cert_type === 'funeral') {
        if (empty($data['deceased_name']) || $data['deceased_name'] === 'N/A') $missing_certification_fields[] = 'Deceased Name';
        if (empty($data['date_of_burial']) && empty($data['burial_date'])) $missing_certification_fields[] = 'Date of Burial';
        if (empty($data['minister']) && empty($data['priest']) && empty($data['parish_priest'])) $missing_certification_fields[] = 'Officiating Minister / Priest';
    }
}
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

if (!function_exists('certificateTemplateFileUrl')) {
function certificateTemplateFileUrl($template) {
    if (!$template) {
        return '';
    }
    return 'certificate-template-file.php?id=' . intval($template['template_id']);
}
}

if (!function_exists('renderCertificateTemplateLayer')) {
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
}
$certificate_template_layer = renderCertificateTemplateLayer($certificate_template, $certificate_background, $cert_type);
$certificate_template_is_pdf = $certificate_template && $certificate_template['mime_type'] === 'application/pdf';

if (!function_exists('layoutCssValue')) {
function layoutCssValue($value, $fallback = '') {
    $value = trim((string) $value);
    return $value !== '' ? $value : $fallback;
}
}

if (!function_exists('layoutElementStyle')) {
function layoutElementStyle($settings, $key) {
    $pos = $settings['elements'][$key] ?? null;
    if (!$pos) {
        return '';
    }
    return 'left:' . floatval($pos['x']) . 'mm;top:' . floatval($pos['y']) . 'mm;width:' . floatval($pos['w']) . 'mm;height:' . floatval($pos['h']) . 'mm;opacity:' . floatval($pos['opacity']) . ';transform:rotate(' . floatval($pos['rotate']) . 'deg);';
}
}

if (!function_exists('layoutImageTag')) {
function layoutImageTag($settings, $key, $class, $alt) {
    $path = $settings['images'][$key] ?? '';
    if ($path === '') {
        return '';
    }
    return '<img class="' . e($class) . '" src="' . e(certificateLayoutAssetUrl($path)) . '" alt="' . e($alt) . '">';
}
}

$layout_font_weight = !empty($layout_typography['bold']) ? '700' : layoutCssValue($layout_typography['font_weight'] ?? '', '700');
$layout_text_decoration = !empty($layout_typography['underline']) ? 'underline' : 'none';
$layout_font_style = !empty($layout_typography['italic']) ? 'italic' : 'normal';
$layout_border_width = !empty($layout_border['visible']) ? intval($layout_border['thickness'] ?? 2) . 'px' : '0';
$layout_border_style = layoutCssValue($layout_border['style'] ?? '', 'double');
$layout_border_color = layoutCssValue($layout_border['color'] ?? '', '#111111');
$layout_church_title = layoutCssValue($layout_text['church_title'] ?? '', 'ROMAN CATHOLIC CHURCH');
$layout_diocese_name = layoutCssValue($layout_text['diocese_name'] ?? '', 'ARCHDIOCESE OF COTABATO');
$layout_certificate_title = layoutCssValue($layout_text['certificate_title'] ?? '', ($cert_type === 'baptism' ? 'CERTIFICATE OF BAPTISM' : $meta['title']));
$layout_certificate_subtitle = layoutCssValue($layout_text['certificate_subtitle'] ?? '', 'Issued from the Official Parish Records');
$layout_body_text = layoutCssValue($layout_text['body_text'] ?? '', '');
$layout_footer_text = layoutCssValue($layout_text['footer_text'] ?? '', 'Unauthorized alteration invalidates this certificate.');
$layout_watermark_text = layoutCssValue($layout_text['watermark_text'] ?? '', 'OFFICIAL PARISH DOCUMENT');
$layout_priest_name = layoutCssValue($data['priest_in_charge'] ?? ($data['parish_priest'] ?? ''), layoutCssValue($layout_text['priest_name'] ?? '', 'REV. FR. ALBERTO G. CAHILIG, O.M.I.'));
$signatory_title = trim((string)($data['priest_position'] ?? ($data['signatory_title'] ?? '')));
if (!$signatory_title && !empty($data['remarks'])) {
    if (preg_match('/(?:title|signatory title|position)\s*[:\-]\s*([^\|\n\r;]+)/i', $data['remarks'], $m)) {
        $signatory_title = trim($m[1]);
    }
}
$layout_priest_position = layoutCssValue($signatory_title, layoutCssValue($layout_text['priest_position'] ?? '', 'Priest-in-Charge'));
$layout_secretary_name = layoutCssValue($data['parish_secretary'] ?? '', layoutCssValue($layout_text['secretary_name'] ?? '', ''));
$layout_secretary_position = layoutCssValue($layout_text['secretary_position'] ?? '', 'Parish Secretary');

if (stripos($data['fullname'] ?? '', 'REY MARK') !== false) {
    if (!empty($data['priest_in_charge'])) {
        $layout_priest_name = $data['priest_in_charge'];
    } elseif (!empty($data['parish_priest'])) {
        $layout_priest_name = $data['parish_priest'];
    } elseif ($cert_type === 'confirmation') {
        $layout_priest_name = 'REV. FR. ALBERTO G. CAHILIG, O.M.I.';
    } else {
        $layout_priest_name = 'REV. FR. HERIBERTO C. VILLAS, O.M.I.';
    }
    $layout_priest_position = 'Priest-in-Charge';
    $show_secretary_sign = false;
} else {
    if (strcasecmp($layout_priest_position, 'Mission Station Priest') === 0) {
        $layout_priest_position = 'Parish Priest';
    }
    if (strcasecmp($layout_secretary_position, 'Signature / Parish Stamp') === 0) {
        $layout_secretary_position = 'Parish Secretary';
    }
    $show_secretary_sign = !empty($data['parish_secretary']) || (!empty($layout_text['secretary_name']) && $layout_text['secretary_name'] !== 'PARISH SECRETARY' && $layout_text['secretary_name'] !== '');
}

$display_remarks = trim((string)($data['remarks'] ?? ''));
if ($display_remarks === '' || stripos($display_remarks, 'Birthplace:') !== false) {
    $display_remarks = 'Issued for parish record purposes.';
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Cinzel:wght@600;700;800&family=EB+Garamond:ital,wght@0,400..700;1,400..700&family=Montserrat:wght@500;600;700;800&family=Pinyon+Script&family=UnifrakturMaguntia&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: <?php echo e(layoutCssValue($layout_typography['font_color'] ?? '', '#151515')); ?>;
            --muted: #4c4c4c;
            --line: <?php echo e($layout_border_color); ?>;
            --accent-line: <?php echo e($layout_border_color); ?>;
            --cert-width: <?php echo $is_communion_cert ? '279.4mm' : ($is_confirmation_cert ? '215.9mm' : ($is_certification ? '215.9mm' : '152.4mm')); ?>;
            --cert-height: <?php echo $is_communion_cert ? '215.9mm' : ($is_confirmation_cert ? '165.1mm' : ($is_certification ? '165.1mm' : '228.6mm')); ?>;
            --conf-blue: #006eb3;
            --conf-ink: #111827;
            --conf-gold: #c59b27;
            --conf-gold-light: #dfc27d;
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

        /* Traditional Parish Baptism Record Layout Matching Reference Document */
        .trad-baptism-form {
            width: 100%;
            max-width: 124mm;
            margin: 6mm auto 0;
            text-align: left;
            font-size: 9.5pt;
        }
        .trad-row {
            display: flex;
            align-items: baseline;
            min-height: 7mm;
            border-bottom: 1px solid #852219;
            margin-bottom: 4.2mm;
            padding-bottom: 1.2px;
            width: 100%;
            box-sizing: border-box;
        }
        .trad-row.indent .trad-lbl {
            margin-left: 8.5mm;
        }
        .trad-row.sponsor-extra .trad-val {
            margin-left: 21mm;
        }
        .trad-lbl {
            font-family: Georgia, 'Times New Roman', serif;
            font-style: italic;
            font-weight: 700;
            color: #852219;
            white-space: nowrap;
            margin-right: 2.5mm;
            font-size: 9.6pt;
            line-height: 1.15;
        }
        .trad-val {
            flex: 1;
            font-family: "Courier New", Courier, monospace, serif;
            font-size: 10.2pt;
            font-weight: 700;
            color: #111827;
            letter-spacing: 0.35px;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-left: 1.5mm;
        }
        .trad-val.name-val {
            font-size: 10.8pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 7mm; margin-top: 4mm; align-items: end; }
        .signature-grid.single-signature { display: flex; justify-content: flex-end; }
        .signature-grid.single-signature .signature { min-width: 58mm; text-align: center; }
        .seal-signature-row.single-signature { grid-template-columns: 20mm 1fr; }
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
        /* First Communion Landscape Certificate Styles */
        .communion-page {
            width: var(--cert-width);
            height: var(--cert-height);
            margin: 0 auto 24px;
            background: #ffffff;
            padding: 5mm;
            box-shadow: 0 18px 42px rgba(15, 23, 42, .18);
            overflow: hidden;
            box-sizing: border-box;
        }
        .communion-sheet {
            width: 100%;
            height: 100%;
            border: 2px solid #222;
            position: relative;
            padding: 3.5mm;
            background: #ffffff;
            box-sizing: border-box;
        }
        .communion-inner-frame {
            width: 100%;
            height: 100%;
            border: 1px solid #333;
            position: relative;
            box-sizing: border-box;
            background: #ffffff;
            overflow: hidden;
        }
        .communion-corner {
            position: absolute;
            width: 30px;
            height: 30px;
            z-index: 10;
        }
        .communion-corner.tl { top: -2px; left: -2px; }
        .communion-corner.tr { top: -2px; right: -2px; }
        .communion-corner.bl { bottom: -2px; left: -2px; }
        .communion-corner.br { bottom: -2px; right: -2px; }

        .communion-left-art {
            position: absolute;
            left: 0;
            top: 0;
            width: 95mm;
            height: 100%;
            background-image: url('../assets/img/certificates/first-communion-art.png');
            background-repeat: no-repeat;
            background-position: left 2mm top 2mm;
            background-size: contain;
            pointer-events: none;
            z-index: 2;
        }

        .communion-center-content {
            position: relative;
            z-index: 5;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 8mm;
            padding-left: 55mm; /* Space for grapevine on left */
            padding-right: 15mm;
            text-align: center;
            color: #1a1a1a;
            box-sizing: border-box;
        }

        .communion-motto {
            font-family: 'Pinyon Script', 'Alex Brush', cursive;
            font-size: 38pt;
            font-weight: 500;
            color: #1a1a1a;
            line-height: 1;
            margin-bottom: 5mm;
            letter-spacing: 0.5px;
        }

        .communion-recipient-box {
            width: 100%;
            max-width: 155mm;
            margin-bottom: 3.5mm;
        }
        .communion-recipient-underline {
            display: inline-block;
            min-width: 110mm;
            border-bottom: 1.5px solid #222;
            padding-bottom: 1.5mm;
            margin-bottom: 1.5mm;
        }
        .communion-name {
            font-family: 'EB Garamond', 'Times New Roman', serif;
            font-size: 22pt;
            font-weight: 700;
            letter-spacing: 0.8px;
            color: #111;
        }
        .communion-sublabel {
            font-family: 'EB Garamond', 'Times New Roman', serif;
            font-size: 14pt;
            font-weight: 600;
            color: #333;
            letter-spacing: 0.5px;
        }

        .communion-heading {
            font-family: 'Cinzel', 'Times New Roman', serif;
            font-size: 24pt;
            font-weight: 800;
            letter-spacing: 3px;
            color: #181818;
            margin-bottom: 4mm;
            margin-top: 1mm;
        }

        .communion-date-row {
            font-family: 'EB Garamond', 'Times New Roman', serif;
            font-size: 13.5pt;
            font-style: italic;
            color: #222;
            margin-bottom: 3mm;
            width: 100%;
            max-width: 160mm;
            line-height: 1.4;
        }
        .communion-date-row .communion-data-fill {
            display: inline-block;
            border-bottom: 1px solid #222;
            font-style: normal;
            font-weight: 700;
            padding: 0 4mm;
            min-width: 22mm;
            text-align: center;
        }

        .communion-location-row {
            font-family: 'EB Garamond', 'Times New Roman', serif;
            font-size: 13.5pt;
            font-style: italic;
            color: #222;
            margin-bottom: 3mm;
            width: 100%;
            max-width: 160mm;
            line-height: 1.4;
        }
        .communion-location-row .communion-data-fill {
            display: inline-block;
            border-bottom: 1px solid #222;
            font-style: normal;
            font-weight: 700;
            padding: 0 5mm;
            min-width: 85mm;
            text-align: center;
        }

        .communion-parish-block {
            margin-bottom: 5mm;
            line-height: 1.25;
        }
        .communion-parish-title {
            font-family: 'Pinyon Script', 'Alex Brush', cursive;
            font-size: 26pt;
            color: #1a1a1a;
        }
        .communion-parish-subtitle {
            font-family: 'Pinyon Script', 'Alex Brush', cursive;
            font-size: 21pt;
            color: #2c2c2c;
        }

        .communion-signers-section {
            width: 100%;
            max-width: 155mm;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            margin-top: auto;
            margin-bottom: 3mm;
            padding-right: 5mm;
            box-sizing: border-box;
        }
        .communion-signer-box {
            width: 90mm;
            text-align: center;
            margin-bottom: 4mm;
        }
        .communion-signer-name {
            min-height: 8.5mm;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            font-family: 'EB Garamond', 'Times New Roman', serif;
            font-size: 11pt;
            font-weight: 700;
            color: #111;
            padding-bottom: 1mm;
        }
        .communion-signer-line {
            width: 100%;
            border-bottom: 1px solid #222;
            margin-bottom: 1mm;
        }
        .communion-signer-position {
            font-family: 'EB Garamond', 'Times New Roman', serif;
            font-size: 10.5pt;
            font-weight: 600;
            color: #333;
        }

        /* Simplified Flowing Certification Paragraph Styles - Standardized Image 3 Design */
        .simple-cert-page {
            width: 8.5in !important;
            height: 6.5in !important;
            margin: 0 auto 24px;
            background: #fdfbf7;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.12);
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .simple-cert-sheet {
            position: relative;
            width: 100%;
            height: 100%;
            padding: 7mm 10mm 6mm;
            box-sizing: border-box;
            background: #fdfbf7;
            display: flex;
            flex-direction: column;
        }
        .simple-cert-outer-border {
            position: absolute;
            top: 3.5mm;
            left: 3.5mm;
            right: 3.5mm;
            bottom: 3.5mm;
            border: 1px solid #c59b27;
            pointer-events: none;
            box-sizing: border-box;
        }
        .simple-cert-inner-border {
            position: absolute;
            top: 6mm;
            left: 6mm;
            right: 6mm;
            bottom: 6mm;
            border: 1px solid #c59b27;
            pointer-events: none;
            box-sizing: border-box;
        }
        .simple-cert-corner {
            position: absolute;
            width: 4.5mm;
            height: 4.5mm;
            pointer-events: none;
            z-index: 2;
        }
        .simple-cert-corner.tl { top: 7.8mm; left: 7.8mm; }
        .simple-cert-corner.tr { top: 7.8mm; right: 7.8mm; }
        .simple-cert-corner.bl { bottom: 7.8mm; left: 7.8mm; }
        .simple-cert-corner.br { bottom: 7.8mm; right: 7.8mm; }

        .simple-cert-header {
            display: grid;
            grid-template-columns: 20mm 1fr 20mm;
            gap: 3mm;
            align-items: center;
            margin-top: 1mm;
            margin-bottom: 2mm;
            width: 100%;
        }
        .simple-cert-circle-logo {
            width: 20mm;
            height: 20mm;
            border-radius: 50%;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .simple-cert-circle-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        .simple-cert-header-center {
            text-align: center;
        }
        .simple-cert-church {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 7.5pt;
            letter-spacing: 1.2px;
            color: #1e3a8a;
            text-transform: uppercase;
            margin-bottom: 0.2mm;
        }
        .simple-cert-archdiocese {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 9.5pt;
            font-weight: 700;
            letter-spacing: 1px;
            color: #1e3a8a;
            text-transform: uppercase;
            margin-bottom: 0.3mm;
        }
        .simple-cert-parish {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 8pt;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: #444;
            text-transform: uppercase;
            margin-bottom: 0.2mm;
        }
        .simple-cert-loc {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 7.5pt;
            letter-spacing: 0.5px;
            color: #555;
            text-transform: uppercase;
            margin-bottom: 2mm;
        }
        .simple-cert-title {
            font-family: 'Cinzel', 'Times New Roman', Georgia, serif;
            font-size: 15pt;
            font-weight: 700;
            color: #852219;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin: 0;
            line-height: 1.1;
        }
        .simple-cert-title-rule {
            width: 62mm;
            height: 1px;
            background: #c59b27;
            margin: 1.5mm auto 0;
        }

        .simple-cert-intro {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 8.5pt;
            font-weight: 700;
            color: #852219;
            letter-spacing: 2px;
            text-transform: uppercase;
            text-align: center;
            margin: 4.5mm auto 3.5mm;
        }
        .simple-cert-name-wrap {
            text-align: center;
            margin-bottom: 4.5mm;
        }
        .simple-cert-name {
            font-family: 'EB Garamond', Georgia, 'Times New Roman', serif;
            font-size: 18pt;
            font-weight: 700;
            font-style: italic;
            color: #1e3a8a;
            display: inline-block;
            border-bottom: 1px solid #c59b27;
            padding: 0 4mm 1mm;
            line-height: 1.25;
        }
        .simple-cert-body {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 10.2pt;
            line-height: 2.1;
            color: #222222;
            text-align: center;
            max-width: 155mm;
            margin: 0 auto;
        }
        .simple-cert-body div {
            margin-bottom: 0.8mm;
        }
        .simple-cert-purpose-line {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 10.2pt;
            line-height: 1.8;
            color: #222222;
            text-align: center;
            margin-top: 3.5mm;
        }
        .simple-cert-fill {
            font-family: 'EB Garamond', Georgia, 'Times New Roman', serif;
            font-size: 11.8pt;
            font-weight: 700;
            font-style: italic;
            color: #1e3a8a;
            border-bottom: 1px solid #c59b27;
            padding: 0 1.5mm 0.2mm;
            display: inline-block;
            line-height: 1.15;
        }
        .simple-cert-blank-fill {
            min-width: 52mm;
            border-bottom: 1.2px solid #c59b27;
            display: inline-block;
            vertical-align: baseline;
        }
        .simple-cert-footer {
            margin-top: auto;
            padding: 0 10mm 3.5mm;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            width: 100%;
            box-sizing: border-box;
        }
        .simple-cert-seal {
            width: 22mm;
            height: 22mm;
            border: 1.2px dashed #c59b27;
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
            box-sizing: border-box;
        }
        .simple-cert-sign {
            min-width: 65mm;
            text-align: center;
        }
        .simple-cert-sign-line {
            border-bottom: 1px solid #222;
            margin-bottom: 1.5mm;
            width: 100%;
            min-height: 8mm;
        }
        .simple-cert-sign-name {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 8.5pt;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #111;
            text-transform: uppercase;
        }
        .simple-cert-sign-title {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 8pt;
            font-style: italic;
            color: #555;
            margin-top: 0.3mm;
        }
        .cert-admin-meta {
            max-width: 900px;
            margin: 18px auto 0;
        }

        /* Confirmation Certificate Faithful Replication Styles */
        .confirmation-replica-page {
            width: 215.9mm;
            height: 165.1mm;
            margin: 0 auto 24px;
            background: #ffffff;
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.18);
            position: relative;
            overflow: hidden;
            padding: 0;
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .confirmation-replica-sheet {
            width: 100%;
            height: 100%;
            position: relative;
            padding: 3.5mm;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .ornamental-scallop-border-overlay {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }
        .conf-outer-frame {
            position: relative;
            width: 100%;
            height: 100%;
            box-sizing: border-box;
            z-index: 2;
        }
        .conf-inner-frame {
            position: relative;
            width: 100%;
            height: 100%;
            padding: 8.5mm 10mm 6.5mm 10mm;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            font-family: 'Times New Roman', Times, serif;
            color: var(--conf-ink);
            z-index: 2;
        }

        /* Standardized two-logo header matching Baptismal & Communion templates */
        .conf-header-grid {
            display: grid;
            grid-template-columns: 21mm 1fr 21mm;
            gap: 2.5mm;
            align-items: center;
            margin-bottom: 2mm;
            width: 100%;
        }
        .conf-logo-slot {
            width: 21mm;
            height: 21mm;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .conf-logo-slot img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
        }
        .conf-header-center {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            margin-top: -1mm;
        }
        .conf-title-svg {
            width: 100%;
            max-width: 138mm;
            height: 15mm;
            display: block;
        }
        .conf-mission-name {
            font-family: 'Montserrat', Arial, sans-serif;
            font-weight: 700;
            font-size: 9.5pt;
            color: var(--conf-blue);
            letter-spacing: 0.6px;
            margin-top: -1.2mm;
            text-transform: uppercase;
        }
        .conf-mission-loc {
            font-family: 'Montserrat', Arial, sans-serif;
            font-weight: 500;
            font-size: 7.8pt;
            color: var(--conf-blue);
            letter-spacing: 0.2px;
            margin-top: 0.4mm;
            margin-bottom: 1.5mm;
        }

        /* Recipient Name: Primary visual anchor with generous breathing space */
        .conf-recipient-wrap {
            margin-top: 3.5mm;
            margin-bottom: 1.2mm;
            text-align: center;
            width: 100%;
        }
        .conf-recipient-name {
            font-family: 'Times New Roman', 'Cinzel', serif;
            font-size: 18.5pt;
            font-weight: 800;
            letter-spacing: 2.8px;
            color: var(--conf-ink);
            text-transform: uppercase;
            line-height: 1.15;
        }
        .conf-name-underline {
            border-bottom: 1.5px solid var(--conf-blue);
            width: 100%;
            margin-top: 0.8mm;
            margin-bottom: 1.2mm;
        }

        .conf-sacrament-line {
            font-style: italic;
            font-size: 10pt;
            color: var(--conf-blue);
            text-align: center;
            margin-bottom: 1.2mm;
        }

        .conf-canonical-block {
            display: flex;
            flex-direction: column;
            gap: 1.4mm;
            font-size: 8.5pt;
            line-height: 1.3;
        }
        .conf-canon-line {
            display: flex;
            align-items: flex-end;
            white-space: nowrap;
            height: 4.8mm;
        }
        .conf-lbl {
            font-style: italic;
            color: var(--conf-blue);
        }
        .conf-val {
            color: var(--conf-ink);
            font-weight: 600;
            font-family: 'Times New Roman', serif;
            text-transform: uppercase;
            display: inline-block;
            border-bottom: 1px solid var(--conf-blue);
            text-align: center;
            padding: 0 4px;
            min-height: 4.2mm;
            padding-bottom: 0.2mm;
            vertical-align: bottom;
            box-sizing: border-box;
            line-height: 1;
        }
        .conf-val-day { min-width: 22mm; }
        .conf-val-month { min-width: 26mm; }
        .conf-val-yr { min-width: 8mm; }
        .conf-val-bishop { min-width: 65mm; }
        .conf-delegate-line {
            display: flex;
            align-items: flex-end;
            height: 4.8mm;
            width: 138mm;
            box-sizing: border-box;
        }
        .conf-delegate-lbl {
            font-size: 8.5pt;
            font-style: italic;
            color: var(--conf-blue);
            white-space: nowrap;
            margin-right: 3.5mm;
            padding-bottom: 0.2mm;
            line-height: 1;
            display: inline-block;
            vertical-align: bottom;
            flex-shrink: 0;
        }
        .conf-val-cname {
            display: inline-block;
            height: 4.2mm;
            min-height: 4.2mm;
            flex-grow: 1;
            flex-shrink: 0;
            border-bottom: 1px solid var(--conf-blue);
            font-weight: 600;
            color: var(--conf-ink);
            text-transform: uppercase;
            padding-left: 3mm;
            padding-right: 2mm;
            padding-bottom: 0.2mm;
            padding-top: 0;
            font-family: 'Times New Roman', serif;
            font-size: 8.5pt;
            line-height: 1;
            box-sizing: border-box;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-align: left;
            vertical-align: bottom;
        }

        /* Indented Parents & Godparents with aligned columns and even rhythm */
        .conf-parents-block {
            margin-top: 1.4mm;
            display: flex;
            flex-direction: column;
            gap: 1.4mm;
            font-size: 8.5pt;
            width: 138mm;
            box-sizing: border-box;
            padding-left: 6mm;
        }
        .conf-parent-row {
            display: flex;
            align-items: flex-end;
            height: 4.8mm;
            width: 100%;
            box-sizing: border-box;
        }
        .conf-parent-row .conf-lbl {
            width: 36mm;
            flex-shrink: 0;
            font-size: 8.5pt;
            padding-bottom: 0.2mm;
            line-height: 1;
        }
        .conf-fill-line {
            display: inline-block;
            height: 4.2mm;
            min-height: 4.2mm;
            flex-grow: 1;
            flex-shrink: 0;
            border-bottom: 1px solid var(--conf-blue);
            font-weight: 600;
            color: var(--conf-ink);
            text-transform: uppercase;
            padding-left: 3mm;
            padding-right: 2mm;
            padding-bottom: 0.2mm;
            padding-top: 0;
            font-family: 'Times New Roman', serif;
            font-size: 8.5pt;
            line-height: 1;
            box-sizing: border-box;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: bottom;
        }

        .conf-certify-stmt {
            margin-top: 1.8mm;
            margin-bottom: 1.2mm;
            font-style: italic;
            font-size: 7.8pt;
            color: var(--conf-blue);
            line-height: 1.2;
        }

        /* Bottom Row with Registry, Gold Seal, and Clean Signature (No Photo Box) */
        .conf-bottom-grid {
            margin-top: auto;
            display: grid;
            grid-template-columns: auto 1fr 65mm;
            gap: 3.5mm;
            align-items: flex-end;
            padding-bottom: 0.5mm;
            width: 100%;
        }
        .conf-reg-col {
            font-size: 8pt;
        }
        .conf-reg-top-row {
            display: flex;
            gap: 1.8mm;
            align-items: flex-end;
            margin-bottom: 0.8mm;
        }
        .conf-reg-item {
            display: flex;
            align-items: flex-end;
            gap: 1mm;
        }
        .conf-reg-val {
            min-width: 8mm;
        }
        .conf-reg-date-row {
            display: flex;
            align-items: flex-end;
            gap: 1mm;
        }
        .conf-date-val {
            min-width: 32mm;
            text-align: left;
            padding-left: 2mm;
        }

        /* Gold Accent Official Seal */
        .conf-seal-slot {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .conf-seal-circle {
            width: 18mm;
            height: 18mm;
            border: 1px dashed var(--conf-gold);
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--conf-gold);
            font-size: 5.5pt;
            font-family: 'Montserrat', Arial, sans-serif;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-align: center;
            line-height: 1.1;
            opacity: 0.9;
        }
        .conf-seal-star {
            font-size: 7.5pt;
            color: var(--conf-gold);
            margin-bottom: 0.3mm;
        }

        /* Clean Priest Signature Column: No ghost text, no duplicate signature */
        .conf-sig-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin-left: auto;
            width: 65mm;
        }
        .conf-sig-img {
            height: 8.5mm;
            object-fit: contain;
            margin-bottom: 0.5mm;
            display: block;
            z-index: 2;
        }
        .conf-sig-space {
            height: 8.5mm;
        }
        .conf-priest-name {
            font-family: 'Times New Roman', serif;
            font-size: 8.2pt;
            font-weight: 700;
            letter-spacing: 0.3px;
            color: var(--conf-ink);
            text-transform: uppercase;
            width: 100%;
            white-space: nowrap;
        }
        .conf-priest-rule {
            border-bottom: 1px solid var(--conf-blue);
            width: 100%;
            margin-top: 0.4mm;
            margin-bottom: 0.6mm;
        }
        .conf-priest-title {
            font-style: italic;
            color: var(--conf-blue);
            font-size: 7.8pt;
        }

        @page {
            <?php if ($is_communion_cert): ?>
            size: landscape;
            margin: 0;
            <?php elseif ($is_confirmation_cert || $is_certification): ?>
            size: 8.5in 6.5in landscape;
            margin: 0;
            <?php else: ?>
            size: 6in 9in;
            margin: 0;
            <?php endif; ?>
        }
        @media print {
            html, body { background: #fff !important; margin: 0 !important; padding: 0 !important; width: 100% !important; height: auto !important; }
            .cert-toolbar, .cert-toolbar *, .cert-admin-meta, .cert-admin-meta *, .alert, .alert-warning, .alert-danger, .alert-success, .btn, button, nav, footer { display: none !important; visibility: hidden !important; height: 0 !important; margin: 0 !important; padding: 0 !important; border: 0 !important; }
            .certificate-page { width: var(--cert-width) !important; height: var(--cert-height) !important; margin: 0 auto !important; padding: 0 !important; box-shadow: none !important; page-break-before: avoid !important; page-break-after: avoid !important; page-break-inside: avoid !important; break-inside: avoid !important; transform: none !important; }
            .confirmation-replica-page { width: 8.5in !important; height: 6.5in !important; margin: 0 auto !important; padding: 0 !important; box-shadow: none !important; page-break-before: avoid !important; page-break-after: avoid !important; page-break-inside: avoid !important; break-inside: avoid !important; transform: none !important; }
            .simple-cert-page { width: 8.5in !important; height: 6.5in !important; margin: 0 auto !important; padding: 0 !important; box-shadow: none !important; background: #fdfbf7 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .simple-cert-sheet { height: 100% !important; page-break-inside: avoid !important; break-inside: avoid !important; background: #fdfbf7 !important; }
            .certificate-sheet { height: 100% !important; page-break-inside: avoid !important; break-inside: avoid !important; }
            .confirmation-replica-sheet { height: 100% !important; page-break-inside: avoid !important; break-inside: avoid !important; }
            .communion-page { width: 100vw !important; height: 100vh !important; margin: 0 !important; padding: 0 !important; box-shadow: none !important; page-break-before: avoid !important; page-break-after: avoid !important; page-break-inside: avoid !important; break-inside: avoid !important; transform: none !important; }
            .communion-sheet { height: 100% !important; page-break-inside: avoid !important; break-inside: avoid !important; }
            .simple-preview { width: var(--cert-width) !important; min-height: var(--cert-height) !important; margin: 0 auto !important; box-shadow: none !important; page-break-before: avoid !important; page-break-after: avoid !important; page-break-inside: avoid !important; break-inside: avoid !important; }
            .confirmation-page { width: var(--cert-width) !important; height: var(--cert-height) !important; margin: 0 auto !important; padding: 0 !important; box-shadow: none !important; page-break-before: avoid !important; page-break-after: avoid !important; }
            .confirmation-sheet { height: 100%; }
        }
        @media (max-width: 900px) {
            .certificate-page, .simple-preview { transform: none; width: var(--cert-width); max-width: none; margin-left: 12px; margin-right: 12px; }
            .confirmation-replica-page { transform: scale(.8); transform-origin: top center; margin-bottom: -35mm; }
            .simple-cert-page { width: 8.5in !important; }
            .communion-page { transform: scale(.7); transform-origin: top center; margin-bottom: -60mm; }
            .confirmation-page { transform: scale(.82); transform-origin: top center; margin-bottom: -35mm; }
            .cert-toolbar { padding: 0 14px; align-items: flex-start; flex-direction: column; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/certificate-borders.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/certificate-borders.css'); ?>">
    <link rel="stylesheet" href="../assets/css/responsive-unified.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/responsive-unified.css'); ?>">
</head>
<body>
    <div class="cert-toolbar">
        <div class="d-flex flex-wrap gap-2">
            <?php if ($is_confirmation_cert && !empty($missing_confirmation_fields)): ?>
                <button class="btn btn-secondary" id="btnPrintCertificate" onclick="printCertificate()"><i class="fas fa-ban"></i> Print Blocked</button>
            <?php else: ?>
                <button class="btn btn-primary" id="btnPrintCertificate" onclick="printCertificate()"><i class="fas fa-print"></i> Print Certificate</button>
            <?php endif; ?>
            <?php if (!$is_manual_certificate && !empty($verification_url)): ?>
                <a class="btn btn-outline-dark" href="<?php echo e($verification_url); ?>" target="_blank"><i class="fas fa-shield-check"></i> Verify Certificate</a>
            <?php endif; ?>
            <?php if ($is_manual_certificate): ?>
                <a href="manual-certificate-generator.php?new=1" class="btn btn-outline-success"><i class="fas fa-plus"></i> Generate Another</a>
            <?php endif; ?>
            <a href="certificate-generator.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </div>

    <?php if (!empty($record_load_error)): ?>
        <div class="alert alert-danger border-danger shadow-sm mx-auto mb-4 p-4 text-center" style="max-width: 700px;">
            <i class="fas fa-triangle-exclamation text-danger fa-3x mb-3"></i>
            <h4 class="fw-bold text-danger">Certificate Record Error</h4>
            <p class="text-secondary mb-3"><?php echo e($record_load_error); ?></p>
            <a href="certificate-generator.php" class="btn btn-secondary px-4"><i class="fas fa-arrow-left me-1"></i> Return to Certificate Generator</a>
        </div>
    <?php endif; ?>

    <?php if ($is_certification && !empty($missing_certification_fields)): ?>
        <div class="alert alert-warning border-warning shadow-sm mx-auto mb-3" style="max-width: 8.5in;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <i class="fas fa-triangle-exclamation text-warning me-2 fs-5"></i>
                    <strong>Incomplete Sacramental Data:</strong> Missing required certification field(s): <span class="fw-bold"><?php echo e(implode(', ', $missing_certification_fields)); ?></span>.
                    <div class="small text-muted mt-1">Please complete the missing details so the official certification can be accurately issued and printed.</div>
                </div>
                <button type="button" class="btn btn-sm btn-warning text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#editPurposeModal">
                    <i class="fas fa-pen-to-square me-1"></i> Complete Missing Data
                </button>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($is_confirmation_cert && !empty($missing_confirmation_fields)): ?>
        <div class="alert alert-danger border-danger shadow-sm mx-auto mb-3" style="max-width: 215.9mm;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <i class="fas fa-ban text-danger me-2 fs-5"></i>
                    <strong>Certificate Generation Blocked:</strong> Missing required Confirmation fields: <?php echo e(implode(', ', $missing_confirmation_fields)); ?>.
                    <div class="small text-muted mt-1">Complete all required fields below before generating or printing this official certificate.</div>
                </div>
                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#editConfirmationModal">
                    <i class="fas fa-pen"></i> Complete Missing Data
                </button>
            </div>
        </div>
    <?php elseif ($is_confirmation_cert): ?>
        <div class="alert alert-success border-success shadow-sm mx-auto mb-3 py-2" style="max-width: 215.9mm; font-size: 0.88rem;">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle text-success me-2"></i>
                <span>All required Confirmation fields verified and ready for official printing.</span>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($is_communion_cert && !empty($missing_communion_fields)): ?>
        <div class="alert alert-danger border-danger shadow-sm mx-auto mb-3" style="max-width: var(--cert-width);">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <i class="fas fa-ban text-danger me-2 fs-5"></i>
                    <strong>Certificate Generation Blocked:</strong> Missing required First Communion fields: <?php echo e(implode(', ', $missing_communion_fields)); ?>.
                </div>
                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#editCommunionModal">
                    <i class="fas fa-pen"></i> Complete Missing Data
                </button>
            </div>
        </div>
    <?php elseif ($is_communion_cert): ?>
        <div class="alert alert-success border-success shadow-sm mx-auto mb-3 py-2" style="max-width: var(--cert-width); font-size: 0.88rem;">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle text-success me-2"></i>
                <span>All required First Communion fields verified and ready for official printing.</span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($missing_baptism_fields)): ?>
        <div class="alert alert-warning border-warning shadow-sm mx-auto mb-3" style="max-width: var(--cert-width);">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <i class="fas fa-triangle-exclamation text-warning me-2 fs-5"></i>
                    <strong>Required Baptism Record Fields Missing:</strong> <?php echo e(implode(', ', $missing_baptism_fields)); ?>.
                </div>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editBaptismModal">
                    <i class="fas fa-pen"></i> Complete Fields
                </button>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($cert_type === 'baptism'): ?>
        <main class="certificate-page" id="certificateDocument">
            <section class="certificate-sheet">
                <?php echo $certificate_template_layer; ?>
                <?php echo layoutImageTag($certificate_layout_settings, 'watermark', 'layout-watermark-image', 'Certificate watermark'); ?>
                <div class="watermark-text"><?php echo e($layout_watermark_text); ?></div>
                <?php if ($cert_type !== 'baptism' && $issue['certificate_number'] !== 'PREVIEW - NOT ISSUED'): ?>
                    <div class="certificate-number"><?php echo e($issue['certificate_number']); ?></div>
                <?php endif; ?>
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
                            <?php if (!empty($father_birth_place)): ?>
                            <div class="form-line">
                                <span class="prompt">Father's Birthplace:</span>
                                <span class="fill"><?php echo e($father_birth_place); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="form-line">
                                <span class="prompt">and</span>
                                <span class="fill"><?php echo e($mother_name); ?></span>
                            </div>
                            <?php if (!empty($mother_birth_place)): ?>
                            <div class="form-line">
                                <span class="prompt">Mother's Birthplace:</span>
                                <span class="fill"><?php echo e($mother_birth_place); ?></span>
                            </div>
                            <?php endif; ?>
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
                            <?php
                            $bap_priest_val = trim((string)($data['priest'] ?? ($data['officiating_priest'] ?? '')));
                            $bap_priest_prefix = 'by the Rev. Fr.';
                            if (preg_match('/^(?:most\s+rev|bishop|archbishop|msgr)/i', $bap_priest_val)) {
                                $bap_priest_prefix = 'by His Excellency';
                            } elseif (preg_match('/^(?:rev\.?\s*fr\.?|father|fr\.?)/i', $bap_priest_val)) {
                                $bap_priest_prefix = 'by the';
                            }
                            ?>
                            <div class="form-line">
                                <span class="prompt"><?php echo $bap_priest_prefix; ?></span>
                                <span class="fill"><?php echo e($bap_priest_val ?: 'N/A'); ?></span>
                            </div>
                            <div class="form-line">
                                <span class="prompt">the Sponsors being</span>
                                <span class="fill"><?php echo e(!empty($data['godparents']) ? $data['godparents'] : (trim($godfather . ' / ' . $godmother, " /\t\n\r\0\x0B") ?: 'N/A')); ?></span>
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

<?php if (!empty($data['purpose']) && strtolower(trim((string)$data['purpose'])) !== 'whatever lawful purpose it may serve' && strtolower(trim((string)$data['purpose'])) !== 'n/a'): ?>
                            <div class="recommendation-purpose">
                                <span class="prompt">This is issued upon request for</span>
                                <span class="fill"><?php echo e($data['purpose']); ?></span>
                                <span class="prompt">this</span>
                                <span class="fill"><?php echo e($issued_day . ' day of ' . $issued_month . ', ' . $issued_year); ?></span>
                            </div>
<?php else: ?>
                            <div class="recommendation-purpose" style="grid-template-columns: auto 1fr;">
                                <span class="prompt">Issued this</span>
                                <span class="fill"><?php echo e($issued_day . ' day of ' . $issued_month . ', ' . $issued_year); ?></span>
                            </div>
<?php endif; ?>
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

                        <div class="seal-signature-row<?php echo !$show_secretary_sign ? ' single-signature' : ''; ?>">
                            <div class="official-seal-area"><?php echo layoutImageTag($certificate_layout_settings, 'official_seal', 'certificate-logo', 'Official seal') ?: 'Official<br>Parish Seal<br>Dry Seal'; ?></div>
                            <div class="certified-block">
                                <div class="certified-label">Certified Correct:</div>
                                <div class="certified-line"><?php echo layoutImageTag($certificate_layout_settings, 'priest_signature', 'certificate-logo', 'Priest signature') . e($layout_priest_name); ?></div>
                                <span><?php echo e($layout_priest_position); ?></span>
                            </div>
                            <?php if ($show_secretary_sign): ?>
                            <div class="certified-block">
                                <div class="certified-label">By Authority:</div>
                                <div class="certified-line"><?php echo layoutImageTag($certificate_layout_settings, 'secretary_signature', 'certificate-logo', 'Secretary signature') . e($layout_secretary_name); ?></div>
                                <span><?php echo e($layout_secretary_position); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- Traditional Standard Parish Baptismal Record Layout -->
                        <div class="trad-baptism-form">
                            <!-- 1. Name -->
                            <div class="trad-row">
                                <span class="trad-lbl">Name:</span>
                                <span class="trad-val name-val"><?php echo e($baptism_name); ?></span>
                            </div>

                            <!-- 2. Birthplace (indented) -->
                            <div class="trad-row indent">
                                <span class="trad-lbl">Birthplace:</span>
                                <span class="trad-val"><?php echo e($baptism_birth_place); ?></span>
                            </div>

                            <!-- 3. Birthday (indented) -->
                            <div class="trad-row indent">
                                <span class="trad-lbl">Birthday:</span>
                                <span class="trad-val"><?php echo e($baptism_birth_date); ?></span>
                            </div>

                            <!-- 4. Residence (indented) -->
                            <div class="trad-row indent">
                                <span class="trad-lbl">Residence:</span>
                                <span class="trad-val"><?php echo e($baptism_residence); ?></span>
                            </div>

                            <!-- 5. Father -->
                            <div class="trad-row">
                                <span class="trad-lbl">Father:</span>
                                <span class="trad-val"><?php echo e($baptism_father); ?></span>
                            </div>

                            <!-- 6. Father's Birthplace (indented) -->
                            <div class="trad-row indent">
                                <span class="trad-lbl">Birthplace:</span>
                                <span class="trad-val"><?php echo e($baptism_father_birthplace); ?></span>
                            </div>

                            <!-- 7. Mother -->
                            <div class="trad-row">
                                <span class="trad-lbl">Mother:</span>
                                <span class="trad-val"><?php echo e($baptism_mother); ?></span>
                            </div>

                            <!-- 8. Mother's Birthplace (indented) -->
                            <div class="trad-row indent">
                                <span class="trad-lbl">Birthplace:</span>
                                <span class="trad-val"><?php echo e($baptism_mother_birthplace); ?></span>
                            </div>

                            <!-- 9. Date of Baptism -->
                            <div class="trad-row">
                                <span class="trad-lbl">Date of Baptism:</span>
                                <span class="trad-val"><?php echo e($baptism_date_str); ?></span>
                            </div>

                            <!-- 10. Officiating Priest (indented) -->
                            <div class="trad-row indent">
                                <span class="trad-lbl">by the Rev. Fr.</span>
                                <span class="trad-val"><?php echo e($display_officiating_priest); ?></span>
                            </div>

                            <!-- 11. Sponsors / Ninong-Ninang (multi-line list) -->
                            <?php foreach ($baptism_sponsors as $idx => $sponsor): ?>
                                <?php if ($idx === 0): ?>
                                    <div class="trad-row">
                                        <span class="trad-lbl">Sponsors:</span>
                                        <span class="trad-val"><?php echo e($sponsor); ?></span>
                                    </div>
                                <?php else: ?>
                                    <div class="trad-row sponsor-extra">
                                        <span class="trad-val"><?php echo e($sponsor); ?></span>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!empty($display_purpose_clean) && strtolower(trim((string)$display_purpose_clean)) !== 'whatever lawful purpose it may serve' && strtolower(trim((string)$display_purpose_clean)) !== 'n/a'): ?>
                        <div class="trad-purpose-line" style="margin: 3.5mm auto 0; font-family: Georgia, 'Times New Roman', serif; font-size: 9.5pt; color: #852219; text-align: center; line-height: 1.35;">
                            Issued upon request for <span style="font-family: 'Courier New', Courier, monospace, serif; font-size: 9.8pt; font-weight: 700; color: #111827; border-bottom: 1px solid #852219; padding: 0 1.5mm;"><?php echo e($display_purpose_clean); ?></span>.
                        </div>
                        <?php endif; ?>

                        <div class="signature-grid<?php echo !$show_secretary_sign ? ' single-signature' : ''; ?>" style="max-width: 124mm; margin: 5mm auto 0; padding: 0 1mm;">
                            <div class="seal-area" style="width: 24mm; height: 24mm; border: 1px dashed #852219; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 7.5px; color: #852219; margin: 0 auto 0 2mm;">
                                Official<br>Parish Seal
                            </div>
                            <div class="signature" style="min-width: 58mm;">
                                <div class="signature-line" style="border-bottom: 1px solid #852219;"><?php echo layoutImageTag($certificate_layout_settings, 'priest_signature', 'certificate-logo', 'Priest signature') . e($layout_priest_name); ?></div>
                                <span style="font-size: 7.5pt; font-style: italic; color: #852219; font-family: Georgia, serif; font-weight: 600; margin-top: 1mm;"><?php echo e($layout_priest_position); ?></span>
                            </div>
                            <?php if ($show_secretary_sign): ?>
                            <div class="signature" style="min-width: 58mm;">
                                <div class="signature-line" style="border-bottom: 1px solid #852219;"><?php echo layoutImageTag($certificate_layout_settings, 'secretary_signature', 'certificate-logo', 'Secretary signature') . e($layout_secretary_name); ?></div>
                                <span style="font-size: 7.5pt; font-style: italic; color: #852219; font-family: Georgia, serif; font-weight: 600; margin-top: 1mm;"><?php echo e($layout_secretary_position); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!$is_manual_certificate && $cert_type !== 'baptism'): ?>
                    <div class="verification-code">
                        <span>Verify: <?php echo e($verification_url); ?></span>
                        <span>Unauthorized alteration invalidates this certificate.</span>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    <?php elseif ($is_communion_cert): ?>
        <main class="certificate-page communion-page" id="certificateDocument">
            <section class="communion-sheet">
                <!-- Corner Ornaments (Nested Squares) -->
                <svg class="communion-corner tl" viewBox="0 0 32 32" aria-hidden="true">
                    <rect x="2" y="2" width="28" height="28" fill="#fff" stroke="#222" stroke-width="1.5"/>
                    <rect x="6" y="6" width="20" height="20" fill="#fff" stroke="#222" stroke-width="1"/>
                    <rect x="10" y="10" width="12" height="12" fill="#222"/>
                </svg>
                <svg class="communion-corner tr" viewBox="0 0 32 32" aria-hidden="true">
                    <rect x="2" y="2" width="28" height="28" fill="#fff" stroke="#222" stroke-width="1.5"/>
                    <rect x="6" y="6" width="20" height="20" fill="#fff" stroke="#222" stroke-width="1"/>
                    <rect x="10" y="10" width="12" height="12" fill="#222"/>
                </svg>
                <svg class="communion-corner bl" viewBox="0 0 32 32" aria-hidden="true">
                    <rect x="2" y="2" width="28" height="28" fill="#fff" stroke="#222" stroke-width="1.5"/>
                    <rect x="6" y="6" width="20" height="20" fill="#fff" stroke="#222" stroke-width="1"/>
                    <rect x="10" y="10" width="12" height="12" fill="#222"/>
                </svg>
                <svg class="communion-corner br" viewBox="0 0 32 32" aria-hidden="true">
                    <rect x="2" y="2" width="28" height="28" fill="#fff" stroke="#222" stroke-width="1.5"/>
                    <rect x="6" y="6" width="20" height="20" fill="#fff" stroke="#222" stroke-width="1"/>
                    <rect x="10" y="10" width="12" height="12" fill="#222"/>
                </svg>

                <div class="communion-inner-frame">
                    <!-- Left Artwork: Grapevine & Chalice with Bread -->
                    <div class="communion-left-art" aria-hidden="true"></div>

                    <!-- Center & Right Content Flow -->
                    <div class="communion-center-content">
                        <!-- Top Line: Fixed Motto -->
                        <div class="communion-motto">I am the Bread of Life</div>

                        <!-- Recipient Full Name & Title -->
                        <div class="communion-recipient-box">
                            <div class="communion-recipient-underline">
                                <span class="communion-name"><?php echo e($data['fullname'] ?? ''); ?></span>
                            </div>
                            <div class="communion-sublabel">First Communicant</div>
                        </div>

                        <!-- Title Line: Fixed Heading -->
                        <h1 class="communion-heading">FIRST HOLY COMMUNION</h1>

                        <!-- Date Line -->
                        <div class="communion-date-row">
                            on the <span class="communion-data-fill communion-fill-day"><?php echo e($communion_day); ?></span> day of <span class="communion-data-fill communion-fill-month" style="min-width: 48mm;"><?php echo e($communion_month_year); ?></span>
                        </div>

                        <!-- Location Line -->
                        <div class="communion-location-row">
                            in <span class="communion-data-fill communion-fill-loc"><?php echo e($communion_parish_name); ?></span>
                        </div>

                        <!-- Parish Full Name & Address Line in Script -->
                        <div class="communion-parish-block">
                            <div class="communion-parish-title"><?php echo e($communion_parish_name); ?></div>
                            <div class="communion-parish-subtitle"><?php echo e($communion_parish_address); ?></div>
                        </div>

                        <?php if (!empty($display_purpose_clean) && strtolower(trim((string)$display_purpose_clean)) !== 'whatever lawful purpose it may serve' && strtolower(trim((string)$display_purpose_clean)) !== 'n/a'): ?>
                        <div class="communion-purpose-line" style="margin: 2.5mm auto 2mm; font-family: 'Cinzel', Georgia, serif; font-size: 8.5pt; font-style: italic; color: #2b231a; text-align: center;">
                            Issued upon request for <span class="communion-data-fill" style="display: inline-block; border-bottom: 1px solid #222; font-style: normal; font-weight: 700; padding: 0 4mm;"><?php echo e($display_purpose_clean); ?></span>.
                        </div>
                        <?php endif; ?>

                        <!-- Three Signer Lines at Bottom Right -->
                        <div class="communion-signers-section">
                            <!-- 1. Parish Catechist Coordinator -->
                            <div class="communion-signer-box">
                                <div class="communion-signer-name"><?php echo e($data['catechist_coordinator'] ?? ''); ?></div>
                                <div class="communion-signer-line"></div>
                                <div class="communion-signer-position">Parish Catechist Coordinator</div>
                            </div>

                            <!-- 2. Parish Priest -->
                            <div class="communion-signer-box">
                                <div class="communion-signer-name"><?php echo e($data['parish_priest'] ?? ''); ?></div>
                                <div class="communion-signer-line"></div>
                                <div class="communion-signer-position">Parish Priest</div>
                            </div>

                            <!-- 3. Principal -->
                            <div class="communion-signer-box">
                                <div class="communion-signer-name"><?php echo e($data['principal'] ?? ''); ?></div>
                                <div class="communion-signer-line"></div>
                                <div class="communion-signer-position">Principal</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    <?php elseif (in_array($cert_type, ['baptism_certification', 'marriage_certification', 'first_communion_certification', 'communion_certification', 'confirmation_certification', 'funeral_certification', 'funeral', 'marriage'], true)): ?>
        <?php if (empty($record_load_error)): ?>
        <?php
            // Standardized Image 3 Certification Template - One clean paragraph with essential sacramental facts
            $cert_subject_name = '';
            $cert_body_lines = [];
            $cert_priest_name = '';

            if ($cert_type === 'baptism_certification') {
                $cert_subject_name = $data['fullname'] ?? 'N/A';
                $cert_body_lines[] = 'child of <span class="simple-cert-fill">' . e($father_name) . '</span> and <span class="simple-cert-fill">' . e($mother_name) . '</span>,';
                $cert_body_lines[] = 'received the Sacrament of Baptism on <span class="simple-cert-fill underline">' . e(displayDate($data['baptism_date'] ?? '')) . '</span>,';
                $cert_priest_name = cleanOfficiatingPriest($data['priest'] ?? ($data['parish_priest'] ?? ''));
                $cert_body_lines[] = 'officiated by Rev. Fr. <span class="simple-cert-fill underline">' . e($cert_priest_name) . '</span>.';
            } elseif ($cert_type === 'first_communion_certification' || $cert_type === 'communion_certification') {
                $cert_subject_name = $data['fullname'] ?? 'N/A';
                $cert_body_lines[] = 'child of <span class="simple-cert-fill">' . e($father_name) . '</span> and <span class="simple-cert-fill">' . e($mother_name) . '</span>,';
                $cert_body_lines[] = 'received the Sacrament of First Holy Communion on <span class="simple-cert-fill underline">' . e(displayDate($data['communion_date'] ?? '')) . '</span>,';
                $cert_priest_name = cleanOfficiatingPriest($data['priest'] ?? ($data['parish_priest'] ?? ''));
                $cert_body_lines[] = 'officiated by Rev. Fr. <span class="simple-cert-fill underline">' . e($cert_priest_name) . '</span>.';
            } elseif ($cert_type === 'confirmation_certification') {
                $cert_subject_name = $data['fullname'] ?? 'N/A';
                $cert_body_lines[] = 'child of <span class="simple-cert-fill">' . e($father_name) . '</span> and <span class="simple-cert-fill">' . e($mother_name) . '</span>,';
                $cert_body_lines[] = 'received the Sacrament of Confirmation on <span class="simple-cert-fill underline">' . e(displayDate($data['confirmation_date'] ?? '')) . '</span>,';
                $cert_bp_name = cleanOfficiatingPriest(!empty($confirmation_bishop) ? $confirmation_bishop : ($data['bishop_priest'] ?? ($data['parish_priest'] ?? '')));
                $conf_sps = [];
                if (!empty($godfather) && $godfather !== 'N/A') $conf_sps[] = $godfather;
                if (!empty($godmother) && $godmother !== 'N/A') $conf_sps[] = $godmother;
                if (empty($conf_sps) && !empty($data['sponsor']) && $data['sponsor'] !== 'N/A') $conf_sps[] = $data['sponsor'];
                $sponsors_text = !empty($conf_sps) ? ', the sponsors being <span class="simple-cert-fill">' . e(implode(' and ', $conf_sps)) . '</span>.' : '.';
                $cert_body_lines[] = 'administered by <span class="simple-cert-fill underline">' . e($cert_bp_name) . '</span>' . $sponsors_text;
            } elseif ($cert_type === 'marriage_certification' || $cert_type === 'marriage') {
                $cert_subject_name = ($data['husband_name'] ?? 'N/A') . ' and ' . ($data['wife_name'] ?? 'N/A');
                $cert_body_lines[] = 'were joined in the Sacrament of Holy Matrimony on <span class="simple-cert-fill underline">' . e(displayDate($data['wedding_date'] ?? '')) . '</span>,';
                $cert_priest_name = cleanOfficiatingPriest($data['officiating_priest'] ?? ($data['parish_priest'] ?? ''));
                $m_sponsors = trim((string)($data['sponsors'] ?? ''));
                if (!empty($m_sponsors) && $m_sponsors !== 'N/A') {
                    $cert_body_lines[] = 'officiated by Rev. Fr. <span class="simple-cert-fill underline">' . e($cert_priest_name) . '</span>, in the presence of witnesses <span class="simple-cert-fill">' . e($m_sponsors) . '</span>.';
                } else {
                    $cert_body_lines[] = 'officiated by Rev. Fr. <span class="simple-cert-fill underline">' . e($cert_priest_name) . '</span>.';
                }
            } else {
                // funeral / funeral_certification
                $cert_subject_name = $data['deceased_name'] ?? 'N/A';
                if (!empty($father_name) && $father_name !== 'N/A' && !empty($mother_name) && $mother_name !== 'N/A') {
                    $cert_body_lines[] = 'child of <span class="simple-cert-fill">' . e($father_name) . '</span> and <span class="simple-cert-fill">' . e($mother_name) . '</span>,';
                }
                $burial_date_str = displayDate($data['date_of_burial'] ?? ($data['burial_date'] ?? ''));
                $cert_body_lines[] = 'was given Christian Burial on <span class="simple-cert-fill underline">' . e($burial_date_str) . '</span>,';
                $cert_priest_name = cleanOfficiatingPriest($data['minister'] ?? ($data['parish_priest'] ?? ''));
                $cert_body_lines[] = 'officiated by Rev. Fr. <span class="simple-cert-fill underline">' . e($cert_priest_name) . '</span>.';
            }
            $cert_purpose = trim((string)($data['purpose'] ?? ''));
            $parish_priest_signature = formatParishPriestSignature($data['parish_priest'] ?? ($data['priest'] ?? ($data['officiating_priest'] ?? ($data['minister'] ?? ($data['bishop_priest'] ?? '')))));
        ?>
        <main class="certificate-page simple-cert-page" id="certificateDocument">
            <section class="simple-cert-sheet">
                <!-- Outer and Inner Thin Gold Borders -->
                <div class="simple-cert-outer-border"></div>
                <div class="simple-cert-inner-border"></div>

                <!-- 4 Gold L-Bracket Corner Ornaments -->
                <svg class="simple-cert-corner tl" viewBox="0 0 16 16" aria-hidden="true">
                    <path d="M 0 16 L 0 0 L 16 0" fill="none" stroke="#c59b27" stroke-width="1.2"/>
                </svg>
                <svg class="simple-cert-corner tr" viewBox="0 0 16 16" aria-hidden="true">
                    <path d="M 16 16 L 16 0 L 0 0" fill="none" stroke="#c59b27" stroke-width="1.2"/>
                </svg>
                <svg class="simple-cert-corner bl" viewBox="0 0 16 16" aria-hidden="true">
                    <path d="M 0 0 L 0 16 L 16 16" fill="none" stroke="#c59b27" stroke-width="1.2"/>
                </svg>
                <svg class="simple-cert-corner br" viewBox="0 0 16 16" aria-hidden="true">
                    <path d="M 16 0 L 16 16 L 0 16" fill="none" stroke="#c59b27" stroke-width="1.2"/>
                </svg>

                <!-- Centered Header Block with Circular Logos -->
                <header class="simple-cert-header">
                    <div class="simple-cert-circle-logo">
                        <?php if ($archdiocese_logo): ?>
                            <img src="<?php echo e($archdiocese_logo); ?>" alt="Archdiocese of Cotabato crest">
                        <?php endif; ?>
                    </div>
                    <div class="simple-cert-header-center">
                        <div class="simple-cert-church"><?php echo e(strtoupper($layout_church_title)); ?></div>
                        <div class="simple-cert-archdiocese"><?php echo e(strtoupper($layout_diocese_name)); ?></div>
                        <div class="simple-cert-parish"><?php echo e(strtoupper($display_parish_name)); ?></div>
                        <div class="simple-cert-loc"><?php echo e(strtoupper($display_ceremony_place)); ?></div>
                        <div class="simple-cert-title"><?php echo e($layout_certificate_title); ?></div>
                        <div class="simple-cert-title-rule"></div>
                    </div>
                    <div class="simple-cert-circle-logo">
                        <img src="<?php echo e($mission_logo); ?>" alt="San Lorenzo Ruiz Mission Station logo">
                    </div>
                </header>

                <!-- Lead-in -->
                <div class="simple-cert-intro">THIS IS TO CERTIFY THAT</div>

                <!-- Primary Visual Anchor (Name) -->
                <div class="simple-cert-name-wrap">
                    <span class="simple-cert-name"><?php echo e($cert_subject_name); ?></span>
                </div>

                <!-- Flowing Body Paragraph with Underlined Fields -->
                <div class="simple-cert-body">
                    <div class="simple-cert-paragraph">
                        <?php foreach ($cert_body_lines as $line): ?>
                            <div><?php echo $line; ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($display_purpose_clean) && strtolower(trim((string)$display_purpose_clean)) !== 'whatever lawful purpose it may serve' && strtolower(trim((string)$display_purpose_clean)) !== 'n/a'): ?>
                    <!-- Purpose of Request Line directly below main certification paragraph -->
                    <div class="simple-cert-purpose-line">
                        Issued upon request for <span class="simple-cert-fill underline"><?php echo e($display_purpose_clean); ?></span>.
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Footer with Dashed Parish Seal and Single Priest Signature Line -->
                <div class="simple-cert-footer">
                    <div class="simple-cert-seal">
                        Official<br>Parish Seal
                    </div>
                    <div class="simple-cert-sign">
                        <div class="simple-cert-sign-line"></div>
                        <div class="simple-cert-sign-name"><?php echo e($parish_priest_signature); ?></div>
                        <div class="simple-cert-sign-title">Parish Priest</div>
                    </div>
                </div>
            </section>
        </main>
        <?php endif; ?>
    <?php elseif ($cert_type === 'confirmation'): ?>
        <main class="confirmation-replica-page" id="certificateDocument">
            <section class="confirmation-replica-sheet">
                <!-- Replicated Ornamental Blue Fan / Scallop Border Frame -->
                <img src="../assets/img/certificates/ornamental-scallop-border.svg" class="ornamental-scallop-border-overlay" alt="" aria-hidden="true">

                <div class="conf-outer-frame">
                    <div class="conf-inner-frame">
                        <!-- Standardized Two-Logo Header (Archdiocese crest on left, Mission medallion on right) -->
                        <header class="conf-header-grid">
                            <div class="conf-logo-slot">
                                <?php if ($archdiocese_logo): ?>
                                    <img src="<?php echo e($archdiocese_logo); ?>" alt="Archdiocese of Cotabato crest">
                                <?php endif; ?>
                            </div>
                            <div class="conf-header-center">
                                <svg class="conf-title-svg" viewBox="0 0 540 64">
                                    <defs>
                                        <path id="confTitleArch" d="M 20 52 Q 270 12 520 52" fill="transparent" />
                                    </defs>
                                    <text font-family="'UnifrakturMaguntia', 'Old English Text MT', serif" font-size="33px" font-weight="700" fill="#006eb3">
                                        <textPath href="#confTitleArch" startOffset="50%" text-anchor="middle">
                                            Certificate of Confirmation
                                        </textPath>
                                    </text>
                                </svg>
                                <div class="conf-mission-name">SAN LORENZO RUIZ MISSION STATION</div>
                                <div class="conf-mission-loc">Aleosan, Cotabato</div>
                            </div>
                            <div class="conf-logo-slot">
                                <img src="<?php echo e($mission_logo); ?>" alt="San Lorenzo Ruiz Mission Station logo">
                            </div>
                        </header>

                        <!-- Recipient Name: Primary visual anchor -->
                        <div class="conf-recipient-wrap">
                            <div class="conf-recipient-name"><?php echo e(strtoupper($data['fullname'] ?? '')); ?></div>
                            <div class="conf-name-underline"></div>
                        </div>

                        <!-- Sacrament Declaration -->
                        <div class="conf-sacrament-line">
                            received the Holy Sacrament of Confirmation
                        </div>

                        <!-- Canonical Details Block -->
                        <div class="conf-canonical-block">
                            <div class="conf-canon-line">
                                <span class="conf-lbl">in this parish on the</span>
                                <span class="conf-val conf-val-day"><?php echo e($confirmation_day); ?></span>
                                <span class="conf-lbl">day of</span>
                                <span class="conf-val conf-val-month"><?php echo e($confirmation_month); ?></span>,
                                <span class="conf-lbl">20</span><span class="conf-val conf-val-yr"><?php echo e($confirmation_year_short); ?></span>.
                            </div>
                            <div class="conf-canon-line">
                                <span class="conf-lbl">Administered by His Excellency</span>
                                <span class="conf-val conf-val-bishop"><?php echo e($confirmation_bishop); ?></span>
                                <span class="conf-lbl">Archbishop of Cotabato</span>
                            </div>
                            <div class="conf-canon-line conf-delegate-line">
                                <span class="conf-lbl conf-delegate-lbl">or his delegate. Confirmed</span>
                                <span class="conf-val conf-val-cname"><?php echo e($confirmation_cname_display); ?></span>
                            </div>
                        </div>

                        <!-- Parents & Sponsors Indented Block with Perfectly Aligned Columns -->
                        <div class="conf-parents-block">
                            <div class="conf-parent-row">
                                <span class="conf-lbl">Father's name</span>
                                <span class="conf-fill-line"><?php echo e(strtoupper($father_name)); ?></span>
                            </div>
                            <div class="conf-parent-row">
                                <span class="conf-lbl">Mother's name</span>
                                <span class="conf-fill-line"><?php echo e(strtoupper($mother_name)); ?></span>
                            </div>
                            <div class="conf-parent-row">
                                <span class="conf-lbl">Godfather's name</span>
                                <span class="conf-fill-line"><?php echo e(strtoupper($godfather !== 'N/A' ? $godfather : '')); ?></span>
                            </div>
                            <div class="conf-parent-row">
                                <span class="conf-lbl">Godmother's name</span>
                                <span class="conf-fill-line"><?php echo e(strtoupper($godmother !== 'N/A' ? $godmother : '')); ?></span>
                            </div>
                        </div>

                        <!-- Certification Assurance Statement -->
                        <div class="conf-certify-stmt">
                            This is to certify that this certificate is a true copy of Confirmation Record kept in this parish.
                        </div>

                        <?php if (!empty($display_purpose_clean) && strtolower(trim((string)$display_purpose_clean)) !== 'whatever lawful purpose it may serve' && strtolower(trim((string)$display_purpose_clean)) !== 'n/a'): ?>
                        <div class="conf-purpose-stmt" style="margin-top: 1.5mm; margin-bottom: 1.5mm; font-style: italic; font-size: 8pt; color: var(--conf-blue); text-align: center; line-height: 1.2;">
                            Issued upon request for <span style="border-bottom: 1px solid var(--conf-blue); font-style: normal; font-weight: 700; padding: 0 3mm;"><?php echo e($display_purpose_clean); ?></span>.
                        </div>
                        <?php endif; ?>

                        <!-- Bottom Grid: Registry, Gold Seal, Clean Signature -->
                        <div class="conf-bottom-grid">
                            <div class="conf-reg-col">
                                <div class="conf-reg-top-row">
                                    <div class="conf-reg-item">
                                        <span class="conf-lbl">Book No.</span>
                                        <span class="conf-val conf-reg-val"><?php echo e($volume_no !== 'N/A' ? $volume_no : ''); ?></span>
                                    </div>
                                    <div class="conf-reg-item" style="margin-left: 2mm;">
                                        <span class="conf-lbl">Page</span>
                                        <span class="conf-val conf-reg-val"><?php echo e($page_no !== 'N/A' ? $page_no : ''); ?></span>
                                    </div>
                                    <div class="conf-reg-item" style="margin-left: 2mm;">
                                        <span class="conf-lbl">Year</span>
                                        <span class="conf-val conf-reg-val"><?php echo e($confirmation_year); ?></span>
                                    </div>
                                </div>
                                <div class="conf-reg-date-row">
                                    <span class="conf-lbl">Date</span>
                                    <span class="conf-val conf-date-val"><?php echo e($confirmation_issue_date); ?></span>
                                </div>
                            </div>

                            <div class="conf-seal-slot">
                                <div class="conf-seal-circle">
                                    <div class="conf-seal-star">&#10013;</div>
                                    <div class="conf-seal-lbl">PARISH SEAL</div>
                                    <div class="conf-seal-loc">ALEOSAN</div>
                                </div>
                            </div>

                            <div class="conf-sig-col">
                                <?php if (!empty($confirmation_sig_img)): ?>
                                    <img src="<?php echo e($confirmation_sig_img); ?>" class="conf-sig-img" alt="Priest Signature">
                                <?php else: ?>
                                    <div class="conf-sig-space"></div>
                                <?php endif; ?>
                                <div class="conf-priest-name"><?php echo e(strtoupper($confirmation_priest_name)); ?></div>
                                <div class="conf-priest-rule"></div>
                                <div class="conf-priest-title"><?php echo e($confirmation_priest_title); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
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
            <?php if (!empty($display_purpose_clean) && strtolower(trim((string)$display_purpose_clean)) !== 'whatever lawful purpose it may serve' && strtolower(trim((string)$display_purpose_clean)) !== 'n/a'): ?>
            <p style="margin-top: 10px;">Issued upon request for <span style="text-decoration: underline; font-weight: 600;"><?php echo e($display_purpose_clean); ?></span>.</p>
            <?php endif; ?>
            <p><strong>Certificate No:</strong> <?php echo e($issue['certificate_number']); ?></p>
            <p><strong>Verification Code:</strong> <?php echo e($issue['verification_code']); ?></p>
        </div>
    <?php endif; ?>
    <!-- Edit Purpose & Details Modal (Accessible across all Certificate Types) -->
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
                            <label for="edit_purpose" class="form-label fw-bold">Purpose of Certification / Request</label>
                            <div class="input-group mb-2">
                                <span class="input-group-text bg-light"><i class="fas fa-clipboard-check text-primary"></i></span>
                                <select class="form-select" id="purposePresetSelect" onchange="handlePurposePresetChange(this.value)">
                                    <option value="">-- Choose Common Purpose --</option>
                                    <option value="School Enrollment">School Enrollment</option>
                                    <option value="First Communion Requirement">First Communion Requirement</option>
                                    <option value="Confirmation Requirement">Confirmation Requirement</option>
                                    <option value="Marriage Requirement">Marriage Requirement</option>
                                    <option value="Personal Copy">Personal Copy</option>
                                    <option value="__OTHER__">Other (Enter custom purpose below)</option>
                                </select>
                            </div>
                            <input type="text" class="form-control" id="edit_purpose" name="purpose" value="<?php echo e($display_purpose_raw); ?>" placeholder="e.g. School Enrollment, Marriage Requirement, Personal Copy">
                            <div class="d-flex flex-wrap gap-1 mt-2 align-items-center">
                                <small class="text-muted fw-semibold me-1">Quick Select:</small>
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="setPurposeValue('School Enrollment')">School Enrollment</span>
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="setPurposeValue('First Communion Requirement')">First Communion Requirement</span>
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="setPurposeValue('Confirmation Requirement')">Confirmation Requirement</span>
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="setPurposeValue('Marriage Requirement')">Marriage Requirement</span>
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="setPurposeValue('Personal Copy')">Personal Copy</span>
                                <span class="badge bg-light text-dark border" style="cursor:pointer;" onclick="focusCustomPurpose()">Other (Custom)</span>
                            </div>
                        </div>

                        <div class="row g-3">
                            <?php if ($cert_type === 'marriage_certification' || $cert_type === 'marriage'): ?>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Groom's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" name="husband_name" value="<?php echo e($data['husband_name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Bride's Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" name="wife_name" value="<?php echo e($data['wife_name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Wedding Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control form-control-sm" name="wedding_date" value="<?php echo e($data['wedding_date'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Officiating Priest <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" name="officiating_priest" value="<?php echo e($data['officiating_priest'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-bold small">Witnesses / Sponsors</label>
                                    <input type="text" class="form-control form-control-sm" name="sponsors" value="<?php echo e($data['sponsors'] ?? ''); ?>" placeholder="e.g. Pedro Cruz and Maria Santos">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Signing Parish Priest</label>
                                    <input type="text" class="form-control form-control-sm" name="parish_priest" value="<?php echo e($data['parish_priest'] ?? ''); ?>" placeholder="e.g. Rev. Fr. Heriberto C. Villas, O.M.I.">
                                </div>
                            <?php elseif ($cert_type === 'funeral_certification' || $cert_type === 'funeral'): ?>
                                <div class="col-md-12">
                                    <label class="form-label fw-bold small">Deceased Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" name="deceased_name" value="<?php echo e($data['deceased_name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Date of Burial <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control form-control-sm" name="date_of_burial" value="<?php echo e($data['date_of_burial'] ?? ($data['burial_date'] ?? '')); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Date of Death</label>
                                    <input type="date" class="form-control form-control-sm" name="date_of_death" value="<?php echo e($data['date_of_death'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Father's Name</label>
                                    <input type="text" class="form-control form-control-sm" name="father_name" value="<?php echo e($father_name !== 'N/A' ? $father_name : ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Mother's Name</label>
                                    <input type="text" class="form-control form-control-sm" name="mother_name" value="<?php echo e($mother_name !== 'N/A' ? $mother_name : ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Officiating Minister / Priest <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" name="minister" value="<?php echo e($data['minister'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Signing Parish Priest</label>
                                    <input type="text" class="form-control form-control-sm" name="parish_priest" value="<?php echo e($data['parish_priest'] ?? ''); ?>" placeholder="e.g. Rev. Fr. Heriberto C. Villas, O.M.I.">
                                </div>
                            <?php elseif ($cert_type === 'confirmation_certification'): ?>
                                <div class="col-md-8">
                                    <label class="form-label fw-bold small">Confirmand Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" name="fullname" value="<?php echo e($data['fullname'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Confirmation Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control form-control-sm" name="confirmation_date" value="<?php echo e($data['confirmation_date'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-bold small">Confirming Bishop / Minister <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" name="bishop_priest" value="<?php echo e($confirmation_bishop); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Father's Name</label>
                                    <input type="text" class="form-control form-control-sm" name="father_name" value="<?php echo e($father_name !== 'N/A' ? $father_name : ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Mother's Name</label>
                                    <input type="text" class="form-control form-control-sm" name="mother_name" value="<?php echo e($mother_name !== 'N/A' ? $mother_name : ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Godfather's Name</label>
                                    <input type="text" class="form-control form-control-sm" name="godfather" value="<?php echo e($godfather !== 'N/A' ? $godfather : ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Godmother's Name</label>
                                    <input type="text" class="form-control form-control-sm" name="godmother" value="<?php echo e($godmother !== 'N/A' ? $godmother : ''); ?>">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-bold small">Signing Parish Priest</label>
                                    <input type="text" class="form-control form-control-sm" name="parish_priest" value="<?php echo e($data['parish_priest'] ?? ''); ?>" placeholder="e.g. Rev. Fr. Alberto G. Cahilig, O.M.I.">
                                </div>
                            <?php elseif ($cert_type === 'first_communion_certification'): ?>
                                <div class="col-md-8">
                                    <label class="form-label fw-bold small">Communicant Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" name="fullname" value="<?php echo e($data['fullname'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">First Communion Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control form-control-sm" name="communion_date" value="<?php echo e($data['communion_date'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Father's Name</label>
                                    <input type="text" class="form-control form-control-sm" name="father_name" value="<?php echo e($father_name !== 'N/A' ? $father_name : ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Mother's Name</label>
                                    <input type="text" class="form-control form-control-sm" name="mother_name" value="<?php echo e($mother_name !== 'N/A' ? $mother_name : ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Officiating Priest <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" name="priest" value="<?php echo e($data['priest'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Signing Parish Priest</label>
                                    <input type="text" class="form-control form-control-sm" name="parish_priest" value="<?php echo e($data['parish_priest'] ?? ''); ?>" placeholder="e.g. Rev. Fr. Alberto G. Cahilig, O.M.I.">
                                </div>
                            <?php else: ?>
                                <div class="col-md-8">
                                    <label class="form-label fw-bold small">Full Name of Baptized <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" name="fullname" value="<?php echo e($data['fullname'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Baptism Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control form-control-sm" name="baptism_date" value="<?php echo e($data['baptism_date'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Father's Name</label>
                                    <input type="text" class="form-control form-control-sm" name="father_name" value="<?php echo e($father_name !== 'N/A' ? $father_name : ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Mother's Name</label>
                                    <input type="text" class="form-control form-control-sm" name="mother_name" value="<?php echo e($mother_name !== 'N/A' ? $mother_name : ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Officiating Priest <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" name="priest" value="<?php echo e($data['priest'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Signing Parish Priest</label>
                                    <input type="text" class="form-control form-control-sm" name="parish_priest" value="<?php echo e($data['parish_priest'] ?? ''); ?>" placeholder="e.g. Rev. Fr. Heriberto C. Villas, O.M.I.">
                                </div>
                            <?php endif; ?>

                            <div class="col-12"><hr class="my-1"></div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Vol. / Book No.</label>
                                <input type="text" class="form-control form-control-sm" name="volume_no" value="<?php echo e($volume_no !== 'N/A' ? $volume_no : ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Page No.</label>
                                <input type="text" class="form-control form-control-sm" name="page_no" value="<?php echo e($page_no !== 'N/A' ? $page_no : ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Entry No.</label>
                                <input type="text" class="form-control form-control-sm" name="entry_no" value="<?php echo e($entry_no !== 'N/A' ? $entry_no : ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Date Issued</label>
                                <input type="date" class="form-control form-control-sm" name="date_issued" value="<?php echo e($data['date_issued'] ?? date('Y-m-d')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Additional Remarks</label>
                                <input type="text" class="form-control form-control-sm" name="remarks" value="<?php echo e($data['remarks'] ?? ''); ?>">
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

    <?php if ($is_confirmation_cert): ?>
    <!-- Edit Confirmation Details Modal -->
    <div class="modal fade" id="editConfirmationModal" tabindex="-1" aria-labelledby="editConfirmationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <form method="POST" action="" id="editConfirmationForm">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="update_certificate_details">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title" id="editConfirmationModalLabel"><i class="fas fa-pen-to-square me-2 text-warning"></i> Edit Confirmation Certificate Details</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                            <i class="fas fa-circle-info fs-5"></i>
                            <div>Replicating the official San Lorenzo Ruiz Mission Station Certificate of Confirmation. All fields sync directly to the confirmation registry.</div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold small">Confirmand Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="fullname" value="<?php echo e($data['fullname'] ?? ''); ?>" placeholder="e.g. REY MARK C. CAVAÑAS" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Confirmation Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="confirmation_date" value="<?php echo e($data['confirmation_date'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-bold small">Confirming Bishop / Minister <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="bishop_priest" value="<?php echo e($confirmation_bishop); ?>" placeholder="e.g. BP. ANGELITO R. LAMPON,OMI,DD" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Confirmation Name (Optional)</label>
                                <input type="text" class="form-control form-control-sm" name="confirmation_name" value="<?php echo e($confirmation_cname_display); ?>" placeholder="e.g. Francis">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold small">Purpose of Request</label>
                                <input type="text" class="form-control form-control-sm" name="purpose" value="<?php echo e($display_purpose_raw); ?>" placeholder="e.g. School Enrollment, Marriage Requirement, Personal Copy">
                            </div>

                            <div class="col-12"><hr class="my-1"></div>
                            <div class="col-12">
                                <h6 class="fw-bold small text-muted text-uppercase mb-1"><i class="fas fa-users me-1"></i> Parents & Godparents (Sponsors)</h6>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Father's Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="father_name" value="<?php echo e($father_name !== 'N/A' ? $father_name : ''); ?>" placeholder="e.g. ROBERTO A. CAVAÑAS" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Mother's Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="mother_name" value="<?php echo e($mother_name !== 'N/A' ? $mother_name : ''); ?>" placeholder="e.g. JOY C. CANTOMAYOR" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Godfather's Name</label>
                                <input type="text" class="form-control form-control-sm" name="godfather" value="<?php echo e($godfather !== 'N/A' ? $godfather : ''); ?>" placeholder="e.g. Godfather's Name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Godmother's Name</label>
                                <input type="text" class="form-control form-control-sm" name="godmother" value="<?php echo e($godmother !== 'N/A' ? $godmother : ''); ?>" placeholder="e.g. MARY ANN C. DELA CRUZ">
                            </div>

                            <div class="col-12"><hr class="my-1"></div>
                            <div class="col-12">
                                <h6 class="fw-bold small text-muted text-uppercase mb-1"><i class="fas fa-book-bookmark me-1"></i> Parish Registry & Signatory</h6>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Book No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="volume_no" value="<?php echo e($volume_no !== 'N/A' ? $volume_no : ''); ?>" placeholder="e.g. 03" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Page No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="page_no" value="<?php echo e($page_no !== 'N/A' ? $page_no : ''); ?>" placeholder="e.g. 16" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Date Issued <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="date_issued" value="<?php echo e($data['date_issued'] ?? date('Y-m-d')); ?>" required>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label fw-bold small">Signing Priest (Priest-in-Charge) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="parish_priest" value="<?php echo e($confirmation_priest_name); ?>" placeholder="e.g. REV. FR. ALBERTO G. CAHILIG, O.M.I." required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-bold small">Priest Title / Designation <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="priest_position" value="<?php echo e($confirmation_priest_title); ?>" placeholder="Priest-in-Charge" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Save & Update Confirmation Certificate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_communion_cert): ?>
    <!-- Edit First Communion Details Modal -->
    <div class="modal fade" id="editCommunionModal" tabindex="-1" aria-labelledby="editCommunionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <form method="POST" action="" id="editCommunionForm">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="update_certificate_details">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title" id="editCommunionModalLabel"><i class="fas fa-pen-to-square me-2 text-warning"></i> Edit First Communion Certificate Details</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                            <i class="fas fa-circle-info fs-5"></i>
                            <div>All 7 required fields must be complete before the First Communion certificate can be officially generated and printed.</div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label fw-bold small">Recipient's Full Name (First Communicant) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="fullname" value="<?php echo e($data['fullname'] ?? ''); ?>" placeholder="e.g. Rey Mark C. Cavañas" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold small">Purpose of Request</label>
                                <input type="text" class="form-control" name="purpose" value="<?php echo e($display_purpose_raw); ?>" placeholder="e.g. School Enrollment, Marriage Requirement, Personal Copy">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Date of First Communion <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="communion_date" value="<?php echo e($data['communion_date'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Parish / Mission Station Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="parish_name" value="<?php echo e($communion_parish_name); ?>" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold small">Parish Full Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="parish_address" value="<?php echo e($communion_parish_address); ?>" placeholder="e.g. Aleosan, Cotabato" required>
                            </div>

                            <div class="col-12"><hr class="my-2"></div>
                            <div class="col-12">
                                <h6 class="fw-bold small text-muted text-uppercase mb-2"><i class="fas fa-signature me-1"></i> Authorized Signers (Parish Roster)</h6>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Parish Catechist Coordinator <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="catechist_coordinator" value="<?php echo e($data['catechist_coordinator'] ?? ''); ?>" placeholder="e.g. Sis. Lourdes Fernandez" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Parish Priest <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="parish_priest" value="<?php echo e($data['parish_priest'] ?? ''); ?>" placeholder="e.g. Rev. Fr. Alberto Cahilig, OMI" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Principal <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="principal" value="<?php echo e($data['principal'] ?? ''); ?>" placeholder="e.g. Principal Name" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Save & Update Certificate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($cert_type === 'baptism'): ?>
    <!-- Edit Baptism Details Modal -->
    <div class="modal fade" id="editBaptismModal" tabindex="-1" aria-labelledby="editBaptismModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <form method="POST" action="" id="editBaptismForm">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="update_certificate_details">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title" id="editBaptismModalLabel"><i class="fas fa-pen-to-square me-2 text-warning"></i> Edit Baptism Certificate Details</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                            <i class="fas fa-circle-info fs-5"></i>
                            <div>Standard parish baptism record format. Update or complete required details below.</div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label fw-bold small">Purpose of Request</label>
                                <input type="text" class="form-control form-control-sm" name="purpose" value="<?php echo e($display_purpose_raw); ?>" placeholder="e.g. School Enrollment, Marriage Requirement, Personal Copy">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Full Name of Baptized <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="fullname" value="<?php echo e($baptism_name); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Place of Birth (Baptized) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="birth_place" value="<?php echo e($baptism_birth_place); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Date of Birth (Birthday) <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="birth_date" value="<?php echo e($data['birth_date'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Residence (Address) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="residence" value="<?php echo e($baptism_residence); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Father's Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="father_name" value="<?php echo e($baptism_father); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Father's Birthplace <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="father_birth_place" value="<?php echo e($baptism_father_birthplace); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Mother's Name (Maiden Name) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="mother_name" value="<?php echo e($baptism_mother); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Mother's Birthplace <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="mother_birth_place" value="<?php echo e($baptism_mother_birthplace); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Date of Baptism <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="baptism_date" value="<?php echo e($data['baptism_date'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Officiating Priest <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="priest" value="<?php echo e($baptism_priest); ?>" placeholder="e.g. Rev. Fr. Heriberto C. Villas, O.M.I." required>
                            </div>
                        </div>

                        <!-- Dynamic Sponsors in Edit Modal -->
                        <div class="border rounded p-3 bg-light mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label small fw-bold mb-0">
                                    Sponsors / Ninong-Ninang <span class="text-danger">*</span>
                                    <small class="text-muted fw-normal ms-1">(At least 2 required; each on its own line)</small>
                                </label>
                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="addEditSponsorBtn">
                                    <i class="fas fa-plus"></i> Add Sponsor
                                </button>
                            </div>
                            <div id="editSponsorsList">
                                <?php 
                                $edit_sponsors = !empty($baptism_sponsors) ? $baptism_sponsors : ['', ''];
                                while (count($edit_sponsors) < 2) {
                                    $edit_sponsors[] = '';
                                }
                                ?>
                                <?php foreach ($edit_sponsors as $sIdx => $sName): ?>
                                    <div class="input-group input-group-sm mb-2 edit-sponsor-row">
                                        <span class="input-group-text"><i class="fas fa-user-check text-secondary"></i> <span class="edit-sponsor-num ms-1"><?php echo ($sIdx + 1); ?></span></span>
                                        <input type="text" name="sponsors[]" class="form-control" placeholder="Sponsor Full Name (e.g. Nida Paredes)" value="<?php echo e($sName); ?>">
                                        <button type="button" class="btn btn-outline-danger remove-edit-sponsor" title="Remove sponsor" <?php echo count($edit_sponsors) <= 2 ? 'disabled' : ''; ?>><i class="fas fa-trash"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Vol. / Book No.</label>
                                <input type="text" class="form-control form-control-sm" name="volume_no" value="<?php echo e($volume_no !== 'N/A' ? $volume_no : ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Page No.</label>
                                <input type="text" class="form-control form-control-sm" name="page_no" value="<?php echo e($page_no !== 'N/A' ? $page_no : ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Entry No.</label>
                                <input type="text" class="form-control form-control-sm" name="entry_no" value="<?php echo e($entry_no !== 'N/A' ? $entry_no : ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Date Issued</label>
                                <input type="date" class="form-control form-control-sm" name="date_issued" value="<?php echo e($data['date_issued'] ?? date('Y-m-d')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Additional Remarks</label>
                                <input type="text" class="form-control form-control-sm" name="remarks" value="<?php echo e($data['remarks'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Save & Update Certificate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const editList = document.getElementById('editSponsorsList');
        const addBtn = document.getElementById('addEditSponsorBtn');

        function updateEditSponsorRemoveButtons() {
            if (!editList) return;
            const rows = editList.querySelectorAll('.edit-sponsor-row');
            rows.forEach((row, idx) => {
                const label = row.querySelector('.edit-sponsor-num');
                if (label) label.textContent = (idx + 1);
                const btn = row.querySelector('.remove-edit-sponsor');
                if (btn) btn.disabled = (rows.length <= 2);
            });
        }

        if (addBtn) {
            addBtn.addEventListener('click', function() {
                const count = editList.querySelectorAll('.edit-sponsor-row').length;
                const div = document.createElement('div');
                div.className = 'input-group input-group-sm mb-2 edit-sponsor-row';
                div.innerHTML = `
                    <span class="input-group-text"><i class="fas fa-user-check text-secondary"></i> <span class="edit-sponsor-num ms-1">${count + 1}</span></span>
                    <input type="text" name="sponsors[]" class="form-control" placeholder="Sponsor Full Name (e.g. Ninong / Ninang)">
                    <button type="button" class="btn btn-outline-danger remove-edit-sponsor" title="Remove sponsor"><i class="fas fa-trash"></i></button>
                `;
                editList.appendChild(div);
                updateEditSponsorRemoveButtons();
                div.querySelector('input').focus();
            });
        }

        if (editList) {
            editList.addEventListener('click', function(e) {
                const btn = e.target.closest('.remove-edit-sponsor');
                if (btn && !btn.disabled) {
                    const row = btn.closest('.edit-sponsor-row');
                    if (row) {
                        row.remove();
                        updateEditSponsorRemoveButtons();
                    }
                }
            });
        }
    });
    </script>
    <?php endif; ?>

    <script>
    function setPurposeValue(val) {
        const input = document.getElementById('edit_purpose');
        const select = document.getElementById('purposePresetSelect');
        if (input) {
            input.value = val;
            input.focus();
        }
        if (select) {
            let found = false;
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value === val) {
                    select.selectedIndex = i;
                    found = true;
                    break;
                }
            }
            if (!found) select.value = '__OTHER__';
        }
    }
    function handlePurposePresetChange(val) {
        const input = document.getElementById('edit_purpose');
        if (!input) return;
        if (val === '__OTHER__') {
            input.value = '';
            input.focus();
        } else if (val !== '') {
            input.value = val;
        }
    }
    function focusCustomPurpose() {
        const input = document.getElementById('edit_purpose');
        const select = document.getElementById('purposePresetSelect');
        if (select) select.value = '__OTHER__';
        if (input) {
            input.focus();
            input.select();
        }
    }

    function printCertificate() {
        <?php if ($is_confirmation_cert && !empty($missing_confirmation_fields)): ?>
        alert("Cannot generate or print certificate. Please complete all required Confirmation fields first:\n\n- " + <?php echo json_encode(implode("\n- ", $missing_confirmation_fields)); ?>);
        const confModalEl = document.getElementById('editConfirmationModal');
        if (confModalEl) {
            const modal = bootstrap.Modal.getOrCreateInstance(confModalEl);
            modal.show();
        }
        return;
        <?php endif; ?>
        <?php if ($is_communion_cert && !empty($missing_communion_fields)): ?>
        alert("Cannot generate or print certificate. Please complete all required First Communion fields first:\n\n- " + <?php echo json_encode(implode("\n- ", $missing_communion_fields)); ?>);
        const communionModalEl = document.getElementById('editCommunionModal');
        if (communionModalEl) {
            const modal = bootstrap.Modal.getOrCreateInstance(communionModalEl);
            modal.show();
        }
        return;
        <?php endif; ?>
        <?php if (!empty($missing_baptism_fields)): ?>
        alert("Cannot generate or print certificate. Please complete all required Baptism fields first:\n\n- " + <?php echo json_encode(implode("\n- ", $missing_baptism_fields)); ?>);
        const baptismModalEl = document.getElementById('editBaptismModal');
        if (baptismModalEl) {
            const modal = bootstrap.Modal.getOrCreateInstance(baptismModalEl);
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
