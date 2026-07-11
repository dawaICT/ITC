<?php
declare(strict_types=1);

final class TeachingPlannerScheduler
{
    private const DAY_NUMBERS = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7];

    public function generate(array $input): array
    {
        $start = new DateTimeImmutable((string)$input['start_date']);
        $end = new DateTimeImmutable((string)$input['end_date']);
        if ($end < $start) {
            throw new InvalidArgumentException('The teaching end date must be on or after the start date.');
        }
        $sessions = $this->buildSessions($start, $end, (array)($input['timetable'] ?? []), (array)($input['events'] ?? []));
        if ($sessions === []) {
            throw new RuntimeException('No valid timetable sessions remain after calendar exclusions. Configure the timetable and academic calendar first.');
        }
        $topics = $this->orderedTopics((array)($input['topics'] ?? []));
        if ($topics === []) {
            throw new RuntimeException('The approved syllabus has no schedulable topics.');
        }
        $assessmentWeeks = array_map('intval', (array)($input['assessment_weeks'] ?? []));
        $revisionWeeks = array_map('intval', (array)($input['revision_weeks'] ?? []));
        $lockedByDate = [];
        foreach ((array)($input['locked_items'] ?? []) as $locked) {
            if (!empty($locked['session_date'])) {
                $lockedByDate[(string)$locked['session_date'] . '|' . (string)($locked['start_time'] ?? '')] = $locked;
            }
        }

        $topicIndex = 0;
        $remainingMinutes = (int)round(((float)$topics[0]['recommended_hours']) * 60);
        $items = [];
        $allocatedMinutes = 0;
        foreach ($sessions as $session) {
            $key = $session['session_date'] . '|' . $session['start_time'];
            if (isset($lockedByDate[$key])) {
                $locked = $lockedByDate[$key];
                $locked['sequence_number'] = count($items) + 1;
                $items[] = $locked;
                $allocatedMinutes += (int)($locked['duration_minutes'] ?? 0);
                continue;
            }
            $week = 1 + (int)floor(($start->diff(new DateTimeImmutable($session['session_date']))->days ?? 0) / 7);
            if (in_array($week, $assessmentWeeks, true)) {
                $items[] = $this->reservedItem($session, count($items) + 1, $week, 'assessment', 'Scheduled assessment');
                continue;
            }
            if (in_array($week, $revisionWeeks, true)) {
                $items[] = $this->reservedItem($session, count($items) + 1, $week, 'revision', 'Revision and syllabus consolidation');
                continue;
            }
            if (!isset($topics[$topicIndex])) {
                $items[] = $this->reservedItem($session, count($items) + 1, $week, 'revision', 'Revision / contingency session');
                continue;
            }
            $topic = $topics[$topicIndex];
            $duration = (int)$session['duration_minutes'];
            $used = min($duration, $remainingMinutes);
            $part = $remainingMinutes > $duration ? ' (continued)' : '';
            $items[] = array_merge($session, [
                'syllabus_topic_id' => (int)($topic['id'] ?? 0) ?: null,
                'item_type' => !empty($topic['is_practical']) ? 'practical' : 'lesson',
                'sequence_number' => count($items) + 1,
                'week_number' => $week,
                'topic' => (string)$topic['topic_title'] . $part,
                'subtopics' => (string)($topic['subtopics'] ?? ''),
                'learning_outcomes' => (string)($topic['learning_outcomes'] ?? ''),
                'teaching_methods' => (string)($topic['teaching_methods'] ?? 'Guided instruction, demonstration and learner practice'),
                'lecturer_activities' => (string)($topic['lecturer_activities'] ?? 'Explain, demonstrate, question and provide feedback.'),
                'learner_activities' => (string)($topic['learner_activities'] ?? 'Observe, discuss, practise and respond to checks for understanding.'),
                'resources' => (string)($topic['resources'] ?? ''),
                'assessment_method' => (string)($topic['assessment_criteria'] ?? 'Formative questioning and task observation'),
                'references_text' => '',
                'duration_minutes' => $used,
                'remarks' => $remainingMinutes > $duration ? 'Topic continues in the next valid session.' : '',
                'status' => 'planned',
                'is_locked' => 0,
            ]);
            $allocatedMinutes += $used;
            $remainingMinutes -= $used;
            if ($remainingMinutes <= 0) {
                $topicIndex++;
                if (isset($topics[$topicIndex])) {
                    $remainingMinutes = (int)round(((float)$topics[$topicIndex]['recommended_hours']) * 60);
                }
            }
        }
        $requiredMinutes = array_sum(array_map(static fn(array $t): int => (int)round(((float)$t['recommended_hours']) * 60), $topics));
        $availableMinutes = array_sum(array_column($sessions, 'duration_minutes'));
        $warnings = [];
        if ($requiredMinutes > $availableMinutes) {
            $warnings[] = sprintf('The approved syllabus requires %.2f hours, but only %.2f timetable hours are available. Add sessions or reduce non-teaching reservations.', $requiredMinutes / 60, $availableMinutes / 60);
        } elseif ($availableMinutes > $requiredMinutes) {
            $warnings[] = sprintf('There are %.2f more timetable hours than the syllabus recommends; remaining sessions are marked for revision or contingency.', ($availableMinutes - $requiredMinutes) / 60);
        }
        if ($topicIndex < count($topics)) {
            $warnings[] = sprintf('%d syllabus topic(s) are not fully covered by the available timetable.', count($topics) - $topicIndex);
        }
        return [
            'items' => $items,
            'available_hours' => round($availableMinutes / 60, 2),
            'planned_hours' => round($allocatedMinutes / 60, 2),
            'required_hours' => round($requiredMinutes / 60, 2),
            'coverage_percent' => $requiredMinutes > 0 ? round(min(100, ($allocatedMinutes / $requiredMinutes) * 100), 2) : 0,
            'warnings' => $warnings,
        ];
    }

    private function buildSessions(DateTimeImmutable $start, DateTimeImmutable $end, array $timetable, array $events): array
    {
        $excluded = [];
        foreach ($events as $event) {
            $type = strtolower((string)($event['event_type'] ?? ''));
            if (!in_array($type, ['holiday', 'break', 'closure', 'examination', 'exam'], true)) {
                continue;
            }
            $from = new DateTimeImmutable(substr((string)$event['starts_at'], 0, 10));
            $to = new DateTimeImmutable(substr((string)$event['ends_at'], 0, 10));
            for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
                $excluded[$date->format('Y-m-d')] = (string)($event['title'] ?? 'Non-teaching event');
            }
        }
        $seen = [];
        $result = [];
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            $dateString = $date->format('Y-m-d');
            if (isset($excluded[$dateString])) {
                continue;
            }
            $day = $date->format('l');
            foreach ($timetable as $slot) {
                if (strcasecmp((string)($slot['day_of_week'] ?? $slot['day'] ?? ''), $day) !== 0) {
                    continue;
                }
                if (!empty($slot['start_date']) && $dateString < (string)$slot['start_date']) {
                    continue;
                }
                if (!empty($slot['end_date']) && $dateString > (string)$slot['end_date']) {
                    continue;
                }
                $startTime = substr((string)($slot['start_time'] ?? ''), 0, 5);
                $endTime = substr((string)($slot['end_time'] ?? ''), 0, 5);
                if ($startTime === '' || $endTime === '') {
                    continue;
                }
                $startAt = new DateTimeImmutable($dateString . ' ' . $startTime);
                $endAt = new DateTimeImmutable($dateString . ' ' . $endTime);
                $minutes = (int)(($endAt->getTimestamp() - $startAt->getTimestamp()) / 60);
                if ($minutes <= 0) {
                    continue;
                }
                $key = $dateString . '|' . $startTime . '|' . $endTime;
                if (isset($seen[$key])) {
                    throw new RuntimeException('The timetable contains a duplicate session on ' . $dateString . ' at ' . $startTime . '.');
                }
                $seen[$key] = true;
                $result[] = ['session_date' => $dateString, 'start_time' => $startTime, 'end_time' => $endTime, 'duration_minutes' => $minutes];
            }
        }
        usort($result, static fn(array $a, array $b): int => [$a['session_date'], $a['start_time']] <=> [$b['session_date'], $b['start_time']]);
        return $result;
    }

    private function orderedTopics(array $topics): array
    {
        usort($topics, static fn(array $a, array $b): int => [(int)($a['display_order'] ?? 0), (int)($a['id'] ?? 0)] <=> [(int)($b['display_order'] ?? 0), (int)($b['id'] ?? 0)]);
        $positions = [];
        foreach ($topics as $index => $topic) {
            $positions[(int)($topic['id'] ?? 0)] = $index;
        }
        foreach ($topics as $index => $topic) {
            $prerequisite = (int)($topic['prerequisite_topic_id'] ?? 0);
            if ($prerequisite > 0 && (!isset($positions[$prerequisite]) || $positions[$prerequisite] >= $index)) {
                throw new RuntimeException('Syllabus prerequisite ordering is invalid for topic: ' . (string)($topic['topic_title'] ?? 'Unknown topic'));
            }
        }
        return $topics;
    }

    private function reservedItem(array $session, int $sequence, int $week, string $type, string $title): array
    {
        return array_merge($session, [
            'syllabus_topic_id' => null, 'item_type' => $type, 'sequence_number' => $sequence,
            'week_number' => $week, 'topic' => $title, 'subtopics' => '', 'learning_outcomes' => '',
            'teaching_methods' => '', 'lecturer_activities' => '', 'learner_activities' => '',
            'resources' => '', 'assessment_method' => $type === 'assessment' ? 'Configured assessment' : '',
            'references_text' => '', 'remarks' => '', 'status' => 'planned', 'is_locked' => 0,
        ]);
    }
}

