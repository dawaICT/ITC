<?php
// eLearning module configuration
// Customize via environment variables or edit values below.

return [
    'storage_root' => dirname(__DIR__) . '/uploads/elearning',

    // Video captioning provider (set one enabled with credentials)
    'captions' => [
        'enabled' => false,
        'provider' => 'azure', // azure | google
        'azure' => [
            'region' => getenv('AZURE_SPEECH_REGION') ?: '',
            'key' => getenv('AZURE_SPEECH_KEY') ?: '',
        ],
        'google' => [
            'project_id' => getenv('GOOGLE_PROJECT_ID') ?: '',
            'credentials_json_path' => getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: ''
        ],
    ],

    // Plagiarism check (Turnitin). Requires an institution account and API credentials.
    'turnitin' => [
        'enabled' => false,
        'base_url' => getenv('TURNITIN_BASE_URL') ?: 'https://api.turnitin.com',
        'client_id' => getenv('TURNITIN_CLIENT_ID') ?: '',
        'client_secret' => getenv('TURNITIN_CLIENT_SECRET') ?: '',
        'tenant' => getenv('TURNITIN_TENANT') ?: '',
    ],

    // Assignment submission archival and AI-writing review.
    'assignments' => [
        'google_drive' => [
            'enabled' => (getenv('ASSIGNMENT_DRIVE_ENABLED') ?: '') === '1',
            'root_folder_id' => getenv('GOOGLE_DRIVE_ASSIGNMENTS_ROOT_FOLDER_ID') ?: '',
            'service_account_json_path' => getenv('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON') ?: (getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: ''),
            'delete_local_after_upload' => (getenv('ASSIGNMENT_DRIVE_DELETE_LOCAL') ?: '1') !== '0',
        ],
        'ai_detector' => [
            'enabled' => (getenv('AI_DETECTOR_ENABLED') ?: '1') !== '0',
            'command' => getenv('AI_DETECTOR_COMMAND') ?: '',
            'min_words' => (int)(getenv('AI_DETECTOR_MIN_WORDS') ?: 80),
        ],
    ],

    // Zoom integration. Prefer Account-level OAuth; store tokens securely (ENV/DB/KMS).
    'zoom' => [
        'enabled' => false,
        'account_id' => getenv('ZOOM_ACCOUNT_ID') ?: '',
        'client_id' => getenv('ZOOM_CLIENT_ID') ?: '',
        'client_secret' => getenv('ZOOM_CLIENT_SECRET') ?: '',
        'access_token' => getenv('ZOOM_ACCESS_TOKEN') ?: '', // optional short-lived; rotate automatically in production
        'base_url' => 'https://api.zoom.us/v2',
    ],

    // Microsoft Teams (Microsoft Graph) integration.
    'microsoft_teams' => [
        'enabled' => false,
        'tenant_id' => getenv('MS_TENANT_ID') ?: '',
        'client_id' => getenv('MS_CLIENT_ID') ?: '',
        'client_secret' => getenv('MS_CLIENT_SECRET') ?: '',
        'base_url' => 'https://graph.microsoft.com/v1.0',
    ],

    // Portal-hosted live meeting engine (system-generated rooms, no external provider).
    // Phase 1 embeds Jitsi on a configurable domain (public meet.jit.si by default; point
    // WUC_JITSI_DOMAIN at a self-hosted Jitsi server later with no code change). JWT is
    // only used by a self-hosted/JaaS server — leave disabled for the public instance.
    'live_meeting' => [
        'engine' => getenv('WUC_LIVE_ENGINE') ?: 'jitsi',
        'domain' => getenv('WUC_JITSI_DOMAIN') ?: 'meet.jit.si',
        'embed' => true,
        'jwt' => [
            'enabled' => (getenv('WUC_JITSI_JWT') ?: '') === '1',
            'app_id' => getenv('WUC_JITSI_APP_ID') ?: '',
            'secret' => getenv('WUC_JITSI_APP_SECRET') ?: '', // store in wucportal-var, never the repo
        ],
    ],

    // Compliance settings
    'compliance' => [
        'gdpr' => [
            'data_retention_days' => 365, // purge raw lms_events after this many days
        ],
        'ferpa' => [
            'restrict_directory_access' => true,
        ],
    ],
];


