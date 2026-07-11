<?php
/**
 * app/ExamRegistration/Controller.php
 *
 * Handles the HTTP request/response cycle.
 * - Reads POST / SESSION
 * - Calls Validator then Repository
 * - Writes flash messages to SESSION
 * - Issues redirects
 *
 * Returns a ViewModel array that the view file consumes.
 * No HTML is produced here.
 */

namespace App\ExamRegistration;

class Controller
{
    public function __construct(
        private Repository $repo,
    ) {}

    /**
     * Main entry point — call this from examReg.php before any output.
     *
     * @return array ViewModel for the view
     */
    public function handle(): array
    {
        $viewModel = [
            'searchPerformed' => false,
            'courses'         => [],
            'lastSearch'      => [
                'Sid'      => '',
                'semester' => '',
                'Year'     => '',
            ],
        ];

        if (empty($_POST)) {
            return $viewModel;
        }

        // ── 1. Registration submit ────────────────────────────────────────────
        if (isset($_POST['register'])) {
            return $this->handleRegister($viewModel);
        }

        // ── 2. Search submit ─────────────────────────────────────────────────
        if (isset($_POST['search'])) {
            return $this->handleSearch($viewModel);
        }

        return $viewModel;
    }

    // ─── Private handlers ─────────────────────────────────────────────────────

    private function handleSearch(array $vm): array
    {
        $validation = Validator::search($_POST);

        if (!empty($validation['errors'])) {
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => implode(' ', $validation['errors']),
            ];
            $this->redirect('examReg.php');
        }

        $d = $validation['data'];

        // Preserve form values so the search form stays populated after redirect
        $vm['lastSearch'] = $d;

        try {
            $courses = $this->repo->getEnrolledCourses($d['Sid'], $d['semester'], $d['Year']);
        } catch (\RuntimeException $e) {
            error_log('[ExamReg] Search DB error: ' . $e->getMessage());
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'A database error occurred. Please try again.',
            ];
            $this->redirect('examReg.php');
        }

        if (empty($courses)) {
            $_SESSION['flash'] = [
                'type'    => 'warning',
                'message' => 'No registered courses found for the selected assessment period and year.',
            ];
            // Fall through: return vm with empty courses so the view renders correctly
            $vm['searchPerformed'] = true;
            $vm['courses']         = [];
            return $vm;
        }

        $vm['searchPerformed'] = true;
        $vm['courses']         = $courses;
        return $vm;
    }

    private function handleRegister(array $vm): array
    {
        $validation = Validator::register($_POST);

        if (!empty($validation['errors'])) {
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => implode(' ', $validation['errors']),
            ];
            $this->redirect('examReg.php');
        }

        $d = $validation['data'];

        try {
            // ── Duplicate check ───────────────────────────────────────────────
            $duplicates = $this->repo->findDuplicates(
                $d['Sid'],
                $d['course_code'],
                $d['semester'],
                $d['Year']
            );

            if (!empty($duplicates)) {
                $_SESSION['flash'] = [
                    'type'    => 'warning',
                    'message' => 'The following course(s) are already registered for exams: '
                               . htmlspecialchars(implode(', ', $duplicates), ENT_QUOTES, 'UTF-8')
                               . '. Please deselect them and try again.',
                ];
                $this->redirect('examReg.php');
            }

            // ── Insert ────────────────────────────────────────────────────────
            $inserted = $this->repo->registerCourses(
                $d['Sid'],
                $d['course_code'],
                $d['semester'],
                $d['Year'],
                $d['examType']
            );

        } catch (\RuntimeException $e) {
            error_log('[ExamReg] Registration DB error: ' . $e->getMessage());
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Registration failed due to a database error. Please try again.',
            ];
            $this->redirect('examReg.php');
        }

        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => $inserted . ' course(s) successfully registered for examination.',
        ];
        $this->redirect('examReg.php');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}
