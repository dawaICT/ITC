<?php
declare(strict_types=1);

final class TeachingPlannerService
{
    public function __construct(private mysqli $db)
    {
    }

    public function lecturerAssignments(string $staffId): array
    {
        $sql = "SELECT lca.id AS assignment_id, lca.staff_id AS lecturer_staff_id, lca.course_offering_id, lca.assignment_role,
                       co.program_code, co.class_group_id, co.academic_period_id, co.academic_year_id,
                       c.course_code, c.course_name, p.program_name, p.program_type, p.structure_type,
                       ay.academic_year_name, ap.period_name, ap.start_date AS period_start, ap.end_date AS period_end,
                       cg.group_name
                FROM lecturer_course_assignments lca
                INNER JOIN course_offerings co ON co.id = lca.course_offering_id
                INNER JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
                INNER JOIN courses c ON c.course_code = cc.course_code
                INNER JOIN programs p ON p.program_code = co.program_code
                LEFT JOIN academic_years ay ON ay.id = co.academic_year_id
                LEFT JOIN academic_periods ap ON ap.id = co.academic_period_id
                LEFT JOIN class_groups cg ON cg.id = co.class_group_id
                WHERE lca.staff_id = ? AND lca.status = 'active' AND co.status IN ('planned','active')
                ORDER BY ay.start_date DESC, p.program_name, c.course_code";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function activeTemplates(string $type, ?int $departmentId = null, ?string $programType = null, ?string $structure = null): array
    {
        $sql = "SELECT tv.id, tv.version_number, tv.effective_date, t.name, t.document_type,
                       t.department_id, t.program_type, t.structure_type
                FROM document_template_versions tv
                INNER JOIN document_templates t ON t.id = tv.template_id
                WHERE t.document_type = ? AND t.status = 'active' AND tv.status = 'active'
                  AND (t.department_id IS NULL OR t.department_id = ?)
                  AND (t.program_type IS NULL OR t.program_type = '' OR t.program_type = ?)
                  AND (? IS NULL OR t.structure_type = ?)
                ORDER BY (t.department_id IS NOT NULL) DESC, (t.program_type IS NOT NULL AND t.program_type <> '') DESC,
                         tv.effective_date DESC, tv.version_number DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('sisss', $type, $departmentId, $programType, $structure, $structure);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function approvedSyllabi(string $programCode, string $courseCode): array
    {
        $stmt = $this->db->prepare("SELECT id, version_label, total_recommended_hours, approved_at FROM syllabus_versions WHERE program_code = ? AND course_code = ? AND status = 'approved' ORDER BY approved_at DESC, id DESC");
        $stmt->bind_param('ss', $programCode, $courseCode);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function generatePreview(string $staffId, array $request): array
    {
        $assignmentId = (int)($request['assignment_id'] ?? 0);
        $templateVersionId = (int)($request['template_version_id'] ?? 0);
        $syllabusVersionId = (int)($request['syllabus_version_id'] ?? 0);
        $assignment = $this->assignment($assignmentId, $staffId);
        if (!$assignment) {
            throw new RuntimeException('The selected lecturer/course assignment is not authorized or is no longer active.');
        }
        $template = $this->templateVersion($templateVersionId, 'scheme_of_work');
        if (!$template || $template['status'] !== 'active' || $template['template_status'] !== 'active') {
            throw new RuntimeException('Select an active Scheme of Work template.');
        }
        $syllabus = $this->syllabus($syllabusVersionId, (string)$assignment['program_code'], (string)$assignment['course_code']);
        if (!$syllabus || $syllabus['status'] !== 'approved') {
            throw new RuntimeException('Select an approved syllabus version for this programme and course.');
        }
        $start = (string)($request['start_date'] ?? $assignment['period_start'] ?? '');
        $end = (string)($request['end_date'] ?? $assignment['period_end'] ?? '');
        if (!$this->validDate($start) || !$this->validDate($end)) {
            throw new RuntimeException('The academic period must have valid teaching start and end dates.');
        }
        if (!empty($assignment['period_start']) && $start < $assignment['period_start']) {
            throw new RuntimeException('The teaching start date cannot be before the assigned academic period.');
        }
        if (!empty($assignment['period_end']) && $end > $assignment['period_end']) {
            throw new RuntimeException('The teaching end date cannot be after the assigned academic period.');
        }
        $timetable = $this->timetable($assignment, $start, $end);
        if ($timetable === []) {
            throw new RuntimeException('No timetable sessions exist for this assigned course and period. Configure the timetable before generating a plan.');
        }
        $topics = $this->topics($syllabusVersionId);
        $events = $this->calendarEvents($start, $end, (string)($assignment['academic_year_name'] ?? ''));
        $scheduler = new TeachingPlannerScheduler();
        $schedule = $scheduler->generate([
            'start_date' => $start,
            'end_date' => $end,
            'timetable' => $timetable,
            'events' => $events,
            'topics' => $topics,
            'assessment_weeks' => $this->numberList($request['assessment_weeks'] ?? ''),
            'revision_weeks' => $this->numberList($request['revision_weeks'] ?? ''),
            'locked_items' => (array)($request['locked_items'] ?? []),
        ]);
        return [
            'assignment' => $assignment,
            'template' => $template,
            'syllabus' => $syllabus,
            'settings' => [
                'start_date' => $start,
                'end_date' => $end,
                'assessment_weeks' => $this->numberList($request['assessment_weeks'] ?? ''),
                'revision_weeks' => $this->numberList($request['revision_weeks'] ?? ''),
                'generation_scope' => (string)($request['generation_scope'] ?? 'full_period'),
                'generate_lesson_plans' => !empty($request['generate_lesson_plans']),
            ],
            'schedule' => $schedule,
        ];
    }

    public function savePreview(string $staffId, array $preview): int
    {
        $assignment = $preview['assignment'];
        $schedule = $preview['schedule'];
        $settings = $preview['settings'];
        $ownsTransaction = !$this->inTransaction();
        if ($ownsTransaction) $this->db->begin_transaction();
        try {
            $sql = "SELECT id FROM teaching_plans WHERE lecturer_staff_id = ? AND course_offering_id = ? AND academic_year = ? AND academic_period = ? AND status IN ('draft','submitted','changes_requested','resubmitted','approved','in_use') FOR UPDATE";
            $stmt = $this->db->prepare($sql);
            $offeringId = (int)$assignment['course_offering_id'];
            $academicYear = (string)($assignment['academic_year_name'] ?? '');
            $periodName = (string)($assignment['period_name'] ?? '');
            $stmt->bind_param('siss', $staffId, $offeringId, $academicYear, $periodName);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                throw new RuntimeException('An active plan already exists for this lecturer, class, course and academic period. Open it or create a controlled revision.');
            }
            $stmt->close();
            $documentNumber = $this->nextDocumentNumber();
            $assignmentId = (int)$assignment['assignment_id'];
            $classGroupId = !empty($assignment['class_group_id']) ? (int)$assignment['class_group_id'] : null;
            $programCode = (string)$assignment['program_code'];
            $templateVersionId = (int)$preview['template']['id'];
            $syllabusVersionId = (int)$preview['syllabus']['id'];
            $settingsJson = tp_json($settings);
            $warningsJson = tp_json($schedule['warnings']);
            $coverage = (float)$schedule['coverage_percent'];
            $planned = (float)$schedule['planned_hours'];
            $available = (float)$schedule['available_hours'];
            $stmt = $this->db->prepare("INSERT INTO teaching_plans (document_number, lecturer_assignment_id, course_offering_id, lecturer_staff_id, program_code, class_group_id, academic_year, academic_period, period_start, period_end, template_version_id, syllabus_version_id, generation_settings, coverage_percent, planned_hours, available_hours, warnings, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('siississssiisdddss', $documentNumber, $assignmentId, $offeringId, $staffId, $programCode, $classGroupId, $academicYear, $periodName, $settings['start_date'], $settings['end_date'], $templateVersionId, $syllabusVersionId, $settingsJson, $coverage, $planned, $available, $warningsJson, $staffId);
            $stmt->execute();
            $planId = (int)$this->db->insert_id;
            $stmt->close();
            $this->insertItems($planId, $schedule['items']);
            tp_audit($this->db, $planId, 'plan.created', 'teaching_plan', (string)$planId, ['document_number' => $documentNumber, 'items' => count($schedule['items'])]);
            if ($ownsTransaction) $this->db->commit();
            return $planId;
        } catch (Throwable $e) {
            if ($ownsTransaction) $this->db->rollback();
            throw $e;
        }
    }

    public function plansForLecturer(string $staffId): array
    {
        $stmt = $this->db->prepare($this->planListSql() . ' WHERE tp.lecturer_staff_id = ? ORDER BY tp.updated_at DESC');
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function plansForReview(?string $sectionId = null): array
    {
        $sql = $this->planListSql() . " LEFT JOIN departments d ON d.id = p.department_id WHERE tp.status IN ('submitted','resubmitted','changes_requested','approved','in_use')";
        if ($sectionId !== null && $sectionId !== '') {
            $sql .= ' AND d.section_id = ?';
        }
        $sql .= ' ORDER BY FIELD(tp.status,\'submitted\',\'resubmitted\',\'changes_requested\',\'approved\',\'in_use\'), tp.updated_at DESC';
        $stmt = $this->db->prepare($sql);
        if ($sectionId !== null && $sectionId !== '') {
            $stmt->bind_param('s', $sectionId);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function plan(int $planId): ?array
    {
        $stmt = $this->db->prepare($this->planListSql() . ' WHERE tp.id = ? LIMIT 1');
        $stmt->bind_param('i', $planId);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$plan) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM teaching_plan_items WHERE teaching_plan_id = ? ORDER BY sequence_number');
        $stmt->bind_param('i', $planId);
        $stmt->execute();
        $plan['items'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $stmt = $this->db->prepare('SELECT a.*, CONCAT(s.Fname, \' \', s.Lname) AS actor_name FROM teaching_plan_approvals a LEFT JOIN staff s ON s.staff_id = a.actor_staff_id WHERE a.teaching_plan_id = ? ORDER BY a.created_at DESC');
        $stmt->bind_param('i', $planId);
        $stmt->execute();
        $plan['approvals'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $plan;
    }

    public function updateItem(string $staffId, int $planId, int $itemId, array $fields, int $expectedLock): void
    {
        $plan = $this->plan($planId);
        if (!$plan || $plan['lecturer_staff_id'] !== $staffId) {
            throw new RuntimeException('You are not authorized to edit this plan.');
        }
        if (!in_array($plan['status'], ['draft', 'changes_requested'], true)) {
            throw new RuntimeException('Only draft or changes-requested plans can be edited. Create a revision for an approved plan.');
        }
        if ((int)$plan['version_lock'] !== $expectedLock) {
            throw new RuntimeException('This plan was changed in another session. Reload before saving to avoid overwriting newer work.');
        }
        $allowed = ['topic', 'subtopics', 'learning_outcomes', 'teaching_methods', 'lecturer_activities', 'learner_activities', 'resources', 'assessment_method', 'references_text', 'remarks', 'status'];
        $values = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $values[$key] = mb_substr(trim((string)$fields[$key]), 0, $key === 'topic' ? 255 : 10000);
            }
        }
        $values['is_locked'] = !empty($fields['is_locked']) ? 1 : 0;
        if ($values === []) {
            return;
        }
        $set = implode(', ', array_map(static fn(string $key): string => "`{$key}` = ?", array_keys($values)));
        $params = array_values($values);
        $types = str_repeat('s', count($params));
        $params[] = $itemId;
        $params[] = $planId;
        $types .= 'ii';
        $ownsTransaction = !$this->inTransaction();
        if ($ownsTransaction) $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("UPDATE teaching_plan_items SET {$set} WHERE id = ? AND teaching_plan_id = ?");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                throw new RuntimeException('The plan row was not found or did not change.');
            }
            $stmt->close();
            $stmt = $this->db->prepare('UPDATE teaching_plans SET version_lock = version_lock + 1 WHERE id = ? AND version_lock = ?');
            $stmt->bind_param('ii', $planId, $expectedLock);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('This plan was changed in another session. Reload and retry.');
            }
            $stmt->close();
            tp_audit($this->db, $planId, 'item.updated', 'teaching_plan_item', (string)$itemId, array_keys($values));
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction) $this->db->rollback();
            throw $e;
        }
    }

    public function transition(int $planId, string $actorId, string $action, string $comment, string $actorMode, ?string $sectionId = null): void
    {
        $map = [
            'submit' => [['draft', 'changes_requested'], 'submitted'],
            'resubmit' => [['changes_requested'], 'resubmitted'],
            'request_changes' => [['submitted', 'resubmitted'], 'changes_requested'],
            'approve' => [['submitted', 'resubmitted'], 'approved'],
            'mark_in_use' => [['approved'], 'in_use'],
            'archive' => [['approved', 'in_use'], 'archived'],
        ];
        if (!isset($map[$action])) {
            throw new RuntimeException('Unsupported workflow action.');
        }
        $ownsTransaction = !$this->inTransaction();
        if ($ownsTransaction) $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM teaching_plans WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $planId);
            $stmt->execute();
            $plan = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$plan) {
                throw new RuntimeException('Teaching plan not found.');
            }
            [$allowedFrom, $to] = $map[$action];
            if (!in_array($plan['status'], $allowedFrom, true)) {
                throw new RuntimeException('This workflow action is not valid for the plan\'s current status.');
            }
            $lecturerAction = in_array($action, ['submit', 'resubmit'], true);
            if ($lecturerAction && ($actorMode !== 'lecturer' || $plan['lecturer_staff_id'] !== $actorId)) {
                throw new RuntimeException('Only the assigned lecturer can submit this plan.');
            }
            if (!$lecturerAction && $actorMode !== 'hos') {
                throw new RuntimeException('Only an assigned Head of Section can make academic approval decisions. Administrator access alone does not grant approval authority.');
            }
            if (!$lecturerAction && $sectionId !== null && $sectionId !== '') {
                $scopeStmt = $this->db->prepare('SELECT 1 FROM programs p INNER JOIN departments d ON d.id = p.department_id WHERE p.program_code = ? AND d.section_id = ? LIMIT 1');
                $scopeStmt->bind_param('ss', $plan['program_code'], $sectionId);
                $scopeStmt->execute();
                $inScope = (bool)$scopeStmt->get_result()->fetch_row();
                $scopeStmt->close();
                if (!$inScope) {
                    throw new RuntimeException('This plan is outside your assigned section.');
                }
            }
            if ($action === 'request_changes' && trim($comment) === '') {
                throw new RuntimeException('A clear review comment is required when requesting changes.');
            }
            $submitted = in_array($to, ['submitted', 'resubmitted'], true) ? ', submitted_at = NOW()' : '';
            $approved = $to === 'approved' ? ', approved_by = ?, approved_at = NOW()' : '';
            if ($to === 'approved') {
                $stmt = $this->db->prepare("UPDATE teaching_plans SET status = ?, version_lock = version_lock + 1 {$submitted} {$approved} WHERE id = ?");
                $stmt->bind_param('ssi', $to, $actorId, $planId);
            } else {
                $stmt = $this->db->prepare("UPDATE teaching_plans SET status = ?, version_lock = version_lock + 1 {$submitted} WHERE id = ?");
                $stmt->bind_param('si', $to, $planId);
            }
            $stmt->execute();
            $stmt->close();
            $approvalAction = $action === 'submit' ? 'submitted' : ($action === 'resubmit' ? 'resubmitted' : $action);
            $stmt = $this->db->prepare('INSERT INTO teaching_plan_approvals (teaching_plan_id, action, from_status, to_status, comment, actor_staff_id) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('isssss', $planId, $approvalAction, $plan['status'], $to, $comment, $actorId);
            $stmt->execute();
            $stmt->close();
            tp_audit($this->db, $planId, 'workflow.' . $action, 'teaching_plan', (string)$planId, ['from' => $plan['status'], 'to' => $to]);
            $this->notifyTransition($plan, $to, $comment, $actorId);
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction) $this->db->rollback();
            throw $e;
        }
    }

    public function createRevision(int $planId, string $staffId): int
    {
        $ownsTransaction = !$this->inTransaction();
        if ($ownsTransaction) $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM teaching_plans WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $planId);
            $stmt->execute();
            $plan = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$plan || $plan['lecturer_staff_id'] !== $staffId || !in_array($plan['status'], ['approved', 'in_use', 'archived'], true)) {
                throw new RuntimeException('Only the assigned lecturer can revise an approved or archived plan.');
            }
            $rootId = (int)($plan['parent_plan_id'] ?: $plan['id']);
            $stmt = $this->db->prepare('SELECT COALESCE(MAX(revision_number), 0) + 1 AS next_revision FROM teaching_plans WHERE id = ? OR parent_plan_id = ?');
            $stmt->bind_param('ii', $rootId, $rootId);
            $stmt->execute();
            $revision = (int)$stmt->get_result()->fetch_assoc()['next_revision'];
            $stmt->close();
            $documentNumber = preg_replace('/-R\d+$/', '', (string)$plan['document_number']) . '-R' . $revision;
            $stmt = $this->db->prepare("INSERT INTO teaching_plans (parent_plan_id, revision_number, document_number, lecturer_assignment_id, course_offering_id, lecturer_staff_id, program_code, class_group_id, academic_year, academic_period, period_start, period_end, template_version_id, syllabus_version_id, generation_settings, status, coverage_percent, planned_hours, available_hours, warnings, created_by) SELECT ?, ?, ?, lecturer_assignment_id, course_offering_id, lecturer_staff_id, program_code, class_group_id, academic_year, academic_period, period_start, period_end, template_version_id, syllabus_version_id, generation_settings, 'draft', coverage_percent, planned_hours, available_hours, warnings, ? FROM teaching_plans WHERE id = ?");
            $stmt->bind_param('iissi', $rootId, $revision, $documentNumber, $staffId, $planId);
            $stmt->execute();
            $newId = (int)$this->db->insert_id;
            $stmt->close();
            $stmt = $this->db->prepare("INSERT INTO teaching_plan_items (teaching_plan_id, syllabus_topic_id, item_type, sequence_number, week_number, session_date, start_time, end_time, topic, subtopics, learning_outcomes, teaching_methods, lecturer_activities, learner_activities, resources, assessment_method, references_text, duration_minutes, remarks, status, is_locked, lock_reason) SELECT ?, syllabus_topic_id, item_type, sequence_number, week_number, session_date, start_time, end_time, topic, subtopics, learning_outcomes, teaching_methods, lecturer_activities, learner_activities, resources, assessment_method, references_text, duration_minutes, remarks, 'planned', 0, NULL FROM teaching_plan_items WHERE teaching_plan_id = ? ORDER BY sequence_number");
            $stmt->bind_param('ii', $newId, $planId);
            $stmt->execute();
            $stmt->close();
            tp_audit($this->db, $newId, 'plan.revision_created', 'teaching_plan', (string)$newId, ['source_plan_id' => $planId, 'revision' => $revision]);
            if ($ownsTransaction) $this->db->commit();
            return $newId;
        } catch (Throwable $e) {
            if ($ownsTransaction) $this->db->rollback();
            throw $e;
        }
    }

    public function saveLessonPlan(string $staffId, int $itemId, int $templateVersionId, array $data, array $stages): int
    {
        $stmt = $this->db->prepare("SELECT i.*, p.lecturer_staff_id, p.status AS plan_status FROM teaching_plan_items i INNER JOIN teaching_plans p ON p.id = i.teaching_plan_id WHERE i.id = ?");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item || $item['lecturer_staff_id'] !== $staffId || !in_array($item['plan_status'], ['draft', 'changes_requested'], true)) {
            throw new RuntimeException('This Scheme of Work row is not editable by the current lecturer.');
        }
        $template = $this->templateVersion($templateVersionId, 'lesson_plan');
        if (!$template || $template['status'] !== 'active') {
            throw new RuntimeException('Select an active Lesson Plan template.');
        }
        $stageTotal = TeachingPlannerLessonPlanValidator::validateStageDurations((int)$item['duration_minutes'], $stages);
        $ownsTransaction = !$this->inTransaction();
        if ($ownsTransaction) $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('SELECT COALESCE(MAX(revision_number), 0) + 1 AS next_revision FROM lesson_plans WHERE teaching_plan_item_id = ?');
            $stmt->bind_param('i', $itemId);
            $stmt->execute();
            $revision = (int)$stmt->get_result()->fetch_assoc()['next_revision'];
            $stmt->close();
            $prior = trim((string)($data['prior_knowledge'] ?? ''));
            $intro = trim((string)($data['introduction_text'] ?? ''));
            $conclusion = trim((string)($data['conclusion_text'] ?? ''));
            $homework = trim((string)($data['homework_text'] ?? ''));
            $reflection = trim((string)($data['reflection_text'] ?? ''));
            $stmt = $this->db->prepare('INSERT INTO lesson_plans (teaching_plan_item_id, template_version_id, revision_number, prior_knowledge, introduction_text, conclusion_text, homework_text, reflection_text, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('iiissssss', $itemId, $templateVersionId, $revision, $prior, $intro, $conclusion, $homework, $reflection, $staffId);
            $stmt->execute();
            $lessonId = (int)$this->db->insert_id;
            $stmt->close();
            $stmt = $this->db->prepare('INSERT INTO lesson_plan_stages (lesson_plan_id, stage_name, lecturer_activity, learner_activity, method_resources, formative_assessment, duration_minutes, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($stages as $index => $stage) {
                $name = mb_substr(trim((string)($stage['stage_name'] ?? '')), 0, 120);
                $lecturer = trim((string)($stage['lecturer_activity'] ?? ''));
                $learner = trim((string)($stage['learner_activity'] ?? ''));
                $resources = trim((string)($stage['method_resources'] ?? ''));
                $assessment = trim((string)($stage['formative_assessment'] ?? ''));
                $minutes = (int)($stage['duration_minutes'] ?? 0);
                $order = $index + 1;
                if ($name === '' || $minutes <= 0) {
                    throw new RuntimeException('Every lesson stage needs a name and a positive duration.');
                }
                $stmt->bind_param('isssssii', $lessonId, $name, $lecturer, $learner, $resources, $assessment, $minutes, $order);
                $stmt->execute();
            }
            $stmt->close();
            tp_audit($this->db, (int)$item['teaching_plan_id'], 'lesson.created', 'lesson_plan', (string)$lessonId, ['item_id' => $itemId, 'stage_minutes' => $stageTotal]);
            if ($ownsTransaction) $this->db->commit();
            return $lessonId;
        } catch (Throwable $e) {
            if ($ownsTransaction) $this->db->rollback();
            throw $e;
        }
    }

    public function suggestField(string $staffId, int $planId, int $itemId, string $field): string
    {
        $allowed = ['teaching_methods', 'lecturer_activities', 'learner_activities', 'assessment_method'];
        if (!in_array($field, $allowed, true)) {
            throw new RuntimeException('Suggestions are available only for supported enrichment fields.');
        }
        $stmt = $this->db->prepare("SELECT i.topic, i.subtopics, i.learning_outcomes, p.status, p.lecturer_staff_id
                                    FROM teaching_plan_items i INNER JOIN teaching_plans p ON p.id = i.teaching_plan_id
                                    WHERE i.id = ? AND p.id = ?");
        $stmt->bind_param('ii', $itemId, $planId); $stmt->execute(); $item = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$item || $item['lecturer_staff_id'] !== $staffId || !in_array($item['status'], ['draft','changes_requested'], true)) {
            throw new RuntimeException('You are not authorized to request a suggestion for this plan row.');
        }
        $topic = trim((string)$item['topic']);
        $outcome = trim((string)$item['learning_outcomes']);
        $suggestions = [
            'teaching_methods' => 'Use a short guided explanation, worked demonstration, targeted questioning and supervised learner practice for ' . $topic . '.',
            'lecturer_activities' => 'Introduce the approved outcome, model the required process for ' . $topic . ', check understanding, observe practice and give corrective feedback.',
            'learner_activities' => 'Recall prerequisite knowledge, observe the demonstration, discuss the approved outcome, complete guided practice and explain the result.',
            'assessment_method' => 'Use outcome-aligned oral questions, observation of the practical or written task, and a short exit check: ' . ($outcome !== '' ? $outcome : $topic) . '.',
        ];
        tp_audit($this->db, $planId, 'suggestion.generated', 'teaching_plan_item', (string)$itemId, ['field' => $field, 'provider' => 'deterministic_fallback']);
        return $suggestions[$field];
    }

    private function assignment(int $id, string $staffId): ?array
    {
        foreach ($this->lecturerAssignments($staffId) as $assignment) {
            if ((int)$assignment['assignment_id'] === $id) {
                $stmt = $this->db->prepare('SELECT p.department_id, d.department_name FROM programs p LEFT JOIN departments d ON d.id = p.department_id WHERE p.program_code = ?');
                $stmt->bind_param('s', $assignment['program_code']);
                $stmt->execute();
                $scope = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
                return array_merge($assignment, $scope);
            }
        }
        return null;
    }

    private function templateVersion(int $id, string $type): ?array
    {
        $stmt = $this->db->prepare('SELECT tv.*, t.name, t.document_type, t.status AS template_status FROM document_template_versions tv INNER JOIN document_templates t ON t.id = tv.template_id WHERE tv.id = ? AND t.document_type = ?');
        $stmt->bind_param('is', $id, $type);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function syllabus(int $id, string $programCode, string $courseCode): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM syllabus_versions WHERE id = ? AND program_code = ? AND course_code = ?');
        $stmt->bind_param('iss', $id, $programCode, $courseCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function topics(int $syllabusId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM syllabus_topics WHERE syllabus_version_id = ? ORDER BY display_order, id');
        $stmt->bind_param('i', $syllabusId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function timetable(array $assignment, string $start, string $end): array
    {
        $stmt = $this->db->prepare("SELECT cs.day_of_week, cs.start_time, cs.end_time, cs.start_date, cs.end_date, cs.recurrence, cs.schedule_type, cs.room
                                    FROM course_schedule cs
                                    INNER JOIN staff s ON s.id = cs.lecturer_id
                                    WHERE cs.course_code = ? AND s.staff_id = ?
                                      AND COALESCE(cs.is_active, 1) = 1 AND cs.status NOT IN ('cancelled','inactive')
                                      AND (cs.start_date IS NULL OR cs.start_date <= ?)
                                      AND (cs.end_date IS NULL OR cs.end_date >= ?)
                                    ORDER BY FIELD(cs.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), cs.start_time");
        $courseCode = (string)$assignment['course_code'];
        $lecturer = (string)$assignment['lecturer_staff_id'];
        $stmt->bind_param('ssss', $courseCode, $lecturer, $end, $start);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function calendarEvents(string $start, string $end, string $academicYear): array
    {
        $stmt = $this->db->prepare("SELECT event_type, title, starts_at, ends_at FROM academic_calendar_events WHERE status = 'active' AND starts_at <= CONCAT(?, ' 23:59:59') AND ends_at >= CONCAT(?, ' 00:00:00') AND (academic_year IS NULL OR academic_year = '' OR academic_year = ?)");
        $stmt->bind_param('sss', $end, $start, $academicYear);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function insertItems(int $planId, array $items): void
    {
        $sql = 'INSERT INTO teaching_plan_items (teaching_plan_id, syllabus_topic_id, item_type, sequence_number, week_number, session_date, start_time, end_time, topic, subtopics, learning_outcomes, teaching_methods, lecturer_activities, learner_activities, resources, assessment_method, references_text, duration_minutes, remarks, status, is_locked) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $this->db->prepare($sql);
        foreach ($items as $item) {
            $topicId = !empty($item['syllabus_topic_id']) ? (int)$item['syllabus_topic_id'] : null;
            $type = (string)$item['item_type']; $sequence = (int)$item['sequence_number']; $week = (int)$item['week_number'];
            $date = (string)$item['session_date']; $start = (string)$item['start_time']; $end = (string)$item['end_time'];
            $topic = (string)$item['topic']; $subtopics = (string)$item['subtopics']; $outcomes = (string)$item['learning_outcomes'];
            $methods = (string)$item['teaching_methods']; $lecturer = (string)$item['lecturer_activities']; $learner = (string)$item['learner_activities'];
            $resources = (string)$item['resources']; $assessment = (string)$item['assessment_method']; $references = (string)$item['references_text'];
            $minutes = (int)$item['duration_minutes']; $remarks = (string)$item['remarks']; $status = (string)$item['status']; $locked = (int)$item['is_locked'];
            $stmt->bind_param('iisiiisssssssssssissi', $planId, $topicId, $type, $sequence, $week, $date, $start, $end, $topic, $subtopics, $outcomes, $methods, $lecturer, $learner, $resources, $assessment, $references, $minutes, $remarks, $status, $locked);
            $stmt->execute();
        }
        $stmt->close();
    }

    private function nextDocumentNumber(): string
    {
        $prefix = 'SOW-' . date('Y');
        $stmt = $this->db->prepare('SELECT COUNT(*) + 1 AS next_number FROM teaching_plans WHERE document_number LIKE CONCAT(?, \'-%\')');
        $stmt->bind_param('s', $prefix);
        $stmt->execute();
        $number = (int)$stmt->get_result()->fetch_assoc()['next_number'];
        $stmt->close();
        return $prefix . '-' . str_pad((string)$number, 5, '0', STR_PAD_LEFT);
    }

    private function planListSql(): string
    {
        return "SELECT tp.*, c.course_code, c.course_name, p.program_name, cg.group_name,
                       CONCAT(s.Fname, ' ', s.Lname) AS lecturer_name,
                       tv.version_number AS template_version, dt.name AS template_name, sv.version_label AS syllabus_version
                FROM teaching_plans tp
                INNER JOIN course_offerings co ON co.id = tp.course_offering_id
                INNER JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
                INNER JOIN courses c ON c.course_code = cc.course_code
                INNER JOIN programs p ON p.program_code = tp.program_code
                INNER JOIN staff s ON s.staff_id = tp.lecturer_staff_id
                LEFT JOIN class_groups cg ON cg.id = tp.class_group_id
                INNER JOIN document_template_versions tv ON tv.id = tp.template_version_id
                INNER JOIN document_templates dt ON dt.id = tv.template_id
                INNER JOIN syllabus_versions sv ON sv.id = tp.syllabus_version_id";
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function numberList(mixed $value): array
    {
        $values = is_array($value) ? $value : preg_split('/[\s,;]+/', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY);
        $numbers = array_values(array_unique(array_filter(array_map('intval', $values ?: []), static fn(int $n): bool => $n > 0 && $n <= 60)));
        sort($numbers);
        return $numbers;
    }

    private function notifyTransition(array $plan, string $to, string $comment, string $actorId): void
    {
        if (!function_exists('wuc_notify_portal')) {
            return;
        }
        if (in_array($to, ['changes_requested', 'approved'], true)) {
            wuc_notify_portal($this->db, [
                'user_id' => (string)$plan['lecturer_staff_id'], 'user_role' => 'lecturer', 'module' => 'teaching_planner',
                'alert_type' => 'teaching_plan_' . $to, 'severity' => $to === 'changes_requested' ? 'warning' : 'info',
                'title' => $to === 'approved' ? 'Teaching plan approved' : 'Teaching plan changes requested',
                'message' => 'Plan ' . $plan['document_number'] . ' is now ' . str_replace('_', ' ', $to) . '.' . ($comment !== '' ? ' ' . $comment : ''),
                'entity_type' => 'teaching_plan', 'entity_id' => (string)$plan['id'],
                'action_url' => '/wucportal/lecturers/teaching_planner.php?view=' . (int)$plan['id'], 'dedupe_days' => 0,
            ]);
        }
    }

    private function inTransaction(): bool
    {
        $result = $this->db->query('SELECT @@in_transaction AS active_transaction');
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['active_transaction'] ?? 0) === 1;
    }
}
