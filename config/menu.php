<?php

$prefix = 'nawasara-cctv';

return [
    [
        'label' => 'CCTV Monitoring',
        'icon' => 'lucide-cctv',
        'url' => '',
        'permission' => 'cctv.camera.view',
        'submenu' => [
            [
                'label' => 'Live View',
                'icon' => 'lucide-monitor-play',
                'url' => url($prefix.'/live'),
                'permission' => 'cctv.camera.view',
                'navigate' => true,
            ],
            [
                'label' => 'Cameras',
                'icon' => 'lucide-video',
                'url' => url($prefix.'/cameras'),
                'permission' => 'cctv.camera.view',
                'navigate' => true,
            ],
            [
                'label' => 'Recordings',
                'icon' => 'lucide-film',
                'url' => url($prefix.'/recordings'),
                'permission' => 'cctv.recording.view',
                'navigate' => true,
            ],
        ],
    ],
];
