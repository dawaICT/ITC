# React Debug Guide for WUC Portal

## Issue Fixed: PowerShell Execution Policy

We've implemented several workarounds for the PowerShell execution policy issue:

1. **Created setup-react-cmd.bat**: This batch file uses Command Prompt instead of PowerShell to bypass the execution policy restriction.
   
   Usage:
   ```
   double-click setup-react-cmd.bat
   ```

2. **Created a Fallback React Implementation**: The courseReg.php page now includes:
   - CDN links to React, ReactDOM, and Babel for direct in-browser transpilation
   - A simple React component directly embedded in the page
   - Logic to detect if the bundle loaded and fall back to the embedded component

3. **Restored Legacy Form**: The legacy PHP form will remain visible if React fails to load.

## How to Debug

1. **Check React Setup**: 
   - Visit: http://localhost/wucportal/students/check_react_setup.php
   - This page tests if React is working and checks for required files

2. **Examine Browser Console**: 
   - Open your browser's developer tools (F12)
   - Check for JavaScript errors in the console
   - Look for "Using fallback React implementation" message

3. **Build React Bundle**:
   - Run Command Prompt (not PowerShell)
   - Navigate to project root: `cd C:\xampp\htdocs\wucportal`
   - Install dependencies: `npm install`
   - Build the bundle: `npm run build`

## Files Modified

- **courseReg.php**: Added inline React implementation with fallback behavior
- **check_react_setup.php**: New diagnostic tool
- **setup-react-cmd.bat**: Command Prompt version of setup script

## Next Steps

1. Use Command Prompt to run npm commands
2. Build the React bundle
3. If you continue experiencing issues, use the inline React implementation which works without building
4. For a permanent solution, consider:
   - Changing PowerShell execution policy (requires admin privileges)
   - Using a different Node.js installation method
   - Using a different development environment
