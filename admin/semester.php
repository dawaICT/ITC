<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', '0');
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if required files exist before including
if (!file_exists("includes/admin.php")) {
    die("Error: includes/admin.php not found");
}
if (!file_exists('semester_courses.php')) {
    die("Error: semester_courses.php not found");
}
if (!file_exists('includes/header.php')) {
    die("Error: includes/header.php not found");
}

include "includes/admin.php";
include 'semester_courses.php';

$page_title = 'Program Courses';
require 'includes/header.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4 mt-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-layer-group me-2 text-primary"></i>Program Courses Management</h5>
                <p class="page-subtitle mb-0">Manage and assign courses to semesters and terms across all academic programs</p>
            </div>
            <div class="header-actions d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary shadow-sm" id="refreshBtn">
                    <i class="fas fa-sync-alt me-1"></i>Refresh
                </button>
                <button type="button" class="btn btn-primary shadow-sm" id="addNewBtn">
                    <i class="fas fa-plus me-2"></i>Add Program Course
                </button>
            </div>
        </div>
    </div>

    <!-- Statistics Cards Row -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-graduation-cap"></i></div>
                    <div>
                        <h3 class="mb-0" id="totalPrograms">--</h3>
                        <p class="text-muted mb-0 small" id="programBreakdown">Total Programs</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3 text-white"><i class="fas fa-book"></i></div>
                    <div>
                        <h3 class="mb-0" id="totalCourses">--</h3>
                        <p class="text-muted mb-0 small">Assigned Courses</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3 text-white"><i class="fas fa-calendar-alt"></i></div>
                    <div>
                        <h3 class="mb-0" id="semesterCoursesTotal">--</h3>
                        <p class="text-muted mb-0 small" id="semesterBreakdown">Semester Basis</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100 border-0">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-purple me-3 text-white"><i class="fas fa-layer-group"></i></div>
                    <div>
                        <h3 class="mb-0" id="termCoursesTotal">--</h3>
                        <p class="text-muted mb-0 small" id="termBreakdown">Term Basis</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Row -->
    <div class="row">
        <div class="col-12">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Curriculum Registry</h5>
                    </div>
                </div>
                <div class="card-body p-4">
                    <!-- Search and Filter Bar -->
                    <div class="admin-section mb-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0">
                                        <i class="fas fa-search text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0 ps-0" id="searchInput"
                                           placeholder="Search courses, codes, or programs...">
                                    <button class="btn btn-outline-secondary" type="button" id="clearSearchBtn">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="programFilter">
                                    <option value="">All Programs</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="semesterFilter">
                                    <option value="">All Periods</option>
                                    <optgroup label="Semesters">
                                        <option value="semester_1">Semester 1</option>
                                        <option value="semester_2">Semester 2</option>
                                    </optgroup>
                                    <optgroup label="Terms">
                                        <option value="term_1">Term 1</option>
                                        <option value="term_2">Term 2</option>
                                        <option value="term_3">Term 3</option>
                                    </optgroup>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="creditsFilter">
                                    <option value="">All Credits</option>
                                    <option value="1">1 Credit</option>
                                    <option value="2">2 Credits</option>
                                    <option value="3">3 Credits</option>
                                    <option value="4">4 Credits</option>
                                    <option value="5">5 Credits</option>
                                    <option value="6">6 Credits</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <div class="btn-group w-100" role="group">
                                    <button type="button" class="btn btn-outline-primary" id="filterBtn">
                                        <i class="fas fa-filter me-1"></i>Filter
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="resetFiltersBtn">
                                        <i class="fas fa-undo"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Advanced Filters Toggle -->
                        <div class="row mt-2">
                            <div class="col-12">
                                <button class="btn btn-link btn-sm text-decoration-none p-0" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#advancedFilters"
                                        aria-expanded="false" aria-controls="advancedFilters">
                                    <i class="fas fa-sliders-h me-1"></i>Advanced Filters
                                    <i class="fas fa-chevron-down ms-1"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Advanced Filters (Collapsible) -->
                        <div class="collapse mt-2" id="advancedFilters">
                            <div class="row g-3 pt-2 border-top">
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold text-muted mb-1">Sort By</label>
                                    <select class="form-select form-select-sm" id="sortBy">
                                        <option value="program_name">Program Name</option>
                                        <option value="course_name">Course Name</option>
                                        <option value="course_code">Course Code</option>
                                        <option value="credits">Credits</option>
                                        <option value="semester">Semester</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold text-muted mb-1">Sort Order</label>
                                    <select class="form-select form-select-sm" id="sortOrder">
                                        <option value="asc">Ascending</option>
                                        <option value="desc">Descending</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold text-muted mb-1">Display Mode</label>
                                    <select class="form-select form-select-sm" id="displayMode">
                                        <option value="cards">Card View</option>
                                        <option value="list">List View</option>
                                        <option value="compact">Compact View</option>
                                    </select>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="showInactive">
                                        <label class="form-check-label small" for="showInactive">
                                            Show inactive courses
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bulk Actions Toolbar -->
                    <div id="bulkActionsToolbar" class="admin-section mb-3" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-check-square me-2 text-primary"></i>
                                <span id="selectedCount">0</span> course(s) selected
                            </div>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="selectAllBtn">
                                    <i class="fas fa-check-square me-1"></i>Select All
                                </button>
                                <button type="button" class="btn btn-outline-warning btn-sm" id="bulkEditBtn">
                                    <i class="fas fa-edit me-1"></i>Bulk Edit
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" id="bulkDeleteBtn">
                                    <i class="fas fa-trash me-1"></i>Delete Selected
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="clearSelectionBtn">
                                    <i class="fas fa-times me-1"></i>Clear
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Semester Courses Content -->
                    <div id="semester-content">
                        <?php
                        // Check if semester_courses.php has output
                        if (function_exists('display_semester_courses')) {
                            display_semester_courses();
                        } else {
                            echo '<div class="text-center py-5">
                                    <i class="fas fa-spinner fa-spin fa-3x text-primary mb-3"></i>
                                    <h5 class="text-muted">Loading semester courses...</h5>
                                  </div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- Add Semester Course Modal -->
<div class="modal fade" id="semesterModal" tabindex="-1" aria-labelledby="semesterModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <!-- Modal Header -->
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="semesterModalLabel">
                <i class="fas fa-plus-circle me-2"></i>Add Program Course
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-4">
                <form action="ajax/manage_semester_courses.php" method="post" role="form" id="semesterCourseForm" enctype="multipart/form-data">

                                <!-- Course Selection -->
            <div class="mb-3">
              <label for="course_code" class="form-label fw-bold">
                <i class="fas fa-book me-1 text-primary"></i>Course
              </label>
              <select class="form-select" name="course_code" id="course_code" required>
                <option value="" disabled selected>Select a course...</option>
                <!-- Courses will be loaded dynamically -->
              </select>
            </div>

            <!-- Program Selection -->
            <div class="mb-3">
              <label for="program_code" class="form-label fw-bold">
                <i class="fas fa-graduation-cap me-1 text-success"></i>Program
              </label>
              <select class="form-select" name="program_code" id="program_code" required>
                <option value="" disabled selected>Select program...</option>
                <!-- Programs will be loaded dynamically -->
              </select>
            </div>

            <!-- Period Selection (Semester or Term, populated dynamically based on program) -->
            <div class="mb-3">
              <label for="semester_select" class="form-label fw-bold">
                <i class="fas fa-calendar me-1 text-info"></i><span id="periodLabel">Semester / Term</span>
              </label>
              <select class="form-select" name="semester" id="semester_select" required>
                <option value="" disabled selected>Select program first...</option>
              </select>
              <div class="form-text" id="periodHelpText">Select a program to see available periods</div>
            </div>

            <!-- Credits Input -->
            <div class="mb-3">
              <label for="credits" class="form-label fw-bold">
                <i class="fas fa-star me-1 text-warning"></i>Credits
              </label>
              <input type="number" class="form-control" name="credits" id="credits" min="1" max="6" required>
              <div class="form-text">Number of credit hours for this course (1-6)</div>
            </div>

            <!-- Course Preview -->
            <div class="alert alert-info" id="coursePreview" style="display: none;">
              <h6 class="alert-heading mb-2">
                <i class="fas fa-eye me-1"></i>Course Preview
              </h6>
              <div id="coursePreviewContent"></div>
            </div>

                </form>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="submit" form="semesterCourseForm" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i>Add Course
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal for editing semester courses -->
<div class="modal fade" id="editSemesterModal" tabindex="-1" aria-labelledby="editSemesterModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="editSemesterModalLabel">
                <i class="fas fa-edit me-2"></i>Edit Program Course
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editSemesterForm">
                    <input type="hidden" id="editCourseId">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Program</label>
                            <input type="text" class="form-control" id="editProgramName" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Course</label>
                            <input type="text" class="form-control" id="editCourseName" readonly>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="editSemesterSelect" class="form-label">
                                <i class="fas fa-calendar me-1"></i><span id="editPeriodLabel">Semester / Term</span> <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="editSemesterSelect" required>
                                <optgroup label="Semesters">
                                    <option value="1">Semester 1</option>
                                    <option value="2">Semester 2</option>
                                </optgroup>
                                <optgroup label="Terms">
                                    <option value="1">Term 1</option>
                                    <option value="2">Term 2</option>
                                    <option value="3">Term 3</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editCreditsInput" class="form-label">
                                <i class="fas fa-star me-1"></i>Credits <span class="text-danger">*</span>
                            </label>
                            <input type="number" class="form-control" id="editCreditsInput" min="1" max="6" required>
                            <div class="form-text">Number of credits for this course (1-6)</div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-warning" id="updateCourseBtn">
                    <i class="fas fa-save me-1"></i>Update Course
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Enhanced JavaScript for semester courses management
document.addEventListener('DOMContentLoaded', function() {
    // Initialize variables
    const addModal = new bootstrap.Modal(document.getElementById('semesterModal'));
    const editModal = new bootstrap.Modal(document.getElementById('editSemesterModal'));
    const addNewBtn = document.getElementById('addNewBtn');
    const refreshBtn = document.getElementById('refreshBtn');
    const updateCourseBtn = document.getElementById('updateCourseBtn');
    const searchInput = document.getElementById('searchInput');
    const clearSearchBtn = document.getElementById('clearSearchBtn');
    const filterBtn = document.getElementById('filterBtn');
    const resetFiltersBtn = document.getElementById('resetFiltersBtn');
    const sortByEl = document.getElementById('sortBy');
    const sortOrderEl = document.getElementById('sortOrder');
    const displayModeEl = document.getElementById('displayMode');

    // Make editModal globally accessible for editCourse()
    window._editModal = editModal;

    // Load initial data
    loadPrograms();
    loadCourses();
    loadStatistics();

    // Enhanced modal form handling
    setupSemesterModal();

    // Event listeners
    addNewBtn.addEventListener('click', () => addModal.show());
    refreshBtn.addEventListener('click', refreshContent);
    updateCourseBtn.addEventListener('click', updateCourse);
    searchInput.addEventListener('input', debounce(filterCourses, 300));
    clearSearchBtn.addEventListener('click', clearSearch);
    filterBtn.addEventListener('click', filterCourses);
    resetFiltersBtn.addEventListener('click', resetFilters);
    sortByEl.addEventListener('change', filterCourses);
    sortOrderEl.addEventListener('change', filterCourses);
    displayModeEl.addEventListener('change', changeDisplayMode);

    // Edit course buttons (delegated event)
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('edit-course-btn') || e.target.closest('.edit-course-btn')) {
            const btn = e.target.classList.contains('edit-course-btn') ? e.target : e.target.closest('.edit-course-btn');
            editCourse(btn);
        }

        if (e.target.classList.contains('delete-course-btn') || e.target.closest('.delete-course-btn')) {
            const btn = e.target.classList.contains('delete-course-btn') ? e.target : e.target.closest('.delete-course-btn');
            deleteCourse(btn);
        }
    });
});

// Enhanced semester modal setup
function setupSemesterModal() {
    const modal = document.getElementById('semesterModal');
    if (!modal) return;

    const form = document.getElementById('semesterCourseForm');
    const courseSelect = document.getElementById('course_code');
    const programSelect = document.getElementById('program_code');
    const semesterSelect = document.getElementById('semester_select');
    const creditsInput = document.getElementById('credits');
    const coursePreview = document.getElementById('coursePreview');
    const coursePreviewContent = document.getElementById('coursePreviewContent');

    // Real-time course preview
    function updateCoursePreview() {
        const courseOption = courseSelect?.options[courseSelect.selectedIndex];
        const programOption = programSelect?.options[programSelect.selectedIndex];
        const semesterOption = semesterSelect?.options[semesterSelect.selectedIndex];
        const creditsValue = creditsInput?.value;

        if (courseOption && courseOption.value &&
            programOption && programOption.value &&
            semesterOption && semesterOption.value && creditsValue) {

          const courseName = courseOption.text.split(' (')[0];
          const programName = programOption.text;
          const periodText = semesterOption.text;
          const studyMode = programOption.getAttribute('data-study-mode') || 'semester';
          const badgeClass = studyMode === 'term' ? 'bg-purple' : 'bg-info';

          coursePreviewContent.innerHTML = `
            <div class="row g-2">
              <div class="col-md-6">
                <strong class="text-primary">${courseName}</strong><br>
                <small class="text-muted">${programName}</small>
              </div>
              <div class="col-md-6 text-end">
                <span class="badge ${badgeClass}">${periodText}</span>
                <span class="badge bg-secondary">${studyMode}-based</span><br>
                <small class="text-muted">${creditsValue} credits</small>
              </div>
            </div>
          `;
          coursePreview.style.display = 'block';
        } else {
          coursePreview.style.display = 'none';
        }
    }

    // Update semester/term options when program changes
    function updatePeriodOptions() {
        const selectedOption = programSelect?.options[programSelect.selectedIndex];
        const studyMode = selectedOption?.getAttribute('data-study-mode') || 'semester';
        const periodLabel = document.getElementById('periodLabel');
        const periodHelpText = document.getElementById('periodHelpText');

        semesterSelect.innerHTML = '';

        if (studyMode === 'term') {
            if (periodLabel) periodLabel.textContent = 'Term';
            if (periodHelpText) periodHelpText.textContent = 'This is a term-based program (3 terms per year)';
            semesterSelect.innerHTML = `
                <option value="" disabled selected>Select term...</option>
                <option value="1">Term 1</option>
                <option value="2">Term 2</option>
                <option value="3">Term 3</option>
            `;
        } else {
            if (periodLabel) periodLabel.textContent = 'Semester';
            if (periodHelpText) periodHelpText.textContent = 'This is a semester-based program (2 semesters per year)';
            semesterSelect.innerHTML = `
                <option value="" disabled selected>Select semester...</option>
                <option value="1">Semester 1</option>
                <option value="2">Semester 2</option>
            `;
        }
    }

    // Event listeners for form changes
    if (courseSelect) courseSelect.addEventListener('change', updateCoursePreview);
    if (programSelect) {
        programSelect.addEventListener('change', function() {
            updatePeriodOptions();
            updateCoursePreview();
        });
    }
    if (semesterSelect) semesterSelect.addEventListener('change', updateCoursePreview);
    if (creditsInput) creditsInput.addEventListener('input', updateCoursePreview);

    // Enhanced form validation and submission
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault(); // Prevent default form submission

            const formData = new FormData(form);
            formData.append('action', 'add_course');

            // Button is in the modal footer (outside <form>) but linked via form="semesterCourseForm"
            const submitBtn = document.querySelector('button[type="submit"][form="semesterCourseForm"]')
                           || form.querySelector('button[type="submit"]');
            const originalText = submitBtn ? submitBtn.innerHTML : '';

            // Show loading state
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Adding Course...';
            }

            fetch('ajax/manage_semester_courses.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Success - show message and close modal
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: data.message,
                        timer: 2000,
                        showConfirmButton: false
                    });

                    // Close modal and refresh content
                    const modalInstance = bootstrap.Modal.getInstance(modal);
                    modalInstance.hide();

                    // Reset form
                    form.reset();
                    coursePreview.style.display = 'none';

                    // Refresh content and statistics
                    refreshContent();
                    loadStatistics();
                } else {
                    // Error - show message
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: data.message
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'An error occurred while adding the course'
                });
            })
            .finally(() => {
                // Reset button state
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            });
        });
    }

    // Enhanced modal behavior
    modal.addEventListener('shown.bs.modal', function() {
        // Focus on first select field
        if (courseSelect) {
            courseSelect.focus();
        }
    });

    modal.addEventListener('hidden.bs.modal', function() {
        // Reset form when modal is closed
        if (form) {
            form.reset();
        }
        if (coursePreview) {
            coursePreview.style.display = 'none';
        }

        // Clear validation states
        form?.querySelectorAll('.is-invalid, .is-valid').forEach(el => {
            el.classList.remove('is-invalid', 'is-valid');
        });
    });
}

// Load programs for dropdown
function loadPrograms() {
    fetch('ajax/get_all_programs.php')
        .then(response => response.json())
        .then(data => {
            const programFilter = document.getElementById('programFilter');
            const modalProgramSelect = document.getElementById('program_code');

            // Clear existing options
            programFilter.innerHTML = '<option value="">All Programs</option>';
            if (modalProgramSelect) {
                modalProgramSelect.innerHTML = '<option value="" disabled selected>Select program...</option>';
            }

            if (data.success && data.programs) {
                data.programs.forEach(program => {
                    const studyMode = program.study_mode || 'semester';
                    const modeTag = studyMode === 'term' ? ' [Term]' : ' [Sem]';

                    const filterOption = new Option(program.program_name + modeTag, program.program_code);
                    filterOption.setAttribute('data-study-mode', studyMode);
                    programFilter.appendChild(filterOption);

                    // For modal form
                    if (modalProgramSelect) {
                        const modalOption = new Option(program.program_name, program.program_code);
                        modalOption.setAttribute('data-study-mode', studyMode);
                        modalProgramSelect.appendChild(modalOption);
                    }
                });
            }
        })
        .catch(error => console.error('Error loading programs:', error));
}

// Load courses for dropdown
function loadCourses() {
    fetch('ajax/get_courses.php')
        .then(response => response.json())
        .then(data => {
            const modalCourseSelect = document.getElementById('course_code');

            if (modalCourseSelect) {
                modalCourseSelect.innerHTML = '<option value="" disabled selected>Select a course...</option>';
            }

            if (data.success && data.courses) {
                data.courses.forEach(course => {
                    // For modal form
                    if (modalCourseSelect) {
                        const modalOption = new Option(
                            `${course.course_name} (${course.course_code})`,
                            course.course_code
                        );
                        modalOption.setAttribute('data-credits', course.credits || 3);
                        modalCourseSelect.appendChild(modalOption);
                    }
                });
            }
        })
        .catch(error => console.error('Error loading courses:', error));
}

// Load statistics for dashboard cards
function loadStatistics() {
    fetch('ajax/get_semester_statistics.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const s = data.statistics;

                // Total Programs with breakdown
                document.getElementById('totalPrograms').textContent = s.total_programs || 0;
                const progBreakdown = document.getElementById('programBreakdown');
                if (progBreakdown) {
                    const semP = s.semester_programs || 0;
                    const termP = s.term_programs || 0;
                    progBreakdown.textContent = semP + ' semester, ' + termP + ' term';
                }

                // Total Courses
                document.getElementById('totalCourses').textContent = s.total_courses || 0;

                // Semester Courses total with breakdown
                const sem1 = parseInt(s.semester_1_courses) || 0;
                const sem2 = parseInt(s.semester_2_courses) || 0;
                const semTotal = sem1 + sem2;
                document.getElementById('semesterCoursesTotal').textContent = semTotal;
                const semBreakdown = document.getElementById('semesterBreakdown');
                if (semBreakdown) {
                    semBreakdown.textContent = 'S1: ' + sem1 + ' | S2: ' + sem2;
                }

                // Term Courses total with breakdown
                const t1 = parseInt(s.term_1_courses) || 0;
                const t2 = parseInt(s.term_2_courses) || 0;
                const t3 = parseInt(s.term_3_courses) || 0;
                const termTotal = t1 + t2 + t3;
                document.getElementById('termCoursesTotal').textContent = termTotal;
                const termBreakdown = document.getElementById('termBreakdown');
                if (termBreakdown) {
                    termBreakdown.textContent = 'T1: ' + t1 + ' | T2: ' + t2 + ' | T3: ' + t3;
                }
            }
        })
        .catch(error => console.error('Error loading statistics:', error));
}

// NOTE: Course preview is handled inside setupSemesterModal()
// NOTE: Form submission is handled via the submit event in setupSemesterModal()

// Edit course
function editCourse(button) {
    const courseId = button.getAttribute('data-id');
    const programName = button.getAttribute('data-program');
    const courseName = button.getAttribute('data-course');
    const semester = button.getAttribute('data-semester');
    const credits = button.getAttribute('data-credits');
    const studyMode = button.getAttribute('data-study-mode') || 'semester';

    document.getElementById('editCourseId').value = courseId;
    document.getElementById('editProgramName').value = programName;
    document.getElementById('editCourseName').value = courseName;
    document.getElementById('editCreditsInput').value = credits;

    // Update period label and options based on study_mode
    const editPeriodLabel = document.getElementById('editPeriodLabel');
    const editSemesterSelect = document.getElementById('editSemesterSelect');

    if (studyMode === 'term') {
        if (editPeriodLabel) editPeriodLabel.textContent = 'Term';
        editSemesterSelect.innerHTML = `
            <option value="1">Term 1</option>
            <option value="2">Term 2</option>
            <option value="3">Term 3</option>
        `;
    } else {
        if (editPeriodLabel) editPeriodLabel.textContent = 'Semester';
        editSemesterSelect.innerHTML = `
            <option value="1">Semester 1</option>
            <option value="2">Semester 2</option>
        `;
    }

    editSemesterSelect.value = semester;

    window._editModal.show();
}

// Update course
function updateCourse() {
    const form = document.getElementById('editSemesterForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = new FormData();
    formData.append('action', 'update_course');
    formData.append('id', document.getElementById('editCourseId').value);
    formData.append('semester', document.getElementById('editSemesterSelect').value);
    formData.append('credits', document.getElementById('editCreditsInput').value);

    fetch('ajax/manage_semester_courses.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Server returned status ' + response.status);
        }
        return response.text();
    })
    .then(text => {
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON response:', text);
            throw new Error('Server returned invalid response. Check console for details.');
        }
    })
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            });
            window._editModal.hide();
            refreshContent();
            loadStatistics();
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: data.message
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: error.message || 'An error occurred while updating the course'
        });
    });
}

// Delete course
function deleteCourse(button) {
    const courseId = button.getAttribute('data-id');
    const courseName = button.getAttribute('data-course');

    Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to remove "${courseName}" from this semester?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'delete_course');
            formData.append('id', courseId);

            fetch('ajax/manage_semester_courses.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Deleted!',
                        text: data.message,
                        timer: 2000,
                        showConfirmButton: false
                    });
                    refreshContent();
                    loadStatistics();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: data.message
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'An error occurred while deleting the course'
                });
            });
        }
    });
}

// Clear search input
function clearSearch() {
    const searchInput = document.getElementById('searchInput');
    searchInput.value = '';
    filterCourses();
    searchInput.focus();
}

// Reset all filters
function resetFilters() {
    searchInput.value = '';
    document.getElementById('programFilter').value = '';
    document.getElementById('semesterFilter').value = '';
    document.getElementById('creditsFilter').value = '';
    document.getElementById('sortBy').value = 'program_name';
    document.getElementById('sortOrder').value = 'asc';
    document.getElementById('displayMode').value = 'cards';
    document.getElementById('showInactive').checked = false;
    filterCourses();
}

// Change display mode
function changeDisplayMode() {
    const mode = document.getElementById('displayMode').value;
    const contentDiv = document.getElementById('semester-content');

    // Add CSS classes based on display mode
    contentDiv.className = `semester-content-${mode}`;
    filterCourses(); // Re-apply filters with new display mode
}

// Enhanced filter courses function (table rows)
function filterCourses() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
    const programFilter = document.getElementById('programFilter').value;
    const semesterFilter = document.getElementById('semesterFilter').value;
    const creditsFilter = document.getElementById('creditsFilter').value;
    const sortBy = document.getElementById('sortBy').value;
    const sortOrder = document.getElementById('sortOrder').value;
    const showInactive = document.getElementById('showInactive').checked;

    const rows = document.querySelectorAll('.course-row');
    const filteredRows = [];

    rows.forEach(row => {
        const courseCode = (row.getAttribute('data-course-code') || '').toLowerCase();
        const courseName = (row.getAttribute('data-course-name') || '').toLowerCase();
        const programName = (row.getAttribute('data-program-name') || '').toLowerCase();
        const programCode = (row.getAttribute('data-program-code') || '').toLowerCase();
        const studyMode = row.getAttribute('data-study-mode') || 'semester';
        const periodLabel = (row.getAttribute('data-period-label') || '').toLowerCase();
        const semester = row.getAttribute('data-semester') || '';
        const credits = row.getAttribute('data-credits') || '';

        let showRow = true;

        // Search filter
        if (searchTerm) {
            const searchable = `${courseCode} ${courseName} ${programName} ${programCode} ${periodLabel}`;
            if (!searchable.includes(searchTerm)) {
                showRow = false;
            }
        }

        // Program filter
        if (programFilter && programCode !== programFilter.toLowerCase()) {
            showRow = false;
        }

        // Period filter (semester_1, term_2, etc.)
        if (semesterFilter) {
            const [filterMode, filterNum] = semesterFilter.split('_');
            if (studyMode !== filterMode || semester !== filterNum) {
                showRow = false;
            }
        }

        // Credits filter
        if (creditsFilter && credits !== creditsFilter) {
            showRow = false;
        }

        // Show/hide inactive
        if (!showInactive && row.classList.contains('inactive-course')) {
            showRow = false;
        }

        row.style.display = showRow ? '' : 'none';

        if (showRow) {
            filteredRows.push(row);
        }
    });

    // Apply sorting
    if (filteredRows.length > 0) {
        sortFilteredRows(filteredRows, sortBy, sortOrder);
    }

    // Update filter results count
    updateFilterResultsCount(filteredRows.length, rows.length);
}

// Sort filtered table rows
function sortFilteredRows(rows, sortBy, sortOrder) {
    const tbody = rows[0].parentElement;
    const sortedRows = Array.from(rows).sort((a, b) => {
        let valueA = '';
        let valueB = '';

        switch (sortBy) {
            case 'program_name':
                valueA = (a.getAttribute('data-program-name') || '').toLowerCase();
                valueB = (b.getAttribute('data-program-name') || '').toLowerCase();
                break;
            case 'course_name':
                valueA = (a.getAttribute('data-course-name') || '').toLowerCase();
                valueB = (b.getAttribute('data-course-name') || '').toLowerCase();
                break;
            case 'course_code':
                valueA = (a.getAttribute('data-course-code') || '').toLowerCase();
                valueB = (b.getAttribute('data-course-code') || '').toLowerCase();
                break;
            case 'credits':
                valueA = parseInt(a.getAttribute('data-credits') || '0');
                valueB = parseInt(b.getAttribute('data-credits') || '0');
                break;
            case 'semester':
                valueA = (a.getAttribute('data-study-mode') || '') + '_' + (a.getAttribute('data-semester') || '0');
                valueB = (b.getAttribute('data-study-mode') || '') + '_' + (b.getAttribute('data-semester') || '0');
                break;
        }

        if (typeof valueA === 'string') {
            valueA = valueA.toLowerCase();
            valueB = valueB.toLowerCase();
        }

        if (sortOrder === 'desc') {
            return valueA < valueB ? 1 : -1;
        } else {
            return valueA > valueB ? 1 : -1;
        }
    });

    // Re-append sorted rows
    sortedRows.forEach(row => {
        tbody.appendChild(row);
    });
}

// Update filter results count
function updateFilterResultsCount(visibleCount, totalCount) {
    let resultsDiv = document.getElementById('filterResultsCount');
    if (!resultsDiv) {
        resultsDiv = document.createElement('div');
        resultsDiv.id = 'filterResultsCount';
        resultsDiv.className = 'text-muted small mb-3';
        document.querySelector('#semester-content').prepend(resultsDiv);
    }

    if (visibleCount === totalCount) {
        resultsDiv.textContent = `Showing all ${totalCount} courses`;
    } else {
        resultsDiv.textContent = `Showing ${visibleCount} of ${totalCount} courses`;
    }
}

// Refresh content
function refreshContent() {
    const contentDiv = document.getElementById('semester-content');
    contentDiv.innerHTML = '<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-3x text-primary mb-3"></i><h5 class="text-muted">Refreshing...</h5></div>';

    // Reload the page content or fetch new data
    setTimeout(() => {
        location.reload();
    }, 1000);
}

// Debounce function for search
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// NOTE: Modal reset is already handled inside setupSemesterModal()

// View course details handler
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('view-course-btn') || e.target.closest('.view-course-btn')) {
        const btn = e.target.classList.contains('view-course-btn') ? e.target : e.target.closest('.view-course-btn');
        viewCourseDetails(btn);
    }
});

// View course details function
function viewCourseDetails(button) {
    const courseData = {
        id: button.getAttribute('data-id'),
        program: button.getAttribute('data-program'),
        course: button.getAttribute('data-course'),
        courseCode: button.getAttribute('data-course-code'),
        semester: button.getAttribute('data-semester'),
        credits: button.getAttribute('data-credits')
    };

    // Create or show course details modal
    let detailsModal = document.getElementById('courseDetailsModal');
    if (!detailsModal) {
        createCourseDetailsModal();
        detailsModal = document.getElementById('courseDetailsModal');
    }

    // Populate modal with course data
    document.getElementById('detailsProgramName').textContent = courseData.program;
    document.getElementById('detailsCourseCode').textContent = courseData.courseCode;
    document.getElementById('detailsCourseName').textContent = courseData.course;
    document.getElementById('detailsSemester').textContent = `Semester ${courseData.semester}`;
    document.getElementById('detailsCredits').textContent = `${courseData.credits} credits`;

    // Show modal
    const modal = new bootstrap.Modal(detailsModal);
    modal.show();
}

// Create course details modal
function createCourseDetailsModal() {
    const modalHTML = `
    <div class="modal fade" id="courseDetailsModal" tabindex="-1" aria-labelledby="courseDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="courseDetailsModalLabel">
                        <i class="fas fa-eye me-2"></i>Course Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="card border-0 bg-light">
                                <div class="card-body">
                                    <h5 class="card-title text-primary" id="detailsCourseName">Course Name</h5>
                                    <div class="mb-3">
                                        <strong>Course Code:</strong>
                                        <span class="badge bg-primary ms-2" id="detailsCourseCode">CODE</span>
                                    </div>
                                    <div class="mb-3">
                                        <strong>Program:</strong>
                                        <span class="text-muted ms-2" id="detailsProgramName">Program Name</span>
                                    </div>
                                    <div class="row">
                                        <div class="col-6">
                                            <strong>Semester:</strong>
                                            <div class="text-info" id="detailsSemester">Semester X</div>
                                        </div>
                                        <div class="col-6">
                                            <strong>Credits:</strong>
                                            <div class="text-success fw-bold" id="detailsCredits">X credits</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-0">
                                <div class="card-body text-center">
                                    <div class="bg-primary bg-opacity-10 rounded-circle p-4 mx-auto mb-3" style="width: 80px; height: 80px;">
                                        <i class="fas fa-book fa-2x text-primary"></i>
                                    </div>
                                    <h6 class="text-muted">Course Information</h6>
                                    <small class="text-muted">Detailed course information and metadata</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Close
                    </button>
                    <button type="button" class="btn btn-info" id="editFromDetailsBtn">
                        <i class="fas fa-edit me-1"></i>Edit Course
                    </button>
                </div>
            </div>
        </div>
    </div>`;

    document.body.insertAdjacentHTML('beforeend', modalHTML);

    // Handle edit button in details modal
    document.getElementById('editFromDetailsBtn').addEventListener('click', function() {
        const modal = bootstrap.Modal.getInstance(document.getElementById('courseDetailsModal'));
        modal.hide();

        // Find and click the edit button for this course
        const courseId = document.getElementById('detailsCourseCode').textContent;
        const editBtn = document.querySelector(`[data-course-code="${courseId}"].edit-course-btn`);
        if (editBtn) {
            editBtn.click();
        }
    });

    // Bulk Actions Functionality
    const bulkActionsToolbar = document.getElementById('bulkActionsToolbar');
    const selectedCount = document.getElementById('selectedCount');
    const selectAllBtn = document.getElementById('selectAllBtn');
    const bulkEditBtn = document.getElementById('bulkEditBtn');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const clearSelectionBtn = document.getElementById('clearSelectionBtn');

    let selectedCourses = new Set();

    // Handle individual checkbox changes
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('course-select-checkbox')) {
            const checkbox = e.target;
            const courseId = checkbox.value;

            if (checkbox.checked) {
                selectedCourses.add(courseId);
            } else {
                selectedCourses.delete(courseId);
            }

            updateBulkActionsToolbar();
        }
    });

    // Select all courses
    selectAllBtn.addEventListener('click', function() {
        const checkboxes = document.querySelectorAll('.course-select-checkbox');
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);

        if (allChecked) {
            // Uncheck all
            checkboxes.forEach(cb => {
                cb.checked = false;
                selectedCourses.delete(cb.value);
            });
        } else {
            // Check all
            checkboxes.forEach(cb => {
                cb.checked = true;
                selectedCourses.add(cb.value);
            });
        }

        updateBulkActionsToolbar();
    });

    // Bulk edit selected courses
    bulkEditBtn.addEventListener('click', function() {
        if (selectedCourses.size === 0) {
            Swal.fire('No Selection', 'Please select courses to edit.', 'warning');
            return;
        }

        // Create bulk edit modal
        createBulkEditModal();
    });

    // Bulk delete selected courses
    bulkDeleteBtn.addEventListener('click', function() {
        if (selectedCourses.size === 0) {
            Swal.fire('No Selection', 'Please select courses to delete.', 'warning');
            return;
        }

        const courseCount = selectedCourses.size;
        Swal.fire({
            title: 'Delete Selected Courses?',
            text: `Are you sure you want to delete ${courseCount} selected course(s)? This action cannot be undone.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete them!'
        }).then((result) => {
            if (result.isConfirmed) {
                bulkDeleteCourses();
            }
        });
    });

    // Clear selection
    clearSelectionBtn.addEventListener('click', function() {
        document.querySelectorAll('.course-select-checkbox').forEach(cb => {
            cb.checked = false;
        });
        selectedCourses.clear();
        updateBulkActionsToolbar();
    });

    // Update bulk actions toolbar visibility and count
    function updateBulkActionsToolbar() {
        if (selectedCourses.size > 0) {
            bulkActionsToolbar.style.display = 'block';
            selectedCount.textContent = selectedCourses.size;
        } else {
            bulkActionsToolbar.style.display = 'none';
        }

        // Sync the table header "select all" checkbox
        const headerCheckbox = document.getElementById('selectAllCheckbox');
        if (headerCheckbox) {
            const allCheckboxes = document.querySelectorAll('.course-select-checkbox');
            const visibleCheckboxes = Array.from(allCheckboxes).filter(cb => cb.closest('tr').style.display !== 'none');
            headerCheckbox.checked = visibleCheckboxes.length > 0 && visibleCheckboxes.every(cb => cb.checked);
            headerCheckbox.indeterminate = visibleCheckboxes.some(cb => cb.checked) && !visibleCheckboxes.every(cb => cb.checked);
        }
    }

    // Table header "Select All" checkbox
    document.addEventListener('change', function(e) {
        if (e.target.id === 'selectAllCheckbox') {
            const checked = e.target.checked;
            const allCheckboxes = document.querySelectorAll('.course-select-checkbox');
            allCheckboxes.forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') {
                    cb.checked = checked;
                    if (checked) {
                        selectedCourses.add(cb.value);
                    } else {
                        selectedCourses.delete(cb.value);
                    }
                }
            });
            updateBulkActionsToolbar();
        }
    });

    // Sortable table headers
    document.addEventListener('click', function(e) {
        const header = e.target.closest('.sortable-header');
        if (!header) return;

        const sortField = header.getAttribute('data-sort');
        if (!sortField) return;

        const sortByEl = document.getElementById('sortBy');
        const sortOrderEl = document.getElementById('sortOrder');

        // Toggle sort order if same column, otherwise default to asc
        if (sortByEl.value === sortField) {
            sortOrderEl.value = sortOrderEl.value === 'asc' ? 'desc' : 'asc';
        } else {
            sortByEl.value = sortField;
            sortOrderEl.value = 'asc';
        }

        // Update sort icons
        document.querySelectorAll('.sortable-header .sort-icon').forEach(icon => {
            icon.className = 'fas fa-sort ms-1 text-muted sort-icon';
        });
        const activeIcon = header.querySelector('.sort-icon');
        if (activeIcon) {
            activeIcon.className = sortOrderEl.value === 'asc'
                ? 'fas fa-sort-up ms-1 text-primary sort-icon'
                : 'fas fa-sort-down ms-1 text-primary sort-icon';
        }

        filterCourses();
    });

    // Bulk delete courses
    function bulkDeleteCourses() {
        const formData = new FormData();
        formData.append('action', 'bulk_delete');
        formData.append('course_ids', Array.from(selectedCourses).join(','));

        fetch('ajax/manage_semester_courses.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Deleted!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                });

                // Clear selection and refresh content
                selectedCourses.clear();
                updateBulkActionsToolbar();
                refreshContent();
                loadStatistics();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: data.message
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'An error occurred while deleting the courses'
            });
        });
    }

    // Create bulk edit modal
    function createBulkEditModal() {
        const modalHTML = `
        <div class="modal fade" id="bulkEditModal" tabindex="-1" aria-labelledby="bulkEditModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="bulkEditModalLabel">
                            <i class="fas fa-edit me-2"></i>Bulk Edit Courses
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            You are editing ${selectedCourses.size} course(s). Changes will be applied to all selected courses.
                        </div>

                        <form id="bulkEditForm">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">
                                        <i class="fas fa-calendar me-1"></i>Semester
                                    </label>
                                    <select class="form-select" id="bulkSemesterSelect">
                                        <option value="">Keep Current</option>
                                        <option value="1">Semester 1</option>
                                        <option value="2">Semester 2</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">
                                        <i class="fas fa-star me-1"></i>Credits
                                    </label>
                                    <select class="form-select" id="bulkCreditsSelect">
                                        <option value="">Keep Current</option>
                                        <option value="1">1 Credit</option>
                                        <option value="2">2 Credits</option>
                                        <option value="3">3 Credits</option>
                                        <option value="4">4 Credits</option>
                                        <option value="5">5 Credits</option>
                                        <option value="6">6 Credits</option>
                                    </select>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Cancel
                        </button>
                        <button type="button" class="btn btn-warning" id="applyBulkEditBtn">
                            <i class="fas fa-save me-1"></i>Apply Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>`;

        document.body.insertAdjacentHTML('beforeend', modalHTML);

        const modal = new bootstrap.Modal(document.getElementById('bulkEditModal'));
        modal.show();

        // Handle bulk edit apply
        document.getElementById('applyBulkEditBtn').addEventListener('click', function() {
            applyBulkEdit();
        });

        // Clean up modal when hidden
        document.getElementById('bulkEditModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('bulkEditModal').remove();
        });
    }

    // Apply bulk edit changes
    function applyBulkEdit() {
        const semester = document.getElementById('bulkSemesterSelect').value;
        const credits = document.getElementById('bulkCreditsSelect').value;

        if (!semester && !credits) {
            Swal.fire('No Changes', 'Please select at least one field to update.', 'warning');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'bulk_edit');
        formData.append('course_ids', Array.from(selectedCourses).join(','));
        if (semester) formData.append('semester', semester);
        if (credits) formData.append('credits', credits);

        fetch('ajax/manage_semester_courses.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Updated!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                });

                // Close modal and refresh content
                const modal = bootstrap.Modal.getInstance(document.getElementById('bulkEditModal'));
                modal.hide();

                selectedCourses.clear();
                updateBulkActionsToolbar();
                refreshContent();
                loadStatistics();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: data.message
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'An error occurred while updating the courses'
            });
        });
    }
}
</script>

<style>
/* Enhanced Semester Courses Styles */
.hover-lift {
    transition: all 0.3s ease;
}

.hover-lift:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15) !important;
}

.course-item {
    transition: background-color 0.2s ease;
}

.course-item:hover {
    background-color: rgba(0,123,255,0.05) !important;
}

.course-code-badge .badge {
    font-size: 0.75rem;
    font-weight: 600;
}

.course-actions {
    opacity: 0.7;
    transition: opacity 0.2s ease;
}

.course-item:hover .course-actions {
    opacity: 1;
}

.btn-group-vertical .btn {
    margin-bottom: 2px;
}

.btn-group-vertical .btn:last-child {
    margin-bottom: 0;
}

/* Advanced Filters Styles */
#advancedFilters {
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
}

/* Filter Results Count */
#filterResultsCount {
    padding: 8px 12px;
    background-color: #f8f9fa;
    border-radius: 0.375rem;
    border: 1px solid #dee2e6;
}

/* Card header badge positioning */
.card-header .badge {
    font-size: 0.75rem;
    position: absolute;
    top: 10px;
    right: 15px;
}

/* Course list styling */
.courses-list {
    max-height: 400px;
    overflow-y: auto;
}

/* Custom scrollbar for course list */
.courses-list::-webkit-scrollbar {
    width: 6px;
}

.courses-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.courses-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.courses-list::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Statistics cards responsive */
@media (max-width: 768px) {
    .statistics-card .card-body {
        padding: 1rem !important;
    }

    .statistics-card .card-body .d-flex {
        flex-direction: column;
        text-align: center;
    }

    .statistics-card .card-body .bg-white {
        margin-top: 1rem;
        margin-left: 0 !important;
    }
}

/* Bulk Actions Styles */
.course-checkbox {
    display: flex;
    align-items: center;
    justify-content: center;
}

.course-select-checkbox {
    transform: scale(1.2);
    cursor: pointer;
}

.course-item.selected {
    background-color: rgba(0, 123, 255, 0.1) !important;
    border-left: 4px solid #007bff;
}

#bulkActionsToolbar {
    transition: all 0.3s ease;
}

#bulkActionsToolbar.show {
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        transform: translateY(-10px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Course selection styles */
.course-item:hover .course-select-checkbox {
    opacity: 1;
}

.course-select-checkbox:not(:checked) {
    opacity: 0.5;
}

/* Advanced Filters Styles */
#advancedFilters {
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
}

/* Filter Results Count */
#filterResultsCount {
    padding: 8px 12px;
    background-color: #f8f9fa;
    border-radius: 0.375rem;
    border: 1px solid #dee2e6;
}

/* Card header badge positioning */
.card-header .badge {
    font-size: 0.75rem;
    position: absolute;
    top: 10px;
    right: 15px;
}

/* Course list styling */
.courses-list {
    max-height: 400px;
    overflow-y: auto;
}

/* Custom scrollbar for course list */
.courses-list::-webkit-scrollbar {
    width: 6px;
}

.courses-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.courses-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.courses-list::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Statistics cards responsive */
@media (max-width: 768px) {
    .statistics-card .card-body {
        padding: 1rem !important;
    }

    .statistics-card .card-body .d-flex {
        flex-direction: column;
        text-align: center;
    }

    .statistics-card .card-body .bg-white {
        margin-top: 1rem;
        margin-left: 0 !important;
    }
}

/* Search and filter responsive */
@media (max-width: 576px) {
    .input-group .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }

    .btn-group-vertical {
        flex-direction: row;
        width: 100%;
    }

    .btn-group-vertical .btn {
        margin-bottom: 0;
        margin-right: 2px;
        flex: 1;
    }

    .btn-group-vertical .btn:last-child {
        margin-right: 0;
    }
}

/* Mobile responsive enhancements */
@media (max-width: 768px) {
    .container-fluid {
        padding-left: 10px;
        padding-right: 10px;
    }

    .card-header h6 {
        font-size: 0.9rem;
    }

    .course-name {
        font-size: 0.95rem;
    }

    .course-meta {
        font-size: 0.8rem;
    }

    /* Stack bulk actions toolbar vertically on mobile */
    #bulkActionsToolbar .btn-group {
        flex-direction: column;
        width: 100%;
    }

    #bulkActionsToolbar .btn {
        margin-bottom: 0.5rem;
        width: 100%;
    }

    /* Hide course codes on very small screens */
    .course-code-badge {
        display: none;
    }

    /* Make action buttons smaller on mobile */
    .btn-group-vertical .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }

    /* Adjust modal sizes for mobile */
    .modal-dialog {
        margin: 0.5rem;
    }

    /* Stack advanced filters vertically */
    #advancedFilters .row > div {
        margin-bottom: 1rem;
    }
}

/* Tablet responsive */
@media (min-width: 769px) and (max-width: 1024px) {
    .col-xl-4 {
        flex: 0 0 auto;
        width: 50%;
    }

    .col-xl-3 {
        flex: 0 0 auto;
        width: 33.333%;
    }
}

/* Large desktop */
@media (min-width: 1200px) {
    .container-fluid {
        max-width: 1400px;
    }
}

/* Enhanced Modal Styles */
.modal-content {
    border-radius: 15px;
    border: none;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}

.modal-header.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 15px 15px 0 0;
    border: none;
    padding: 2rem 1.5rem;
}

.modal-body {
    padding: 2rem;
}

.modal-footer {
    border-radius: 0 0 15px 15px;
    border: none;
    padding: 1.5rem 2rem;
}

.modal-icon {
    background: rgba(255,255,255,0.2);
    border-radius: 12px;
    padding: 12px;
}

.form-floating > .form-select {
    border: 2px solid #e9ecef;
    border-radius: 10px;
    padding: 1rem 0.75rem;
    font-size: 1rem;
    transition: all 0.3s ease;
}

.form-floating > .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    transform: translateY(-2px);
}

.form-floating > label {
    padding: 1rem 0.75rem;
    font-weight: 500;
    color: #6c757d;
}

.btn-success {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    border: none;
}

.btn-success:hover {
    background: linear-gradient(135deg, #218838 0%, #1aa085 100%);
}

/* Course preview styling */
#coursePreview {
    animation: fadeInUp 0.3s ease;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Print styles */
@media print {
    .btn, .modal, .bulk-actions-toolbar {
        display: none !important;
    }

    .card {
        border: 1px solid #000 !important;
        box-shadow: none !important;
    }

    .course-select-checkbox {
        display: none;
    }
}

/* Mobile responsive for modal */
@media (max-width: 768px) {
    .modal-dialog {
        margin: 0.5rem;
        max-width: calc(100% - 1rem);
    }

    .modal-body {
        padding: 1.5rem;
    }

    .modal-header, .modal-footer {
        padding: 1.5rem;
    }

    .form-floating > .form-select {
        font-size: 0.9rem;
    }

    .btn-group {
        flex-direction: column;
        width: 100%;
    }

    .btn-group .btn {
        margin-bottom: 0.5rem;
    }
}
</style>

<?php 
// Check if footer file exists
if (file_exists('includes/footer.php')) {
    require 'includes/footer.php';
} else {
    echo '</body></html>'; // Basic closure if footer is missing
}
?>
