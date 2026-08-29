<?php

return [
    'name' => 'Sync',
    'push_batch_max' => (int) env('SYNC_PUSH_BATCH_MAX', 50),
    'pull_limit' => (int) env('SYNC_PULL_LIMIT', 100),
];
