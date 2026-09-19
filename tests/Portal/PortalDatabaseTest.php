<?php

declare(strict_types=1);

namespace Tests\Portal;

/**
 * Live-DB smoke test for the portal harness.
 *
 * Proves the PHPUnit suite can reach the same wucportal database the pages use
 * and that the known-good registration/login tables resolve. Kept deliberately
 * small and deterministic so a green baseline is meaningful before the heavy
 * legacy harnesses run.
 */
final class PortalDatabaseTest extends TestCase
{
    public function testCanConnectToLivePortalDatabase(): void
    {
        $this->assertInstanceOf(\mysqli::class, $this->db);
        $res = $this->db->query('SELECT DATABASE()');
        $name = $res ? (string) $res->fetch_row()[0] : '';
        $this->assertSame('wucportal', $name, 'Connected database should be "wucportal".');
    }

    public function testKnownGoodLoginChainTablesExist(): void
    {
        // A working login requires all of these (see wuc-seed-test-accounts).
        foreach (['students', 'student_login', 'student_program', 'programs'] as $table) {
            $this->assertTableExists($table);
        }
    }

    public function testStudentProgramJoinColumnsResolve(): void
    {
        $this->assertColumnExists('student_program', 'Sid');
        $this->assertColumnExists('student_program', 'program_code');
        $this->assertColumnExists('programs', 'program_code');
        $this->assertColumnExists('programs', 'program_name');
        $this->assertColumnExists('students', 'SID');
    }

    public function testProgramsCarryShortCourseFlags(): void
    {
        // Used by includes/short_course_student.php to split portal routes.
        $this->assertColumnExists('programs', 'is_short_course');
        $this->assertColumnExists('programs', 'structure_type');
    }

    public function testHomepageableQueryForStudentDashboardRuns(): void
    {
        $res = $this->db->query(
            'SELECT COUNT(*) AS c FROM students s ' .
            'INNER JOIN student_login sl ON s.SID = sl.Sid ' .
            'INNER JOIN student_program sp ON s.SID = sp.Sid'
        );
        $this->assertNotFalse($res, 'Dashboard join query should execute without a mysqli fatal.');
    }
}