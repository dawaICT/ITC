<?php
// Copy to config/assignment_storage.local.php and fill in the real values.
// The Google Drive folder must be shared with the service-account client_email.

return [
    'archive_mode' => 'local_archive', // local_archive | google_drive
    'local_archive_path' => 'C:\\Users\\Dawa\\wucportal-assignment-archive',
    'online_archive_url' => 'https://drive.google.com/drive/my-drive',
    'online_archive_provider' => 'Google Drive',
    'google_drive' => [
        'enabled' => true,
        'root_folder_id' => 'PASTE_GOOGLE_DRIVE_FOLDER_ID_HERE',
        'service_account_json_path' => 'C:\\Users\\Dawa\\wucportal-secrets\\wucportal-google-drive-service-account.json',
        'delete_local_after_upload' => true,
    ],
    'ai_detector' => [
        'enabled' => true,
        // Optional. Command must print JSON with score/status/report or a numeric score.
        // Example: 'python E:\\tools\\ai-detector\\detect.py {file}'
        'command' => '',
        'min_words' => 80,
    ],
];
