<?php
declare(strict_types=1);

$savePath = dirname(__DIR__) . '/storage/tmp_sessions';
if (!is_dir($savePath)) {
    mkdir($savePath, 0700, true);
}

$fixtures = [
    'phase11admin' => [
        'user_id' => 4,
        'role' => 'admin',
        'fullname' => 'TUGON Parish Admin',
        'email' => 'tugonparish@gmail.com',
    ],
    'phase11user' => [
        'user_id' => 5,
        'role' => 'user',
        'fullname' => 'Responsive Test User',
        'email' => 'reymarkcavanas0@gmail.com',
    ],
];

foreach ($fixtures as $id => $identity) {
    session_save_path($savePath);
    session_name('TUGONSESSID');
    session_id($id);
    session_start();
    $_SESSION = $identity + [
        'fully_authenticated' => true,
        'session_regenerated_at' => time(),
        'last_activity' => time(),
    ];
    session_write_close();
}

echo "Responsive session fixtures created.\n";
