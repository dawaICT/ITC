<?php
declare(strict_types=1);

final class TeachingPlannerTemplateValidator
{
    public const SUPPORTED = [
        'institution_name', 'department_name', 'programme_name', 'course_code', 'course_name',
        'lecturer_name', 'academic_year', 'academic_period', 'document_number', 'document_version',
        'approval_status', 'watermark', 'week_number', 'week_dates', 'session_date', 'start_time',
        'end_time', 'topic', 'subtopics', 'learning_outcomes', 'teaching_methods',
        'lecturer_activities', 'learner_activities', 'resources', 'assessment_method',
        'references', 'duration', 'remarks', 'prior_knowledge', 'introduction', 'conclusion',
        'homework', 'reflection', 'stage_name', 'stage_duration', 'formative_assessment',
    ];

    private const REQUIRED = [
        'scheme_of_work' => ['programme_name', 'course_code', 'course_name', 'lecturer_name', 'academic_year', 'academic_period', 'week_number', 'topic', 'learning_outcomes', 'duration'],
        'lesson_plan' => ['course_code', 'course_name', 'lecturer_name', 'session_date', 'topic', 'learning_outcomes', 'duration'],
        'practical_lesson_plan' => ['course_code', 'course_name', 'lecturer_name', 'session_date', 'topic', 'learning_outcomes', 'duration'],
        'assessment_plan' => ['course_code', 'course_name', 'academic_year', 'academic_period', 'assessment_method'],
    ];

    public function validate(string $path, string $documentType): array
    {
        if (!is_file($path) || filesize($path) < 4) {
            throw new RuntimeException('The uploaded template is empty or missing.');
        }
        $signature = file_get_contents($path, false, null, 0, 4);
        if ($signature === false || !str_starts_with($signature, "PK\x03\x04")) {
            throw new RuntimeException('The file is not a valid DOCX package.');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP Zip extension is required to inspect DOCX templates.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true || $zip->locateName('word/document.xml') === false) {
            throw new RuntimeException('The file does not contain a valid Word document.');
        }
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (!preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $name)) {
                continue;
            }
            $xml = (string)$zip->getFromIndex($i);
            $plain = preg_replace('/<[^>]+>/', '', $xml) ?? '';
            $text .= html_entity_decode($plain, ENT_QUOTES | ENT_XML1, 'UTF-8') . "\n";
        }
        $zip->close();
        preg_match_all('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i', $text, $matches);
        $found = array_map('strtolower', $matches[1] ?? []);
        $counts = array_count_values($found);
        $unique = array_keys($counts);
        sort($unique);
        $required = self::REQUIRED[$documentType] ?? [];
        $missing = array_values(array_diff($required, $unique));
        $unknown = array_values(array_diff($unique, self::SUPPORTED));
        $duplicates = [];
        foreach ($counts as $placeholder => $count) {
            if ($count > 1) {
                $duplicates[$placeholder] = $count;
            }
        }
        return [
            'valid' => $missing === [] && $unknown === [],
            'placeholders' => $unique,
            'missing' => $missing,
            'unknown' => $unknown,
            'duplicates' => $duplicates,
            'required' => $required,
        ];
    }
}

