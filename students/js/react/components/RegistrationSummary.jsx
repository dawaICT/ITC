import React, { useMemo } from 'react';

const RegistrationSummary = ({ selectedCourses, maxCredits = 24 }) => {
  // Calculate estimated credits (assuming 3 credits per course for display purposes)
  const estimatedCredits = useMemo(() => {
    return selectedCourses.length * 3;
  }, [selectedCourses]);
  
  // Determine if credits exceed maximum
  const isOverCredits = estimatedCredits > maxCredits;
  
  return (
    <div className={`alert ${isOverCredits ? 'alert-danger' : 'alert-info'} mt-3`}>
      <div className="d-flex justify-content-between align-items-center">
        <div>
          <strong>Summary:</strong> {selectedCourses.length} course(s) selected
          {maxCredits && (
            <span> (estimated {estimatedCredits} credits)</span>
          )}
        </div>
        
        {maxCredits && (
          <div>
            <strong>Maximum credits allowed:</strong> {maxCredits}
            {isOverCredits && (
              <span className="ms-2 badge bg-danger">Exceeds limit</span>
            )}
          </div>
        )}
      </div>
      
      {selectedCourses.length > 0 && (
        <div className="mt-2">
          <strong>Selected courses:</strong> {selectedCourses.join(', ')}
        </div>
      )}
    </div>
  );
};

export default RegistrationSummary;
