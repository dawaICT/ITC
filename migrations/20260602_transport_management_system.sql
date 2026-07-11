-- Transport Section Management System foundation.
-- Run with admin/scripts/install_transport_module.php or your normal migration process.

CREATE TABLE IF NOT EXISTS transport_campuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campus_code VARCHAR(20) NOT NULL,
    campus_name VARCHAR(120) NOT NULL,
    location VARCHAR(180) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_transport_campuses_code (campus_code),
    KEY idx_transport_campuses_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO transport_campuses (campus_code, campus_name, location) VALUES
('LSK', 'Lusaka Campus', 'Plot 36354, Buyantashi Road, Heavy Industrial Area'),
('KTW', 'Kitwe Campus', 'Copperbelt Province');

CREATE TABLE IF NOT EXISTS transport_programs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_code VARCHAR(30) NOT NULL,
    program_name VARCHAR(180) NOT NULL,
    program_type ENUM('driver_training','diploma','assessment','specialist') NOT NULL DEFAULT 'driver_training',
    license_class VARCHAR(40) NULL,
    duration_days INT NOT NULL DEFAULT 20,
    required_contact_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    default_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    rtsa_regulated TINYINT(1) NOT NULL DEFAULT 1,
    teveta_regulated TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_transport_programs_code (program_code),
    KEY idx_transport_programs_status (status),
    KEY idx_transport_programs_type (program_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO transport_programs
(program_code, program_name, program_type, license_class, duration_days, required_contact_hours, default_fee, rtsa_regulated, teveta_regulated)
VALUES
('PDB', 'Professional Driving - Class B', 'driver_training', 'B', 20, 40.00, 1550.00, 1, 0),
('PDC', 'Professional Driving - Class C', 'driver_training', 'C', 20, 40.00, 0.00, 1, 0),
('PDCE', 'Professional Driving - Class CE', 'driver_training', 'CE', 20, 40.00, 0.00, 1, 0),
('MCA', 'Motorcycle Riding - Class A', 'driver_training', 'A', 5, 10.00, 0.00, 1, 0),
('DEF', 'Defensive Driving', 'specialist', NULL, 20, 40.00, 0.00, 1, 0),
('REF', 'Driver Refresher Course', 'specialist', NULL, 5, 10.00, 0.00, 1, 0),
('DRA', 'Driver Recruitment Assessment', 'assessment', NULL, 5, 10.00, 0.00, 0, 0),
('DST', 'Driver Suitability Assessment Test', 'assessment', NULL, 5, 10.00, 0.00, 0, 0),
('CHAUF', 'Chauffeur Driving', 'specialist', NULL, 5, 10.00, 0.00, 1, 0),
('HAZ', 'HAZCHEM Transport', 'specialist', NULL, 5, 10.00, 0.00, 1, 1),
('FLT', 'Forklift Truck Operation', 'specialist', 'Forklift', 5, 10.00, 0.00, 1, 1),
('PSV', 'Public Service Vehicle Training', 'specialist', 'PSV', 5, 10.00, 0.00, 1, 0),
('DTL', 'Diploma in Transport and Logistics', 'diploma', NULL, 1095, 0.00, 0.00, 0, 1);

CREATE TABLE IF NOT EXISTS transport_corporate_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_name VARCHAR(180) NOT NULL,
    contact_person VARCHAR(140) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(140) NULL,
    billing_address TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_transport_clients_status (status),
    KEY idx_transport_clients_name (client_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_instructors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) NULL,
    campus_id INT NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    license_classes VARCHAR(120) NULL,
    rtsa_license_no VARCHAR(80) NULL,
    rtsa_expiry DATE NULL,
    teveta_accreditation_no VARCHAR(80) NULL,
    teveta_expiry DATE NULL,
    zcilt_member_no VARCHAR(80) NULL,
    status ENUM('active','inactive','on_leave') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_instructors_campus FOREIGN KEY (campus_id) REFERENCES transport_campuses(id),
    KEY idx_transport_instructors_staff (staff_id),
    KEY idx_transport_instructors_status (status),
    KEY idx_transport_instructors_expiry (rtsa_expiry, teveta_expiry)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campus_id INT NOT NULL,
    asset_tag VARCHAR(60) NULL,
    registration_no VARCHAR(40) NOT NULL,
    vehicle_type ENUM('light_vehicle','rigid_truck','articulated_truck','coach','motorcycle','forklift','simulator','trailer','other') NOT NULL DEFAULT 'other',
    make_model VARCHAR(160) NULL,
    supported_license_class VARCHAR(80) NULL,
    current_mileage INT NOT NULL DEFAULT 0,
    status ENUM('available','assigned','maintenance','unavailable') NOT NULL DEFAULT 'available',
    fitness_expiry DATE NULL,
    insurance_expiry DATE NULL,
    last_service_date DATE NULL,
    next_service_due DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_vehicles_campus FOREIGN KEY (campus_id) REFERENCES transport_campuses(id),
    UNIQUE KEY uq_transport_vehicles_registration (registration_no),
    KEY idx_transport_vehicles_status (status),
    KEY idx_transport_vehicles_expiry (fitness_expiry, insurance_expiry)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_cohorts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    campus_id INT NOT NULL,
    cohort_name VARCHAR(140) NOT NULL,
    intake_month DATE NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    capacity INT NOT NULL DEFAULT 20,
    status ENUM('planning','open','in_progress','completed','cancelled') NOT NULL DEFAULT 'planning',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_cohorts_program FOREIGN KEY (program_id) REFERENCES transport_programs(id),
    CONSTRAINT fk_transport_cohorts_campus FOREIGN KEY (campus_id) REFERENCES transport_campuses(id),
    KEY idx_transport_cohorts_status (status),
    KEY idx_transport_cohorts_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_trainees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(140) NULL,
    existing_license_class VARCHAR(50) NULL,
    medical_clearance_status ENUM('pending','cleared','not_required','failed') NOT NULL DEFAULT 'pending',
    emergency_contact VARCHAR(160) NULL,
    employer_name VARCHAR(180) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_transport_trainees_student (student_id),
    KEY idx_transport_trainees_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    trainee_id INT NOT NULL,
    cohort_id INT NOT NULL,
    corporate_client_id INT NULL,
    enrollment_type ENUM('individual','corporate') NOT NULL DEFAULT 'individual',
    enrollment_date DATE NOT NULL,
    fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('enrolled','active','completed','withdrawn','failed') NOT NULL DEFAULT 'enrolled',
    certificate_issued TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_enrollments_trainee FOREIGN KEY (trainee_id) REFERENCES transport_trainees(id) ON DELETE CASCADE,
    CONSTRAINT fk_transport_enrollments_cohort FOREIGN KEY (cohort_id) REFERENCES transport_cohorts(id) ON DELETE CASCADE,
    CONSTRAINT fk_transport_enrollments_client FOREIGN KEY (corporate_client_id) REFERENCES transport_corporate_clients(id) ON DELETE SET NULL,
    UNIQUE KEY uq_transport_enrollments_trainee_cohort (trainee_id, cohort_id),
    KEY idx_transport_enrollments_status (status),
    KEY idx_transport_enrollments_client (corporate_client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cohort_id INT NOT NULL,
    instructor_id INT NOT NULL,
    vehicle_id INT NULL,
    session_type ENUM('theory','practical','simulator','assessment','maintenance_window') NOT NULL DEFAULT 'practical',
    session_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    contact_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    location VARCHAR(160) NULL,
    status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_sessions_cohort FOREIGN KEY (cohort_id) REFERENCES transport_cohorts(id) ON DELETE CASCADE,
    CONSTRAINT fk_transport_sessions_instructor FOREIGN KEY (instructor_id) REFERENCES transport_instructors(id),
    CONSTRAINT fk_transport_sessions_vehicle FOREIGN KEY (vehicle_id) REFERENCES transport_vehicles(id) ON DELETE SET NULL,
    KEY idx_transport_sessions_date (session_date, start_time, end_time),
    KEY idx_transport_sessions_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_preuse_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    instructor_id INT NULL,
    checklist_date DATE NOT NULL,
    odometer INT NULL,
    tyres_ok TINYINT(1) NOT NULL DEFAULT 1,
    lights_ok TINYINT(1) NOT NULL DEFAULT 1,
    brakes_ok TINYINT(1) NOT NULL DEFAULT 1,
    fluids_ok TINYINT(1) NOT NULL DEFAULT 1,
    overall_status ENUM('fit','defect_reported','unfit') NOT NULL DEFAULT 'fit',
    defects TEXT NULL,
    action_taken TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_checks_vehicle FOREIGN KEY (vehicle_id) REFERENCES transport_vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_transport_checks_instructor FOREIGN KEY (instructor_id) REFERENCES transport_instructors(id) ON DELETE SET NULL,
    KEY idx_transport_checks_date (checklist_date),
    KEY idx_transport_checks_status (overall_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_telematics_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    device_identifier VARCHAR(80) NULL,
    recorded_at DATETIME NOT NULL,
    location VARCHAR(180) NULL,
    speed_kph DECIMAL(7,2) NULL,
    engine_rpm INT NULL,
    fuel_level DECIMAL(6,2) NULL,
    fuel_consumption DECIMAL(8,2) NULL,
    behavior_events TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_telematics_vehicle FOREIGN KEY (vehicle_id) REFERENCES transport_vehicles(id) ON DELETE CASCADE,
    KEY idx_transport_telematics_vehicle_time (vehicle_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_maintenance_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    service_date DATE NOT NULL,
    next_service_date DATE NULL,
    tasks_due TEXT NULL,
    completed_tasks TEXT NULL,
    status ENUM('scheduled','completed','overdue','cancelled') NOT NULL DEFAULT 'scheduled',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_maintenance_vehicle FOREIGN KEY (vehicle_id) REFERENCES transport_vehicles(id) ON DELETE CASCADE,
    KEY idx_transport_maintenance_vehicle_date (vehicle_id, service_date),
    KEY idx_transport_maintenance_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_fuel_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    fuel_date DATE NOT NULL,
    energy_type ENUM('fuel','battery') NOT NULL DEFAULT 'fuel',
    level_percent DECIMAL(6,2) NULL,
    amount_added DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    consumption DECIMAL(10,2) NULL,
    odometer INT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_fuel_vehicle FOREIGN KEY (vehicle_id) REFERENCES transport_vehicles(id) ON DELETE CASCADE,
    KEY idx_transport_fuel_vehicle_date (vehicle_id, fuel_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_route_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    planned_date DATE NOT NULL,
    origin VARCHAR(180) NOT NULL,
    destination VARCHAR(180) NOT NULL,
    stops TEXT NULL,
    optimized_route TEXT NULL,
    status ENUM('planned','in_progress','completed','cancelled') NOT NULL DEFAULT 'planned',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_routes_vehicle FOREIGN KEY (vehicle_id) REFERENCES transport_vehicles(id) ON DELETE CASCADE,
    KEY idx_transport_routes_vehicle_date (vehicle_id, planned_date),
    KEY idx_transport_routes_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_incident_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    instructor_id INT NULL,
    incident_time DATETIME NOT NULL,
    location VARCHAR(180) NULL,
    description TEXT NOT NULL,
    telematics_snapshot TEXT NULL,
    status ENUM('open','in_review','resolved') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_incidents_vehicle FOREIGN KEY (vehicle_id) REFERENCES transport_vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_transport_incidents_instructor FOREIGN KEY (instructor_id) REFERENCES transport_instructors(id) ON DELETE SET NULL,
    KEY idx_transport_incidents_vehicle_time (vehicle_id, incident_time),
    KEY idx_transport_incidents_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_parts_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NULL,
    part_name VARCHAR(160) NOT NULL,
    stock_level INT NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 0,
    status ENUM('available','assigned','reorder','retired') NOT NULL DEFAULT 'available',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_parts_vehicle FOREIGN KEY (vehicle_id) REFERENCES transport_vehicles(id) ON DELETE SET NULL,
    KEY idx_transport_parts_vehicle (vehicle_id),
    KEY idx_transport_parts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_assessments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    assessment_type ENUM('theory','practical','simulator','rtsa_ready','corporate_suitability') NOT NULL,
    assessment_date DATE NOT NULL,
    score DECIMAL(6,2) NULL,
    result ENUM('pending','pass','fail','deferred') NOT NULL DEFAULT 'pending',
    assessor_id INT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_assessments_enrollment FOREIGN KEY (enrollment_id) REFERENCES transport_enrollments(id) ON DELETE CASCADE,
    CONSTRAINT fk_transport_assessments_assessor FOREIGN KEY (assessor_id) REFERENCES transport_instructors(id) ON DELETE SET NULL,
    KEY idx_transport_assessments_date (assessment_date),
    KEY idx_transport_assessments_result (result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_corporate_bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    corporate_client_id INT NOT NULL,
    program_id INT NOT NULL,
    campus_id INT NOT NULL,
    requested_slots INT NOT NULL DEFAULT 1,
    booking_date DATE NOT NULL,
    expected_start_date DATE NULL,
    status ENUM('requested','confirmed','in_progress','completed','cancelled') NOT NULL DEFAULT 'requested',
    quoted_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    invoiced_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_bookings_client FOREIGN KEY (corporate_client_id) REFERENCES transport_corporate_clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_transport_bookings_program FOREIGN KEY (program_id) REFERENCES transport_programs(id),
    CONSTRAINT fk_transport_bookings_campus FOREIGN KEY (campus_id) REFERENCES transport_campuses(id),
    KEY idx_transport_bookings_status (status),
    KEY idx_transport_bookings_dates (booking_date, expected_start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
