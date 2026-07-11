import React from 'react';

const PreviewModal = ({ 
  isOpen, 
  onClose, 
  onConfirm, 
  studentId, 
  semester, 
  year, 
  selectedCourses,
  feePreview,
  isLoading
}) => {
  if (!isOpen) return null;

  const formatCurrency = (amount) => {
    return parseFloat(amount || 0).toFixed(2);
  };
  
  return (
    <div className="modal fade show" style={{ display: 'block' }} tabIndex="-1" aria-modal="true">
      <div className="modal-dialog modal-lg modal-dialog-centered">
        <div className="modal-content">
          <div className="modal-header">
            <h5 className="modal-title">Registration Preview</h5>
            <button 
              type="button" 
              className="btn-close" 
              onClick={onClose}
              aria-label="Close"
              disabled={isLoading}
            ></button>
          </div>
          <div className="modal-body">
            <div className="row mb-3">
              <div className="col-md-4">
                <strong>Student:</strong> {studentId}
              </div>
              <div className="col-md-4">
                <strong>Year:</strong> {year}
              </div>
              <div className="col-md-4">
                <strong>Semester:</strong> {semester}
              </div>
            </div>
            
            <div className="table-responsive mb-3">
              <table className="table table-sm">
                <thead>
                  <tr>
                    <th>Course Code</th>
                  </tr>
                </thead>
                <tbody>
                  {selectedCourses.map(course => (
                    <tr key={course}>
                      <td>{course}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            
            <div className="card">
              <div className="card-body">
                <h6 className="card-title mb-3">Fees Preview</h6>
                {feePreview.loading ? (
                  <div className="d-flex justify-content-center">
                    <div className="spinner-border text-primary" role="status">
                      <span className="visually-hidden">Loading...</span>
                    </div>
                  </div>
                ) : (
                  <div className="row">
                    <div className="col-md-6">
                      <div>Base Tuition: <strong>ZMW {formatCurrency(feePreview.base_tuition)}</strong></div>
                      <div>Registration Fee: <strong>ZMW {formatCurrency(feePreview.registration_fee)}</strong></div>
                    </div>
                    <div className="col-md-6">
                      <div>Additional Fees: <strong>ZMW {formatCurrency(feePreview.additional_fees)}</strong></div>
                      <div className="fs-5">Total: <strong>ZMW {formatCurrency(feePreview.total)}</strong></div>
                    </div>
                  </div>
                )}
                
                <div className="text-muted mt-3">
                  Note: Admin can correct wrong registrations and invoices.
                </div>
              </div>
            </div>
            
            {feePreview.error && (
              <div className="alert alert-danger mt-3">
                {feePreview.error}
              </div>
            )}
          </div>
          <div className="modal-footer">
            <button 
              type="button" 
              className="btn btn-secondary" 
              onClick={onClose}
              disabled={isLoading}
            >
              Cancel
            </button>
            <button 
              type="button" 
              className="btn btn-primary" 
              onClick={onConfirm}
              disabled={isLoading || feePreview.loading || feePreview.error}
            >
              {isLoading ? (
                <>
                  <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                  Registering...
                </>
              ) : 'Confirm & Register'}
            </button>
          </div>
        </div>
      </div>
      <div className="modal-backdrop fade show"></div>
    </div>
  );
};

export default PreviewModal;
