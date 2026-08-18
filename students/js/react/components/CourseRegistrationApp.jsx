import React, { useState, useEffect } from 'react';
import CourseSelector from './CourseSelector';
import RegistrationSummary from './RegistrationSummary';
import PreviewModal from './PreviewModal';
import axios from 'axios';

const CourseRegistrationApp = ({
  studentId,
  semester,
  year,
  programCode,
  availableCourses,
  preselectedCourses
}) => {
  // State for selected courses
  const [selectedCourses, setSelectedCourses] = useState(preselectedCourses || []);
  
  // State for eligibility data
  const [eligibilityData, setEligibilityData] = useState({
    flags: {},
    failedCourses: [],
    offeredFailed: [],
    credits: { max_allowed: 24 }
  });
  
  // State for fee preview
  const [feePreview, setFeePreview] = useState({
    base_tuition: 0,
    registration_fee: 0,
    additional_fees: 0,
    total: 0,
    loading: false,
    error: null
  });
  
  // State for modal
  const [isModalOpen, setIsModalOpen] = useState(false);
  
  // State for registration status
  const [registrationStatus, setRegistrationStatus] = useState({
    loading: false,
    success: null,
    error: null
  });

  // Load eligibility data
  useEffect(() => {
    if (semester && year) {
      fetch(`api_get_eligibility.php?semester=${encodeURIComponent(semester)}&Year=${encodeURIComponent(year)}`, {
        credentials: 'same-origin'
      })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            setEligibilityData({
              flags: data.data?.flags || {},
              failedCourses: data.data?.failed_courses || [],
              offeredFailed: data.data?.offered_failed || [],
              credits: data.data?.credits || { max_allowed: 24 }
            });
            
            // Pre-select failed courses if they're required
            if (data.data?.flags?.repeat_semester && data.data?.offered_failed) {
              const mandatoryCourses = data.data.offered_failed.map(course => course.course_code);
              setSelectedCourses(prevSelected => {
                const newSelection = [...prevSelected];
                mandatoryCourses.forEach(courseCode => {
                  if (!newSelection.includes(courseCode)) {
                    newSelection.push(courseCode);
                  }
                });
                return newSelection;
              });
            }
          }
        })
        .catch(error => {
          console.error('Error fetching eligibility data:', error);
        });
    }
  }, [semester, year]);

  // Handle course selection change
  const handleCourseChange = (selectedOptions) => {
    const selectedValues = selectedOptions.map(option => option.value);
    
    // If repeat semester, ensure failed courses remain selected
    if (eligibilityData.flags.repeat_semester) {
      const mandatoryCourses = eligibilityData.offeredFailed.map(course => course.course_code);
      mandatoryCourses.forEach(courseCode => {
        if (!selectedValues.includes(courseCode)) {
          selectedValues.push(courseCode);
        }
      });
    }
    
    setSelectedCourses(selectedValues);
  };

  // Preview fees before registration
  const previewFees = async () => {
    if (selectedCourses.length === 0) {
      alert('Please select at least one course before previewing.');
      return;
    }
    
    setFeePreview(prev => ({ ...prev, loading: true, error: null }));
    
    try {
      const response = await axios.post('calculate_fees.php', {
        courses: selectedCourses,
        is_transfer: false
      });
      
      if (response.data.success) {
        setFeePreview({
          ...response.data.fees,
          loading: false,
          error: null
        });
        setIsModalOpen(true);
      } else {
        setFeePreview(prev => ({
          ...prev,
          loading: false,
          error: response.data.error || 'Failed to preview fees'
        }));
      }
    } catch (error) {
      setFeePreview(prev => ({
        ...prev,
        loading: false,
        error: 'Network error occurred while calculating fees'
      }));
    }
  };

  // Submit registration
  const submitRegistration = async () => {
    if (selectedCourses.length === 0) {
      alert('Please select at least one course before registering.');
      return;
    }
    
    setRegistrationStatus({
      loading: true,
      success: null,
      error: null
    });
    
    // First ping the server to ensure session is active
    try {
      // Keep session alive before submitting
      const pingResp = await fetch('api_get_eligibility.php?keepAlive=1&pre_submit=1&_=' + new Date().getTime(), {
        credentials: 'same-origin',
        cache: 'no-store'
      });
      
      // Check for session errors in the ping response
      if (!pingResp.ok) {
        const data = await pingResp.json();
        if (data && !data.success) {
          console.error('Session validation failed:', data.message);
          if (data.session_expired) {
            // Session expired - redirect to login
            alert('Your session has expired. You will be redirected to login.');
            window.location.href = 'studentLogout.php?expired=1&return=' + encodeURIComponent('courseReg.php');
            return;
          }
        }
      }
    } catch (pingError) {
      console.warn('Session ping failed:', pingError);
      // Continue with submission even if ping fails
    }
    
    // Use a simple form submission approach rather than AJAX
    // This avoids most session issues by using the browser's built-in cookie handling
    try {
      // Create a hidden form
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = 'process_direct_submit.php'; // Use our reliable direct submission handler
      form.style.display = 'none';
      
      // Add form fields
      const addField = (name, value) => {
        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = name;
        field.value = value;
        form.appendChild(field);
      };
      
      // Add required fields
      addField('direct_submission', '1');
      addField('Sid', studentId);
      addField('semester', semester);
      addField('Year', year);
      addField('react_submission', '1');
      const csrfFromDom = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('[data-csrf]')?.getAttribute('data-csrf')
        || '';
      if (csrfFromDom) {
        addField('csrf_token', csrfFromDom);
      }
      
      // Add selected courses
      selectedCourses.forEach(course => {
        addField('course_code[]', course);
      });
      
      // Add the form to the document and submit it
      document.body.appendChild(form);
      
      console.log('Submitting form directly to avoid session issues');
      form.submit();
      
      // Don't update state or redirect - the form submission will handle that
      return;
      
    } catch (formError) {
      console.error('Form submission error:', formError);
      
      // Fall back to AJAX if form submission fails
      console.log('Falling back to AJAX submission');
      fallbackToAjax();
    }
    
    // Fallback to AJAX approach
    async function fallbackToAjax() {
      const formData = new FormData();
      formData.append('Sid', studentId);
      formData.append('semester', semester);
      formData.append('Year', year);
      formData.append('direct_submission', '1');
      formData.append('react_submission', '1');
      const csrfFromDom = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('[data-csrf]')?.getAttribute('data-csrf')
        || '';
      if (csrfFromDom) {
        formData.append('csrf_token', csrfFromDom);
      }
      selectedCourses.forEach(course => {
        formData.append('course_code[]', course);
      });
      
      try {
        // Add a timestamp to prevent caching issues
        const timestamp = new Date().getTime();
        const response = await axios.post(`process_direct_submit.php?_=${timestamp}`, formData, {
          // Ensure cookies are sent with request to maintain session
          withCredentials: true,
          headers: {
            'Cache-Control': 'no-cache, no-store, max-age=0, must-revalidate',
            'X-Requested-With': 'XMLHttpRequest'
          },
          // Don't follow redirects - handle them manually
          maxRedirects: 0,
          validateStatus: function(status) {
            return status >= 200 && status < 600; // Accept all status codes
          }
        });
        
        console.log('AJAX response:', response.status, response.headers);
        
        // Check if response is a redirect to fees page
        const locationHeader = response.headers?.location;
        if ((response.status === 302 || response.status === 301) && locationHeader) {
          console.log('Handling redirect to:', locationHeader);
          window.location.href = locationHeader;
          return;
        }
        
        if (response.data && response.data.success) {
          setRegistrationStatus({
            loading: false,
            success: 'Courses registered successfully!',
            error: null
          });
          
          // Close modal if open
          setIsModalOpen(false);
          
          // Check if response contains an invoice number and redirect to fees page
          if (response.data.invoice_number) {
            window.location.href = `fees.php?invoice=${response.data.invoice_number}`;
            return;
          }
          
          // Redirect to fees page as a fallback
          window.location.href = 'fees.php';
        } else {
          setRegistrationStatus({
            loading: false,
            success: null,
            error: response.data?.error || 'Registration failed'
          });
        }
      } catch (error) {
        // Check if it's a redirect
        if (error.response && (error.response.status === 301 || error.response.status === 302)) {
          const redirectUrl = error.response.headers?.location;
          if (redirectUrl) {
            console.log('Redirect from error response:', redirectUrl);
            window.location.href = redirectUrl;
            return;
          }
        }
        
        console.error('AJAX registration error:', error);
        
        // Show error and offer alternative
        const errorMsg = error.response?.data?.error || error.message || 'Network error occurred during registration';
        setRegistrationStatus({
          loading: false,
          success: null,
          error: errorMsg + ' - Please try using the direct submission form instead.'
        });
        
        // Add a button to try the direct submission form
        const errorDiv = document.getElementById('registration-error');
        if (errorDiv) {
          const directLink = document.createElement('a');
          directLink.href = 'direct_course_submit.php';
          directLink.className = 'btn btn-warning mt-3';
          directLink.textContent = 'Try Alternative Submission Form';
          errorDiv.appendChild(directLink);
        }
      }
    }
  };

  return (
    <div className="course-registration-container">
      {registrationStatus.success && (
        <div className="alert alert-success">{registrationStatus.success}</div>
      )}
      
      {registrationStatus.error && (
        <div id="registration-error" className="alert alert-danger">
          {registrationStatus.error}
        </div>
      )}
      
      <div className="card mb-4">
        <div className="card-header bg-primary text-white">
          <h5 className="card-title mb-0">Course Registration</h5>
        </div>
        <div className="card-body">
          <div className="row mb-3">
            <div className="col-md-6">
              <div className="form-group">
                <label htmlFor="studentId">Student ID:</label>
                <input 
                  type="text" 
                  className="form-control" 
                  id="studentId" 
                  value={studentId} 
                  readOnly 
                />
              </div>
            </div>
            <div className="col-md-6">
              <div className="form-group">
                <label htmlFor="programCode">Program Code:</label>
                <input 
                  type="text" 
                  className="form-control" 
                  id="programCode" 
                  value={programCode} 
                  readOnly 
                />
              </div>
            </div>
          </div>
          
          <div className="row mb-3">
            <div className="col-md-6">
              <div className="form-group">
                <label htmlFor="semester">Semester:</label>
                <input 
                  type="text" 
                  className="form-control" 
                  id="semester" 
                  value={semester} 
                  readOnly 
                />
              </div>
            </div>
            <div className="col-md-6">
              <div className="form-group">
                <label htmlFor="year">Year:</label>
                <input 
                  type="text" 
                  className="form-control" 
                  id="year" 
                  value={year} 
                  readOnly 
                />
              </div>
            </div>
          </div>
          
          {eligibilityData.flags.repeat_semester && (
            <div className="alert alert-danger">
              <strong>Repeat Semester Required:</strong> You have {eligibilityData.failedCourses.length} failed course(s) that must be retaken.
            </div>
          )}
          
          {eligibilityData.flags.auto_append_failed && eligibilityData.offeredFailed.length > 0 && (
            <div className="alert alert-warning">
              <strong>Note:</strong> {eligibilityData.offeredFailed.length} failed course(s) have been automatically added to your selection.
            </div>
          )}
          
          <CourseSelector 
            availableCourses={availableCourses}
            selectedCourses={selectedCourses}
            onChange={handleCourseChange}
            mandatoryCourses={eligibilityData.flags.repeat_semester ? 
              eligibilityData.offeredFailed.map(c => c.course_code) : []}
          />
          
          <RegistrationSummary 
            selectedCourses={selectedCourses}
            maxCredits={eligibilityData.credits.max_allowed}
          />
        </div>
        <div className="card-footer">
          <div className="d-flex gap-2">
            <button 
              className="btn btn-secondary" 
              onClick={previewFees}
              disabled={registrationStatus.loading || selectedCourses.length === 0}
            >
              Preview & Confirm
            </button>
            <button 
              className="btn btn-primary" 
              onClick={submitRegistration}
              disabled={registrationStatus.loading || selectedCourses.length === 0}
            >
              {registrationStatus.loading ? 'Registering...' : 'Register Courses'}
            </button>
            <a
              className="btn btn-outline-secondary"
              href="continuousAssessment.php"
            >
              View CA
            </a>
          </div>
        </div>
      </div>
      
      <PreviewModal 
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        onConfirm={submitRegistration}
        studentId={studentId}
        semester={semester}
        year={year}
        selectedCourses={selectedCourses}
        feePreview={feePreview}
        isLoading={registrationStatus.loading}
      />
    </div>
  );
};

export default CourseRegistrationApp;

