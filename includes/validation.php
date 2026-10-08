<?php

/** Canonical input normalization and validation helpers. */
function sanitize($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function isValidPhilippineMobile($phone) {
    $digits = preg_replace('/\D/', '', (string) $phone);
    return (bool) preg_match('/^(09\d{9}|639\d{9}|9\d{9})$/', $digits);
}

function normalizePhilippineMobileForStorage($phone) {
    $digits = preg_replace('/\D/', '', (string) $phone);
    if (preg_match('/^09\d{9}$/', $digits)) {
        return $digits;
    }
    if (preg_match('/^639\d{9}$/', $digits)) {
        return '0' . substr($digits, 2);
    }
    if (preg_match('/^9\d{9}$/', $digits)) {
        return '0' . $digits;
    }
    return trim((string) $phone);
}

function normalizePhilippineMobileForSms($phone) {
    $digits = preg_replace('/\D/', '', (string) $phone);
    if (preg_match('/^09\d{9}$/', $digits)) {
        return '+63' . substr($digits, 1);
    }
    if (preg_match('/^639\d{9}$/', $digits)) {
        return '+' . $digits;
    }
    if (preg_match('/^9\d{9}$/', $digits)) {
        return '+63' . $digits;
    }
    $trimmed = trim((string) $phone);
    if (str_starts_with($trimmed, '+')) {
        return '+' . $digits;
    }
    return $trimmed;
}

function isValidPassword($password) {
    $password = (string) $password;
    $minimum = defined('PASSWORD_MIN_LENGTH') ? (int) PASSWORD_MIN_LENGTH : 8;
    if (strlen($password) < $minimum || preg_match('/^[A-Za-z\d@$!%*?&#^()_+\-=.]+$/', $password) !== 1) {
        return false;
    }
    if (defined('PASSWORD_REQUIRE_UPPERCASE') && PASSWORD_REQUIRE_UPPERCASE && preg_match('/[A-Z]/', $password) !== 1) {
        return false;
    }
    if (preg_match('/[a-z]/', $password) !== 1) {
        return false;
    }
    if (defined('PASSWORD_REQUIRE_NUMBERS') && PASSWORD_REQUIRE_NUMBERS && preg_match('/\d/', $password) !== 1) {
        return false;
    }
    if (defined('PASSWORD_REQUIRE_SPECIAL_CHARS') && PASSWORD_REQUIRE_SPECIAL_CHARS && preg_match('/[@$!%*?&#^()_+\-=.]/', $password) !== 1) {
        return false;
    }
    return true;
}

function passwordRequirementsMessage(): string {
    $minimum = defined('PASSWORD_MIN_LENGTH') ? (int) PASSWORD_MIN_LENGTH : 8;
    return "Password must be at least {$minimum} characters and include uppercase, lowercase, a number, and a special character.";
}
