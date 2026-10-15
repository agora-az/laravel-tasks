<?php

return [
    'bank' => [
        'host' => env('BANK_SFTP_HOST'),
        'port' => env('BANK_SFTP_PORT'),
        'username' => env('BANK_SFTP_USERNAME'),
        'password' => env('BANK_SFTP_PASSWORD'),
        'remote_path' => env('BANK_SFTP_REMOTE_PATH'),
        'local_path' => env('BANK_SFTP_LOCAL_PATH', 'resources/data/cibc'),
        'file_pattern' => env('BANK_SFTP_FILE_PATTERN', '*.xml'),
        'dry_run' => filter_var(env('BANK_SFTP_DRY_RUN', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'settlement' => [
        'host' => env('SETTLEMENT_SFTP_HOST'),
        'port' => env('SETTLEMENT_SFTP_PORT'),
        'username' => env('SETTLEMENT_SFTP_USERNAME'),
        'password' => env('SETTLEMENT_SFTP_PASSWORD'),
        'remote_path' => env('SETTLEMENT_SFTP_REMOTE_PATH'),
        'local_path' => env('SETTLEMENT_SFTP_LOCAL_PATH'),
        'file_pattern' => env('SETTLEMENT_SFTP_FILE_PATTERN'),
        'dry_run' => filter_var(env('SETTLEMENT_SFTP_DRY_RUN', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'asm' => [
        'host' => env('ASM_SFTP_HOST'),
        'port' => env('ASM_SFTP_PORT'),
        'username' => env('ASM_SFTP_USERNAME'),
        'password' => env('ASM_SFTP_PASSWORD'),
        'remote_path' => env('ASM_SFTP_REMOTE_PATH', '/ASM_Files'),
        'local_path' => env('ASM_SFTP_LOCAL_PATH', 'resources/data/asm'),
        'file_pattern' => env('ASM_SFTP_FILE_PATTERN', 'asm_AGRA_*.xlsx'),
        'dry_run' => filter_var(env('ASM_SFTP_DRY_RUN', false), FILTER_VALIDATE_BOOLEAN),
    ],
];
