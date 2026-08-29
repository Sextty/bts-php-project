<?php

return [
    'default_days' => (int) env('ANALYTICS_DEFAULT_DAYS', 365),
    'max_days' => (int) env('ANALYTICS_MAX_DAYS', 730),
    'export_max_rows' => (int) env('ANALYTICS_EXPORT_MAX_ROWS', 2500),
];
