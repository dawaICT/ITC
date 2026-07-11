<!-- Admission Modal -->
<div class="modal fade" id="admitModal" tabindex="-1" aria-labelledby="admitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="admitModalLabel">
                    <i class="fas fa-user-check me-2"></i>
                    <span v-show="currentStep === 1">Step 1: Find Student</span>
                    <span v-show="currentStep === 2">Step 2: Program Details</span>
                    <span v-show="currentStep === 3">Step 3: Confirm Admission</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body">
                <!-- Progress Steps -->
                <div class="mb-4">
                    <ul class="nav nav-pills nav-justified">
                        <li class="nav-item">
                            <a class="nav-link" :class="{'active': currentStep >= 1, 'disabled': currentStep < 1}" href="#" @click.prevent="changeStep(1)">
                                <strong>1.</strong> Find Student
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" :class="{'active': currentStep >= 2, 'disabled': currentStep < 2}" href="#" @click.prevent="changeStep(2)">
                                <strong>2.</strong> Program Details
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" :class="{'active': currentStep >= 3, 'disabled': currentStep < 3}" href="#" @click.prevent="changeStep(3)">
                                <strong>3.</strong> Confirm
                            </a>
                        </li>
                    </ul>
                </div>
                
                <!-- Step 1: Find Student -->
                <div v-show="currentStep === 1">
                    <div class="mb-3">
                        <label class="form-label" for="searchQuery">Search Student</label>
                        <div class="input-group">
                            <input
                                type="text"
                                class="form-control"
                                id="searchQuery"
                                name="searchQuery"
                                v-model="searchQuery"
                                @input="handleSearchInput"
                                placeholder="Enter student ID, NRC, or name (min 3 characters)..."
                                minlength="3"
                                autocomplete="off"
                            >
                            <button
                                class="btn btn-primary"
                                type="button"
                                @click="searchStudent"
                                :disabled="!canSearch() || isSearching"
                            >
                                <span v-show="isSearching">
                                    <span class="spinner-border spinner-border-sm me-2"></span>
                                    Searching...
                                </span>
                                <span v-show="!isSearching">
                                    <i class="fas fa-search me-2"></i>Search
                                </span>
                            </button>
                        </div>
                        <small class="text-muted">Minimum 3 characters required</small>
                    </div>
                    
                    <!-- Search Results -->
                    <div v-show="searchPerformed">
                        <div v-show="isSearching" class="text-center py-3">
                            <div class="spinner-border text-primary"></div>
                            <p class="text-muted mt-2">Searching...</p>
                        </div>
                        
                        <div v-show="!isSearching && searchResults.length === 0" class="alert alert-info">
                            No students found. Please check your search criteria.
                        </div>
                        
                        <div v-show="!isSearching && searchResults.length > 0" class="table-responsive mt-3">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="student in searchResults" :key="student.SID">
                                        <tr>
                                            <td class="fw-bold" v-text="student.SID"></td>
                                            <td><span v-text="student.Fname"></span> <span v-text="student.Lname"></span></td>
                                            <td v-text="student.email || 'N/A'"></td>
                                            <td>
                                                <button
                                                    class="btn btn-sm btn-success"
                                                    type="button"
                                                    @click="selectStudent(student)"
                                                >
                                                    <i class="fas fa-check me-1"></i>Select
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Step 2: Program Details -->
                <!-- v-if (not v-show): the children dereference selectedAdmissionStudent,
                     so they must not render/evaluate while it is still null. -->
                <div v-if="currentStep === 2 && selectedAdmissionStudent">
                    <div class="mb-3">
                        <h6 class="text-primary">Selected Student</h6>
                        <div class="card p-3 bg-light">
                            <div><strong v-text="selectedAdmissionStudent.Fname + ' ' + selectedAdmissionStudent.Lname"></strong></div>
                            <div><small class="text-muted" v-text="selectedAdmissionStudent.SID"></small></div>
                        </div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="program_code">Program <span class="text-danger">*</span></label>
                            <select
                                class="form-select"
                                id="program_code"
                                name="program_code"
                                v-model="admissionData.program_code"
                                @change="handleProgramChange"
                                required
                            >
                                <option value="">Select Program</option>
                                <template v-for="prog in programs" :key="prog.program_code">
                                    <option :value="prog.program_code" v-text="prog.program_name"></option>
                                </template>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label" for="intake">Intake <span class="text-danger">*</span></label>
                            <select
                                class="form-select"
                                id="intake"
                                name="intake"
                                v-model="admissionData.intake"
                                @change="handleIntakeChange"
                                required
                            >
                                <option value="">Select Intake</option>
                                <template v-for="opt in intakeOptions" :key="opt.value">
                                    <option :value="opt.value" v-text="opt.text"></option>
                                </template>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label" for="mode">Mode <span class="text-danger">*</span></label>
                            <select class="form-select" id="mode" name="mode" v-model="admissionData.mode" required>
                                <option value="">Select Mode</option>
                                <option value="Full-time">Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Distance">Distance</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label" for="startYear">Start Year <span class="text-danger">*</span></label>
                            <input
                                type="number"
                                class="form-control"
                                id="startYear"
                                name="startYear"
                                v-model.number="admissionData.startYear"
                                min="2000"
                                max="2100"
                                required
                            >
                        </div>
                        
                        <!-- Term-based fields -->
                        <div v-show="isTermBased()" class="col-md-6">
                            <label class="form-label" for="term_start_date">Term Start Date</label>
                            <input
                                type="date"
                                class="form-control"
                                id="term_start_date"
                                name="term_start_date"
                                v-model="admissionData.term_start_date"
                            >
                        </div>
                        
                        <div v-show="isTermBased()" class="col-md-6">
                            <label class="form-label" for="term_end_date">Term End Date</label>
                            <input
                                type="date"
                                class="form-control"
                                id="term_end_date"
                                name="term_end_date"
                                v-model="admissionData.term_end_date"
                            >
                        </div>
                        
                        <!-- Transfer Student Fields -->
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="isTransfer"
                                    name="isTransfer"
                                    v-model="admissionData.is_transfer"
                                >
                                <label class="form-check-label" for="isTransfer">
                                    Transfer Student
                                </label>
                            </div>
                        </div>
                        
                        <div v-show="admissionData.is_transfer" class="col-md-6">
                            <label class="form-label" for="previous_institution">Previous Institution <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control"
                                id="previous_institution"
                                name="previous_institution"
                                v-model="admissionData.previous_institution"
                                placeholder="Enter previous institution name"
                                required
                            >
                        </div>
                        
                        <div v-show="admissionData.is_transfer" class="col-md-6">
                            <label class="form-label" for="credits_transferred">Credits Transferred</label>
                            <input
                                type="number"
                                class="form-control"
                                id="credits_transferred"
                                name="credits_transferred"
                                v-model.number="admissionData.credits_transferred"
                                min="0"
                            >
                        </div>
                        
                        <div v-show="admissionData.is_transfer" class="col-12">
                            <label class="form-label" for="transfer_document">Transfer Document (PDF/JPG/PNG, max 5MB)</label>
                            <input
                                type="file"
                                class="form-control"
                                id="transfer_document"
                                name="transfer_document"
                                accept=".pdf,.jpg,.jpeg,.png"
                                @change="handleFileUpload"
                            >
                            <small class="text-muted">Optional but recommended</small>
                        </div>
                    </div>
                </div>
                
                <!-- Step 3: Confirm Admission -->
                <!-- v-if (not v-show): see Step 2 — children read selectedAdmissionStudent. -->
                <div v-if="currentStep === 3 && selectedAdmissionStudent">
                    <div class="alert alert-info">
                        <h6 class="mb-3"><i class="fas fa-info-circle me-2"></i>Admission Summary</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Student:</strong> <span v-text="selectedAdmissionStudent.Fname + ' ' + selectedAdmissionStudent.Lname"></span></p>
                                <p><strong>ID:</strong> <span v-text="selectedAdmissionStudent.SID"></span></p>
                                <p><strong>Email:</strong> <span v-text="selectedAdmissionStudent.email || 'N/A'"></span></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Program:</strong> <span v-text="getProgramName(admissionData.program_code)"></span></p>
                                <p><strong>Intake:</strong> <span v-text="admissionData.intake"></span></p>
                                <p><strong>Mode:</strong> <span v-text="admissionData.mode"></span></p>
                                <p v-show="admissionData.is_transfer">
                                    <strong>Transfer:</strong> Yes (<span v-text="admissionData.previous_institution"></span>)
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-secondary"
                    @click="prevStep"
                    v-show="currentStep > 1"
                >
                    <i class="fas fa-arrow-left me-2"></i>Back
                </button>
                
                <button
                    type="button"
                    class="btn btn-primary"
                    @click="nextStep"
                    v-show="currentStep < 3 && canProceed()"
                >
                    Next <i class="fas fa-arrow-right ms-2"></i>
                </button>
                
                <button
                    type="button"
                    class="btn btn-success"
                    @click="submitAdmission"
                    v-show="currentStep === 3"
                    :disabled="isSubmittingAdmission"
                >
                    <span v-show="isSubmittingAdmission">
                        <span class="spinner-border spinner-border-sm me-2"></span>
                        Processing...
                    </span>
                    <span v-show="!isSubmittingAdmission">
                        <i class="fas fa-check-circle me-2"></i>Confirm Admission
                    </span>
                </button>
                
                <button
                    type="button"
                    class="btn btn-danger"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>
