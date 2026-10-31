<?php

return [
    // Local/testing always use "array", even if SMTP or this switch is configured.
    'real_mail_enabled' => env('COMMERCIAL_DOCUMENTS_REAL_MAIL_ENABLED', false),
    'mailer' => env('COMMERCIAL_DOCUMENTS_MAILER', 'smtp'),
    'from_address' => env('COMMERCIAL_DOCUMENTS_FROM_ADDRESS', 'ventas@upgradecolombia.com'),
    'from_name' => env('COMMERCIAL_DOCUMENTS_FROM_NAME', 'Upgrade La 79'),
];
