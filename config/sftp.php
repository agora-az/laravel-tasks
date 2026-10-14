<?php

return [
    'bank' => [
        'host' => env('BANK_SFTP_HOST'),
        'port' => env('BANK_SFTP_PORT'),
        'username' => env('BANK_SFTP_USERNAME'),
        'password' => env('BANK_SFTP_PASSWORD'),
        'remote_path' => env('BANK_SFTP_REMOTE_PATH'),
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
];