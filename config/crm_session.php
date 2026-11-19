<?php

return [
    // User interaction only. Polling and ordinary HTTP requests never renew this clock.
    'idle_minutes' => max(1, min(1440, (int) env('CRM_SESSION_IDLE_MINUTES', 30))),
];
