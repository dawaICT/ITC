@echo off
echo Setting up React for WUC Portal...

echo Installing dependencies...
call npm install

echo Building React components...
call npm run build

echo Setup complete!
echo To run development mode with auto-rebuilding, use: npm run dev
pause
