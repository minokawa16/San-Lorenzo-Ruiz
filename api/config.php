<?php

define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');

define(
    'GEMINI_API_URL',
    'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'
);
