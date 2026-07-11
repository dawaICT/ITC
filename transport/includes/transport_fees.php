<?php
/**
 * Transport fee-calculation engine (spec §8 / BR002, BR008, BR009, BR016, BR017).
 *
 * Computes the fee payable for a program + study mode from the APPROVED, year-
 * versioned schedule — never from a number typed on a form. Pure functions taking
 * a `mysqli $db`.
 *
 * Sources:
 *   transport_course_fees      base fee per (program, year, mode); is_available=0 => mode N/A (BR002)
 *   transport_additional_fees  mandatory add-ons (standard/equipment/rtsa) + optional choices (option)
 *   transport_programs         default_fee (legacy fallback when no schedule row exists yet)
 */

/** The fee year currently in effect. */
function tf_default_year(): int
{
    return (int)date('Y');
}

/** All fee rows for a program in a given year (any availability), keyed by lowercase mode. */
function tf_course_fee_rows(mysqli $db, int $programId, int $year): array
{
    $rows = [];
    $stmt = $db->prepare(
        "SELECT id, training_mode, amount, currency, is_available
         FROM transport_course_fees
         WHERE program_id = ? AND fee_year = ?
         ORDER BY is_available DESC, training_mode ASC"
    );
    if (!$stmt) {
        return $rows;
    }
    $stmt->bind_param('ii', $programId, $year);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $rows[strtolower(trim((string)$r['training_mode']))] = $r;
    }
    $stmt->close();
    return $rows;
}

/**
 * Study modes a student may actually pick for this program/year (is_available=1).
 * Falls back to a synthetic full-time row from default_fee when no schedule exists.
 * @return array<int,array{training_mode:string,amount:float,is_available:int,synthetic?:bool}>
 */
function tf_available_modes(mysqli $db, int $programId, int $year): array
{
    $rows = tf_course_fee_rows($db, $programId, $year);
    $out = [];
    foreach ($rows as $r) {
        if ((int)$r['is_available'] === 1) {
            $out[] = [
                'training_mode' => (string)$r['training_mode'],
                'amount'        => (float)$r['amount'],
                'is_available'  => 1,
            ];
        }
    }
    if (!empty($out) || !empty($rows)) {
        return $out; // rows exist (some may be N/A); offer the available ones only
    }
    // No schedule at all -> legacy fallback so the form still works.
    $stmt = $db->prepare("SELECT default_fee FROM transport_programs WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $programId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $out[] = ['training_mode' => 'full-time', 'amount' => (float)$row['default_fee'], 'is_available' => 1, 'synthetic' => true];
        }
    }
    return $out;
}

/** Active additional fees for a program (mandatory add-ons + selectable options). */
function tf_additional_fees(mysqli $db, int $programId): array
{
    $out = [];
    $stmt = $db->prepare(
        "SELECT id, fee_name, amount, currency, is_mandatory, fee_category
         FROM transport_additional_fees
         WHERE program_id = ? AND is_active = 1
         ORDER BY (fee_category='option') ASC, fee_category ASC, id ASC"
    );
    if (!$stmt) {
        return $out;
    }
    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $out[] = $r;
    }
    $stmt->close();
    return $out;
}

/**
 * §8 CalculateFees. Base (by mode) + mandatory add-ons + selected options + RTSA.
 *
 * @param int[] $selectedOptionIds  ids of optional (is_mandatory=0) additional fees the applicant chose
 * @return array{ok:bool,error:?string,base:float,mode:string,currency:string,lines:array<int,array{name:string,amount:float,category:string}>,total:float}
 */
function tf_calculate(mysqli $db, int $programId, string $mode, array $selectedOptionIds = [], ?int $year = null): array
{
    $year = $year ?? tf_default_year();
    $mode = trim($mode);
    $fail = static function (string $msg): array {
        return ['ok' => false, 'error' => $msg, 'base' => 0.0, 'mode' => '', 'currency' => 'ZMW', 'lines' => [], 'total' => 0.0];
    };

    $stmt = $db->prepare("SELECT id, program_name, default_fee FROM transport_programs WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return $fail('Unable to load the course.');
    }
    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $program = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$program) {
        return $fail('Course not found.');
    }

    $rows = tf_course_fee_rows($db, $programId, $year);
    $currency = 'ZMW';
    $modeUsed = $mode !== '' ? $mode : 'full-time';

    if (empty($rows)) {
        // Legacy fallback: no approved schedule yet -> use the program default fee.
        $base = (float)$program['default_fee'];
    } else {
        if ($mode === '') {
            return $fail('Select a training mode for this course.');
        }
        $key = strtolower($mode);
        if (!isset($rows[$key])) {
            return $fail('The selected training mode is not offered for this course.');
        }
        $row = $rows[$key];
        if ((int)$row['is_available'] !== 1) {
            // BR002: a mode marked N/A cannot be selected.
            return $fail('This training mode is not available for this course (N/A).');
        }
        $base = (float)$row['amount'];
        $modeUsed = (string)$row['training_mode'];
        $currency = (string)($row['currency'] ?: 'ZMW');
    }

    $lines = [];
    $lines[] = ['name' => 'Course fee (' . $modeUsed . ')', 'amount' => round($base, 2), 'category' => 'base'];

    // Additional fees: all mandatory add-ons always apply; optional fees only when chosen.
    $selected = array_map('intval', $selectedOptionIds);
    foreach (tf_additional_fees($db, $programId) as $af) {
        $isMandatory = (int)$af['is_mandatory'] === 1;
        $isSelected  = in_array((int)$af['id'], $selected, true);
        if ($isMandatory || $isSelected) {
            $lines[] = [
                'name'     => (string)$af['fee_name'],
                'amount'   => round((float)$af['amount'], 2),
                'category' => (string)$af['fee_category'],
            ];
        }
    }

    $total = 0.0;
    foreach ($lines as $l) {
        $total += (float)$l['amount'];
    }

    return [
        'ok'       => true,
        'error'    => null,
        'base'     => round($base, 2),
        'mode'     => $modeUsed,
        'currency' => $currency,
        'lines'    => $lines,
        'total'    => round($total, 2),
    ];
}
