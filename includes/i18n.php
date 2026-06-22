<?php
/**
 * Lightweight Tugon translation helper.
 * Supports English and Filipino across shared UI, navigation, and chatbot text.
 */

function tugonSupportedLanguages() {
    return [
        'en' => 'English',
        'fil' => 'Filipino'
    ];
}

// Tugon Normalize Language Function - Documents this helper's role in the parish management workflow.
function tugonNormalizeLanguage($language) {
    $language = strtolower(trim((string) $language));
    return array_key_exists($language, tugonSupportedLanguages()) ? $language : 'en';
}

// Tugon Current Language Function - Documents this helper's role in the parish management workflow.
function tugonCurrentLanguage() {
    if (isset($_GET['lang'])) {
        $language = tugonNormalizeLanguage($_GET['lang']);
        $_SESSION['tugon_lang'] = $language;
        if (!headers_sent()) {
            setcookie('tugon_lang', $language, time() + (86400 * 180), BASE_URL, '', false, true);
        }
        return $language;
    }

    if (isset($_SESSION['tugon_lang'])) {
        return tugonNormalizeLanguage($_SESSION['tugon_lang']);
    }

    if (isset($_COOKIE['tugon_lang'])) {
        return tugonNormalizeLanguage($_COOKIE['tugon_lang']);
    }

    return 'en';
}

// Tugon Language URL Function - Documents this helper's role in the parish management workflow.
function tugonLanguageUrl($language) {
    $language = tugonNormalizeLanguage($language);
    $requestUri = $_SERVER['REQUEST_URI'] ?? BASE_URL;
    $parts = parse_url($requestUri);
    $path = $parts['path'] ?? BASE_URL;
    $query = [];

    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $query['lang'] = $language;
    $queryString = http_build_query($query);
    return $path . ($queryString ? '?' . $queryString : '');
}

// Tugon Translations Function - Documents this helper's role in the parish management workflow.
function tugonTranslations() {
    return [
        'en' => [
            'lang.label' => 'Language',
            'lang.english' => 'English',
            'lang.filipino' => 'Filipino',
            'nav.main_menu' => 'Main Menu',
            'nav.communication' => 'Communication',
            'nav.account' => 'Account',
            'nav.dashboard' => 'Dashboard',
            'nav.my_requests' => 'My Requests',
            'nav.certificates' => 'Certificates',
            'nav.blessings' => 'Blessings',
            'nav.sacramental_services' => 'Sacramental Services',
            'nav.schedule' => 'Schedule',
            'nav.announcements' => 'Announcements',
            'nav.notifications' => 'Notifications',
            'nav.ai_assistant' => 'AI Assistant',
            'nav.profile_settings' => 'Profile Settings',
            'nav.logout' => 'Logout',
            'nav.request_management' => 'Request Management',
            'nav.requests' => 'Requests',
            'nav.sacramental_records' => 'Sacramental Records',
            'nav.operations' => 'Operations',
            'nav.generate_certificates' => 'Generate Certificates',
            'nav.schedule_calendar' => 'Schedule Calendar',
            'nav.reservations' => 'Reservations',
            'nav.analytics_report' => 'Analytics Report',
            'nav.administration' => 'Administration',
            'nav.parishioners' => 'Parishioners',
            'nav.verify_registrations' => 'Verify Registrations',
            'nav.audit_logs' => 'Audit Logs',
            'nav.archives' => 'Archives',
            'nav.settings' => 'Settings',
            'search.requests' => 'Search requests...',
            'footer.tagline' => 'Serving with faith and care',
            'footer.rights' => 'All rights reserved.',
            'footer.powered' => 'Powered by AI for efficient parish services',
            'chatbot.title' => 'TUGON AI Parish Assistant',
            'chatbot.subtitle' => 'Your Digital Parish Companion',
            'chatbot.trigger_label' => 'AI Parish Assistant',
            'chatbot.close_label' => 'Close AI assistant',
            'chatbot.empty' => 'Ask anything about Tugon or parish services.',
            'chatbot.placeholder' => 'Ask about certificates, status, schedule...',
            'chatbot.send' => 'Send',
            'chatbot.you' => 'You',
            'chatbot.typing' => 'TUGON AI is typing',
            'chatbot.unable' => 'Unable to answer right now.',
            'chatbot.no_answer' => 'I could not find a Tugon answer for that question.',
            'chatbot.endpoint_error' => 'Unable to reach the chatbot endpoint. Please try again.',
            'chatbot.login_required' => 'Please log in to use the AI Parish Assistant.',
            'chatbot.outside_title' => 'Parish services focus',
            'chatbot.out_of_context' => 'I specialize in parish services and church-related concerns. I may not have information about that topic, but I would be happy to help you with certificates, sacraments, schedules, announcements, and other parish services.',
            'chatbot.office_title' => 'Parish office schedule',
            'chatbot.office_answer' => 'The parish office is open Monday to Saturday, 8:00 AM to 5:00 PM, with lunch break from 12:00 PM to 1:00 PM. The office is closed on Sunday.',
            'chatbot.mass_title' => 'Mass schedule',
            'chatbot.mass_default' => 'The stored Mass schedule is Sunday Mass at 6:00 AM, 8:00 AM, and 5:00 PM; weekday Mass is Monday to Friday at 5:30 PM.',
            'chatbot.status_title' => 'Request status',
            'chatbot.status_help' => 'Open My Requests to view your request status. Pending means waiting for review, processing means staff are checking it, approved means accepted, completed means finished, and rejected means it was not approved or needs correction.',
            'chatbot.no_request' => 'No request record was found for reference {reference} in the records available to your account.',
            'chatbot.request_is' => 'Request {reference}{owner} is currently {status}.',
            'chatbot.owner_for' => ' for {name}',
            'chatbot.requirements_title' => 'Request requirements',
            'chatbot.requirements_answer' => 'For certificate and sacramental requests, submit accurate details and upload a clear requirements file such as a valid ID, supporting parish document, or required record scan before submitting.',
            'chatbot.certificates_title' => 'Certificate requests',
            'chatbot.certificates_answer' => 'To request a certificate, go to Certificates, select the certificate type, enter the needed details, attach your requirements file, and submit the request for parish review.',
            'chatbot.blessing_title' => 'Blessing requests',
            'chatbot.blessing_answer' => 'To request a blessing, go to Blessings, choose the blessing type, enter the preferred date, time, location, add any details, attach requirements if available, and submit.',
            'chatbot.service_title' => 'Sacramental service requests',
            'chatbot.service_answer' => 'To request a sacramental service, go to Sacramental Services, select the service, enter the date, time, location, details, attach requirements if available, and submit.',
            'chatbot.announcements_title' => 'Announcements',
            'chatbot.announcements_answer' => 'Open Announcements to view active parish announcements, schedules, events, and attached files posted by the parish office.',
            'chatbot.payment_title' => 'Payment status',
            'chatbot.payment_answer' => 'No payment status record is stored for your account in Tugon. Please contact the parish office for payment confirmation.',
            'chatbot.registration_title' => 'Account registration',
            'chatbot.registration_answer' => 'To register, complete your parishioner details, pass live identity verification, scan your valid ID, and wait for parish administrator approval before logging in.',
            'chatbot.schedule_title' => 'Parish schedules',
            'chatbot.schedule_answer' => 'Open Schedule to view approved public parish schedules, events, Masses, and sacramental service dates.',
            'chatbot.login_title' => 'Login help',
            'chatbot.login_answer' => 'Use your registered Gmail address and password to log in. For password recovery, open Forgot Password or contact the parish office for account verification.',
            'chatbot.search_found' => 'I found related Tugon records. Open the matching result below.',
            'chatbot.activity_title' => 'Request activity',
            'chatbot.analytics_limited' => 'Open My Requests to view your request status and activity. Analytics summaries are limited to parish administration.'
        ],
        'fil' => [
            'lang.label' => 'Wika',
            'lang.english' => 'Ingles',
            'lang.filipino' => 'Filipino',
            'nav.main_menu' => 'Pangunahing Menu',
            'nav.communication' => 'Komunikasyon',
            'nav.account' => 'Account',
            'nav.dashboard' => 'Dashboard',
            'nav.my_requests' => 'Aking Mga Request',
            'nav.certificates' => 'Mga Sertipiko',
            'nav.blessings' => 'Mga Pagpapabendisyon',
            'nav.sacramental_services' => 'Serbisyong Sakramental',
            'nav.schedule' => 'Iskedyul',
            'nav.announcements' => 'Mga Anunsyo',
            'nav.notifications' => 'Mga Abiso',
            'nav.ai_assistant' => 'AI Assistant',
            'nav.profile_settings' => 'Ayos ng Profile',
            'nav.logout' => 'Mag-logout',
            'nav.request_management' => 'Pamamahala ng Request',
            'nav.requests' => 'Mga Request',
            'nav.sacramental_records' => 'Mga Rekord Sakramental',
            'nav.operations' => 'Operasyon',
            'nav.generate_certificates' => 'Gumawa ng Sertipiko',
            'nav.schedule_calendar' => 'Kalendaryo ng Iskedyul',
            'nav.reservations' => 'Mga Reserbasyon',
            'nav.analytics_report' => 'Ulat at Analytics',
            'nav.administration' => 'Administrasyon',
            'nav.parishioners' => 'Mga Parokyano',
            'nav.verify_registrations' => 'Suriin ang Rehistrasyon',
            'nav.audit_logs' => 'Audit Logs',
            'nav.archives' => 'Mga Archive',
            'nav.settings' => 'Settings',
            'search.requests' => 'Maghanap ng request...',
            'footer.tagline' => 'Naglilingkod nang may pananampalataya at malasakit',
            'footer.rights' => 'Lahat ng karapatan ay nakalaan.',
            'footer.powered' => 'Pinapagana ng AI para sa mas maayos na serbisyo ng parokya',
            'chatbot.title' => 'TUGON AI Parish Assistant',
            'chatbot.subtitle' => 'Iyong Digital Parish Companion',
            'chatbot.trigger_label' => 'AI Parish Assistant',
            'chatbot.close_label' => 'Isara ang AI assistant',
            'chatbot.empty' => 'Magtanong tungkol sa Tugon o mga serbisyo ng parokya.',
            'chatbot.placeholder' => 'Magtanong tungkol sa sertipiko, status, iskedyul...',
            'chatbot.send' => 'Ipadala',
            'chatbot.you' => 'Ikaw',
            'chatbot.typing' => 'Nagta-type ang TUGON AI',
            'chatbot.unable' => 'Hindi makasagot sa ngayon.',
            'chatbot.no_answer' => 'Wala akong mahanap na Tugon answer para sa tanong na iyon.',
            'chatbot.endpoint_error' => 'Hindi maabot ang chatbot endpoint. Subukan muli.',
            'chatbot.login_required' => 'Mag-log in muna para magamit ang AI Parish Assistant.',
            'chatbot.outside_title' => 'Saklaw ng serbisyong parokya',
            'chatbot.out_of_context' => 'Nakatuon ako sa mga serbisyo ng parokya at mga usaping may kaugnayan sa simbahan. Maaaring wala akong impormasyon tungkol sa paksang iyon, pero handa akong tumulong sa certificates, sacraments, schedules, announcements, at iba pang parish services.',
            'chatbot.office_title' => 'Iskedyul ng opisina ng parokya',
            'chatbot.office_answer' => 'Bukas ang opisina ng parokya mula Lunes hanggang Sabado, 8:00 AM hanggang 5:00 PM, may lunch break mula 12:00 PM hanggang 1:00 PM. Sarado ang opisina tuwing Linggo.',
            'chatbot.mass_title' => 'Iskedyul ng Misa',
            'chatbot.mass_default' => 'Ang naka-store na iskedyul ng Misa ay Linggo sa 6:00 AM, 8:00 AM, at 5:00 PM; weekday Mass ay Lunes hanggang Biyernes sa 5:30 PM.',
            'chatbot.status_title' => 'Status ng request',
            'chatbot.status_help' => 'Buksan ang Aking Mga Request para makita ang status. Ang Pending ay naghihintay ng review, Processing ay sinusuri ng staff, Approved ay tinanggap, Completed ay tapos na, at Rejected ay hindi naaprubahan o kailangan ng correction.',
            'chatbot.no_request' => 'Walang nakitang request record para sa reference {reference} sa mga rekord na available sa iyong account.',
            'chatbot.request_is' => 'Ang request {reference}{owner} ay kasalukuyang {status}.',
            'chatbot.owner_for' => ' para kay {name}',
            'chatbot.requirements_title' => 'Mga requirement ng request',
            'chatbot.requirements_answer' => 'Para sa certificate at sacramental requests, maglagay ng tamang detalye at mag-upload ng malinaw na requirements file tulad ng valid ID, supporting parish document, o required record scan bago magsumite.',
            'chatbot.certificates_title' => 'Mga request ng sertipiko',
            'chatbot.certificates_answer' => 'Para humiling ng sertipiko, pumunta sa Mga Sertipiko, piliin ang uri ng sertipiko, ilagay ang kailangang detalye, i-attach ang requirements file, at isumite para sa review ng parokya.',
            'chatbot.blessing_title' => 'Mga request ng pagpapabendisyon',
            'chatbot.blessing_answer' => 'Para humiling ng pagpapabendisyon, pumunta sa Mga Pagpapabendisyon, piliin ang uri, ilagay ang gustong petsa, oras, lokasyon, dagdag na detalye, mag-attach ng requirements kung mayroon, at isumite.',
            'chatbot.service_title' => 'Mga request ng serbisyong sakramental',
            'chatbot.service_answer' => 'Para humiling ng serbisyong sakramental, pumunta sa Serbisyong Sakramental, piliin ang serbisyo, ilagay ang petsa, oras, lokasyon, detalye, mag-attach ng requirements kung mayroon, at isumite.',
            'chatbot.announcements_title' => 'Mga Anunsyo',
            'chatbot.announcements_answer' => 'Buksan ang Mga Anunsyo para makita ang aktibong parish announcements, schedules, events, at attached files mula sa parish office.',
            'chatbot.payment_title' => 'Status ng bayad',
            'chatbot.payment_answer' => 'Walang naka-store na payment status record para sa iyong account sa Tugon. Makipag-ugnayan sa parish office para sa confirmation ng bayad.',
            'chatbot.registration_title' => 'Rehistrasyon ng account',
            'chatbot.registration_answer' => 'Para mag-register, kumpletuhin ang parishioner details, pumasa sa live identity verification, i-scan ang valid ID, at maghintay ng approval ng parish administrator bago mag-login.',
            'chatbot.schedule_title' => 'Mga iskedyul ng parokya',
            'chatbot.schedule_answer' => 'Buksan ang Iskedyul para makita ang approved public parish schedules, events, Masses, at sacramental service dates.',
            'chatbot.login_title' => 'Tulong sa login',
            'chatbot.login_answer' => 'Gamitin ang registered Gmail address at password para mag-login. Para sa password recovery, buksan ang Forgot Password o makipag-ugnayan sa parish office para sa account verification.',
            'chatbot.search_found' => 'May nakita akong kaugnay na Tugon records. Buksan ang matching result sa ibaba.',
            'chatbot.activity_title' => 'Aktibidad ng request',
            'chatbot.analytics_limited' => 'Buksan ang Aking Mga Request para makita ang status at aktibidad ng iyong request. Ang analytics summaries ay para lamang sa parish administration.'
        ]
    ];
}

// Translation helper `t` - Returns the translated string for a key.
// - `$key`: translation key (e.g., 'nav.dashboard')
// - `$fallback`: optional fallback string if key is missing
function t($key, $fallback = '') {
    $language = tugonCurrentLanguage();
    $translations = tugonTranslations();

    if (isset($translations[$language][$key])) {
        return $translations[$language][$key];
    }

    if (isset($translations['en'][$key])) {
        return $translations['en'][$key];
    }

    return $fallback !== '' ? $fallback : $key;
}

// Translation helper `tr` - Returns a translated string and applies replacements.
// - `$key`: translation key
// - `$fallback`: optional fallback string
// - `$replace`: associative array of tokens to replace in the translated text
function tr($key, $fallback = '', $replace = []) {
    $text = t($key, $fallback);
    foreach ($replace as $token => $value) {
        $text = str_replace('{' . $token . '}', (string) $value, $text);
    }
    return $text;
}
