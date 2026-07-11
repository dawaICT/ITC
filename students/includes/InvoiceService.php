<?php
/**
 * InvoiceService - Handles invoice generation and management
 * Manages invoice creation, retrieval, and status tracking
 */

class InvoiceService {
    private $db;
    private $invoiceColumns = null;
    private $courseRegistrationColumns = null;
    
    public function __construct($dbConnection) {
        $this->db = $dbConnection;
    }
    
    /**
     * Generate a unique invoice number
     * Format: INV-YYYY-NNNNNN (e.g., INV-2025-000123)
     * 
     * @return string Invoice number
     */
    private function tableColumns(string $table): array {
        $columns = [];
        $safeTable = $this->db->real_escape_string($table);
        if ($result = $this->db->query("SHOW COLUMNS FROM `{$safeTable}`")) {
            while ($row = $result->fetch_assoc()) {
                $columns[] = (string)$row['Field'];
            }
            $result->free();
        }
        return $columns;
    }

    private function invoiceColumns(): array {
        if ($this->invoiceColumns === null) {
            $this->invoiceColumns = $this->tableColumns('invoices');
        }
        return $this->invoiceColumns;
    }

    private function courseRegistrationColumns(): array {
        if ($this->courseRegistrationColumns === null) {
            $this->courseRegistrationColumns = $this->tableColumns('course_registration');
        }
        return $this->courseRegistrationColumns;
    }

    private function hasInvoiceColumn(string $column): bool {
        return in_array($column, $this->invoiceColumns(), true);
    }

    private function invoiceNumberColumn(): ?string {
        foreach (['invoice_number', 'invoice_no', 'invoice', 'reference'] as $column) {
            if ($this->hasInvoiceColumn($column)) {
                return $column;
            }
        }
        return null;
    }

    private function studentIdColumn(): string {
        return $this->hasInvoiceColumn('student_id') ? 'student_id' : ($this->hasInvoiceColumn('SID') ? 'SID' : 'student_id');
    }

    /**
     * Normalized SELECT column list so every getter returns the same shape
     * regardless of which physical columns the invoices table actually has.
     * Derives amount_paid/balance from status when those columns are absent.
     */
    private function invoiceSelectColumns(): string {
        $numberColumn = $this->invoiceNumberColumn() ?: 'id';
        $totalExpr  = $this->hasInvoiceColumn('total_amount') ? 'total_amount' : ($this->hasInvoiceColumn('amount') ? 'amount' : '0');
        $statusExpr = $this->hasInvoiceColumn('status') ? 'status' : ($this->hasInvoiceColumn('payment_status') ? 'payment_status' : "'pending'");
        $paidExpr   = $this->hasInvoiceColumn('amount_paid')
            ? 'amount_paid'
            : "(CASE WHEN LOWER({$statusExpr}) IN ('paid','completed','cleared') THEN {$totalExpr} ELSE 0 END)";
        $balanceExpr = $this->hasInvoiceColumn('balance') ? 'balance' : "({$totalExpr} - {$paidExpr})";
        $dueExpr     = $this->hasInvoiceColumn('due_date') ? 'due_date' : 'invoice_date';
        $studentExpr = $this->hasInvoiceColumn('student_id') ? 'student_id' : ($this->hasInvoiceColumn('SID') ? 'SID' : "''");
        $regExpr     = $this->hasInvoiceColumn('registration_id') ? 'registration_id' : 'NULL';
        $descExpr    = $this->hasInvoiceColumn('description') ? 'description' : "''";
        $yearExpr    = $this->hasInvoiceColumn('academic_year') ? 'academic_year' : "''";
        $semExpr     = $this->hasInvoiceColumn('semester') ? 'semester' : "''";

        return "`{$numberColumn}` AS invoice_number,
                {$studentExpr} AS student_id,
                {$regExpr} AS registration_id,
                {$descExpr} AS description,
                invoice_date,
                {$totalExpr} AS total_amount,
                {$totalExpr} AS amount,
                {$paidExpr} AS amount_paid,
                {$balanceExpr} AS balance,
                {$statusExpr} AS status,
                {$dueExpr} AS due_date,
                {$yearExpr} AS academic_year,
                {$semExpr} AS semester";
    }

    private function generateInvoiceNumber() {
        $year = date('Y');
        $numberColumn = $this->invoiceNumberColumn();
        if ($numberColumn === null) {
            return sprintf("INV-%s-%06d", $year, random_int(1, 999999));
        }
        
        // Get the last invoice number for this year
        $stmt = $this->db->prepare("
            SELECT `{$numberColumn}` AS invoice_number
            FROM invoices 
            WHERE `{$numberColumn}` LIKE ? 
            ORDER BY `{$numberColumn}` DESC 
            LIMIT 1
        ");
        
        $pattern = "INV-{$year}-%";
        $stmt->bind_param("s", $pattern);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            // Extract the sequential number and increment
            $lastNumber = intval(substr($row['invoice_number'], -6));
            $newNumber = $lastNumber + 1;
        } else {
            // First invoice of the year
            $newNumber = 1;
        }
        
        // Format: INV-2025-000123
        return sprintf("INV-%s-%06d", $year, $newNumber);
    }
    
    /**
     * Generate an invoice for a student's course registration
     * 
     * @param string $studentId
     * @param int $registrationId
     * @param float $totalAmount
     * @param string $academicYear
     * @param string $semester
     * @return string Invoice number
     * @throws Exception if invoice generation fails
     */
    public function generateInvoice($studentId, $registrationId, $totalAmount, $academicYear, $semester) {
        try {
            $this->db->begin_transaction();
            
            // Generate unique invoice number
            $invoiceNumber = $this->generateInvoiceNumber();
            
            // Calculate due date (typically 30 days from now, or customize as needed)
            $dueDate = date('Y-m-d', strtotime('+30 days'));
            
            $invoiceColumns = $this->invoiceColumns();
            $fields = [];
            $placeholders = [];
            $types = '';
            $params = [];

            $add = function (string $column, string $type, $value, bool $raw = false) use (&$fields, &$placeholders, &$types, &$params, $invoiceColumns): void {
                if (!in_array($column, $invoiceColumns, true)) {
                    return;
                }
                $fields[] = "`{$column}`";
                if ($raw) {
                    $placeholders[] = (string)$value;
                    return;
                }
                $placeholders[] = '?';
                $types .= $type;
                $params[] = $value;
            };

            $numberColumn = $this->invoiceNumberColumn();
            if ($numberColumn !== null) {
                $add($numberColumn, 's', $invoiceNumber);
            }
            $add('student_id', 's', $studentId);
            $add('SID', 's', $studentId);
            $add('registration_id', 'i', $registrationId);
            $add('invoice_date', '', 'NOW()', true);
            $add('date_generated', '', 'NOW()', true);
            $add('description', 's', 'Course registration invoice');
            $add('amount', 'd', $totalAmount);
            $add('total_amount', 'd', $totalAmount);
            $add('amount_paid', 'd', 0.0);
            $add('balance', 'd', $totalAmount);
            $add('status', 's', 'pending');
            $add('payment_status', 's', 'pending');
            $add('due_date', 's', $dueDate);
            $add('academic_year', 's', $academicYear);
            $add('semester', 's', $semester);
            $add('created_at', '', 'NOW()', true);
            $add('updated_at', '', 'NOW()', true);

            if (empty($fields)) {
                throw new Exception('Invoices table has no supported columns.');
            }

            $stmt = $this->db->prepare("INSERT INTO invoices (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")");
            if (!$stmt) {
                throw new Exception("Failed to prepare invoice: " . $this->db->error);
            }
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create invoice: " . $this->db->error);
            }
            
            $courseRegColumns = $this->courseRegistrationColumns();
            if (in_array('invoice_number', $courseRegColumns, true) || in_array('invoice_no', $courseRegColumns, true)) {
                $courseInvoiceColumn = in_array('invoice_number', $courseRegColumns, true) ? 'invoice_number' : 'invoice_no';
                $sets = ["`{$courseInvoiceColumn}` = ?"];
                if (in_array('invoice_generated_date', $courseRegColumns, true)) {
                    $sets[] = "invoice_generated_date = NOW()";
                }
                $updateStmt = $this->db->prepare("UPDATE course_registration SET " . implode(', ', $sets) . " WHERE semester_registration_id = ?");
                if ($updateStmt) {
                    $updateStmt->bind_param("si", $invoiceNumber, $registrationId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
            }
            
            $this->db->commit();
            
            return $invoiceNumber;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("ERROR in generateInvoice: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get invoice details by registration ID
     * 
     * @param int $registrationId
     * @return array|null Invoice details
     */
    public function getInvoiceByRegistration($registrationId) {
        try {
            if (!$this->hasInvoiceColumn('registration_id')) {
                return null;
            }
            $stmt = $this->db->prepare("
                SELECT " . $this->invoiceSelectColumns() . "
                FROM invoices
                WHERE registration_id = ?
                ORDER BY invoice_date DESC
                LIMIT 1
            ");

            $stmt->bind_param("i", $registrationId);
            $stmt->execute();
            $result = $stmt->get_result();

            return $result->fetch_assoc();

        } catch (Exception $e) {
            error_log("ERROR in getInvoiceByRegistration: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get invoice details by invoice number
     * 
     * @param string $invoiceNumber
     * @return array|null Invoice details
     */
    public function getInvoiceByNumber($invoiceNumber) {
        try {
            $numberColumn = $this->invoiceNumberColumn();
            if ($numberColumn === null) {
                return null;
            }
            $stmt = $this->db->prepare("
                SELECT " . $this->invoiceSelectColumns() . "
                FROM invoices
                WHERE `{$numberColumn}` = ?
                LIMIT 1
            ");

            $stmt->bind_param("s", $invoiceNumber);
            $stmt->execute();
            $result = $stmt->get_result();

            return $result->fetch_assoc();

        } catch (Exception $e) {
            error_log("ERROR in getInvoiceByNumber: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all invoices for a student
     * 
     * @param string $studentId
     * @param string|null $academicYear Optional filter by academic year
     * @param string|null $semester Optional filter by semester
     * @return array List of invoices
     */
    public function getStudentInvoices($studentId, $academicYear = null, $semester = null) {
        try {
            $studentColumn = $this->studentIdColumn();
            $sql = "
                SELECT " . $this->invoiceSelectColumns() . "
                FROM invoices
                WHERE `{$studentColumn}` = ?
            ";

            $params = [$studentId];
            $types = "s";

            if ($academicYear !== null && $this->hasInvoiceColumn('academic_year')) {
                $sql .= " AND academic_year = ?";
                $params[] = $academicYear;
                $types .= "s";
            }

            if ($semester !== null && $this->hasInvoiceColumn('semester')) {
                $sql .= " AND semester = ?";
                $params[] = $semester;
                $types .= "s";
            }

            $sql .= " ORDER BY invoice_date DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $invoices = [];
            while ($row = $result->fetch_assoc()) {
                $invoices[] = $row;
            }
            
            return $invoices;
            
        } catch (Exception $e) {
            error_log("ERROR in getStudentInvoices: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update invoice payment status
     * 
     * @param string $invoiceNumber
     * @param float $paymentAmount
     * @return bool Success status
     */
    public function recordPayment($invoiceNumber, $paymentAmount) {
        try {
            $this->db->begin_transaction();
            
            // Get current invoice details
            $invoice = $this->getInvoiceByNumber($invoiceNumber);
            if (!$invoice) {
                throw new Exception("Invoice not found: $invoiceNumber");
            }
            
            $newAmountPaid = $invoice['amount_paid'] + $paymentAmount;
            $newBalance = $invoice['total_amount'] - $newAmountPaid;
            
            // Determine new status
            if ($newBalance <= 0) {
                $newStatus = 'paid';
            } elseif ($newAmountPaid > 0) {
                $newStatus = 'partial';
            } else {
                $newStatus = 'pending';
            }
            
            $numberColumn = $this->invoiceNumberColumn();
            if ($numberColumn === null) {
                throw new Exception('Invoice number column not found.');
            }
            $sets = [];
            $types = '';
            $params = [];
            if ($this->hasInvoiceColumn('amount_paid')) { $sets[] = 'amount_paid = ?'; $types .= 'd'; $params[] = $newAmountPaid; }
            if ($this->hasInvoiceColumn('balance')) { $sets[] = 'balance = ?'; $types .= 'd'; $params[] = $newBalance; }
            if ($this->hasInvoiceColumn('status')) { $sets[] = 'status = ?'; $types .= 's'; $params[] = $newStatus; }
            if ($this->hasInvoiceColumn('last_payment_date')) { $sets[] = 'last_payment_date = NOW()'; }
            if ($this->hasInvoiceColumn('updated_at')) { $sets[] = 'updated_at = NOW()'; }
            if (empty($sets)) {
                throw new Exception('No writable payment columns found.');
            }
            $types .= 's';
            $params[] = $invoiceNumber;
            $stmt = $this->db->prepare("UPDATE invoices SET " . implode(', ', $sets) . " WHERE `{$numberColumn}` = ?");
            $stmt->bind_param($types, ...$params);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update invoice: " . $this->db->error);
            }
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("ERROR in recordPayment: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get invoice line items (courses) for an invoice
     * 
     * @param int $registrationId
     * @return array List of courses/line items
     */
    public function getInvoiceLineItems($registrationId) {
        try {
            $courseColumns = $this->tableColumns('courses');
            $creditExpr = in_array('credits', $courseColumns, true) ? 'c.credits' : (in_array('credit_hours', $courseColumns, true) ? 'c.credit_hours' : '3');
            $feeExpr = in_array('fee', $courseColumns, true) ? 'c.fee' : '0';
            $stmt = $this->db->prepare("
                SELECT 
                    c.course_code,
                    c.course_name,
                    {$creditExpr} AS credit_hours,
                    {$feeExpr} as amount,
                    'Active' as status
                FROM course_registration sc
                INNER JOIN courses c ON sc.course_code = c.course_code
                WHERE sc.semester_registration_id = ?
                ORDER BY c.course_code ASC
            ");
            
            $stmt->bind_param("i", $registrationId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $items = [];
            while ($row = $result->fetch_assoc()) {
                $items[] = $row;
            }
            
            return $items;
            
        } catch (Exception $e) {
            error_log("ERROR in getInvoiceLineItems: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if invoice is overdue
     * 
     * @param string $invoiceNumber
     * @return bool
     */
    public function isOverdue($invoiceNumber) {
        try {
            $numberColumn = $this->invoiceNumberColumn();
            if ($numberColumn === null) {
                return false;
            }
            if (!$this->hasInvoiceColumn('due_date')) {
                return false; // No due date tracked; cannot be overdue.
            }
            $statusExpr = $this->hasInvoiceColumn('status') ? 'status' : ($this->hasInvoiceColumn('payment_status') ? 'payment_status' : "'pending'");
            $stmt = $this->db->prepare("
                SELECT due_date, {$statusExpr} AS status
                FROM invoices
                WHERE `{$numberColumn}` = ?
                LIMIT 1
            ");

            $stmt->bind_param("s", $invoiceNumber);
            $stmt->execute();
            $result = $stmt->get_result();
            $invoice = $result->fetch_assoc();

            if (!$invoice || empty($invoice['due_date'])) {
                return false;
            }

            // Invoice is overdue if status is not paid and the due date has passed
            $dueDate = strtotime((string)$invoice['due_date']);
            $today = strtotime(date('Y-m-d'));

            return (!in_array(strtolower((string)$invoice['status']), ['paid', 'completed', 'cleared'], true) && $today > $dueDate);

        } catch (Exception $e) {
            error_log("ERROR in isOverdue: " . $e->getMessage());
            return false;
        }
    }
}
