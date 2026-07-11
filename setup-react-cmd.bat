@echo off
echo Setting up React for WUC Portal using Command Prompt...

echo Installing dependencies...
cmd /c npm install

echo Building React components...
cmd /c npm run build

echo Setup complete!
echo To run development mode with auto-rebuilding, use: cmd /c npm run dev
pause
