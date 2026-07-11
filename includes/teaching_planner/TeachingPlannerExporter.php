<?php
declare(strict_types=1);

final class TeachingPlannerExporter
{
    public function __construct(private mysqli $db)
    {
    }

    public function exportPlan(int $planId, string $actorId, string $format = 'docx'): array
    {
        $service = new TeachingPlannerService($this->db);
        $plan = $service->plan($planId);
        if (!$plan) {
            throw new RuntimeException('Teaching plan not found.');
        }
        if ($plan['lecturer_staff_id'] !== $actorId && !$this->canMonitorPlan($plan)) {
            throw new RuntimeException('You are not authorized to export this plan.');
        }
        if (!in_array($format, ['docx', 'pdf'], true)) {
            throw new RuntimeException('Unsupported export format.');
        }
        $stmt = $this->db->prepare('SELECT tv.* FROM document_template_versions tv WHERE tv.id = ?');
        $templateId = (int)$plan['template_version_id'];
        $stmt->bind_param('i', $templateId); $stmt->execute(); $template = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$template) {
            throw new RuntimeException('The retained template version used by this plan is missing.');
        }
        $templatePath = tp_safe_storage_path((string)$template['storage_path']);
        if (!is_file($templatePath) || !hash_equals((string)$template['checksum_sha256'], (string)hash_file('sha256', $templatePath))) {
            throw new RuntimeException('The retained template failed its integrity check.');
        }
        $folder = 'exports/' . date('Y') . '/' . $planId;
        $absoluteFolder = tp_storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);
        if (!is_dir($absoluteFolder) && !mkdir($absoluteFolder, 0770, true) && !is_dir($absoluteFolder)) {
            throw new RuntimeException('The export folder is not writable.');
        }
        $safeNumber = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$plan['document_number']) ?: 'teaching-plan';
        $relativeDocx = $folder . '/' . $safeNumber . '-v' . (int)$plan['revision_number'] . '-' . date('YmdHis') . '.docx';
        $docxPath = tp_storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDocx);
        if (!copy($templatePath, $docxPath)) {
            throw new RuntimeException('The export could not be created from the retained template.');
        }
        $this->mergeDocx($docxPath, $plan);
        $relative = $relativeDocx;
        $finalPath = $docxPath;
        if ($format === 'pdf') {
            $converter = $this->pdfConverter();
            if ($converter === null) {
                @unlink($docxPath);
                throw new RuntimeException('PDF conversion is not available on this server. Export Word instead.');
            }
            $command = escapeshellarg($converter) . ' --headless --convert-to pdf --outdir ' . escapeshellarg($absoluteFolder) . ' ' . escapeshellarg($docxPath) . ' 2>&1';
            exec($command, $output, $code);
            $pdfPath = substr($docxPath, 0, -5) . '.pdf';
            if ($code !== 0 || !is_file($pdfPath) || filesize($pdfPath) < 100) {
                throw new RuntimeException('PDF conversion failed. The Word export remains available.');
            }
            $relative = substr($relativeDocx, 0, -5) . '.pdf';
            $finalPath = $pdfPath;
        }
        $checksum = hash_file('sha256', $finalPath);
        $watermarked = $plan['status'] !== 'approved' && $plan['status'] !== 'in_use' ? 1 : 0;
        $stmt = $this->db->prepare('INSERT INTO teaching_plan_exports (teaching_plan_id, export_format, storage_path, checksum_sha256, is_draft_watermarked, exported_by) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssis', $planId, $format, $relative, $checksum, $watermarked, $actorId); $stmt->execute(); $exportId = (int)$this->db->insert_id; $stmt->close();
        tp_audit($this->db, $planId, 'plan.exported', 'teaching_plan_export', (string)$exportId, ['format' => $format, 'watermarked' => (bool)$watermarked]);
        return ['id' => $exportId, 'path' => $finalPath, 'relative_path' => $relative, 'filename' => basename($finalPath), 'mime' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    }

    public function pdfAvailable(): bool
    {
        return $this->pdfConverter() !== null;
    }

    public function previewTemplate(int $versionId, string $actorId): array
    {
        $stmt = $this->db->prepare('SELECT tv.*, t.document_type, t.name FROM document_template_versions tv INNER JOIN document_templates t ON t.id = tv.template_id WHERE tv.id = ?');
        $stmt->bind_param('i', $versionId); $stmt->execute(); $template = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$template) {
            throw new RuntimeException('Template version not found.');
        }
        $source = tp_safe_storage_path((string)$template['storage_path']);
        if (!is_file($source) || !hash_equals((string)$template['checksum_sha256'], (string)hash_file('sha256', $source))) {
            throw new RuntimeException('Template integrity check failed.');
        }
        $folder = tp_storage_root() . DIRECTORY_SEPARATOR . 'previews';
        if (!is_dir($folder) && !mkdir($folder, 0770, true) && !is_dir($folder)) {
            throw new RuntimeException('Preview storage is not writable.');
        }
        $path = $folder . DIRECTORY_SEPARATOR . 'template-' . $versionId . '-' . bin2hex(random_bytes(5)) . '.docx';
        if (!copy($source, $path)) {
            throw new RuntimeException('Template preview could not be created.');
        }
        $values = [
            'institution_name' => 'WUC Preview Institution', 'department_name' => 'Engineering Section',
            'programme_name' => 'Sample Diploma Programme', 'course_code' => 'SAMPLE101', 'course_name' => 'Sample Course',
            'lecturer_name' => 'A. Lecturer', 'academic_year' => date('Y'), 'academic_period' => 'Term 1',
            'document_number' => 'PREVIEW-0001', 'document_version' => (string)$template['version_number'], 'approval_status' => 'PREVIEW',
            'watermark' => 'PREVIEW', 'week_number' => '1', 'week_dates' => date('d M Y'), 'session_date' => date('d M Y'),
            'start_time' => '08:00', 'end_time' => '10:00', 'topic' => 'Introduction to the approved topic',
            'subtopics' => 'Concept A; Concept B', 'learning_outcomes' => 'Explain and apply the approved concepts.',
            'teaching_methods' => 'Guided discussion and demonstration', 'lecturer_activities' => 'Explain and demonstrate.',
            'learner_activities' => 'Discuss and practise.', 'resources' => 'Approved handbook and equipment',
            'assessment_method' => 'Observation and short formative task', 'references' => 'Approved syllabus references',
            'duration' => '120 minutes', 'remarks' => 'Template preview data only', 'prior_knowledge' => 'Required prerequisite knowledge',
            'introduction' => 'Connect prior learning to the lesson.', 'conclusion' => 'Summarise and check understanding.',
            'homework' => 'Complete the approved follow-up task.', 'reflection' => 'Lecturer reflection area',
            'stage_name' => 'Lesson development', 'stage_duration' => '90 minutes', 'formative_assessment' => 'Questions and observation',
        ];
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            @unlink($path); throw new RuntimeException('Preview DOCX could not be opened.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (!preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $name)) continue;
            $xml = (string)$zip->getFromIndex($i);
            $xml = $this->replace($xml, $values);
            $zip->addFromString($name, $xml);
        }
        $zip->close();
        tp_audit($this->db, null, 'template.previewed', 'document_template_version', (string)$versionId, ['actor' => $actorId]);
        return ['path' => $path, 'filename' => 'preview-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$template['name']) . '-v' . (int)$template['version_number'] . '.docx'];
    }

    private function mergeDocx(string $path, array $plan): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The retained DOCX template could not be opened.');
        }
        $base = [
            'institution_name' => 'WUC', 'department_name' => '', 'programme_name' => $plan['program_name'],
            'course_code' => $plan['course_code'], 'course_name' => $plan['course_name'], 'lecturer_name' => $plan['lecturer_name'],
            'academic_year' => $plan['academic_year'], 'academic_period' => $plan['academic_period'],
            'document_number' => $plan['document_number'], 'document_version' => (string)$plan['revision_number'],
            'approval_status' => strtoupper(str_replace('_', ' ', $plan['status'])),
            'watermark' => in_array($plan['status'], ['approved', 'in_use'], true) ? '' : 'DRAFT',
        ];
        $unresolved = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string)$zip->getNameIndex($index);
            if (!preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $name)) {
                continue;
            }
            $xml = (string)$zip->getFromIndex($index);
            if ($name === 'word/document.xml') {
                $xml = $this->repeatPlanRow($xml, $plan['items'], $base);
                if (!in_array($plan['status'], ['approved', 'in_use'], true)) {
                    $xml = $this->addDraftWatermark($xml);
                }
            }
            $xml = $this->replace($xml, $base);
            if (preg_match_all('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i', html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'), $matches)) {
                $unresolved = array_merge($unresolved, $matches[1]);
            }
            $zip->addFromString($name, $xml);
        }
        $zip->close();
        if ($unresolved !== []) {
            @unlink($path);
            throw new RuntimeException('Export stopped because these template placeholders were unresolved: ' . implode(', ', array_unique($unresolved)) . '.');
        }
    }

    private function repeatPlanRow(string $xml, array $items, array $base): string
    {
        if (!preg_match_all('#<w:tr\b[^>]*>.*?</w:tr>#s', $xml, $rows, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('The Scheme of Work template does not contain a table row for plan items.');
        }
        foreach ($rows[0] as [$row, $offset]) {
            $plain = html_entity_decode(strip_tags($row), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (!str_contains($plain, '{{topic}}') && !str_contains($plain, '{{week_number}}')) {
                continue;
            }
            $rendered = '';
            foreach ($items as $item) {
                $values = array_merge($base, [
                    'week_number' => (string)$item['week_number'], 'week_dates' => (string)$item['session_date'],
                    'session_date' => (string)$item['session_date'], 'start_time' => substr((string)$item['start_time'], 0, 5),
                    'end_time' => substr((string)$item['end_time'], 0, 5), 'topic' => (string)$item['topic'],
                    'subtopics' => (string)$item['subtopics'], 'learning_outcomes' => (string)$item['learning_outcomes'],
                    'teaching_methods' => (string)$item['teaching_methods'], 'lecturer_activities' => (string)$item['lecturer_activities'],
                    'learner_activities' => (string)$item['learner_activities'], 'resources' => (string)$item['resources'],
                    'assessment_method' => (string)$item['assessment_method'], 'references' => (string)$item['references_text'],
                    'duration' => (string)$item['duration_minutes'] . ' minutes', 'remarks' => (string)$item['remarks'],
                ]);
                $rendered .= $this->replace($row, $values);
            }
            return substr($xml, 0, $offset) . $rendered . substr($xml, $offset + strlen($row));
        }
        throw new RuntimeException('The Scheme of Work template needs a table row containing {{topic}} or {{week_number}} so weekly rows can repeat.');
    }

    private function replace(string $xml, array $values): string
    {
        foreach ($values as $key => $value) {
            $escaped = htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml = preg_replace('/\{\{\s*' . preg_quote((string)$key, '/') . '\s*\}\}/i', $escaped, $xml) ?? $xml;
        }
        return $xml;
    }

    private function addDraftWatermark(string $xml): string
    {
        if (!str_contains($xml, 'xmlns:v=')) {
            $xml = preg_replace('/<w:document\b/', '<w:document xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office"', $xml, 1) ?? $xml;
        }
        $watermark = '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:pict><v:shape id="TeachingPlannerDraftWatermark" o:spid="_x0000_s2049" type="#_x0000_t136" style="position:absolute;margin-left:0;margin-top:0;width:468pt;height:117pt;rotation:315;z-index:-251654144;mso-position-horizontal:center;mso-position-horizontal-relative:page;mso-position-vertical:center;mso-position-vertical-relative:page" fillcolor="silver" stroked="f"><v:textpath style="font-family:Arial;font-size:1pt" string="DRAFT"/></v:shape></w:pict></w:r></w:p>';
        return preg_replace('/<w:body>/', '<w:body>' . $watermark, $xml, 1) ?? $xml;
    }

    private function pdfConverter(): ?string
    {
        foreach (['C:\\Program Files\\LibreOffice\\program\\soffice.exe', 'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private function canMonitorPlan(array $plan): bool
    {
        if (!function_exists('hasRole')) {
            return false;
        }
        if (hasRole(ROLE_REGISTRAR) || isSystemsAdmin()) {
            return true;
        }
        if (!hasRole(ROLE_HEAD_OF_DEPARTMENT)) {
            return false;
        }
        $sectionId = trim((string)($_SESSION['hos_section_id'] ?? ''));
        if ($sectionId === '') {
            return false;
        }
        $stmt = $this->db->prepare('SELECT 1 FROM programs p INNER JOIN departments d ON d.id = p.department_id WHERE p.program_code = ? AND d.section_id = ? LIMIT 1');
        $stmt->bind_param('ss', $plan['program_code'], $sectionId); $stmt->execute();
        $allowed = (bool)$stmt->get_result()->fetch_row(); $stmt->close();
        return $allowed;
    }
}
