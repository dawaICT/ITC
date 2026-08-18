# WUC Portal React Implementation

This guide explains how to set up and use the React components for the WUC Portal system.

## Prerequisites

- Node.js 14+ and npm installed
- A working XAMPP/WAMP/LAMP installation

## Setup Instructions

1. **Install Dependencies**:
   
   On Windows:
   ```bash
   .\setup-react.bat
   ```
   
   On Mac/Linux:
   ```bash
   chmod +x setup-react.sh
   ./setup-react.sh
   ```
   
   Alternatively, run these commands manually:
   ```bash
   npm install
   npm run build
   ```

2. **Development Mode**:
   
   If you're actively developing and want automatic rebuilds:
   ```bash
   npm run dev
   ```

## Implemented Features

The React implementation includes these enhanced features:

1. **Modern UI Components**:
   - Responsive form elements
   - Improved multi-select for courses
   - Better validation feedback

2. **Enhanced Submit Button**:
   - Loading state indicators
   - Improved error handling
   - Better user feedback

3. **Course Registration Workflow**:
   - Preview fees before registration
   - Confirmation modal
   - Live validation

## File Structure

- `students/js/react/`: Main React code
  - `courseRegistration.js`: Entry point
  - `components/`: React components
- `students/dist/js/`: Compiled JavaScript bundles
- `webpack.config.js`: Webpack configuration
- `.babelrc`: Babel configuration

## Fallback Mechanism

The implementation includes a fallback to the original PHP forms if React fails to load.

## API Endpoints

The React components interact with these PHP APIs:

- `api_get_eligibility.php`: Get student eligibility data
- `api_get_courses.php`: Get available courses
- `calculate_fees.php`: Calculate registration fees
- `processCourseReg.php`: Process course registration

## Troubleshooting

If you encounter issues:

1. Check the browser console for JavaScript errors
2. Ensure npm installed all dependencies correctly
3. Verify the compiled bundle exists at `students/dist/js/courseRegistration.bundle.js`
4. Try clearing browser cache
5. If React fails, the system will fall back to the original PHP implementation

## Adding New Components

To add new React components:

1. Create component files in `students/js/react/components/`
2. Import them where needed
3. Run `npm run build` to rebuild the bundle

## Security Considerations

The React implementation maintains the same security measures as the original:

- All API endpoints include proper authentication checks
- Server-side validation is still performed
- CSRF protection is maintained
