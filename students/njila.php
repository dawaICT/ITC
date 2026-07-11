<?php
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Njila AI - ITC</title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <style>
        /* Centred reading column nested INSIDE .content-wrapper so it does not
           fight the shared sidebar offset (.content-wrapper gets margin-left:
           var(--sidebar-width) from student-unified.css, which loads after this
           inline block and would otherwise override margin:0 auto). */
        .njila-page {
            max-width: 1280px;
            margin-inline: auto;
        }
        .njila-panel,
        .njila-card {
            background: #fff;
            border: 1px solid #e4e7ec;
            border-radius: 8px;
            box-shadow: 0 10px 24px rgba(17, 24, 39, .06);
        }
        .njila-panel {
            padding: 1.5rem;
        }
        .njila-title {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
            color: #101828;
        }
        .njila-subtitle {
            max-width: 720px;
            color: #667085;
        }
        .context-list {
            display: grid;
            gap: .75rem;
        }
        .context-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            border-bottom: 1px solid #eef2f6;
            padding-bottom: .65rem;
        }
        .context-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }
        .context-row span {
            color: #667085;
        }
        .context-row strong {
            text-align: right;
            color: #101828;
        }
        .course-chip {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            border: 1px solid #d0d5dd;
            border-radius: 999px;
            padding: .35rem .65rem;
            margin: .2rem;
            background: #f8fafc;
            color: #344054;
            font-size: .88rem;
            font-weight: 600;
        }

        /* Embedded Njila.ai app */
        .njila-embed-card {
            padding: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .njila-embed-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .75rem;
            padding: .6rem 1rem;
            border-bottom: 1px solid #e4e7ec;
            background: #f8fafc;
        }
        .njila-embed-wrap {
            position: relative;
            flex: 1 1 auto;
            min-height: 660px;
        }
        .njila-embed-frame {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            border: 0;
        }
        .njila-embed-loading {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #fff;
            z-index: 2;
            transition: opacity .25s ease;
            text-align: center;
            padding: 1.5rem;
        }
        .njila-embed-loading.is-hidden {
            opacity: 0;
            pointer-events: none;
        }
        .njila-embed-footnote {
            padding: .55rem 1rem;
            border-top: 1px solid #eef2f6;
        }
        @media (max-width: 991.98px) {
            .njila-embed-wrap {
                min-height: 540px;
            }
        }
        @media (max-width: 640px) {
            .context-row {
                display: block;
            }
            .context-row strong {
                display: block;
                margin-top: .2rem;
                text-align: left;
            }
        }
    </style>
</head>
<body class="bg-light student-portal">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="content-wrapper pt-3 pb-5">
  <div class="njila-page">
    <section class="njila-panel mb-3">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div>
                <span class="text-uppercase small fw-bold text-muted">Student Services</span>
                <h1 class="njila-title">Njila AI</h1>
                <p class="njila-subtitle mb-0">Discover your skills &amp; strengths with Njila AI &mdash; loaded right here inside your student portal.</p>
            </div>
            <div class="d-flex align-items-start gap-2">
                <button type="button" class="btn btn-light border" id="reloadNjila" title="Reload Njila AI">
                    <i class="fas fa-rotate-right me-2"></i>Reload
                </button>
                <a href="https://njila.ai/#/" target="_blank" rel="noopener noreferrer" class="btn btn-primary" id="openNjila">
                    <i class="fas fa-external-link-alt me-2"></i>Open in new tab
                </a>
                <a href="index.php" class="btn btn-light border">
                    <i class="fas fa-arrow-left me-2"></i>Back
                </a>
            </div>
        </div>
    </section>

    <div class="row g-3">
        <div class="col-lg-4">
            <section class="njila-card p-4 h-100">
                <h2 class="h5 mb-3">Your Portal Context</h2>
                <div id="njilaStatus" class="alert alert-info py-2">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    Loading portal context...
                </div>
                <div class="context-list d-none" id="njilaContext">
                    <div class="context-row">
                        <span>Student</span>
                        <strong id="ctxName">-</strong>
                    </div>
                    <div class="context-row">
                        <span>Student ID</span>
                        <strong id="ctxSid">-</strong>
                    </div>
                    <div class="context-row">
                        <span>Program</span>
                        <strong id="ctxProgram">-</strong>
                    </div>
                    <div class="context-row">
                        <span>Current period</span>
                        <strong id="ctxPeriod">-</strong>
                    </div>
                </div>

                <hr class="my-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h3 class="h6 mb-0">Your Courses</h3>
                    <span class="badge bg-primary" id="ctxCourseCount">0</span>
                </div>
                <div id="ctxCourses" class="text-muted small">No courses loaded yet.</div>

                <p class="text-muted small mt-3 mb-0">
                    Njila AI is an external skills-discovery tool. Your portal details above are shown for your reference only and are not transmitted to Njila.
                </p>
            </section>
        </div>
        <div class="col-lg-8">
            <section class="njila-card njila-embed-card h-100">
                <div class="njila-embed-bar">
                    <span class="d-inline-flex align-items-center gap-2">
                        <i class="fas fa-robot text-primary"></i>
                        <strong>Njila AI</strong>
                        <span class="text-muted small">njila.ai</span>
                    </span>
                    <span class="badge bg-light text-muted border" id="njilaFrameState">Loading&hellip;</span>
                </div>
                <div class="njila-embed-wrap">
                    <div class="njila-embed-loading" id="njilaFrameLoading">
                        <span class="spinner-border text-primary" role="status" aria-hidden="true"></span>
                        <p class="mt-3 mb-0 text-muted">Loading Njila AI&hellip;</p>
                    </div>
                    <iframe id="njilaFrame"
                            class="njila-embed-frame"
                            src="https://njila.ai/#/"
                            title="Njila AI"
                            referrerpolicy="no-referrer"
                            allow="clipboard-write"
                            sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-modals allow-downloads"></iframe>
                </div>
                <div class="njila-embed-footnote text-muted small">
                    If Njila AI does not appear above, your browser may be blocking the embed &mdash;
                    <a href="https://njila.ai/#/" target="_blank" rel="noopener noreferrer">open it in a new tab</a> instead.
                </div>
            </section>
        </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var status = document.getElementById('njilaStatus');
    var context = document.getElementById('njilaContext');
    var courseWrap = document.getElementById('ctxCourses');
    var courseCount = document.getElementById('ctxCourseCount');

    function text(id, value) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = value || '-';
        }
    }

    function periodLabel(registration, academic) {
        if (!registration || Object.keys(registration).length === 0) {
            return '';
        }
        var parts = [];
        if (registration.academic_year) parts.push(registration.academic_year);
        if (registration.year_of_study) parts.push('Year ' + registration.year_of_study);
        var periodNum = registration.semester;
        if (periodNum) {
            var shortLabel = (academic && academic.period_label_short) || 'Period';
            parts.push(shortLabel + ' ' + periodNum);
        }
        return parts.join(' / ');
    }

    // ---- Portal context panel (reference only; not sent to Njila) ----
    fetch('api_njila.php?action=context', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
    }).then(function (response) {
        return response.json().then(function (data) {
            if (!response.ok || !data.success) {
                throw new Error(data.message || data.error || 'Failed to load Njila context.');
            }
            return data;
        });
    }).then(function (data) {
        var student = data.student || {};
        var program = (data.academic && data.academic.program) || {};
        var registration = (data.academic && data.academic.registration) || {};
        var courses = Array.isArray(data.courses) ? data.courses : [];
        var shortCourses = Array.isArray(data.short_courses) ? data.short_courses : [];
        var combinedCourses = courses.concat(shortCourses);

        text('ctxName', student.display_name);
        text('ctxSid', student.id);
        text('ctxProgram', program.program_name || program.program_code || 'Short course');
        text('ctxPeriod', periodLabel(registration, data.academic) || (shortCourses.length ? 'Short course enrolment' : '-'));

        courseCount.textContent = String(combinedCourses.length);
        courseWrap.innerHTML = '';
        if (combinedCourses.length) {
            combinedCourses.forEach(function (course) {
                var chip = document.createElement('span');
                chip.className = 'course-chip';
                chip.innerHTML = '<i class="fas fa-book-open"></i> ';
                chip.appendChild(document.createTextNode((course.course_code || '') + (course.course_name ? ' - ' + course.course_name : '')));
                courseWrap.appendChild(chip);
            });
        } else {
            courseWrap.textContent = 'No current courses found for this student.';
            courseWrap.className = 'text-muted small';
        }

        status.className = 'alert alert-success py-2';
        status.innerHTML = '<i class="fas fa-circle-check me-2"></i>Portal context ready.';
        context.classList.remove('d-none');
    }).catch(function (error) {
        status.className = 'alert alert-danger py-2';
        status.textContent = error.message || 'Unable to load portal context.';
    });

    // ---- Embedded Njila.ai app ----
    var frame = document.getElementById('njilaFrame');
    var frameLoading = document.getElementById('njilaFrameLoading');
    var frameState = document.getElementById('njilaFrameState');
    var reloadBtn = document.getElementById('reloadNjila');
    var settled = false;

    function frameReady() {
        if (settled) { return; }
        settled = true;
        frameLoading.classList.add('is-hidden');
        frameState.textContent = 'Connected';
        frameState.className = 'badge bg-success-subtle text-success border';
    }

    if (frame) {
        frame.addEventListener('load', frameReady);
        // Fallback: if 'load' never fires (e.g. embed blocked), prompt the user.
        window.setTimeout(function () {
            if (settled) { return; }
            settled = true;
            frameState.textContent = 'Blocked?';
            frameState.className = 'badge bg-warning-subtle text-warning border';
            frameLoading.innerHTML =
                '<i class="fas fa-triangle-exclamation fa-2x text-warning"></i>' +
                '<p class="mt-3 mb-2 text-muted">Njila AI is taking too long to load here.</p>' +
                '<a class="btn btn-primary btn-sm" href="https://njila.ai/#/" target="_blank" rel="noopener noreferrer">' +
                '<i class="fas fa-external-link-alt me-2"></i>Open Njila AI in a new tab</a>';
        }, 15000);
    }

    if (reloadBtn && frame) {
        reloadBtn.addEventListener('click', function () {
            settled = false;
            frameState.textContent = 'Loading…';
            frameState.className = 'badge bg-light text-muted border';
            frameLoading.innerHTML =
                '<span class="spinner-border text-primary" role="status" aria-hidden="true"></span>' +
                '<p class="mt-3 mb-0 text-muted">Loading Njila AI…</p>';
            frameLoading.classList.remove('is-hidden');
            // Reassign src to force a reload (works cross-origin).
            frame.src = 'https://njila.ai/#/?t=' + Date.now();
        });
    }
});
</script>
</body>
</html>
