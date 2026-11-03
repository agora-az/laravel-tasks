<?php

return [
    'disks' => [
        'report_exports' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => true,
        ],
    ],
];
