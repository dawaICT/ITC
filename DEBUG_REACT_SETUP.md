# Debugging React Setup for WUC Portal

## Issue 1: PowerShell Execution Policy

The error message indicates that PowerShell's execution policy is restricting npm from running:

```
npm : File C:\Program Files\nodejs\npm.ps1 cannot be loaded because running scripts is disabled on this system.
```

### Solutions:

#### Option 1: Temporarily change PowerShell execution policy (run as Administrator):
```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
```
Then try running npm commands in the same PowerShell window.

#### Option 2: Use Command Prompt instead of PowerShell:
1. Open Command Prompt (cmd.exe)
2. Navigate to your project directory:
   ```
   cd C:\xampp\htdocs\wucportal
   ```
3. Run npm commands there:
   ```
   npm install
   npm run build
   ```

#### Option 3: Use the Windows Terminal with Command Prompt profile

## Issue 2: Ensuring React Components Render Correctly

If you're having issues with the React components not rendering:

1. Check if the bundle file exists at `students/dist/js/courseRegistration.bundle.js`
2. Open your browser's developer console (F12) to check for JavaScript errors
3. Verify that the mount point exists in the DOM:
   ```html
   <div id="react-course-registration" ...></div>
   ```

## Issue 3: Data Passing From PHP to React

If your React components aren't receiving the correct data:

1. Verify the data attributes on the mount point:
   ```php
   data-student-id="<?php echo htmlspecialchars((string)($_SESSION['Sid'] ?? '')); ?>"
   ```
2. Check the console for any parsing errors in `courseRegistration.js`

## Temporary Development Solution

Until you can resolve the npm execution issue, you can use this approach:

1. Use an online tool like [Babel REPL](https://babeljs.io/repl) to manually transpile React components
2. Create the bundle file manually by combining the transpiled components
3. Place the bundle file in `students/dist/js/courseRegistration.bundle.js`

## Minimal React Implementation

For a quick test that doesn't require npm, create a minimal implementation:

1. Add these script tags to courseReg.php right before `</body>`:
   ```html
   <script src="https://unpkg.com/react@18/umd/react.development.js"></script>
   <script src="https://unpkg.com/react-dom@18/umd/react-dom.development.js"></script>
   <script src="https://unpkg.com/babel-standalone@6/babel.min.js"></script>
   <script type="text/babel">
     // Simple React component
     const CourseRegistrationApp = () => {
       const [selectedCourses, setSelectedCourses] = React.useState([]);
       
       // Get data from the mount point
       const mountPoint = document.getElementById('react-course-registration');
       const studentId = mountPoint?.dataset.studentId || '';
       const semester = mountPoint?.dataset.semester || '';
       const year = mountPoint?.dataset.year || '';
       
       return (
         <div className="card mb-4">
           <div className="card-header bg-primary text-white">
             <h5 className="card-title mb-0">Course Registration</h5>
           </div>
           <div className="card-body">
             <div className="alert alert-info">
               Student ID: {studentId}, Semester: {semester}, Year: {year}
             </div>
             <button className="btn btn-primary">
               Register Courses
             </button>
           </div>
         </div>
       );
     };
     
     // Render React component
     ReactDOM.createRoot(
       document.getElementById('react-course-registration')
     ).render(<CourseRegistrationApp />);
   </script>
   ```

## Fallback Solution

If you can't get React working, modify courseReg.php to remove the React implementation:
   
```php
<!-- Change this line -->
<form action="processCourseReg.php" method="POST" role="form" onsubmit="return validateBeforeSubmit();" style="display: none;">

<!-- To this -->
<form action="processCourseReg.php" method="POST" role="form" onsubmit="return validateBeforeSubmit();">
```

This will restore the original PHP form functionality.
