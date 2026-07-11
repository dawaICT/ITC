<?php
require "includes/admin.php";
require_once dirname(__DIR__) . '/includes/csrf_guard.php';
require_once __DIR__ . '/includes/register_types.php';

$csrf_token     = wuc_ajax_csrf_token();
$register_types = wuc_register_types();

// Compact config for the client (label / scope / period word / max period).
$js_types = [];
foreach ($register_types as $key => $cfg) {
    $js_types[$key] = [
        'label'       => $cfg['label'],
        'scope'       => $cfg['scope'],
        'period_word' => $cfg['period_word'],
        'max_period'  => $cfg['max_period'],
    ];
}

// Load active programs for the program dropdown.
$programs = [];
if ($res = $db->query("SELECT program_code, program_name FROM programs WHERE is_active = 1 ORDER BY program_name")) {
    while ($row = $res->fetch_assoc()) {
        $programs[] = $row;
    }
    $res->free();
}

// Distinct course categories (optional filter). Guarded — table may have NULLs only.
$categories = [];
if ($res = $db->query("SELECT DISTINCT category FROM courses WHERE category IS NOT NULL AND category <> '' ORDER BY category")) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row['category'];
    }
    $res->free();
}

$page_title = 'Print Registers';
require 'includes/header.php';
?>

<style>
.print-registers-heading {
    display: flex;
    align-items: center;
    gap: 14px;
}
.print-registers-logo {
    width: 220px;
    max-width: 100%;
    height: auto;
    flex: 0 0 auto;
}
@media (max-width: 576px) {
    .print-registers-heading {
        align-items: flex-start;
    }
    .print-registers-logo {
        width: 150px;
    }
}
</style>

<div class="container-fluid px-4">
    <!-- Dashboard Header -->
    <div class="dashboard-header mb-4">
        <div class="row align-items-center">
            <div class="col">
                <div class="print-registers-heading">
                    <img src="/wucportal/images/itc_logo.png" alt="ITC Logo" class="print-registers-logo">
                    <div>
                        <h1 class="dashboard-title mb-1">Print Registers</h1>
                        <p class="text-muted mb-0">Generate printable semester, term, test, exam and short-course registers.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Register Generation Card -->
    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-print me-2"></i>Generate Register
                </h5>
            </div>
        </div>
        <div class="card-body">
            <form id="registerForm" method="POST" action="generate_register.php" target="_blank" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                <div class="row g-4">
                    <!-- Register Type -->
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="register_type" class="form-label">Register Type <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-list-check"></i></span>
                                <select class="form-select" name="register_type" id="register_type" required>
                                    <?php foreach ($register_types as $key => $cfg): ?>
                                        <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>">
                                            <?= htmlspecialchars($cfg['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <small id="type_hint" class="text-muted d-block mt-1"></small>
                        </div>
                    </div>

                    <!-- ============ PROGRAM-SCOPED FIELDS ============ -->
                    <div class="col-md-8 program-scope">
                        <div class="form-group">
                            <label for="program_code" class="form-label">Program <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-graduation-cap"></i></span>
                                <select class="form-select" name="program_code" id="program_code">
                                    <option value="" disabled selected>Select program</option>
                                    <?php foreach ($programs as $p): ?>
                                        <option value="<?= htmlspecialchars($p['program_code'], ENT_QUOTES) ?>">
                                            <?= htmlspecialchars($p['program_code'] . ' — ' . $p['program_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="invalid-feedback">Please choose a program.</div>
                        </div>
                    </div>

                    <div class="col-md-4 program-scope">
                        <div class="form-group">
                            <label for="year_of_study" class="form-label">Year of Study <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                                <select class="form-select" name="year_of_study" id="year_of_study">
                                    <option value="" disabled selected>Select year</option>
                                    <?php for ($y = 1; $y <= 5; $y++): ?>
                                        <option value="<?= $y ?>">Year <?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="invalid-feedback">Please choose a year of study.</div>
                        </div>
                    </div>

                    <div class="col-md-4 program-scope">
                        <div class="form-group">
                            <label for="semester" class="form-label"><span id="period_label">Semester</span> <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                <select class="form-select" name="semester" id="semester">
                                    <option value="" disabled selected>Select period</option>
                                </select>
                            </div>
                            <div class="invalid-feedback">Please choose a period.</div>
                        </div>
                    </div>

                    <div class="col-md-4 program-scope">
                        <div class="form-group">
                            <label for="category" class="form-label">Category <small class="text-muted">(filter)</small></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-layer-group"></i></span>
                                <select class="form-select" name="category" id="category">
                                    <option value="all">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat, ENT_QUOTES) ?>"><?= htmlspecialchars($cat) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12 program-scope">
                        <div class="form-group">
                            <label for="course_search" class="form-label">Search Courses <small class="text-muted">(optional)</small></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" id="course_search" placeholder="Type a course code or name to filter the list below">
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12 program-scope">
                        <div class="form-group">
                            <label for="course_code" class="form-label">Course <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-book"></i></span>
                                <select class="form-select" name="course_code" id="course_code" disabled>
                                    <option value="" disabled selected>Select program, year and period first</option>
                                </select>
                            </div>
                            <div class="invalid-feedback">Please choose a course.</div>
                            <small id="course_hint" class="text-muted d-block mt-1"></small>
                        </div>
                    </div>

                    <!-- ============ SHORT-COURSE-SCOPED FIELDS ============ -->
                    <div class="col-md-12 short-course-scope" style="display:none;">
                        <div class="form-group">
                            <label for="short_course_search" class="form-label">Search Short Courses <small class="text-muted">(optional)</small></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" id="short_course_search" placeholder="Type a short-course code or name to filter">
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12 short-course-scope" style="display:none;">
                        <div class="form-group">
                            <label for="short_course_id" class="form-label">Short Course <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-certificate"></i></span>
                                <select class="form-select" name="short_course_id" id="short_course_id" disabled>
                                    <option value="" disabled selected>Loading short courses…</option>
                                </select>
                            </div>
                            <div class="invalid-feedback">Please choose a short course.</div>
                            <small id="short_course_hint" class="text-muted d-block mt-1"></small>
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <div class="d-flex justify-content-end gap-2">
                    <button type="reset" class="btn btn-outline-secondary" id="resetBtn">
                        <i class="fas fa-rotate-left me-1"></i>Reset
                    </button>
                    <button type="submit" class="btn btn-primary" id="generateBtn" disabled>
                        <i class="fas fa-file-pdf me-1"></i>Generate PDF
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    'use strict';

    const TYPES = <?= json_encode($js_types, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    const form        = document.getElementById('registerForm');
    const typeEl       = document.getElementById('register_type');
    const typeHint     = document.getElementById('type_hint');

    // Program-scope controls
    const programEl    = document.getElementById('program_code');
    const yearEl       = document.getElementById('year_of_study');
    const periodEl     = document.getElementById('semester');
    const periodLabel  = document.getElementById('period_label');
    const categoryEl   = document.getElementById('category');
    const searchEl     = document.getElementById('course_search');
    const courseEl     = document.getElementById('course_code');
    const courseHint   = document.getElementById('course_hint');
    const programFields = Array.from(document.querySelectorAll('.program-scope'));
    const programInputs = [programEl, yearEl, periodEl, courseEl];

    // Short-course controls
    const scSearchEl   = document.getElementById('short_course_search');
    const scSelectEl   = document.getElementById('short_course_id');
    const scHint       = document.getElementById('short_course_hint');
    const scFields      = Array.from(document.querySelectorAll('.short-course-scope'));

    const generateBtn  = document.getElementById('generateBtn');
    const resetBtn     = document.getElementById('resetBtn');

    let searchTimer = null, scSearchTimer = null;
    let courseSeq = 0, scSeq = 0;

    function currentConfig() {
        return TYPES[typeEl.value] || { scope: 'program', period_word: 'Semester', max_period: 2, label: '' };
    }

    function show(el, visible) { el.style.display = visible ? '' : 'none'; }

    // ---- Scope switching ----------------------------------------------------
    function applyScope() {
        const cfg = currentConfig();
        const isProgram = cfg.scope === 'program';

        programFields.forEach(el => show(el, isProgram));
        scFields.forEach(el => show(el, !isProgram));

        // Required + disabled toggling so HTML5 validation and submission match scope.
        // (disabled controls are never submitted, keeping the POST clean.)
        programInputs.forEach(el => { el.disabled = !isProgram; el.required = isProgram; });
        scSelectEl.disabled = isProgram;
        scSelectEl.required = !isProgram;

        typeHint.textContent = isProgram
            ? 'Lists students registered for the selected course in the chosen period.'
            : 'Lists students enrolled in the selected short course.';

        form.classList.remove('was-validated');

        if (isProgram) {
            gateOnProgram();   // Year + Period only unlock once a program is chosen
            refreshCourses();
        } else {
            loadShortCourses();
        }
        updateGenerateState();
    }

    // ---- Program-first cascade ---------------------------------------------
    // Year and Period stay disabled until a Program is selected; once it is,
    // they unlock and the period options (Semester/Term/Period) auto-populate.
    function gateOnProgram() {
        const hasProgram = !!programEl.value;
        yearEl.disabled   = !hasProgram;
        periodEl.disabled = !hasProgram;

        if (hasProgram) {
            buildPeriodOptions();
        } else {
            periodLabel.textContent = currentConfig().period_word || 'Semester';
            periodEl.innerHTML = '<option value="" disabled selected>Select a program first</option>';
        }
    }

    function onProgramChange() {
        gateOnProgram();
        refreshCourses();
    }

    // ---- Period dropdown (Semester 1-2 / Term 1-3 / Period 1-3) -------------
    function buildPeriodOptions() {
        const cfg = currentConfig();
        const word = cfg.period_word || 'Period';
        const max  = cfg.max_period || 2;
        periodLabel.textContent = word;

        const previous = periodEl.value;
        periodEl.innerHTML = '<option value="" disabled selected>Select ' + word.toLowerCase() + '</option>';
        for (let i = 1; i <= max; i++) {
            const opt = document.createElement('option');
            opt.value = i;
            opt.textContent = word + ' ' + i;
            periodEl.appendChild(opt);
        }
        // Preserve a still-valid previous choice.
        if (previous && Number(previous) <= max) { periodEl.value = previous; }
    }

    // ---- Program course list ------------------------------------------------
    function programReady() {
        return programEl.value && yearEl.value && periodEl.value;
    }

    function setCoursePlaceholder(text, disabled) {
        courseEl.innerHTML = '<option value="" disabled selected>' + text + '</option>';
        courseEl.disabled = disabled;
    }

    function refreshCourses() {
        if (!programReady()) {
            setCoursePlaceholder('Select program, year and period first', true);
            courseHint.textContent = '';
            updateGenerateState();
            return;
        }
        const seq = ++courseSeq;
        setCoursePlaceholder('Loading courses…', true);
        courseHint.textContent = '';
        updateGenerateState();

        const params = new URLSearchParams({
            program_code:  programEl.value,
            year_of_study: yearEl.value,
            semester:      periodEl.value,
            register_type: typeEl.value,
            category:      categoryEl.value || 'all',
            search:        searchEl.value || ''
        });

        fetch('ajax/get_program_courses.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(courses => {
                if (seq !== courseSeq) return;
                if (!Array.isArray(courses) || courses.length === 0) {
                    setCoursePlaceholder('No courses found for this selection', false);
                    courseHint.textContent = 'No courses are attached to this program for the chosen year and period.';
                    updateGenerateState();
                    return;
                }
                courseEl.innerHTML = '<option value="" disabled selected>Select course</option>';
                courses.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.course_code;
                    opt.textContent = c.course_code + ' — ' + c.course_name + ' (' + c.registered_students + ' registered)';
                    opt.dataset.count = c.registered_students;
                    courseEl.appendChild(opt);
                });
                courseEl.disabled = false;
                courseHint.textContent = courses.length + ' course(s) available.';
                updateGenerateState();
            })
            .catch(err => {
                if (seq !== courseSeq) return;
                setCoursePlaceholder('Could not load courses', false);
                courseHint.textContent = 'Error loading courses. Please try again.';
                console.error('get_program_courses:', err);
                updateGenerateState();
            });
    }

    // ---- Short course list --------------------------------------------------
    function loadShortCourses() {
        const seq = ++scSeq;
        scSelectEl.innerHTML = '<option value="" disabled selected>Loading short courses…</option>';
        scSelectEl.disabled = true;
        scHint.textContent = '';
        updateGenerateState();

        const params = new URLSearchParams({ search: scSearchEl.value || '' });
        fetch('ajax/get_short_courses.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(list => {
                if (seq !== scSeq) return;
                if (!Array.isArray(list) || list.length === 0) {
                    scSelectEl.innerHTML = '<option value="" disabled selected>No short courses found</option>';
                    scHint.textContent = 'No short courses match your search.';
                    updateGenerateState();
                    return;
                }
                scSelectEl.innerHTML = '<option value="" disabled selected>Select short course</option>';
                list.forEach(sc => {
                    const opt = document.createElement('option');
                    opt.value = sc.id;
                    opt.textContent = sc.course_code + ' — ' + sc.course_name + ' (' + sc.enrolled + ' enrolled)';
                    opt.dataset.count = sc.enrolled;
                    scSelectEl.appendChild(opt);
                });
                scSelectEl.disabled = false;
                scHint.textContent = list.length + ' short course(s) available.';
                updateGenerateState();
            })
            .catch(err => {
                if (seq !== scSeq) return;
                scSelectEl.innerHTML = '<option value="" disabled selected>Could not load short courses</option>';
                scHint.textContent = 'Error loading short courses. Please try again.';
                console.error('get_short_courses:', err);
                updateGenerateState();
            });
    }

    // ---- Generate button + roster-size hints --------------------------------
    function updateGenerateState() {
        const cfg = currentConfig();
        if (cfg.scope === 'program') {
            generateBtn.disabled = !courseEl.value;
            if (courseEl.value) {
                const count = parseInt(courseEl.options[courseEl.selectedIndex].dataset.count || '0', 10);
                courseHint.textContent = count === 0
                    ? 'Warning: no students registered for this period — the register will be empty.'
                    : count + ' student(s) will appear on the register.';
            }
        } else {
            generateBtn.disabled = !scSelectEl.value;
            if (scSelectEl.value) {
                const count = parseInt(scSelectEl.options[scSelectEl.selectedIndex].dataset.count || '0', 10);
                scHint.textContent = count === 0
                    ? 'Warning: no students enrolled — the register will be empty.'
                    : count + ' enrolled student(s) will appear on the register.';
            }
        }
    }

    // ---- Wiring -------------------------------------------------------------
    typeEl.addEventListener('change', applyScope);
    programEl.addEventListener('change', onProgramChange);
    [yearEl, periodEl, categoryEl].forEach(el => el.addEventListener('change', refreshCourses));
    courseEl.addEventListener('change', updateGenerateState);
    scSelectEl.addEventListener('change', updateGenerateState);

    searchEl.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(refreshCourses, 300); });
    scSearchEl.addEventListener('input', () => { clearTimeout(scSearchTimer); scSearchTimer = setTimeout(loadShortCourses, 300); });

    resetBtn.addEventListener('click', () => setTimeout(() => { typeEl.selectedIndex = 0; applyScope(); }, 0));

    form.addEventListener('submit', e => {
        if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
        form.classList.add('was-validated');
    });

    // Initial render.
    applyScope();
})();
</script>

<?php include "includes/footer.php"; ?>
