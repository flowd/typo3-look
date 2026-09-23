<?php

use Flowd\Typo3Look\Controller\PreviewController;

return [
    // The isolated preview request. "access: public" skips the CSRF route token; the request has no
    // backend session either, PreviewRequestHandler answers it before the authentication runs.
    'look_preview' => [
        'path' => '/look/preview',
        'access' => 'public',
        'methods' => ['GET'],
        'target' => PreviewController::class . '::render',
    ],
];
