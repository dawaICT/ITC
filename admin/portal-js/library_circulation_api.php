<?php
define('IS_SCRIPT', true);
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../../includes/permissions.php';
if (!headers_sent()) { header('Content-Type: application/json'); }
if (!isset($_SESSION['staff_id'])) { echo json_encode(['error'=>'auth']); exit; }
if (!canCirculate($_SESSION['staff_id'])) { echo json_encode(['error'=>'forbidden']); exit; }

function json_ok($d){ echo json_encode($d); exit; }
function json_err($m){ http_response_code(400); echo json_encode(['error'=>$m]); exit; }

function lib_table_columns(mysqli $db, string $table): array {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  $cols = [];
  $safe = $db->real_escape_string($table);
  if ($res = $db->query("SHOW COLUMNS FROM `$safe`")) {
    while ($row = $res->fetch_assoc()) $cols[] = (string)$row['Field'];
    $res->free();
  }
  return $cache[$table] = $cols;
}

function lib_has_col(mysqli $db, string $table, string $col): bool {
  return in_array($col, lib_table_columns($db, $table), true);
}

function lib_due_col(mysqli $db): ?string {
  return lib_has_col($db, 'library_loans', 'due_date') ? 'due_date' : (lib_has_col($db, 'library_loans', 'due_at') ? 'due_at' : null);
}

function lib_sid_col(mysqli $db): ?string {
  return lib_has_col($db, 'library_loans', 'borrower_id') ? 'borrower_id' : (lib_has_col($db, 'library_loans', 'student_id') ? 'student_id' : null);
}

function lib_copy_lookup_where(mysqli $db, string $safe): string {
  $parts = ["barcode='$safe'"];
  if (lib_has_col($db, 'library_copies', 'rfid_tag')) $parts[] = "rfid_tag='$safe'";
  return implode(' OR ', $parts);
}

function lib_insert_loan(mysqli $db, int $copyId, int $itemId, string $borrowerType, string $borrowerId, string $dueDate, string $staffId): bool {
  $loanCols = lib_table_columns($db, 'library_loans');
  $fields = [];
  $placeholders = [];
  $types = '';
  $params = [];
  $add = function(string $col, string $type, $value) use (&$fields, &$placeholders, &$types, &$params, $loanCols) {
    if (!in_array($col, $loanCols, true)) return;
    $fields[] = "`$col`";
    $placeholders[] = '?';
    $types .= $type;
    $params[] = $value;
  };
  $add('copy_id', 'i', $copyId);
  $add('item_id', 'i', $itemId);
  $add('borrower_type', 's', $borrowerType);
  $add('borrower_id', 's', $borrowerId);
  if ($borrowerType === 'student') $add('student_id', 's', $borrowerId);
  $add('due_date', 's', $dueDate);
  $add('due_at', 's', $dueDate);
  $add('borrowed_at', 's', date('Y-m-d H:i:s'));
  $add('status', 's', 'active');
  $add('created_by', 's', $staffId);
  if (empty($fields)) return false;
  $stmt = $db->prepare("INSERT INTO library_loans (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")");
  if (!$stmt) return false;
  $stmt->bind_param($types, ...$params);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'active_loans') {
  $dueCol = lib_due_col($db);
  $returnCol = lib_has_col($db, 'library_loans', 'returned_at') ? 'returned_at' : null;
  $activeWhere = $returnCol ? "l.`$returnCol` IS NULL" : (lib_has_col($db, 'library_loans', 'status') ? "l.`status` = 'active'" : '1=1');
  $dueSelect = $dueCol ? "l.`$dueCol` AS due_date" : "NULL AS due_date";
  $borrowerTypeSelect = lib_has_col($db, 'library_loans', 'borrower_type') ? 'l.borrower_type' : "'student' AS borrower_type";
  $sidCol = lib_sid_col($db);
  $borrowerIdSelect = $sidCol ? "l.`$sidCol` AS borrower_id" : "'' AS borrower_id";
  $orderBy = $dueCol ? "l.`$dueCol` ASC" : "l.id DESC";
  $sql = "SELECT l.*, $dueSelect, $borrowerTypeSelect, $borrowerIdSelect, c.barcode, i.title FROM library_loans l
          JOIN library_copies c ON c.id=l.copy_id
          JOIN library_items i ON i.id=c.item_id
          WHERE $activeWhere ORDER BY $orderBy LIMIT 200";
  $rows=[]; if ($res = $db->query($sql)) { while ($r=$res->fetch_assoc()) $rows[]=$r; }
  json_ok(['results'=>$rows]);
}

if ($action === 'checkout') {
  $barcode = trim($_POST['barcode'] ?? '');
  $borrower_type = $_POST['borrower_type'] === 'staff' ? 'staff' : 'student';
  $borrower_id = trim($_POST['borrower_id'] ?? '');
  $due_date = trim($_POST['due_date'] ?? '');
  if ($barcode === '' || $borrower_id === '' || $due_date === '') json_err('Missing fields');

  // find copy by barcode/rfid
  $safe = $db->real_escape_string($barcode);
  $res = $db->query("SELECT * FROM library_copies WHERE " . lib_copy_lookup_where($db, $safe) . " LIMIT 1");
  if (!$res || !$res->num_rows) json_err('Copy not found');
  $copy = $res->fetch_assoc();
  if ($copy['status'] !== 'available') json_err('Copy not available');

  if (!lib_insert_loan($db, (int)$copy['id'], (int)$copy['item_id'], $borrower_type, $borrower_id, $due_date.' 23:59:59', (string)$_SESSION['staff_id'])) json_err('Failed to checkout');
  $db->query("UPDATE library_copies SET status='loaned' WHERE id=".$copy['id']);
  audit_log($db, $_SESSION['staff_id'], 'library_checkout', json_encode(['barcode'=>$barcode,'borrower_type'=>$borrower_type,'borrower_id'=>$borrower_id,'due_date'=>$due_date]));
  json_ok(['message'=>'Checked out']);
}

if ($action === 'return') {
  $barcode = trim($_POST['barcode'] ?? '');
  if ($barcode === '') json_err('Missing barcode');
  $safe = $db->real_escape_string($barcode);
  $dueCol = lib_due_col($db);
  $returnCol = lib_has_col($db, 'library_loans', 'returned_at') ? 'returned_at' : null;
  $activeJoin = $returnCol ? "l.`$returnCol` IS NULL" : (lib_has_col($db, 'library_loans', 'status') ? "l.`status` = 'active'" : '1=1');
  $dueSelect = $dueCol ? "l.`$dueCol` AS due_date" : "NULL AS due_date";
  $borrowerTypeSelect = lib_has_col($db, 'library_loans', 'borrower_type') ? 'l.borrower_type' : "'student' AS borrower_type";
  $sidCol = lib_sid_col($db);
  $borrowerIdSelect = $sidCol ? "l.`$sidCol` AS borrower_id" : "'' AS borrower_id";
  $res = $db->query("SELECT c.*, l.id loan_id, $dueSelect, $borrowerTypeSelect, $borrowerIdSelect FROM library_copies c
                     JOIN library_loans l ON l.copy_id=c.id AND $activeJoin
                     WHERE " . str_replace('barcode', 'c.barcode', lib_copy_lookup_where($db, $safe)) . " LIMIT 1");
  if (!$res || !$res->num_rows) json_err('Active loan not found for copy');
  $row = $res->fetch_assoc();

  // fine calculation
  $fine = 0.00;
  $ruleRes = $db->query("SELECT fr.* FROM library_fine_rules fr JOIN library_items i ON i.id={$row['item_id']} AND fr.item_type=i.item_type LIMIT 1");
  $rule = $ruleRes && $ruleRes->num_rows ? $ruleRes->fetch_assoc() : ['grace_days'=>0,'daily_rate'=>0,'max_fine'=>0];
  $dueTs = strtotime($row['due_date']);
  $now = time();
  if ($now > $dueTs) {
    $daysLate = floor(($now - $dueTs) / 86400) - (int)$rule['grace_days'];
    if ($daysLate > 0) {
      $fine = $daysLate * (float)$rule['daily_rate'];
      if ((float)$rule['max_fine'] > 0 && $fine > (float)$rule['max_fine']) $fine = (float)$rule['max_fine'];
    }
  }

  // close loan
  $sets = [];
  $types = '';
  $params = [];
  if (lib_has_col($db, 'library_loans', 'returned_at')) $sets[] = 'returned_at=NOW()';
  if (lib_has_col($db, 'library_loans', 'status')) $sets[] = "status='returned'";
  if (lib_has_col($db, 'library_loans', 'fine_amount')) { $sets[] = 'fine_amount=?'; $types .= 'd'; $params[] = $fine; }
  if (empty($sets)) json_err('Loan table is not configured for returns');
  $types .= 'i'; $params[] = (int)$row['loan_id'];
  $stmt = $db->prepare("UPDATE library_loans SET " . implode(',', $sets) . " WHERE id=?");
  $stmt->bind_param($types, ...$params);
  if (!$stmt->execute()) json_err('Failed to return');
  $db->query("UPDATE library_copies SET status='available' WHERE id=".$row['id']);

  if ($fine > 0) {
    $stmt2 = $db->prepare("INSERT INTO library_fines (loan_id, borrower_type, borrower_id, amount, reason) VALUES (?,?,?,?,?)");
    $reason = 'Overdue return';
    $stmt2->bind_param('issds', $row['loan_id'], $row['borrower_type'], $row['borrower_id'], $fine, $reason);
    $stmt2->execute();
  }
  // auto-fulfill reservation if exists for this item
  $res2 = $db->query("SELECT id, borrower_type, borrower_id FROM library_reservations WHERE item_id=".$row['item_id']." AND status='active' ORDER BY reserved_at ASC LIMIT 1");
  if ($res2 && $res2->num_rows) {
    $resv = $res2->fetch_assoc();
    // assign copy to reserved borrower by creating a loan with due date +7 days
    $due = date('Y-m-d 23:59:59', strtotime('+7 days'));
    if (lib_insert_loan($db, (int)$row['id'], (int)$row['item_id'], (string)$resv['borrower_type'], (string)$resv['borrower_id'], $due, (string)$_SESSION['staff_id'])) {
      $db->query("UPDATE library_copies SET status='loaned' WHERE id=".$row['id']);
      $db->query("UPDATE library_reservations SET status='fulfilled' WHERE id=".$resv['id']);
      audit_log($db, $_SESSION['staff_id'], 'library_reservation_fulfilled', json_encode(['reservation_id'=>$resv['id'], 'borrower_id'=>$resv['borrower_id']]));
    }
  }
  audit_log($db, $_SESSION['staff_id'], 'library_return', json_encode(['barcode'=>$barcode,'fine'=>$fine]));
  json_ok(['message' => $fine > 0 ? ('Returned. Fine: ' . number_format($fine,2)) : 'Returned']);
}

json_err('unknown');
?>


