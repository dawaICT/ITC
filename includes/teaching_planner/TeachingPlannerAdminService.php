<?php
declare(strict_types=1);

final class TeachingPlannerAdminService
{
    public function __construct(private mysqli $db, private TeachingPlannerTemplateValidator $validator)
    {
    }

    public function uploadTemplate(array $file, array $data, string $actorId): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The template upload did not complete successfully.');
        }
        $name = trim((string)($data['name'] ?? ''));
        $documentType = (string)($data['document_type'] ?? '');
        $structure = (string)($data['structure_type'] ?? '');
        $allowedTypes = ['scheme_of_work', 'lesson_plan', 'practical_lesson_plan', 'assessment_plan'];
        $allowedStructures = ['term', 'semester', 'annual', 'short_course'];
        if ($name === '' || !in_array($documentType, $allowedTypes, true) || !in_array($structure, $allowedStructures, true)) {
            throw new RuntimeException('Template name, document type and academic structure are required.');
        }
        $original = basename((string)($file['name'] ?? ''));
        if (strtolower(pathinfo($original, PATHINFO_EXTENSION)) !== 'docx') {
            throw new RuntimeException('Only .docx template files are supported.');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > 10 * 1024 * 1024) {
            throw new RuntimeException('The DOCX template must be between 1 byte and 10 MB.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (!in_array($mime, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], true)) {
            throw new RuntimeException('The uploaded file MIME type is not allowed for a DOCX template.');
        }
        $report = $this->validator->validate($tmp, $documentType);
        $checksum = hash_file('sha256', $tmp);
        if ($checksum === false) {
            throw new RuntimeException('The template checksum could not be calculated.');
        }
        $departmentId = !empty($data['department_id']) ? (int)$data['department_id'] : null;
        $programType = trim((string)($data['program_type'] ?? '')) ?: null;
        $effectiveDate = (string)($data['effective_date'] ?? date('Y-m-d'));
        if (DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate) === false) {
            throw new RuntimeException('Choose a valid effective date.');
        }
        $templateId = !empty($data['template_id']) ? (int)$data['template_id'] : 0;
        $this->db->begin_transaction();
        $storedAbsolute = null;
        try {
            if ($templateId > 0) {
                $stmt = $this->db->prepare('SELECT id, document_type FROM document_templates WHERE id = ? FOR UPDATE');
                $stmt->bind_param('i', $templateId);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$existing || $existing['document_type'] !== $documentType) {
                    throw new RuntimeException('The selected template series does not match this document type.');
                }
            } else {
                $stmt = $this->db->prepare('INSERT INTO document_templates (name, document_type, department_id, program_type, structure_type, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('ssisss', $name, $documentType, $departmentId, $programType, $structure, $actorId);
                $stmt->execute();
                $templateId = (int)$this->db->insert_id;
                $stmt->close();
            }
            $stmt = $this->db->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version FROM document_template_versions WHERE template_id = ? FOR UPDATE');
            $stmt->bind_param('i', $templateId);
            $stmt->execute();
            $version = (int)$stmt->get_result()->fetch_assoc()['next_version'];
            $stmt->close();
            $relative = 'templates/' . date('Y') . '/' . $templateId . '/v' . $version . '-' . substr($checksum, 0, 16) . '.docx';
            $storedAbsolute = tp_storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_dir(dirname($storedAbsolute)) && !mkdir(dirname($storedAbsolute), 0770, true) && !is_dir(dirname($storedAbsolute))) {
                throw new RuntimeException('The protected template folder could not be created.');
            }
            if (!move_uploaded_file($tmp, $storedAbsolute)) {
                throw new RuntimeException('The template could not be moved into protected storage.');
            }
            $reportJson = tp_json($report);
            $mapJson = tp_json(array_fill_keys($report['placeholders'], null));
            $stmt = $this->db->prepare('INSERT INTO document_template_versions (template_id, version_number, effective_date, original_filename, storage_path, mime_type, file_size, checksum_sha256, placeholder_map, validation_report, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('iissssissss', $templateId, $version, $effectiveDate, $original, $relative, $mime, $size, $checksum, $mapJson, $reportJson, $actorId);
            $stmt->execute();
            $versionId = (int)$this->db->insert_id;
            $stmt->close();
            tp_audit($this->db, null, 'template.uploaded', 'document_template_version', (string)$versionId, ['template_id' => $templateId, 'version' => $version, 'valid' => $report['valid']]);
            $this->db->commit();
            return $versionId;
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($storedAbsolute !== null && is_file($storedAbsolute)) {
                @unlink($storedAbsolute);
            }
            throw $e;
        }
    }

    public function activateTemplate(int $versionId, string $actorId): void
    {
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('SELECT tv.*, t.id AS template_id FROM document_template_versions tv INNER JOIN document_templates t ON t.id = tv.template_id WHERE tv.id = ? FOR UPDATE');
            $stmt->bind_param('i', $versionId);
            $stmt->execute();
            $version = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$version) {
                throw new RuntimeException('Template version not found.');
            }
            $report = tp_decode_json($version['validation_report']);
            if (empty($report['valid'])) {
                throw new RuntimeException('This template cannot be activated until all missing and unknown placeholders are corrected.');
            }
            $path = tp_safe_storage_path((string)$version['storage_path']);
            if (!is_file($path) || !hash_equals((string)$version['checksum_sha256'], (string)hash_file('sha256', $path))) {
                throw new RuntimeException('Template integrity check failed. Upload a new version instead of activating this file.');
            }
            $templateId = (int)$version['template_id'];
            $stmt = $this->db->prepare("UPDATE document_template_versions SET status = 'superseded' WHERE template_id = ? AND status = 'active' AND id <> ?");
            $stmt->bind_param('ii', $templateId, $versionId);
            $stmt->execute(); $stmt->close();
            $stmt = $this->db->prepare("UPDATE document_template_versions SET status = 'active', activated_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $versionId);
            $stmt->execute(); $stmt->close();
            $stmt = $this->db->prepare("UPDATE document_templates SET status = 'active' WHERE id = ?");
            $stmt->bind_param('i', $templateId);
            $stmt->execute(); $stmt->close();
            tp_audit($this->db, null, 'template.activated', 'document_template_version', (string)$versionId, ['template_id' => $templateId, 'actor' => $actorId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function createSyllabus(array $data, array $outcomes, array $topics, string $actorId): int
    {
        $program = trim((string)($data['program_code'] ?? ''));
        $course = trim((string)($data['course_code'] ?? ''));
        $version = trim((string)($data['version_label'] ?? ''));
        $hours = (float)($data['total_recommended_hours'] ?? 0);
        if ($program === '' || $course === '' || $version === '' || $hours <= 0) {
            throw new RuntimeException('Programme, course, syllabus version and total recommended hours are required.');
        }
        $cleanOutcomes = array_values(array_filter(array_map('trim', $outcomes), static fn(string $v): bool => $v !== ''));
        if ($cleanOutcomes === []) {
            throw new RuntimeException('Add at least one approved learning outcome before saving the syllabus.');
        }
        $cleanTopics = [];
        $topicHours = 0.0;
        foreach ($topics as $topic) {
            $title = trim((string)($topic['topic_title'] ?? ''));
            $topicDuration = (float)($topic['recommended_hours'] ?? 0);
            $learning = trim((string)($topic['learning_outcomes'] ?? ''));
            if ($title === '' && $topicDuration <= 0 && $learning === '') {
                continue;
            }
            if ($title === '' || $topicDuration <= 0 || $learning === '') {
                throw new RuntimeException('Every syllabus topic needs a title, positive recommended hours and learning outcomes.');
            }
            $topic['topic_title'] = $title;
            $topic['recommended_hours'] = $topicDuration;
            $topic['learning_outcomes'] = $learning;
            $cleanTopics[] = $topic;
            $topicHours += $topicDuration;
        }
        if ($cleanTopics === []) {
            throw new RuntimeException('Add at least one syllabus topic.');
        }
        if (abs($topicHours - $hours) > 0.01) {
            throw new RuntimeException(sprintf('Topic hours total %.2f, but syllabus total recommended hours are %.2f. Make these values equal.', $topicHours, $hours));
        }
        $curriculumId = !empty($data['curriculum_version_id']) ? (int)$data['curriculum_version_id'] : null;
        $purpose = trim((string)($data['purpose'] ?? ''));
        $credits = ($data['credits'] ?? '') !== '' ? (float)$data['credits'] : null;
        $assessment = trim((string)($data['assessment_criteria'] ?? ''));
        $resources = trim((string)($data['resources'] ?? ''));
        $source = (string)($data['source_type'] ?? 'manual');
        if (!in_array($source, ['manual', 'word_import', 'pdf_import', 'excel_import', 'ai_extraction'], true)) {
            $source = 'manual';
        }
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO syllabus_versions (curriculum_version_id, program_code, course_code, version_label, purpose, credits, total_recommended_hours, assessment_criteria, resources, source_type, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('issssddssss', $curriculumId, $program, $course, $version, $purpose, $credits, $hours, $assessment, $resources, $source, $actorId);
            $stmt->execute();
            $syllabusId = (int)$this->db->insert_id;
            $stmt->close();
            $stmt = $this->db->prepare('INSERT INTO syllabus_outcomes (syllabus_version_id, outcome_code, outcome_text, display_order) VALUES (?, ?, ?, ?)');
            foreach ($cleanOutcomes as $index => $outcome) {
                $code = 'LO' . ($index + 1); $order = $index + 1;
                $stmt->bind_param('issi', $syllabusId, $code, $outcome, $order); $stmt->execute();
            }
            $stmt->close();
            $stmt = $this->db->prepare('INSERT INTO syllabus_topics (syllabus_version_id, topic_code, topic_title, subtopics, recommended_hours, learning_outcomes, assessment_criteria, resources, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($cleanTopics as $index => $topic) {
                $code = trim((string)($topic['topic_code'] ?? '')) ?: 'T' . ($index + 1);
                $title = $topic['topic_title']; $subtopics = trim((string)($topic['subtopics'] ?? '')); $topicDuration = (float)$topic['recommended_hours'];
                $learning = $topic['learning_outcomes']; $topicAssessment = trim((string)($topic['assessment_criteria'] ?? '')); $topicResources = trim((string)($topic['resources'] ?? '')); $order = $index + 1;
                $stmt->bind_param('isssdsssi', $syllabusId, $code, $title, $subtopics, $topicDuration, $learning, $topicAssessment, $topicResources, $order); $stmt->execute();
            }
            $stmt->close();
            tp_audit($this->db, null, 'syllabus.created', 'syllabus_version', (string)$syllabusId, ['program' => $program, 'course' => $course, 'topic_hours' => $topicHours]);
            $this->db->commit();
            return $syllabusId;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function approveSyllabus(int $syllabusId, string $actorId, bool $isHos, ?string $sectionId = null): void
    {
        if (!$isHos) {
            throw new RuntimeException('Academic syllabus approval requires Head of Section authority; administrator access alone is not sufficient.');
        }
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM syllabus_versions WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $syllabusId); $stmt->execute();
            $syllabus = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$syllabus || !in_array($syllabus['status'], ['draft', 'under_review'], true)) {
                throw new RuntimeException('Only draft or under-review syllabus versions can be approved.');
            }
            if ($sectionId !== null && $sectionId !== '') {
                $scopeStmt = $this->db->prepare('SELECT 1 FROM programs p INNER JOIN departments d ON d.id = p.department_id WHERE p.program_code = ? AND d.section_id = ? LIMIT 1');
                $scopeStmt->bind_param('ss', $syllabus['program_code'], $sectionId);
                $scopeStmt->execute(); $inScope = (bool)$scopeStmt->get_result()->fetch_row(); $scopeStmt->close();
                if (!$inScope) {
                    throw new RuntimeException('This syllabus is outside your assigned section.');
                }
            }
            $stmt = $this->db->prepare('SELECT COUNT(*) AS topic_count, COALESCE(SUM(recommended_hours),0) AS topic_hours, SUM(CASE WHEN learning_outcomes IS NULL OR TRIM(learning_outcomes) = \'\' THEN 1 ELSE 0 END) AS missing_outcomes FROM syllabus_topics WHERE syllabus_version_id = ?');
            $stmt->bind_param('i', $syllabusId); $stmt->execute(); $check = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ((int)$check['topic_count'] < 1 || (int)$check['missing_outcomes'] > 0 || abs((float)$check['topic_hours'] - (float)$syllabus['total_recommended_hours']) > 0.01) {
                throw new RuntimeException('The syllabus cannot be approved: verify topics, learning outcomes and total recommended hours.');
            }
            $stmt = $this->db->prepare("UPDATE syllabus_versions SET status = 'superseded' WHERE program_code = ? AND course_code = ? AND status = 'approved' AND id <> ?");
            $stmt->bind_param('ssi', $syllabus['program_code'], $syllabus['course_code'], $syllabusId); $stmt->execute(); $stmt->close();
            $stmt = $this->db->prepare("UPDATE syllabus_versions SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->bind_param('si', $actorId, $syllabusId); $stmt->execute(); $stmt->close();
            tp_audit($this->db, null, 'syllabus.approved', 'syllabus_version', (string)$syllabusId, []);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback(); throw $e;
        }
    }

    public function templates(): array
    {
        $sql = "SELECT tv.*, t.name, t.document_type, t.structure_type, t.department_id, t.program_type, t.status AS template_status,
                       d.department_name, CONCAT(s.Fname, ' ', s.Lname) AS uploader_name
                FROM document_template_versions tv INNER JOIN document_templates t ON t.id = tv.template_id
                LEFT JOIN departments d ON d.id = t.department_id LEFT JOIN staff s ON s.staff_id = tv.uploaded_by
                ORDER BY tv.created_at DESC";
        return $this->db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    public function syllabi(?string $sectionId = null): array
    {
        $sql = "SELECT sv.*, p.program_name, c.course_name, CONCAT(s.Fname, ' ', s.Lname) AS creator_name,
                       (SELECT COUNT(*) FROM syllabus_topics st WHERE st.syllabus_version_id = sv.id) AS topic_count,
                       (SELECT COALESCE(SUM(st.recommended_hours),0) FROM syllabus_topics st WHERE st.syllabus_version_id = sv.id) AS topic_hours
                FROM syllabus_versions sv INNER JOIN programs p ON p.program_code = sv.program_code
                INNER JOIN courses c ON c.course_code = sv.course_code LEFT JOIN staff s ON s.staff_id = sv.created_by
                LEFT JOIN departments d ON d.id = p.department_id";
        if ($sectionId !== null && $sectionId !== '') {
            $stmt = $this->db->prepare($sql . ' WHERE d.section_id = ? ORDER BY sv.created_at DESC');
            $stmt->bind_param('s', $sectionId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $rows;
        }
        return $this->db->query($sql . ' ORDER BY sv.created_at DESC')->fetch_all(MYSQLI_ASSOC);
    }
}
