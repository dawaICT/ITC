@echo off
REM Quick Setup Script for Course Registration & Fee Tracking
REM Run this from the lecturers directory

echo ================================================================
echo  COURSE REGISTRATION TABLE SETUP
echo  WUC Portal - CA Upload Module
echo ================================================================
echo.

echo Step 1: Running migration to create/update course_registration table...
echo.
php ensure_course_registration_table.php
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo ERROR: Migration failed. Please check the error messages above.
    pause
    exit /b 1
)

echo.
echo ================================================================
echo.
echo Step 2: Testing fee eligibility validation...
echo.
php test_fee_eligibility.php
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo ERROR: Tests failed. Please review the output above.
    pause
    exit /b 1
)

echo.
echo ================================================================
echo.
echo Step 3: Do you want to generate sample test data? (Y/N)
set /p GENERATE_SAMPLE="Enter choice: "

if /i "%GENERATE_SAMPLE%"=="Y" (
    echo.
    echo Generating sample course registration data...
    php sample_course_registration_data.php
)

echo.
echo ================================================================
echo  SETUP COMPLETE!
echo ================================================================
echo.
echo Next steps:
echo   1. Open your browser and navigate to:
echo      http://localhost/wucportal/lecturers/view_course_registrations.php
echo.
echo   2. Review the registration statistics and recent registrations
echo.
echo   3. Test CA upload at:
echo      http://localhost/wucportal/lecturers/upload_ca.php
echo.
echo Documentation: See COURSE_REGISTRATION_SETUP.md for details
echo.
pause
