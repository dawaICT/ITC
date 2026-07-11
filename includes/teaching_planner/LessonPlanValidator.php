<?php
declare(strict_types=1);

final class TeachingPlannerLessonPlanValidator
{
    public static function validateStageDurations(int $scheduledMinutes, array $stages): int
    {
        if ($scheduledMinutes <= 0) {
            throw new RuntimeException('The scheduled lesson duration must be positive.');
        }
        $total = 0;
        foreach ($stages as $stage) {
            $name = trim((string)($stage['stage_name'] ?? ''));
            $minutes = (int)($stage['duration_minutes'] ?? 0);
            if ($name === '' || $minutes <= 0) {
                throw new RuntimeException('Every lesson stage needs a name and a positive duration.');
            }
            $total += $minutes;
        }
        if ($total !== $scheduledMinutes) {
            throw new RuntimeException('Lesson stage durations must total exactly ' . $scheduledMinutes . ' minutes. Current total: ' . $total . ' minutes.');
        }
        return $total;
    }
}

