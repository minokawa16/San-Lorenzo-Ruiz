<?php
$htmlRegister = file_get_contents('http://127.0.0.1/ParishSystem/auth/register.php');
$htmlLogin = file_get_contents('http://127.0.0.1/ParishSystem/auth/login.php');
$htmlForgot = file_get_contents('http://127.0.0.1/ParishSystem/auth/forgot-password.php');

echo "=== FORM ICON & PADDING STANDARDIZATION AUDIT ===\n\n";

// Check register.php CSS rules
$cssMobile = file_get_contents(__DIR__ . '/../assets/css/auth-mobile.css');
$cssPds = file_get_contents(__DIR__ . '/../assets/css/parish-design-system.css');
$cssStyle = file_get_contents(__DIR__ . '/../assets/css/style.css');

function checkPattern($name, $content, $pattern) {
    $matched = preg_match($pattern, $content);
    echo ($matched ? "[PASS]" : "[FAIL]") . " {$name}\n";
}

checkPattern("auth-mobile.css has 44px left padding", $cssMobile, '/padding:\s*10px\s+14px\s+10px\s+44px\s*!important/i');
checkPattern("auth-mobile.css has absolute field-icon", $cssMobile, '/\.auth-register-card\s+\.input-wrap\s+\.field-icon[^}]+position:\s*absolute\s*!important/i');
checkPattern("auth-mobile.css has verification-option flex layout", $cssMobile, '/\.auth-register-card\s+\.verification-option[^}]+display:\s*flex\s*!important/i');
checkPattern("parish-design-system.css has 44px leading icon padding", $cssPds, '/padding-left:\s*44px\s*!important/i');
checkPattern("style.css has 44px leading icon padding", $cssStyle, '/padding-left:\s*44px\s*!important/i');

echo "\nRegistration Form HTML verification:\n";
echo "Has first_name field-icon: " . (strpos($htmlRegister, 'fas fa-user field-icon') !== false ? 'YES' : 'NO') . "\n";
echo "Has chapel_district select: " . (strpos($htmlRegister, 'id="chapel_district"') !== false ? 'YES' : 'NO') . "\n";
echo "Has password-toggle: " . (strpos($htmlRegister, 'data-toggle-password="password"') !== false ? 'YES' : 'NO') . "\n";
echo "Has verification-option radio: " . (strpos($htmlRegister, 'class="verification-option"') !== false ? 'YES' : 'NO') . "\n";
