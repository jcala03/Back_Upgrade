<?php

return [
    // The existing Hostinger bridge runs Laravel in the sibling domain. No Node
    // daemon or new credentials are required. Tests override this directory.
    'frontend_path' => env('PUBLIC_CATALOG_FRONTEND_PATH', base_path('../../upgradecolombia.com/public_html')),
    'site_url' => 'https://upgradecolombia.com',
];
