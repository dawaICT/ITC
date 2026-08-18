// Ensure the DOM is ready before running the script
$(document).ready(function() {
	// Initial load for Year 1, Semester 1
	const initialYearOfStudy = $('#yearOfStudy').val() || 1;
	const initialSemester = $('#semester').val() || 1;
	const initialAcademicYear = $('#academicYear').val() || '';

	loadRequiredCourses(initialYearOfStudy, initialSemester);

	// initial server check for pre-existing registration
	checkRegistrationStatus(initialAcademicYear, initialYearOfStudy, initialSemester);

	// Handle unregister click
	$('#unregisterBtn').on('click', function() {
		if (!confirm('Are you sure you want to clear your registration for this term? This will delete your invoice and course selections.')) {
			return;
		}

		const studentId = $('#studentId').val();
		const academicYear = $('#academicYear').val();
		const semester = $('#semester').val();
		const yearOfStudy = $('#yearOfStudy').val();

		const $btn = $(this);
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
				academic_year: academicYear,
				semester: semester,
				year_of_study: yearOfStudy,
				csrf_token: csrfToken
			},
			dataType: 'json',
			success: function(resp) {
				if (resp && resp.success) {
					showSuccess(resp.message);
					// Refresh state
					checkRegistrationStatus(academicYear, yearOfStudy, semester);
					loadRequiredCourses(yearOfStudy, semester);
				} else {
					showError(resp.error || 'Failed to unregister.');
				}
			},
			error: function() {
				showError('Network error while unregistering.');
			},
			complete: function() {
				$btn.prop('disabled', false).html('<i class="fa fa-trash-alt me-1"></i>Unregister');
			}
		});
	});

	// Handle transfer student checkbox
	$('#isTransferStudent').on('change', function() {
		const isTransfer = this.checked;
		const yearSelect = $('#yearOfStudy');
		const semesterSelect = $('#semester');

		// Enable/disable year and semester options
		yearSelect.find('option').each(function() {
			$(this).prop('disabled', !isTransfer && $(this).val() !== '1');
		});

		if (!isTransfer) {
			// Reset to Year 1, Semester 1 for new students
			yearSelect.val('1');
			semesterSelect.val(initialSemester);
			loadRequiredCourses(1, initialSemester);
		} else {
			// For transfer students, load courses for the selected year/semester
			const yearOfStudy = yearSelect.val();
			const semester = semesterSelect.val();
			loadRequiredCourses(yearOfStudy, semester);
		}
	});

	// Handle academic year and semester changes
	$('#yearOfStudy, #semester').on('change', function() {
		const yearOfStudy = $('#yearOfStudy').val();
		const academicYear = $('#academicYear').val();
		const semester = $('#semester').val();
		if (yearOfStudy && semester) {
			// refresh required courses
			loadRequiredCourses(yearOfStudy, semester);
			// check server for existing registration for the selected year/semester
			checkRegistrationStatus(academicYear, yearOfStudy, semester);
		}
	});

	// Check registration status via AJAX
	function checkRegistrationStatus(academicYear, yearOfStudy, semester) {
		const studentId = $('#studentId').val();
		if (!studentId) return;
		$.ajax({
			url: 'check_registration_status.php',
			method: 'POST',
			data: {
				student_id: studentId,
				academic_year: academicYear,
				year_of_study: yearOfStudy,
				semester: semester
			},
			dataType: 'json',
			success: function(resp) {
				if (resp && resp.success) {
					window.alreadyRegistered = !!resp.registered;
					if (resp.registered) {
						// render banner
						let html = '<div class="alert alert-success d-flex align-items-center justify-content-between">';
						html += '<div><i class="fa fa-check-circle me-2"></i><strong>Registration Complete:</strong> You have already registered for Year ' + yearOfStudy + ' Semester ' + semester + ' (' + academicYear + ').</div>';
						if (resp.invoice_number) {
							html = '<div class="alert alert-success d-flex align-items-center justify-content-between"><div><i class="fa fa-check-circle me-2"></i><strong>Registration Complete:</strong> You have already registered for Year ' + academicYear + ' Semester ' + semester + '.<div class="mt-1">Invoice: <a href="fees.php?invoice=' + encodeURIComponent(resp.invoice_number) + '">' + escapeHtml(resp.invoice_number) + '</a></div></div>';
						}
						html += '<div><a href="students/myCourses.php" class="btn btn-outline-primary btn-sm">View Courses</a> <a href="students/fees.php" class="btn btn-outline-secondary btn-sm ms-2">View Fees</a></div></div>';
						$('#registrationStatusBanner').html(html);
						setRegisterButtonsEnabled(false);
					} else {
						$('#registrationStatusBanner').html('');
						// enable register buttons if courses are loaded
						if (window.coursesLoaded) setRegisterButtonsEnabled(true);
					}
				}
			}
		});
	}

	function escapeHtml(unsafe) {
		return String(unsafe)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	// Shared submission function: pay = true triggers redirect to payment if available
	function submitRegistration(pay, triggerBtn) {
		console.debug('submitRegistration called', { pay: pay, triggerBtn: triggerBtn });
		$('#registrationSuccess').hide();
		$('#registrationError').hide();

		if (typeof window.coursesLoaded === 'undefined' || !window.coursesLoaded) {
			showError('Courses not yet loaded. Click "Preview Fees" or wait for the courses to load.');
			return;
		}

		const formEl = $('#newStudentForm')[0];
		if (!formEl.checkValidity()) {
			formEl.classList.add('was-validated');
			return;
		}

		const studentId = $('#studentId').val();
		const academicYear = $('#academicYear').val() || '';
		const yearOfStudy = $('#yearOfStudy').val() || '1';
		const sem = $('#semester').val() || '1';
		const coursesVal = ($('#selectedCourses').val() || '').trim();

		if (!coursesVal) {
			showError('No courses selected. Please check your year and semester selection.');
			return;
		}

		const formData = new FormData(formEl);
		formData.set('student_id', studentId);
		formData.set('academic_year', academicYear);
		formData.set('year_of_study', yearOfStudy);
		formData.set('semester', sem);
		formData.set('courses', coursesVal);
		formData.set('pay', pay ? '1' : '0');

		const $btn = triggerBtn ? $(triggerBtn) : null;
		const originalText = $btn ? $btn.html() : null;
		if ($btn) $btn.html('<i class="fa fa-spinner fa-spin"></i> Processing...').prop('disabled', true);

		$.ajax({
			url: 'process_registration.php',
			method: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			dataType: 'json',
			success: function(response) {
				if (response && response.success) {
					showSuccess(pay ? 'Registration successful! Redirecting to payment...' : 'Registration successful!');
					if (pay) {
						const nextUrl = (response.next_url && typeof response.next_url === 'string')
							? response.next_url
							: ('fees.php' + (response.invoice_number ? ('?invoice=' + encodeURIComponent(response.invoice_number)) : ''));
						setTimeout(() => { window.location.href = nextUrl; }, 800);
					} else {
						setTimeout(() => { location.reload(); }, 800);
					}
				} else {
					const errorMsg = (response && response.error) ? response.error : 'Registration failed. Please try again.';
					showError(errorMsg);
				}
			},
			error: function(xhr, status, error) {
				console.error('Registration error:', status, error, xhr && xhr.responseText);
				let msg = 'Registration failed: ' + (error || status);
				try {
					const j = JSON.parse(xhr.responseText || '{}');
					if (j && j.error) msg = 'Registration failed: ' + j.error;
				} catch(_e){}
				showError(msg);
			},
			complete: function() {
				if ($btn) $btn.html(originalText).prop('disabled', false);
			}
		});
	}

	// Bind to existing form submit (if user presses Enter) - read pay from hidden input
	$('#newStudentForm').on('submit', function(e) {
		e.preventDefault();
		const payVal = $('#payInput').val();
		const pay = (typeof payVal !== 'undefined') ? (String(payVal) === '1') : true;
		submitRegistration(pay, null);
	});

	// New buttons - set hidden pay input and call registration
	$('#registerOnlyBtn').on('click', function() {
		$('#payInput').val('0');
		submitRegistration(false, this);
	});

	$('#registerAndPayBtn').on('click', function() {
		$('#payInput').val('1');
		submitRegistration(true, this);
	});

	// Preview Fees button: load courses and calculate fees without submitting
	$('#previewBtn').on('click', function() {
		const year = $('#academicYear').val() || '1';
		const semester = $('#semester').val() || '1';
		// Reload courses (this will call calculateFees on success)
		const $btn = $(this);
		const orig = $btn.html();
		$btn.html('<i class="fa fa-spinner fa-spin"></i> Loading...').prop('disabled', true);
		loadRequiredCourses(year, semester);
		// re-enable in callback paths; set a fallback timeout
		setTimeout(() => { $btn.html(orig).prop('disabled', false); }, 3500);
	});

	// Disable register buttons until courses are loaded
	window.coursesLoaded = false;
	function setRegisterButtonsEnabled(enabled) {
		$('#registerOnlyBtn, #registerAndPayBtn').prop('disabled', !enabled);
	}
	setRegisterButtonsEnabled(false);

	// Non-jQuery fallback: ensure buttons are clickable even if jQuery fails to load
	(function(){
		if (typeof window.jQuery !== 'undefined') return; // jQuery present, nothing to do
		try {
			var rBtn = document.getElementById('registerOnlyBtn');
			var pBtn = document.getElementById('registerAndPayBtn');
			var payInput = document.getElementById('payInput');
			var form = document.getElementById('newStudentForm');
			if (rBtn) rBtn.addEventListener('click', function(){ if(payInput) payInput.value='0'; form.submit(); });
			if (pBtn) pBtn.addEventListener('click', function(){ if(payInput) payInput.value='1'; form.submit(); });
		} catch(e) { /* ignore */ }
	})();

	// --------------------------------------
	// Helper functions
	// --------------------------------------
	function loadRequiredCourses(yearOfStudy, semester) {
		$('#requiredCoursesList').html('<tr><td colspan="4" class="text-center"><i class="fa fa-spinner fa-spin"></i> Loading courses...</td></tr>');
		// mark not loaded during fetch
		window.coursesLoaded = false;
		setRegisterButtonsEnabled(false);

		$.ajax({
			url: 'get_required_courses.php',
			method: 'POST',
			data: {
				year_of_study: yearOfStudy,
				semester: semester,
				is_transfer: $('#isTransferStudent').is(':checked') ? 1 : 0
			},
			dataType: 'json',
			success: function(response) {
				if (response && typeof response === 'object') {
					if (response.success && response.courses) {
						displayRequiredCourses(response.courses);
						calculateFees(response.courses);
						updateCreditSummary(response.courses);
						// enable register actions now that courses are present (unless already registered)
						window.coursesLoaded = true;
						if (!window.alreadyRegistered) setRegisterButtonsEnabled(true);
					} else if (response.error) {
						showError(response.error);
						displayEmptyCourses();
					} else {
						showError('Invalid response format from server');
						displayEmptyCourses();
					}
				} else {
					showError('Invalid response from server');
					displayEmptyCourses();
				}
			},
			error: function(xhr, status, error) {
				console.error('Course loading error:', status, error, xhr && xhr.responseText);
				let msg = 'Failed to load required courses. ';
				if (xhr.status === 0) {
					msg += 'Network error. Please check your connection.';
				} else if (xhr.status === 404) {
					msg += 'Server endpoint not found.';
				} else if (xhr.status >= 500) {
					msg += 'Server error. Please try again later.';
				} else {
					msg += 'Please try again.';
				}
				showError(msg);
				displayEmptyCourses();
			}
		});
	}

	function displayRequiredCourses(courses) {
		const tbody = $('#requiredCoursesList');
		tbody.empty();

		if (!courses || courses.length === 0) {
			tbody.append('\n\t\t\t<tr>\n\t\t\t\t<td colspan="4" class="text-center text-muted">No courses available for the selected year and semester</td>\n\t\t\t</tr>\n\t\t');
			$('#selectedCourses').val('');
			return;
		}

		courses.forEach(course => {
			const type = course.is_advanced === '1' ? 'Advanced' :
						course.is_laboratory === '1' ? 'Laboratory' : 'Core';

			tbody.append(`
				<tr>
					<td class="fw-bold text-primary">${course.course_code || 'N/A'}</td>
					<td>
						<div class="fw-bold">${course.course_name || 'Unnamed Course'}</div>
						<div class="small text-muted d-md-none">${course.credit_hours || 0} Credits | ${type}</div>
					</td>
					<td class="d-none d-md-table-cell"><span class="badge bg-light text-dark border">${course.credit_hours || 0}</span></td>
					<td class="d-none d-md-table-cell"><span class="badge ${getBadgeClass(type)}">${type}</span></td>
				</tr>
			`);
		});

		$('#selectedCourses').val(courses.map(c => c.course_code).join(','));
	}

	function displayEmptyCourses() {
		const tbody = $('#requiredCoursesList');
		tbody.html('\n\t\t<tr>\n\t\t\t<td colspan="4" class="text-center text-muted">No course data available</td>\n\t\t</tr>\n\t');
		$('#feeSummary').hide();
		$('#baseTuition').text('ZMW 0.00');
		$('#registrationFee').text('ZMW 0.00');
		$('#additionalFees').text('ZMW 0.00');
		$('#totalFees').text('ZMW 0.00');
		$('#creditSummary').text('0 courses selected');
		$('#creditProgress').css('width', '0%');
		$('#selectedCourses').val('');
		// mark courses as not loaded and disable register actions
		window.coursesLoaded = false;
		setRegisterButtonsEnabled(false);
	}

	function updateCreditSummary(courses) {
		if (!courses || courses.length === 0) {
			$('#creditSummary').text('0 courses selected');
			$('#creditProgress').css('width', '0%');
			return;
		}
		const totalCourses = courses.length;
		const maxCourses = $('#isTransferStudent').is(':checked') ? 8 : 6;
		const percentage = (totalCourses / maxCourses) * 100;
		$('#creditSummary').text(`${totalCourses} course${totalCourses !== 1 ? 's' : ''} selected`);
		$('#creditProgress').css('width', `${Math.min(percentage, 100)}%`);
	}

	function calculateFees(courses) {
		if (!courses || courses.length === 0) {
			$('#feeSummary').hide();
			return;
		}
		$.ajax({
			url: 'calculate_fees.php',
			method: 'POST',
			data: JSON.stringify({
				courses: courses.map(c => c.course_code),
				is_transfer: $('#isTransferStudent').is(':checked') ? 1 : 0
			}),
			contentType: 'application/json',
			dataType: 'json',
			success: function(response) {
				if (response && response.success) {
					displayFees(response.fees);
					$('#feeSummary').show();
				} else {
					const msg = (response && response.error) ? response.error : 'Failed to calculate fees';
					showError(msg);
					$('#feeSummary').hide();
				}
			},
			error: function(xhr, status, error) {
				console.error('Fee calculation error:', status, error, xhr && xhr.responseText);
				let msg = 'Failed to calculate fees. ';
				if (xhr.status === 0) {
					msg += 'Network error. Please check your connection.';
				} else {
					msg += 'Please try again.';
				}
				showError(msg);
				$('#feeSummary').hide();
			}
		});
	}

	function displayFees(fees) {
		if (!fees) {
			$('#baseTuition').text('ZMW 0.00');
			$('#registrationFee').text('ZMW 0.00');
			$('#additionalFees').text('ZMW 0.00');
			$('#totalFees').text('ZMW 0.00');
			return;
		}
		$('#baseTuition').text('ZMW ' + (fees.base_tuition || 0).toFixed(2));
		$('#registrationFee').text('ZMW ' + (fees.registration_fee || 0).toFixed(2));
		$('#additionalFees').text('ZMW ' + (fees.additional_fees || 0).toFixed(2));
		$('#totalFees').text('ZMW ' + (fees.total || 0).toFixed(2));
	}

	function getBadgeClass(type) {
		switch (type) {
			case 'Laboratory':
				return 'badge-lab';
			case 'Advanced':
				return 'badge-advanced';
			default:
				return 'badge-core';
		}
	}

	function showError(message) {
		$('#registrationError').text(message).show();
		$('#registrationSuccess').hide();
		setTimeout(() => { $('#registrationError').fadeOut(); }, 5000);
	}

	function showSuccess(message) {
		$('#registrationSuccess').text(message).show();
		$('#registrationError').hide();
		setTimeout(() => { $('#registrationSuccess').fadeOut(); }, 5000);
	}
});
