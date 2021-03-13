<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'SMS MFA Provider',
    'description' => 'Provides a multi-factor authentication via SMS for TYPO3 (requires external SMS provider)',
    'category' => 'be',
    'author' => 'Markus Hölzle',
    'author_email' => 'typo3@markus-hoelzle.de',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '11.1.0-11.2.99'
        ],
        'conflicts' => [],
        'suggests' => []
    ],
    'autoload' => [
        'psr-4' => [
            'DifferentTechnology\\MfaSms\\' => 'Classes/',
        ]
    ],
];
