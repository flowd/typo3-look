<?php

use Flowd\Typo3Look\Controller\PreviewTokenController;

return [
    // Exchanges a signed preview descriptor for a short-lived preview token (authenticated backend user).
    // The core registers AJAX routes with the prefix "ajax_", so the identifier is "ajax_look_preview_token".
    'look_preview_token' => [
        'path' => '/look/preview/token',
        'methods' => ['POST'],
        'target' => PreviewTokenController::class . '::issue',
    ],
];
