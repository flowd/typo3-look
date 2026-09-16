<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Look',
    'description' => '"Look" what you\'ll see on your website: content elements in the page module rendered with the real frontend templates, inside an isolated preview frame.',
    'category' => 'be',
    'version' => '1.0.0',
    'state' => 'stable',
    'author' => 'Sascha Egerer',
    'author_email' => 'sascha.egerer@flowd.de',
    'author_company' => 'Flowd GmbH',
    'license' => 'GPL-2.0-or-later',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.5.99',
            'typo3' => '13.4.0-14.99.99',
            'backend' => '13.4.0-14.99.99',
            'fluid' => '13.4.0-14.99.99',
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Flowd\\Typo3Look\\' => 'Classes/',
        ],
    ],
];
