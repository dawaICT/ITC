@echo off
echo =========================================================
echo  WUC Portal Local AI Service Mock (Port 11434)
echo =========================================================
echo.
echo Starting built-in PHP web server on http://localhost:11434...
echo.
echo Press Ctrl+C to stop.
echo.
"C:\xampp\php\php.exe" -S localhost:11434 ai/mock_server.php
pause
