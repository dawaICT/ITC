<?php
declare(strict_types=1);

/**
 * Ephemeral notifications when Test Docket / Exam Slip become available.
 * Not stored in portal_alerts — driven by StudentAcademicWorkflowService gates.
 */

require_once __DIR__ . '/StudentAcademicWorkflowService.php';
require_once dirname(__DIR__, 2) . '/includes/short_course_student.php';

if (!function_exists('student_document_eligibility_pair')) {
    /**
     * @return array{docket:array,exam_slip:array}
     */
    function student_document_eligibility_pair(mysqli $db, string $studentId): array
    {
        static $cache = [];
        $studentId = trim($studentId);
        if ($studentId === '') {
            return ['docket' => [], 'exam_slip' => []];
        }
        if (isset($cache[$studentId])) {
            return $cache[$studentId];
        }

        if (function_exists('isShortCourseStudent') && isShortCourseStudent($db, $studentId)) {
            return $cache[$studentId] = ['docket' => [], 'exam_slip' => []];
        }

        // Best-effort chrome: a service/DB hiccup here must never take down
        // the page that included the navbar.
        try {
            $workflow = new StudentAcademicWorkflowService($db);
            return $cache[$studentId] = [
                'docket' => $workflow->getDocketEligibility($studentId),
                'exam_slip' => $workflow->getExamSlipEligibility($studentId),
            ];
        } catch (Throwable $e) {
            error_log('student_document_eligibility_pair degraded: ' . $e->getMessage());
            return $cache[$studentId] = ['docket' => [], 'exam_slip' => []];
        }
    }
}

if (!function_exists('student_document_notification_items')) {
    /**
     * Dashboard bell / attention items (only when documents are available).
     *
     * @return list<array{type:string,icon:string,title:string,text:string,href:string,label:string}>
     */
    function student_document_notification_items(mysqli $db, string $studentId): array
    {
        $pair = student_document_eligibility_pair($db, $studentId);
        $items = [];

        if (!empty($pair['docket']['available'])) {
            $items[] = [
                'type' => 'info',
                'icon' => 'fa-file-alt',
                'title' => 'Test Docket available',
                'text' => (string)($pair['docket']['reason'] ?? 'Your test docket is ready to view or print.'),
                'href' => 'test_docket.php',
                'label' => 'Open docket',
            ];
        }
        if (!empty($pair['exam_slip']['available'])) {
            $items[] = [
                'type' => 'info',
                'icon' => 'fa-id-card',
                'title' => 'Exam Slip available',
                'text' => (string)($pair['exam_slip']['reason'] ?? 'Your exam slip is ready to view or print.'),
                'href' => 'exam_slip.php',
                'label' => 'Open exam slip',
            ];
        }

        return $items;
    }
}

if (!function_exists('student_render_document_availability_alerts')) {
    /** Inline alert banners for student pages (navbar hook). */
    function student_render_document_availability_alerts(mysqli $db, string $studentId): string
    {
        $items = student_document_notification_items($db, $studentId);
        if ($items === []) {
            return '';
        }

        ob_start();
        echo '<div class="content-wrapper wuc-flash-host student-doc-availability-alerts" aria-live="polite">';
        foreach ($items as $item) {
            $href = htmlspecialchars((string)$item['href'], ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars((string)$item['title'], ENT_QUOTES, 'UTF-8');
            $text = htmlspecialchars((string)$item['text'], ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8');
            $icon = htmlspecialchars((string)$item['icon'], ENT_QUOTES, 'UTF-8');
            echo '<div class="alert alert-success border-0 shadow-sm d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2" role="status">';
            echo '<div><i class="fas ' . $icon . ' me-2"></i><strong>' . $title . '</strong> <span class="text-muted">' . $text . '</span></div>';
            echo '<a class="btn btn-sm btn-success" href="' . $href . '">' . $label . '</a>';
            echo '</div>';
        }
        echo '</div>';
        return (string)ob_get_clean();
    }
}
