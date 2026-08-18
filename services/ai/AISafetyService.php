<?php
declare(strict_types=1);

final class AISafetyService
{
    public function validateQuestion(string $question): string
    {
        $question = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $question) ?? '');
        if ($question === '') {
            throw new InvalidArgumentException('Please enter a question.');
        }
        if (mb_strlen($question) > 2000) {
            throw new InvalidArgumentException('The question is too long. Keep it under 2,000 characters.');
        }
        return $question;
    }

    public function policyResponse(string $question): ?string
    {
        $q = strtolower($question);
        $blocked = [
            'change my mark', 'modify my mark', 'award me marks', 'approve my progression',
            'admit this student', 'disciplinary decision', 'assign me a lecturer',
            'guarantee me a job', 'change my academic record',
        ];
        foreach ($blocked as $phrase) {
            if (str_contains($q, $phrase)) {
                return 'I can explain the relevant policy or help you prepare a request, but I cannot make or alter an official academic, admission, disciplinary, staffing, or employment decision. Please use Ask My Lecturer or contact the responsible office.';
            }
        }
        return null;
    }

    public function cleanModelOutput(string $answer, int $maxChars = 6000): string
    {
        $answer = preg_replace('/<think>.*?<\/think>/is', '', $answer) ?? $answer;
        $answer = trim(strip_tags($answer, '<p><br><strong><em><ul><ol><li><code>'));
        return mb_substr($answer, 0, $maxChars);
    }
}
