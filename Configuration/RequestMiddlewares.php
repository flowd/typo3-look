<?php

use Flowd\Typo3Look\Middleware\PreviewRequestHandler;

return [
    'backend' => [
        'flowd/look/preview-request' => [
            'target' => PreviewRequestHandler::class,
            'after' => [
                'typo3/cms-backend/backend-routing',
            ],
            'before' => [
                'typo3/cms-backend/authentication',
            ],
        ],
    ],
];
