<?php
// Start session immediately
if (session_status() === PHP_SESSION_NONE) { 
    session_start();
}

// Refresh session timestamp immediately
$_SESSION['last_activity'] = time();

// Check authentication early
if (!isset($_SESSION['Sid'])) {
    header('Location: studentLogout.php?expired=1&return=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Set up data for React without heavy queries
$sid = isset($_SESSION['Sid']) ? (string)$_SESSION['Sid'] : '';
$sessionTimeout = 300; // 5 minutes in seconds
$lastActivity = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : time();
$remainingTime = $sessionTimeout - (time() - $lastActivity);

// Generate CSRF token for form security
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Include minimal required files
require_once __DIR__ . '/../db/connect.php';

// Get minimal student data for React
$studentData = [
    'id' => $sid,
    'session_time_remaining' => $remainingTime
];

// Get latest semester registration
if ($sid && $db) {
    $query = "SELECT semester, Year, program_code FROM semester_registration 
              WHERE Sid=? ORDER BY id DESC LIMIT 1";
    $stmt = $db->prepare($query);
    if ($stmt) {
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $studentData['semester'] = $row['semester'];
            $studentData['year'] = $row['Year'];
            $studentData['program_code'] = $row['program_code'];
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Registration - React</title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    
    <!-- CSRF Token for Ajax Security -->
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    
    <!-- React and React-DOM -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js" crossorigin></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js" crossorigin></script>
    
    <style>
        /* Additional styles for the React app */
        .course-item {
            padding: 8px;
            margin-bottom: 4px;
            border-radius: 4px;
        }
        .course-item:hover {
            background-color: #f8f9fa;
        }
        .course-item.selected {
            background-color: #e9ecef;
        }
        .course-item.mandatory {
            background-color: #f8d7da;
        }
        .timer-warning {
            color: #dc3545;
        }
        .session-timer {
            font-size: 0.9rem;
            padding: 8px;
            border-radius: 4px;
            background-color: #f8f9fa;
            margin-bottom: 16px;
        }
    </style>
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <div class="content-wrapper">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <!-- React root element -->
                    <div id="course-registration-app" 
                        data-student="<?= htmlspecialchars(json_encode($studentData)) ?>"
                        data-csrf="<?= htmlspecialchars($csrfToken) ?>">
                        <!-- React app will render here -->
                        <div class="text-center p-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-3">Loading course registration...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
    
    <script>
    // React Application using JSX-like syntax with createElement
    document.addEventListener('DOMContentLoaded', function() {
        const e = React.createElement;
        const { useState, useEffect, useCallback, useRef } = React;
        
        // Get student data from data attribute
        const appElement = document.getElementById('course-registration-app');
        const studentDataStr = appElement.getAttribute('data-student');
        const csrfToken = appElement.getAttribute('data-csrf');
        
        let studentData = { id: '', semester: '', year: '', program_code: '', session_time_remaining: 300 };
        
        try {
            const parsedData = JSON.parse(studentDataStr);
            studentData = { ...studentData, ...parsedData };
        } catch (err) {
            console.error('Failed to parse student data:', err);
        }
        
        // Session Timer Component
        const SessionTimer = ({ remainingTime, onTimeout }) => {
            const [timeLeft, setTimeLeft] = useState(remainingTime);
            const timerRef = useRef(null);
            
            useEffect(() => {
                timerRef.current = setInterval(() => {
                    setTimeLeft(prev => {
                        if (prev <= 1) {
                            clearInterval(timerRef.current);
                            onTimeout();
                            return 0;
                        }
                        return prev - 1;
                    });
                }, 1000);
                
                return () => clearInterval(timerRef.current);
            }, [onTimeout]);
            
            // Refresh session every 4 minutes
            useEffect(() => {
                const keepAliveInterval = setInterval(() => {
                    fetch('api_get_eligibility.php?keepAlive=1&_=' + Date.now(), {
                        credentials: 'same-origin',
                        cache: 'no-store'
                    }).then(res => res.json())
                      .then(data => {
                          if (data.success && data.time_remaining) {
                              setTimeLeft(data.time_remaining);
                          }
                      })
                      .catch(err => console.warn('Failed to refresh session:', err));
                }, 240000); // 4 minutes
                
                return () => clearInterval(keepAliveInterval);
            }, []);
            
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            const isWarning = timeLeft < 60;
            
            return e('div', { className: 'session-timer' + (isWarning ? ' timer-warning' : '') },
                e('strong', null, 'Session timeout: '),
                e('span', null, 
                    `${minutes}:${seconds.toString().padStart(2, '0')}`
                ),
                isWarning ? e('span', null, ' (Refreshing soon)') : null
            );
        };
        
        // Main Course Registration Component
        const CourseRegistration = () => {
            const [loading, setLoading] = useState(true);
            const [courses, setCourses] = useState([]);
            const [selectedCourses, setSelectedCourses] = useState([]);
            const [mandatoryCourses, setMandatoryCourses] = useState([]);
            const [error, setError] = useState(null);
            const [submitting, setSubmitting] = useState(false);
            const [success, setSuccess] = useState(null);
            
            // Handle session timeout
            const handleTimeout = useCallback(() => {
                alert('Your session is about to expire. The page will refresh to keep your session active.');
                window.location.reload();
            }, []);
            
            // Load courses on component mount
            useEffect(() => {
                if (!studentData.semester || !studentData.year || !studentData.program_code) {
                    setLoading(false);
                    setError('Missing semester registration data. Please complete semester registration first.');
                    return;
                }
                
                const loadCourses = async () => {
                    try {
                        // Get eligibility data and failed courses
                        const eligibilityRes = await fetch(`api_get_eligibility.php?semester=${studentData.semester}&Year=${studentData.year}&_=${Date.now()}`, {
                            credentials: 'same-origin'
                        });
                        const eligibilityData = await eligibilityRes.json();
                        
                        // Get courses for this term
                        const coursesRes = await fetch(`api_get_courses.php?sid=${studentData.id}&semester=${studentData.semester}&year=${studentData.year}&_=${Date.now()}`, {
                            credentials: 'same-origin'
                        });
                        const coursesData = await coursesRes.json();
                        
                        if (coursesData.success && coursesData.courses) {
                            setCourses(coursesData.courses);
                            
                            // Set preselected courses
                            if (coursesData.registered && coursesData.registered.length > 0) {
                                setSelectedCourses(coursesData.registered);
                            }
                            
                            // Handle mandatory courses for repeat semester
                            if (eligibilityData.success && eligibilityData.data) {
                                const { flags, offered_failed } = eligibilityData.data;
                                if (flags && flags.repeat_semester && offered_failed) {
                                    const mandatoryCodes = offered_failed.map(c => c.course_code);
                                    setMandatoryCourses(mandatoryCodes);
                                    
                                    // Ensure mandatory courses are selected
                                    setSelectedCourses(prev => {
                                        const newSelection = [...prev];
                                        mandatoryCodes.forEach(code => {
                                            if (!newSelection.includes(code)) {
                                                newSelection.push(code);
                                            }
                                        });
                                        return newSelection;
                                    });
                                }
                            }
                        } else {
                            setError('Failed to load courses for your term.');
                        }
                    } catch (err) {
                        setError('Error loading course data: ' + err.message);
                    } finally {
                        setLoading(false);
                    }
                };
                
                loadCourses();
            }, []);
            
            // Handle course selection
            const toggleCourse = (courseCode) => {
                // Don't allow deselecting mandatory courses
                if (mandatoryCourses.includes(courseCode) && selectedCourses.includes(courseCode)) {
                    return;
                }
                
                setSelectedCourses(prev => {
                    if (prev.includes(courseCode)) {
                        return prev.filter(c => c !== courseCode);
                    } else {
                        return [...prev, courseCode];
                    }
                });
            };
            
            // Handle form submission
            const handleSubmit = async (e) => {
                e.preventDefault();
                
                if (selectedCourses.length === 0) {
                    alert('Please select at least one course before submitting.');
                    return;
                }
                
                setSubmitting(true);
                setError(null);
                
                try {
                    // Create a form submission that will work with PHP sessions
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'process_direct_submit.php'; 
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
                    addField('Sid', studentData.id);
                    addField('semester', studentData.semester);
                    addField('Year', studentData.year);
                    addField('react_submission', '1');
                    addField('csrf_token', csrfToken);
                    
                    // Add selected courses
                    selectedCourses.forEach(course => {
                        addField('course_code[]', course);
                    });
                    
                    // Add the form to the document and submit it
                    document.body.appendChild(form);
                    form.submit();
                } catch (err) {
                    setError('Error submitting registration: ' + err.message);
                    setSubmitting(false);
                }
            };
            
            return e('div', { className: 'card' },
                e('div', { className: 'card-header bg-primary text-white' },
                    e('h4', { className: 'mb-0' }, 'Course Registration (React)')
                ),
                e('div', { className: 'card-body' },
                    e(SessionTimer, { remainingTime: studentData.session_time_remaining, onTimeout: handleTimeout }),
                    
                    success && e('div', { className: 'alert alert-success' }, success),
                    error && e('div', { className: 'alert alert-danger' }, error),
                    
                    !loading && !studentData.semester && 
                        e('div', { className: 'alert alert-warning' }, 
                          'No active semester registration found. Please complete semester registration first.'),
                    
                    !loading && courses.length === 0 && !error && 
                        e('div', { className: 'alert alert-warning' }, 
                          'No courses found for your registered term.'),
                    
                    loading ? 
                        e('div', { className: 'text-center p-4' },
                            e('div', { className: 'spinner-border text-primary' })
                        ) :
                        
                        e('form', { onSubmit: handleSubmit },
                            // Student info
                            e('div', { className: 'row mb-3' },
                                e('div', { className: 'col-md-4' },
                                    e('div', { className: 'form-group' },
                                        e('label', null, 'Student ID:'),
                                        e('input', { 
                                            type: 'text',
                                            className: 'form-control',
                                            value: studentData.id,
                                            readOnly: true
                                        })
                                    )
                                ),
                                e('div', { className: 'col-md-4' },
                                    e('div', { className: 'form-group' },
                                        e('label', null, 'Semester:'),
                                        e('input', { 
                                            type: 'text',
                                            className: 'form-control',
                                            value: studentData.semester,
                                            readOnly: true
                                        })
                                    )
                                ),
                                e('div', { className: 'col-md-4' },
                                    e('div', { className: 'form-group' },
                                        e('label', null, 'Year:'),
                                        e('input', { 
                                            type: 'text',
                                            className: 'form-control',
                                            value: studentData.year,
                                            readOnly: true
                                        })
                                    )
                                )
                            ),
                            
                            // Mandatory courses warning
                            mandatoryCourses.length > 0 && 
                                e('div', { className: 'alert alert-danger' },
                                    e('strong', null, 'Repeat Semester Required: '),
                                    'You have failed courses that must be retaken.'
                                ),
                            
                            // Course selection
                            e('div', { className: 'form-group mb-3' },
                                e('label', null, 'Select Courses:'),
                                e('div', { 
                                    className: 'border rounded p-2', 
                                    style: { maxHeight: '300px', overflowY: 'auto' } 
                                },
                                    courses.map(course => {
                                        const isMandatory = mandatoryCourses.includes(course.course_code);
                                        const isSelected = selectedCourses.includes(course.course_code);
                                        
                                        return e('div', {
                                            key: course.course_code,
                                            className: `course-item d-flex align-items-center ${isMandatory ? 'mandatory' : ''} ${isSelected ? 'selected' : ''}`,
                                            onClick: () => toggleCourse(course.course_code)
                                        },
                                            e('div', { className: 'form-check' },
                                                e('input', {
                                                    type: 'checkbox',
                                                    className: 'form-check-input',
                                                    checked: isSelected,
                                                    readOnly: true
                                                }),
                                                e('label', {
                                                    className: `form-check-label ${isMandatory ? 'fw-bold text-danger' : ''}`
                                                }, 
                                                    course.course_code,
                                                    course.course_name && e('span', { className: 'ms-2 text-muted' }, 
                                                        `- ${course.course_name}`
                                                    ),
                                                    isMandatory && e('span', { className: 'badge bg-danger ms-2' }, 
                                                        'Required'
                                                    )
                                                )
                                            )
                                        );
                                    })
                                )
                            ),
                            
                            // Course summary
                            selectedCourses.length > 0 && 
                                e('div', { className: 'alert alert-info mb-3' },
                                    e('strong', null, `${selectedCourses.length} course(s) selected`)
                                ),
                            
                            // Submit button
                            e('div', { className: 'd-grid gap-2' },
                                e('button', {
                                    type: 'submit',
                                    className: 'btn btn-primary',
                                    disabled: submitting || courses.length === 0 || selectedCourses.length === 0
                                }, 
                                    submitting ? 
                                        [
                                            e('span', { 
                                                className: 'spinner-border spinner-border-sm me-2',
                                                key: 'spinner'
                                            }),
                                            'Submitting...'
                                        ] : 
                                        [
                                            e('i', { 
                                                className: 'fas fa-save me-2',
                                                key: 'icon'
                                            }),
                                            'Register Courses'
                                        ]
                                )
                            )
                        )
                )
            );
        };
        
        // Render React app
        const root = ReactDOM.createRoot(document.getElementById('course-registration-app'));
        root.render(e(CourseRegistration));
    });
    </script>
</body>
</html>

