<?php
// Local machine settings for assignment Drive archival.
// Replace the two placeholder values below with the real folder ID and JSON path.
// Do not publish this file to a shared repository or hosting package with secrets inside.

return [
    'archive_mode' => 'local_archive',
    'local_archive_path' => 'C:\\Users\\Dawa\\wucportal-assignment-archive',
    'online_archive_url' => 'https://drive.google.com/drive/my-drive',
    'online_archive_provider' => 'Google Drive',
    'google_drive' => [
        'enabled' => true,
        'root_folder_id' => '',
        'service_account_json_path' => 'C:\\Users\\Dawa\\wucportal-secrets\\wucportal-google-drive-service-account.json',
        'delete_local_after_upload' => true,
    ],
    'ai_detector' => [
        'enabled' => true,
        'command' => '',
        'min_words' => 80,
    ],
];
