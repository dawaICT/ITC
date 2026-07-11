<?php
/**
 * Shared renderer for a portal-hosted live room (Jitsi IFrame API embed).
 *
 * Both the student entry (students/elearning/join_meeting.php, after the secure token is
 * validated) and the lecturer/admin entry (admin/elearning/room.php, after ownership is
 * verified) call this. The meeting room is hosted on the configured engine domain
 * (config/elearning.php 'live_meeting'); the room name is generated and owned by the
 * portal — no external provider link is ever exposed to the user.
 *
 * @param array{
 *   domain:string, room:string, displayName:string, isModerator:bool,
 *   topic?:string, courseCode?:string, backUrl?:string, jwt?:?string
 * } $opts
 */
function elearningRenderJitsiRoom(array $opts): void
{
    $domain = trim((string)($opts['domain'] ?? 'meet.jit.si')) ?: 'meet.jit.si';
    $room = (string)($opts['room'] ?? '');
    $displayName = (string)($opts['displayName'] ?? 'Participant');
    $isModerator = !empty($opts['isModerator']);
    $topic = (string)($opts['topic'] ?? 'Live Session');
    $courseCode = (string)($opts['courseCode'] ?? '');
    $backUrl = (string)($opts['backUrl'] ?? '');
    $jwt = isset($opts['jwt']) ? (string)$opts['jwt'] : '';

    // Everything below is injected into JS as JSON, so values are safely encoded.
    $cfg = [
        'domain' => $domain,
        'room' => $room,
        'displayName' => $displayName,
        'isModerator' => $isModerator,
        'jwt' => $jwt !== '' ? $jwt : null,
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($topic, ENT_QUOTES, 'UTF-8'); ?> &middot; Live Room</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --wuc-purple: #6f42c1; }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; font-family: 'Inter', system-ui, Arial, sans-serif; background: #0f172a; color: #fff; }
        .room-bar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .6rem 1rem; background: #111827; border-bottom: 1px solid #1f2937; }
        .room-bar .meta { display: flex; align-items: center; gap: .6rem; min-width: 0; }
        .room-bar .badge { background: var(--wuc-purple); border-radius: 999px; padding: .2rem .7rem; font-size: .75rem; font-weight: 600; white-space: nowrap; }
        .room-bar .title { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .room-bar .course { color: #94a3b8; font-size: .85rem; }
        .room-bar a.leave { color: #fff; text-decoration: none; background: #dc2626; padding: .4rem .9rem; border-radius: 8px; font-size: .85rem; white-space: nowrap; }
        .room-bar a.leave:hover { background: #b91c1c; }
        #jitsi-root { height: calc(100% - 52px); width: 100%; }
        .room-fallback { padding: 2rem; text-align: center; }
        .room-fallback a { color: #93c5fd; }
    </style>
</head>
<body>
    <div class="room-bar">
        <div class="meta">
            <span class="badge"><i class="fas fa-broadcast-tower"></i> <?php echo $isModerator ? 'Host' : 'Live'; ?></span>
            <span class="title"><?php echo htmlspecialchars($topic, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($courseCode !== ''): ?><span class="course"><?php echo htmlspecialchars($courseCode, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
        </div>
        <?php if ($backUrl !== ''): ?>
            <a class="leave" href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-arrow-left"></i> Leave</a>
        <?php endif; ?>
    </div>
    <div id="jitsi-root">
        <div class="room-fallback" id="room-fallback">
            <p><i class="fas fa-spinner fa-spin"></i> Connecting to the live room&hellip;</p>
        </div>
    </div>

    <script src="https://<?php echo htmlspecialchars($domain, ENT_QUOTES, 'UTF-8'); ?>/external_api.js"></script>
    <script>
    (function () {
        var cfg = <?php echo json_encode($cfg, JSON_UNESCAPED_SLASHES); ?>;
        var backUrl = <?php echo json_encode($backUrl, JSON_UNESCAPED_SLASHES); ?>;
        var root = document.getElementById('jitsi-root');
        var fallback = document.getElementById('room-fallback');

        if (typeof JitsiMeetExternalAPI === 'undefined') {
            fallback.innerHTML = 'The live room engine could not be loaded. ' +
                (backUrl ? '<a href="' + backUrl + '">Go back</a>.' : 'Please try again later.');
            return;
        }

        var options = {
            roomName: cfg.room,
            parentNode: root,
            width: '100%',
            height: '100%',
            userInfo: { displayName: cfg.displayName },
            configOverwrite: {
                prejoinPageEnabled: true,
                disableDeepLinking: true,
                startWithAudioMuted: !cfg.isModerator,
                startWithVideoMuted: !cfg.isModerator
            },
            interfaceConfigOverwrite: {
                MOBILE_APP_PROMO: false,
                SHOW_JITSI_WATERMARK: false,
                DEFAULT_BACKGROUND: '#0f172a'
            }
        };
        if (cfg.jwt) { options.jwt = cfg.jwt; }

        if (fallback) { fallback.remove(); }
        var api = new JitsiMeetExternalAPI(cfg.domain, options);
        api.addEventListener('readyToClose', function () {
            if (backUrl) { window.location.href = backUrl; }
        });
    })();
    </script>
</body>
</html>
    <?php
}
