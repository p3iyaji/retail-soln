<?php

return [
    'name' => 'Sync',
    'push_batch_max' => (int) env('SYNC_PUSH_BATCH_MAX', 50),
];
