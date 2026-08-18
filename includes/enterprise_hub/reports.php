<?php
declare(strict_types=1);

if (!function_exists('eh_report_rows')) {
    function eh_report_rows(mysqli $db, string $report): array
    {
        $allowed = [
            'by_category', 'by_programme', 'products_vs_services', 'status_distribution',
            'investment_by_category', 'employment_by_category', 'interest_by_type',
            'interest_followup', 'most_viewed', 'conversion_outcomes',
        ];
        if (!in_array($report, $allowed, true)) {
            return [];
        }

        $sql = match ($report) {
            'by_category' => "SELECT COALESCE(c.category_name,'(Uncategorised)') AS label, COUNT(*) AS total
                FROM enterprise_items i LEFT JOIN enterprise_categories c ON c.id=i.category_id
                WHERE i.status<>'archived' GROUP BY label ORDER BY total DESC",
            'by_programme' => "SELECT COALESCE(NULLIF(p.programme_name,''),'(Not set)') AS label, COUNT(*) AS total
                FROM enterprise_items i JOIN enterprise_profiles p ON p.id=i.enterprise_profile_id
                WHERE i.status<>'archived' GROUP BY label ORDER BY total DESC",
            'products_vs_services' => "SELECT item_type AS label, COUNT(*) AS total FROM enterprise_items
                WHERE status<>'archived' GROUP BY item_type ORDER BY total DESC",
            'status_distribution' => "SELECT status AS label, COUNT(*) AS total FROM enterprise_items
                GROUP BY status ORDER BY total DESC",
            'investment_by_category' => "SELECT COALESCE(c.category_name,'(Uncategorised)') AS label,
                COALESCE(SUM(i.investment_required),0) AS total
                FROM enterprise_items i LEFT JOIN enterprise_categories c ON c.id=i.category_id
                WHERE i.status='published' GROUP BY label ORDER BY total DESC",
            'employment_by_category' => "SELECT COALESCE(c.category_name,'(Uncategorised)') AS label,
                COALESCE(SUM(i.employment_potential),0) AS total
                FROM enterprise_items i LEFT JOIN enterprise_categories c ON c.id=i.category_id
                WHERE i.status='published' GROUP BY label ORDER BY total DESC",
            'interest_by_type' => "SELECT interest_type AS label, COUNT(*) AS total FROM enterprise_interests
                GROUP BY interest_type ORDER BY total DESC",
            'interest_followup' => "SELECT follow_up_status AS label, COUNT(*) AS total FROM enterprise_interests
                GROUP BY follow_up_status ORDER BY total DESC",
            'most_viewed' => "SELECT title AS label, view_count AS total FROM enterprise_items
                WHERE status='published' ORDER BY view_count DESC LIMIT 50",
            'conversion_outcomes' => "SELECT follow_up_status AS label, COUNT(*) AS total FROM enterprise_interests
                WHERE follow_up_status IN ('converted','closed','not_suitable','under_review')
                GROUP BY follow_up_status ORDER BY total DESC",
            default => '',
        };
        if ($sql === '') {
            return [];
        }
        $res = $db->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
            $res->free();
        }
        return $rows;
    }
}

if (!function_exists('eh_export_csv')) {
    function eh_export_csv(string $filename, array $headers, array $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $filename) . '"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, array_map('eh_csv_safe', $headers));
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $h) {
                $key = is_array($row) ? (array_key_exists($h, $row) ? $h : strtolower(str_replace(' ', '_', $h))) : '';
                if (is_array($row) && array_key_exists('label', $row) && ($h === 'Label' || $h === 'label')) {
                    $line[] = eh_csv_safe($row['label']);
                } elseif (is_array($row) && array_key_exists('total', $row) && ($h === 'Total' || $h === 'total')) {
                    $line[] = eh_csv_safe($row['total']);
                } else {
                    $line[] = eh_csv_safe($row[$key] ?? $row[$h] ?? '');
                }
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }
}
