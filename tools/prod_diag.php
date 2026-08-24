<?php
if (php_sapi_name() !== 'cli' && ($_GET['token'] ?? '') !== 'tugon_secret_diag_2026') {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/authentication.php';
require_once __DIR__ . '/../includes/account-management.php';
require_once __DIR__ . '/../includes/otp-transactions.php';
require_once __DIR__ . '/../config/textbee.php';

echo "=== PRODUCTION TEXTBEE CONFIG ===\n";
echo "TEXTBEE_API_KEY: " . (defined('TEXTBEE_API_KEY') ? substr(TEXTBEE_API_KEY, 0, 10) . '... len=' . strlen(TEXTBEE_API_KEY) : 'UNDEFINED') . "\n";
echo "TEXTBEE_DEVICE_ID: " . (defined('TEXTBEE_DEVICE_ID') ? TEXTBEE_DEVICE_ID : 'UNDEFINED') . "\n";
echo "TEXTBEE_BASE_URL: " . (defined('TEXTBEE_BASE_URL') ? TEXTBEE_BASE_URL : 'UNDEFINED') . "\n\n";

$action = $_GET['action'] ?? ($argv[1] ?? '');
$paramPhone = $_GET['phone'] ?? ($argv[2] ?? '');

// Action: sync / activate
if ($action === 'activate_all') {
    $conn->query("UPDATE users SET status = 'active' WHERE status != 'active'");
    echo "All users updated to 'active' status.\n\n";
}

if ($action === 'add_user') {
    $phone = $paramPhone ?: '09631237247';
    $name = $_GET['name'] ?? ($argv[3] ?? 'Prince Ondoy');
    $email = $_GET['email'] ?? ($argv[4] ?? 'princeondoy0@gmail.com');
    
    $check = $conn->prepare("SELECT id FROM users WHERE phone_number = ? OR email = ?");
    $check->bind_param('ss', $phone, $email);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    
    if (!$existing) {
        $u2 = $conn->query("SELECT role FROM users LIMIT 1")->fetch_assoc();
        $validRole = $u2['role'] ?? 'user';
        echo "Detected valid role: [{$validRole}]\n";
        
        $pw = password_hash('Parishioner@123', PASSWORD_DEFAULT);
        $insertSql = "INSERT INTO users (fullname, email, phone_number, password, role, status) VALUES (?, ?, ?, ?, ?, 'active')";
        $stmt = $conn->prepare($insertSql);
        if (!$stmt) {
            echo "Prepare failed: " . $conn->error . "\n";
        } else {
            $stmt->bind_param('sssss', $name, $email, $phone, $pw, $validRole);
            if (!$stmt->execute()) {
                echo "Execute failed: " . $stmt->error . "\n";
            } else {
                $newId = $conn->insert_id;
                echo "Created User #{$newId} ({$name}, {$phone}, {$email})\n";
                synchronizeAuthenticationIdentifier($conn, $newId, 'mobile', $phone);
                synchronizeAuthenticationIdentifier($conn, $newId, 'email', $email);
            }
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("UPDATE users SET status = 'active', phone_number = ? WHERE id = ?");
        $stmt->bind_param('si', $phone, $existing['id']);
        $stmt->execute();
        $stmt->close();
        synchronizeAuthenticationIdentifier($conn, $existing['id'], 'mobile', $phone);
        echo "User already exists with ID #{$existing['id']} - updated to active\n";
    }
}

if ($action === 'test_sms') {
    $targetPhone = $paramPhone ?: '09635866550';
    echo "Sending direct SMS to {$targetPhone} from Railway...\n";
    $smsResult = sendTugonSms($conn, $targetPhone, "TUGON Railway Test: TextBee is connected! Time: " . date('H:i:s'), 1, 'test');
    echo "Result: " . json_encode($smsResult) . "\n\n";
}

if ($action === 'test_forgot_pw') {
    $targetPhone = $paramPhone ?: '09635866550';
    echo "Simulating Forgot Password OTP creation for {$targetPhone} on Railway...\n";
    $user = findUserByAuthenticationIdentifier($conn, $targetPhone);
    if (!$user) {
        echo "findUserByAuthenticationIdentifier returned NULL for {$targetPhone}\n";
    } else {
        echo "Found User #{$user['id']} ({$user['fullname']}), status: {$user['status']}\n";
        $sent = createOtpTransaction($conn, (int) $user['id'], 'password_reset', 'mobile');
        echo "createOtpTransaction result: " . json_encode($sent) . "\n\n";
    }
}

if ($action === 'set_pw') {
    $targetPhone = $paramPhone ?: '09635866550';
    $newPw = $_GET['new_password'] ?? ($argv[3] ?? 'Parishioner@123');
    $hash = password_hash($newPw, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE phone_number = ? OR email = ?");
    $stmt->bind_param('sss', $hash, $targetPhone, $targetPhone);
    $stmt->execute();
    echo "Updated password for {$targetPhone} to: [{$newPw}] (affected: {$stmt->affected_rows})\n\n";
    $stmt->close();
}

if ($action === 'check_pw') {
    echo "=== CHECKING PASSWORDS FOR ALL USERS ===\n";
    $res = $conn->query("SELECT id, fullname, phone_number, email, password FROM users");
    $common = ['Reymark@123', 'Parishioner@123', 'Admin@123', 'Password@123', 'password123', 'admin123', '12345678', 'reymark123', 'password'];
    while ($u = $res->fetch_assoc()) {
        echo "User #{$u['id']}: {$u['fullname']} ({$u['phone_number']} / {$u['email']})\n";
        $found = false;
        foreach ($common as $p) {
            if (password_verify($p, $u['password'])) {
                echo "  MATCHED: [{$p}]\n";
                $found = true;
                break;
            }
        }
        if (!$found) {
            echo "  Hash: " . substr($u['password'], 0, 20) . "... (no standard dictionary match)\n";
        }
    }
    echo "\n";
}

if ($action === 'test_login') {
    $targetPhone = $paramPhone ?: '09635866550';
    $password = $_GET['password'] ?? ($argv[3] ?? 'Reymark@123');
    echo "=== TESTING LOGIN AUTHENTICATION FOR [{$targetPhone}] ===\n";
    $auth = beginPasswordAuthentication($conn, $targetPhone, $password);
    echo "Result:\n";
    print_r($auth);
    echo "\n";
}

if ($action === 'sync_knowledge') {
    echo "=== SYNCHRONIZING CANONICAL CHATBOT KNOWLEDGE ===\n";
    $knowledgeItems = [
        [30, "Baptism Requirements", "what are the baptism requirements,baptism requirements,ano ang requirements sa binyag,what papers are needed for baby baptism,i want to baptize my child what do i need,requirements for baptism,binyag,baptism,baptismal,baptize,pabinyag,baby baptism,christening,requirements,papers,documents", "Before submitting a Baptism request, prepare these official parish requirements.", "Chapel recommendation\nParents' latest marriage contract or receipt, if applicable\nPhotocopy of marriage certificate, if married\nPhotocopy of the child's live birth certificate with registry number\nTwo white cards of sponsors\nWhite cards of parents\nPre-baptismal investigation sheet, if requested by the parish office", "sacrament", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "fe72ea43e235d8f98fdd73fd029e09678c09c00934a2e954de952f53f2e206e1"],
        [31, "Confirmation Requirements", "what are the confirmation requirements,confirmation requirements,ano ang requirements sa kumpirmasyon,what documents are needed for confirmation,how can i request confirmation,requirements for confirmation,confirmation,kumpil,pakumpil,confirmand,requirements,papers,documents,ano ang requirements sa kumpil,paano magpakumpil,kumpil requirements,kailangan sa confirmation", "For Confirmation, prepare the information and supporting parish documents requested by the parish office.", "Baptismal Certificate\nConfirmation Registration Form\nConfirmation Seminar (recollection)\nConfirmation Sponsor (Godparents)", "sacrament", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "d45461da23c854ee22ff6f6eddc003c1f1c4b672dbd307b804b9cf6b25df6bd2"],
        [32, "Marriage Requirements", "what are the marriage requirements,marriage requirements,ano ang requirements sa kasal,what papers do we need for church wedding,paano magpa schedule ng kasal,requirements for marriage,requirements for wedding,wedding requirements,marriage,wedding,kasal,pakasal,merriage,requirements,papers,documents", "Marriage requirements include the following official parish documents and preparation steps.", "Pre-Cana seminar\nMunicipal marriage license\nBEC recommendation\nBaptismal certificate for marriage purpose\nConfirmation certificate\nPermit to marry, if applicable\nMarriage interview\nConfession\nCO permit, if applicable for police or army personnel", "sacrament", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "d95cbebcb1c95e8c9cf68077571f3566b604708599c3b0a7c56f89a6a2602c86"],
        [33, "First Holy Communion Requirements", "what are the first holy communion requirements,first holy communion requirements,first communion requirements,requirements for first communion,first communion,holy communion,communion,komunyon,requirements,papers,documents,ano ang requirements sa komunyon,first communion requirements,kailangan sa first holy communion", "For First Holy Communion, prepare the communicant information and supporting parish records requested by the parish office.", "Baptismal Certificate\nRegistration Form\nCommunion Preparation Classes\nRecollection/Seminar\nFirst Confession", "sacrament", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "434084cf63c117486de194390489b40e28f693fdea8a8b78b26cb1e2e3f6b545"],
        [34, "Anointing of the Sick", "how can i request anointing of the sick,anointing of the sick,pahid ng langis,pabihis,sick call,anointing,sick,ospital,hospital,urgent,priest visit", "For Anointing of the Sick, provide the sick person's name, location, contact person, preferred date and time, and urgent details if any.", "Prepare the complete name of the sick person\nProvide the exact address or hospital location\nAdd a contact person and phone number\nContact the parish office directly if the matter is urgent", "sacrament", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "9614794a62b1f2d19450a47790f4ea8b0661817dd6d0b963f6fdf80bc90398df"],
        [35, "Funeral Mass", "how can i request a funeral mass,funeral mass,burol,libing,request funeral,funeral,burial,lamay,patay,memorial", "For Funeral Mass requests, provide the deceased person's name, preferred date and time, contact person, and parish office instructions.", "Prepare the complete name of the deceased\nChoose the preferred Mass date and time\nProvide the contact person and phone number\nWait for parish office confirmation of availability", "sacrament", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "6b3e1535619506f9a3b43ae9c05594679c39f041a3cb0b9d3aa7a0903841235f"],
        [36, "House Blessing", "how can i request a house blessing,house blessing,pabasbas ng bahay,blessing ng bahay,bless house,bahay,pabless bahay,pa bless bahay,home blessing", "For house blessing requests, provide the requester name, exact address, preferred schedule, and contact details.", "Enter the complete house address\nChoose a preferred date and time\nProvide a contact number\nWait for parish office confirmation", "blessing", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "037a25a1e3614a38aea94565b44f99fe1bbb5e00d4f41001c2660d13eb4b906e"],
        [37, "Vehicle Blessing", "how can i request a vehicle blessing,vehicle blessing,pabasbas ng sasakyan,car blessing,motor blessing,sasakyan,pabless sasakyan,pa bless car", "For vehicle blessing requests, provide the owner name, vehicle details, preferred schedule, and contact details.", "Provide the owner or requester name\nEnter the vehicle details\nChoose a preferred date and time\nWait for parish office confirmation", "blessing", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "cd7b81ccdfcc7b15eb8b81f51e58c45dc9c9b0c79c830bec5c84dfd3ae394d0f"],
        [38, "Certificate Request", "how can i request a parish certificate,how to request certificate,how to request certificates,request certificate,certificate,baptism certificate,baptismal certificate,confirmation certificate,first communion certificate,marriage certificate,need certificate,get my certificate,record copy,certification,paano kumuha ng certificate,paano mag request ng baptismal certificate,sertipiko,kopya ng record", "To request a parish certificate, choose the certificate type, complete the required information, upload supporting documents, and wait for parish review.", "Open the certificate request page\nSelect the certificate type\nEnter accurate names, dates, and related details\nUpload the required supporting documents\nSubmit and track the request status", "certificate", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "61f9e84b4216065f0b1a2aaf2739a3774cddb2b4ee6d3574d199cc6c894faf33"],
        [39, "Mass Schedule", "mass schedule,mass time,what time is mass,sunday mass,sunday mass schedule,what is the sunday mass schedule,weekday mass,wednesday mass,what are the wednesday mass schedules,today mass,misa,oras ng misa,schedule ng misa,church schedule,anong oras ang misa,oras ng misa,iskedyul ng misa,may misa ba ngayon", "Mass Schedule\n\nSunday Mass: 8:30 AM\nWednesday Mass: 5:00 PM\n\nThe parish office manages official Mass schedules through the schedule calendar. Please check the Schedule page or contact the parish office for the latest approved schedule.", "", "schedule", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "463d89416d878471fe14ed05668c24a361becfe249b23cc8adb2bda699cdcf16"],
        [40, "Office Hours", "office hours,office schedule,what time do you open,what time do you close,opening hours,closing hours,are you open,parish office hours,parish office,opisina,open ba,contact office,bukas ba ang opisina,oras ng office,office hours po,kailan bukas ang parish office", "Parish Office Hours\nTuesday - Saturday: 8:00 AM - 5:00 PM\nLunch Break: 12:00 PM - 1:00 PM\nSunday: 7:00 AM - 12:00 PM", "", "office", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "de818fe82420ec1e5ee3b9c1459d88d1f99126244605145fc1d99a8e0664969d"],
        [41, "Reservations", "how can i make a reservation,reservation,reserve,book a schedule,how to reserve,booking,hall reservation,venue,function hall,paano mag reserve,reservation po,mag book ng schedule,venue reservation", "Reservation requests are reviewed based on event type, date, time, location, and parish schedule availability.", "Choose the reservation or service type\nEnter the event date, time, and location\nProvide the purpose and contact details\nWait for approval or parish office follow-up", "general", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "fa4beffe30e1dfb167e2ca2c4a87024ac3b01c4b3e8864eaa324dd2101ee5258"],
        [42, "Emergency Contact", "who should i contact for urgent parish concerns,emergency contact,urgent concern,emergency,urgent,contact,phone,help,priest urgent", "For urgent sacramental or parish concerns, please contact the parish office directly so staff can assist immediately.", "Prepare the name of the person needing assistance, exact location, and reachable contact number.", "office", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "9b736ce60be2fd4ecb157fd99bd0a85c6c0aafb7294b9205bc170a56297bcd25"],
        [43, "Parish Priest", "who is the parish priest,parish priest,priest,pastor,who is the priest,who is the priest in the aleosan parish,sino ang parish priest,pari ng parokya,who is the priest", "The Parish Priest is Rev. Fr. Alberto G. Cahilig, OMI.", "", "office", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "367781f8cd5c66101a198a4b7f96dc8befa8c62dad8862632652070f5b71c347"],
        [44, "Parish Vicar", "who is the parish vicar,parish vicar,vicar,assistant priest,parochial vicar,sino ang parish vicar,assistant priest,parochial vicar", "The Parish Vicar is Rev. Fr. Alvin Vicente C. Barretto, OMI.", "", "office", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "d048d2669a46f2311a466cd5d725903cb7a6f608eff33c11cf41664cda5d7320"],
        [45, "Mass Celebrant Assignments", "who is the priest this sunday,celebrant,mass celebrant,priest schedule,who will celebrate mass", "Mass celebrant assignments may change without prior notice. Please contact the parish office for the latest celebrant schedule.", "", "office", "TUGON parish knowledge base", "active", "approved", 1, "2026-07-12", "bilingual", "80d536828fd6abc9c68a4c9c21b2f4f0e5b3774434a57e6c07016c78107a6f0e"]
    ];

    $stmt = $conn->prepare("REPLACE INTO chatbot_knowledge (knowledge_id, topic, keywords, answer, steps, category, source, status, approval_status, version, effective_date, language, reviewed_at, content_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
    $inserted = 0;
    foreach ($knowledgeItems as $k) {
        $stmt->bind_param('issssssssisss', $k[0], $k[1], $k[2], $k[3], $k[4], $k[5], $k[6], $k[7], $k[8], $k[9], $k[10], $k[11], $k[12]);
        if ($stmt->execute()) {
            $inserted++;
        } else {
            echo "Error inserting #{$k[0]}: " . $stmt->error . "\n";
        }
    }
    $stmt->close();
    echo "Successfully synchronized {$inserted} / " . count($knowledgeItems) . " canonical knowledge records.\n\n";

    // Set dataset metadata
    $conn->query("REPLACE INTO chatbot_knowledge_meta (meta_key, meta_value) VALUES ('official_dataset_version', '2026-08-25-canonical-v1')");

    // Ensure AI permissions exist and are mapped
    echo "=== ENSURING AI RBAC PERMISSIONS ===\n";
    $permissions = [
        ['ai.parishioner.use', 'Use Parishioner AI', 'ai', 'Use AI within the authenticated parishioner data scope'],
        ['ai.staff.use', 'Use Staff AI', 'ai', 'Use staff-facing AI guidance'],
        ['ai.admin.use', 'Use Administrator AI', 'ai', 'Use administrator AI analytics and broad authorized search'],
        ['ai.search.records', 'AI Record Search', 'ai', 'Search records through permission-scoped AI'],
        ['ai.search.reports', 'AI Report Search', 'ai', 'Read authorized aggregate reports through AI'],
        ['ai.manage.knowledge', 'Manage AI Knowledge', 'ai', 'Manage and approve authoritative AI knowledge'],
        ['ai.review.feedback', 'Review AI Feedback', 'ai', 'Submit and review AI response feedback']
    ];
    $pStmt = $conn->prepare("INSERT INTO permissions (permission_key, display_name, category, description) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), category=VALUES(category), description=VALUES(description)");
    foreach ($permissions as $p) {
        $pStmt->bind_param('ssss', $p[0], $p[1], $p[2], $p[3]);
        $pStmt->execute();
    }
    $pStmt->close();

    $conn->query("INSERT IGNORE INTO role_permissions (role_id, permission_id)
        SELECT r.role_id, p.permission_id FROM roles r JOIN permissions p
        WHERE (r.role_key = 'parishioner' AND p.permission_key = 'ai.parishioner.use')
           OR (r.role_key = 'administrator' AND p.permission_key IN ('ai.staff.use','ai.admin.use','ai.search.records','ai.search.reports','ai.manage.knowledge','ai.review.feedback'))
           OR (r.role_key = 'records_clerk' AND p.permission_key IN ('ai.staff.use','ai.search.records'))
           OR (r.role_key = 'finance_staff' AND p.permission_key IN ('ai.staff.use','ai.search.reports'))
           OR (r.role_key = 'parish_staff' AND p.permission_key IN ('ai.staff.use','ai.search.records'))");

    // Ensure all users have roles mapped
    $conn->query("INSERT INTO user_roles (user_id, role_id)
        SELECT u.id, r.role_id
        FROM users u
        JOIN roles r ON r.role_key = CASE WHEN u.role = 'admin' THEN 'administrator' ELSE 'parishioner' END
        ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)");

    echo "RBAC permissions and user roles verified and updated.\n\n";
}

if ($action === 'check_ai') {
    echo "=== PRODUCTION AI DATABASE AUDIT ===\n";
    $tables = ['chatbot_knowledge', 'chatbot_knowledge_meta', 'chatbot_inquiries', 'ai_responses', 'ai_feedback', 'roles', 'permissions', 'role_permissions', 'user_roles'];
    foreach ($tables as $t) {
        $res = $conn->query("SHOW TABLES LIKE '$t'");
        if ($res && $res->num_rows > 0) {
            $countRes = $conn->query("SELECT COUNT(*) c FROM `$t`");
            $c = $countRes ? $countRes->fetch_assoc()['c'] : 0;
            echo "Table `$t`: EXISTS with {$c} rows\n";
        } else {
            echo "Table `$t`: MISSING\n";
        }
    }

    echo "\n=== PRODUCTION CHATBOT KNOWLEDGE RECORDS ===\n";
    $res = $conn->query("SELECT knowledge_id, topic, category, status, approval_status FROM chatbot_knowledge ORDER BY knowledge_id");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            echo "#{$r['knowledge_id']} | [{$r['category']}] {$r['topic']} | Status: {$r['status']} | Approval: " . ($r['approval_status'] ?? 'N/A') . "\n";
        }
    } else {
        echo "Query failed: " . $conn->error . "\n";
    }

    echo "\n=== TESTING AI ASSISTANT SERVICE ON PRODUCTION ===\n";
    require_once __DIR__ . '/../services/AiAssistantService.php';
    try {
        $svc = new AiAssistantService($conn);
        $testCaps = ['staff' => true, 'admin' => true, 'records' => true, 'reports' => true, 'feedback' => true];
        $testResp = $svc->respond(1, $testCaps, 'What are the requirements for baptism?', 'chat');
        echo "Test Query Result:\n";
        print_r($testResp);
    } catch (Throwable $e) {
        echo "AI Assistant Service Error: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    }
}

echo "=== PRODUCTION USERS ===\n";
$res = $conn->query("SELECT id, fullname, phone_number, email, role, status FROM users ORDER BY id");
while ($r = $res->fetch_assoc()) {
    echo "User #" . $r['id'] . ": " . $r['fullname'] . " | Phone: [" . $r['phone_number'] . "] | Email: [" . $r['email'] . "] | Role: [" . $r['role'] . "] | Status: " . $r['status'] . "\n";
}

echo "\n=== PRODUCTION USER_AUTH_IDENTIFIERS ===\n";
$res = $conn->query("SELECT user_id, identifier_type, normalized_value, verified_at FROM user_auth_identifiers ORDER BY user_id");
while ($r = $res->fetch_assoc()) {
    echo "User #" . $r['user_id'] . " | " . $r['identifier_type'] . ": [" . $r['normalized_value'] . "] | Verified: " . ($r['verified_at'] ?: "NO") . "\n";
}

echo "\n=== PRODUCTION RECENT SMS LOGS ===\n";
$res = $conn->query("SELECT * FROM sms_notification_logs ORDER BY log_id DESC LIMIT 5");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        echo "#" . $r['log_id'] . " | Phone: " . $r['phone_number'] . " | Status: " . $r['delivery_status'] . " | Err: " . $r['error_message'] . " | Time: " . $r['created_at'] . "\n";
    }
}
