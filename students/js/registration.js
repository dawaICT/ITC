/**
 * registration.js
 * Unified registration logic for New and Returning Students
 */
$(document).ready(function() {
    // Initial State Check
    const initialYearOfStudy = $('#yearOfStudy').val() || 1;
    const initialSemester = $('#semester').val() || 1;
    const initialAcademicYear = $('#academicYear').val() || '';
    const studentId = $('#studentId').val();
    const programCode = $('#programCode').val();

    // Setup Select2 for better UX if needed
    if ($.fn.select2) {
        $('.select2').select2({ width: '100%' });
    }

    // Initialize course loading
    loadRequiredCourses(initialYearOfStudy, initialSemester);

    // Initial server check for pre-existing registration
    checkRegistrationStatus(initialAcademicYear, initialYearOfStudy, initialSemester);

    // If it's a returning student, we might want to run an eligibility check
    if (window.isReturning) {
        checkEligibility(studentId, initialAcademicYear, initialSemester);
    }

    // --- EVENT HANDLERS ---

    // Handle Year/Semester changes
    $('#yearOfStudy, #semester').on('change', function() {
        const year = $('#yearOfStudy').val();
        const sem = $('#semester').val();
        const ay = $('#academicYear').val();
        
        loadRequiredCourses(year, sem);
        checkRegistrationStatus(ay, year, sem);
        
        if (window.isReturning) {
            checkEligibility(studentId, ay, sem);
        }
    });

    // Handle transfer student toggle (New Students only)
    $('#isTransferStudent').on('change', function() {
        if (window.isReturning) return; // Should be disabled anyway
        
        const isTransfer = $(this).is(':checked');
        const yearSelect = $('#yearOfStudy');
        
        // Disable/Enable higher years for non-transfer new students
        yearSelect.find('option').each(function() {
            const val = $(this).val();
            if (val !== '1') {
                $(this).prop('disabled', !isTransfer);
            }
        });

        if (!isTransfer && yearSelect.val() !== '1') {
            yearSelect.val('1').trigger('change');
        } else {
            loadRequiredCourses(yearSelect.val(), $('#semester').val());
        }
    });

    // Handle Unregister Action (Resetting for testing/fixing)
    $('#unregisterBtn').on('click', function() {
        const year = $('#yearOfStudy').val();
        const sem = $('#semester').val();
        const ay = $('#academicYear').val();

        if (!confirm(`CAUTION: Are you sure you want to clear registration for Year ${year} Sem ${sem}? \nThis will delete your invoice and course selections.`)) {
            return;
        }

        const $btn = $(this);
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin me-1"></i>Clearing...');

        const csrfToken = $('meta[name="csrf-token"]').attr('content')
            || $('input[name="csrf_token"]').first().val()
            || window.WUC_CSRF_TOKEN
            || '';

        $.ajax({
            url: 'process_unregister.php',
            method: 'POST',
            data: {
                student_id: studentId,
                academic_year: ay,
                semester: sem,
                year_of_study: year,
                csrf_token: csrfToken
            },
            dataType: 'json',
            success: function(resp) {
                if (resp && resp.success) {
                    showSuccess(resp.message || 'Registration cleared successfully.');
                    // Force reload state
                    checkRegistrationStatus(ay, year, sem);
                    loadRequiredCourses(year, sem);
                    if (window.isReturning) checkEligibility(studentId, ay, sem);
                } else {
                    showError(resp.error || 'Failed to unregister.');
                }
            },
            error: function() {
                showError('Network error occurred while trying to unregister.');
            },
            complete: function() {
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    });

    // Submit Buttons
    $('#registerAndPayBtn').on('click', function() { submitRegistration(true, this); });
    $('#registerOnlyBtn').on('click', function() { submitRegistration(false, this); });
    $('#registerNowBtn').on('click', function() { submitRegistration(false, this); });
    $('#submitSemesterOnlyBtn').on('click', function() { submitRegistration(false, this); });
    $('#refreshCoursesBtn').on('click', function() {
        const year = $('#yearOfStudy').val();
        const sem = $('#semester').val();
        loadRequiredCourses(year, sem, true);
    });
    $('#previewBtn').on('click', function() {
        const year = $('#yearOfStudy').val();
        const sem = $('#semester').val();
        loadRequiredCourses(year, sem, true);
    });

    $('#startRegistrationBtn').on('click', function() {
        const target = $('#registrationForm');
        if (target.length) {
            $('html, body').animate({ scrollTop: target.offset().top - 80 }, 500);
            target.addClass('animate__animated animate__pulse');
            setTimeout(() => target.removeClass('animate__animated animate__pulse'), 800);
        }
    });

    // --- CORE FUNCTIONS ---

    function loadRequiredCourses(yearOfStudy, semester, isManualRefresh = false) {
        const listContainer = $('#requiredCoursesList');
        listContainer.html('<tr><td colspan="4" class="text-center py-4"><i class="fa fa-spinner fa-spin fa-2x text-primary mb-2"></i><br>Consulting curriculum...</td></tr>');
        
        window.coursesLoaded = false;
        setRegisterButtonsEnabled(false);
        $('#feeSummary').fadeOut();

        $.ajax({
            url: 'get_required_courses.php',
            method: 'POST',
            data: {
                student_id: studentId,
                program_code: $('#programCode').val(),
                year_of_study: yearOfStudy,
                semester: semester,
                is_transfer: $('#isTransferStudent').is(':checked') ? 1 : 0,
                is_returning: window.isReturning ? 1 : 0
            },
            dataType: 'json',
            success: function(response) {
                if (response && response.success && response.courses) {
                    displayCourses(response.courses);
                    calculateFees(response.courses);
                    updateCreditSummary(response.courses);
                    window.coursesLoaded = true;
                    
                    if (!window.alreadyRegistered) {
                        setRegisterButtonsEnabled(true);
                    }
                    
                    if (isManualRefresh) {
                        showSuccess("Curriculum updated.");
                    }
                } else {
                    const msg = (response && response.error) ? response.error : 'No courses found for this period.';
                    listContainer.html(`<tr><td colspan="4" class="text-center py-4 text-muted"><i class="fa fa-info-circle mb-2 fa-2x"></i><br>${msg}</td></tr>`);
                    $('#selectedCourses').val('');
                }
            },
            error: function(xhr) {
                let msg = 'Failed to connect to course registry.';
                try {
                    const resp = JSON.parse(xhr.responseText);
                    if (resp && resp.error) msg = resp.error;
                } catch(e) {}
                listContainer.html(`<tr><td colspan="4" class="text-center py-4 text-danger"><i class="fa fa-exclamation-triangle mb-2 fa-2x"></i><br>${msg}</td></tr>`);
            }
        });
    }

    function displayCourses(courses) {
        const container = $('#requiredCoursesList');
        container.empty();
        
        let courseCodes = [];
        
        courses.forEach(course => {
            courseCodes.push(course.course_code);
            const badgeClass = course.course_type === 'Core' ? 'badge-core' : (course.course_type === 'Lab' ? 'badge-lab' : 'badge-advanced');
            
            const row = `
                <tr class="animate__animated animate__fadeIn">
                    <td class="fw-bold text-primary">${course.course_code}</td>
                    <td>
                        <div class="fw-semibold">${course.course_name}</div>
                        <div class="small text-muted">${course.description || ''}</div>
                    </td>
                    <td><span class="badge bg-light text-dark border">${course.credits} Credits</span></td>
                    <td><span class="badge ${badgeClass}">${course.course_type}</span></td>
                </tr>
            `;
            container.append(row);
        });
        
        $('#selectedCourses').val(courseCodes.join(','));
    }

    function calculateFees(courses) {
        const courseCodes = courses.map(c => c.course_code);
        
        $.ajax({
            url: 'calculate_fees.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                courses: courseCodes,
                is_transfer: $('#isTransferStudent').is(':checked')
            }),
            dataType: 'json',
            success: function(response) {
                if (response && response.success && response.fees) {
                    const f = response.fees;
                    $('#baseTuition').text('K' + f.base_tuition.toLocaleString());
                    $('#registrationFee').text('K' + f.registration_fee.toLocaleString());
                    $('#additionalFees').text('K' + f.additional_fees.toLocaleString());
                    $('#totalFees').text('K' + f.total.toLocaleString());
                    $('#feeSummary').fadeIn();
                }
            }
        });
    }

    function updateCreditSummary(courses) {
        const count = courses.length;
        let totalCredits = 0;
        courses.forEach(c => totalCredits += parseFloat(c.credits || 0));
        
        $('#creditSummary').text(`${count} courses | ${totalCredits} Total Credits`);
        
        // Progress bar logic (assuming 18 credits is full load)
        const progress = Math.min((totalCredits / 18) * 100, 100);
        $('#creditProgress').css('width', progress + '%').attr('aria-valuenow', progress);
    }

    function checkRegistrationStatus(ay, yos, sem) {
        const banner = $('#registrationStatusBanner');
        
        $.ajax({
            url: 'check_registration_status.php',
            method: 'POST',
            data: { student_id: studentId, academic_year: ay, year_of_study: yos, semester: sem },
            dataType: 'json',
            success: function(resp) {
                if (resp && resp.registered) {
                    window.alreadyRegistered = true;
                    setRegisterButtonsEnabled(false);
                    
                    let html = `
                        <div class="alert alert-success d-flex align-items-center justify-content-between animate__animated animate__fadeIn">
                            <div>
                                <i class="fa fa-check-circle me-3 fa-2x"></i>
                                <div>
                                    <strong>Semester Registration Complete:</strong> Proceed to do course registration.
                                    ${resp.invoice_number ? `<div class="mt-1">Invoice: <a href="fees.php?invoice=${encodeURIComponent(resp.invoice_number)}" class="fw-bold">${resp.invoice_number}</a></div>` : ''}
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="courseReg.php" class="btn btn-primary btn-sm rounded-pill"><i class="fa fa-book me-1"></i> Proceed to Course Registration</a>
                            </div>
                        </div>
                    `;
                    banner.html(html);
                } else {
                    window.alreadyRegistered = false;
                    banner.empty();
                    if (window.coursesLoaded) setRegisterButtonsEnabled(true);
                }
            }
        });
    }

    function checkEligibility(sid, ay, sem) {
        const banner = $('#eligibilityBanner');
        banner.hide().empty();

        $.ajax({
            url: 'check_eligibility.php',
            method: 'POST',
            data: { student_id: sid, academic_year: ay, semester: sem },
            dataType: 'json',
            success: function(resp) {
                if (resp && !resp.eligible) {
                    let html = `
                        <div class="alert alert-warning border-warning shadow-sm">
                            <h6 class="fw-bold"><i class="fa fa-exclamation-triangle me-2"></i>Registration Warning</h6>
                            <p class="mb-0 small">${resp.reason || "You might not be fully eligible for registration. Please consult the registrar."}</p>
                        </div>
                    `;
                    banner.html(html).fadeIn();
                    // Optional: setRegisterButtonsEnabled(false); // If we want to block them
                }
            }
        });
    }

    function submitRegistration(pay, btn) {
        $('#registrationError, #registrationSuccess').hide();
        
        // If they are just registering for the semester, we allow empty courses
        const isSemesterOnly = $(btn).attr('id') === 'submitSemesterOnlyBtn';
        const courses = $('#selectedCourses').val();

        if (!window.coursesLoaded && !isSemesterOnly) {
            showError("Please wait for courses to load.");
            return;
        }

        if (!courses && !isSemesterOnly) {
            if (!confirm("No courses were found for this period. Do you want to proceed with Semester Registration only?")) {
                return;
            }
        }

        const $btn = $(btn);
        const originalHtml = $btn.html();
        
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin me-2"></i>Processing...');

        const formData = {
            student_id: studentId,
            academic_year: $('#academicYear').val(),
            year_of_study: $('#yearOfStudy').val(),
            semester: $('#semester').val(),
            courses: $('#selectedCourses').val(),
            is_transfer: $('#isTransferStudent').is(':checked') ? 1 : 0,
            pay: pay ? 1 : 0,
            csrf_token: $('meta[name="csrf-token"]').attr('content')
        };

        $.ajax({
            url: 'process_registration.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response && response.success) {
                    showSuccess(pay ? 'Registration confirmed! Redirecting to payment...' : 'Registration successful!');
                    
                    setTimeout(() => {
                        if (pay) {
                            window.location.href = response.next_url || `fees.php?invoice=${encodeURIComponent(response.invoice_number)}`;
                        } else {
                            location.reload();
                        }
                    }, 1500);
                } else {
                    showError(response.error || 'Failed to complete registration.');
                    $btn.prop('disabled', false).html(originalHtml);
                }
            },
            error: function(xhr) {
                let msg = 'Server error occurred.';
                try {
                    const err = JSON.parse(xhr.responseText);
                    if (err.error) msg = err.error;
                } catch(e) {}
                showError(msg);
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    }

    // --- UTILS ---

    function setRegisterButtonsEnabled(enabled) {
        // Respect payment eligibility check (50% tuition threshold)
        // Only enable if both courses are loaded AND payment threshold is met
        const canEnable = enabled && window.canRegisterCourses;
        $('#registerOnlyBtn, #registerAndPayBtn, #registerNowBtn, #submitSemesterOnlyBtn').prop('disabled', !canEnable);
        
        // Update button text/title if blocked by payment
        if (!window.canRegisterCourses) {
            $('#registerOnlyBtn, #registerAndPayBtn, #registerNowBtn, #submitSemesterOnlyBtn').attr('title', 'Payment threshold not met - 50% tuition required');
        } else {
            $('#registerOnlyBtn, #registerAndPayBtn, #registerNowBtn, #submitSemesterOnlyBtn').removeAttr('title');
        }
    }

    function showSuccess(msg) {
        $('#registrationSuccess').text(msg).fadeIn().delay(3000).fadeOut();
    }

    function showError(msg) {
        $('#registrationError').text(msg).fadeIn();
        $('html, body').animate({ scrollTop: $('#registrationError').offset().top - 100 }, 500);
    }
});
