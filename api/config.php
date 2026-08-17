<?php

define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');

define(
    'GEMINI_API_URL',
    'https://generativelanguage.googleapis.com/v1beta/models/' .
    rawurlencode(getenv('GEMINI_MODEL') ?: 'gemini-3.6-flash') .
    ':generateContent'
);
