<?php

if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}

\call_user_func(
    static function () {
        \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Imaging\IconRegistry::class)->registerIcon(
            'tx-mfa-sms-icon',
            \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
            ['source' => 'EXT:mfa_sms/Resources/Public/Icons/Extension.svg']
        );
    }
);
