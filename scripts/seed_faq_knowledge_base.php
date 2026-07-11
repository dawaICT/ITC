<?php
declare(strict_types=1);

/**
 * Seed faq_knowledge_base with curated portal FAQs (Sprints 5 + 9).
 *
 * Idempotent: an FAQ is skipped when its intent_key already exists, so the
 * script can be re-run after edits. Adjust answers here (or directly in the
 * table) as institutional policy evolves.
 *
 * Usage: C:\xampp\php\php.exe scripts\seed_faq_knowledge_base.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script can only be run from the command line.';
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/db/connect.php';
require_once $root . '/includes/schema_guard.php';

if (!wuc_table_exists($db, 'faq_knowledge_base')) {
    fwrite(STDERR, "faq_knowledge_base table is missing — apply migrations/20260702_zero_cost_ai_foundations.sql first.\n");
    exit(2);
}

/**
 * [category, intent_key, keywords (comma-separated), question, answer_template (Markdown), roles_allowed, priority]
 */
$faqs = [
    // ===== Admissions =====
    ['admissions', 'adm_how_to_apply', 'how to apply, apply online, application form, admission apply', 'How do I apply for admission?', "**Applying to ITC**\n\n1. Complete the online application form on the ITC website or visit the admissions office.\n2. Attach your academic results, a copy of your NRC/passport, and the application fee deposit slip.\n3. Admissions will review your application and contact you by phone or email.", 'all', 10],
    ['admissions', 'adm_requirements', 'entry requirements, admission requirements, qualifications needed, minimum grades', 'What are the entry requirements?', "**Entry requirements** vary by programme. In general you need your Grade 12 certificate (or equivalent) with passes relevant to the programme, plus identification documents. Short courses and trade tests may accept work experience instead. Contact the admissions office for the specific programme requirements.", 'all', 9],
    ['admissions', 'adm_application_status', 'application status, admission status, application progress, heard nothing', 'How do I check my application status?', "Your application status is shown to the admissions office as **pending**, **accepted**, or **rejected**. Contact the admissions office with your full name and application details, and they will look it up for you.", 'all', 8],
    ['admissions', 'adm_missing_documents', 'missing documents, submit documents, upload results, incomplete application', 'What documents does my application need?', "A complete application includes:\n\n- Certified academic results/certificates\n- NRC or passport copy\n- Application fee deposit slip\n- Programme and intake choice\n- Contact details and next of kin\n\nIf any item is missing the application is flagged incomplete until it is supplied.", 'all', 8],
    ['admissions', 'adm_admission_letter', 'admission letter, acceptance letter, offer letter', 'When do I get my admission letter?', "Admission letters are issued by the admissions office once an application is **accepted**. If you have been told you are accepted but have no letter yet, contact the admissions office to have it generated.", 'all', 7],
    ['admissions', 'adm_intakes', 'intake dates, next intake, when does intake start, application deadline', 'When is the next intake?', "ITC normally runs multiple intakes per year, and short courses may start on a rolling basis. Check the current intake calendar with the admissions office or on the ITC website — deadlines differ by programme.", 'all', 7],
    ['admissions', 'adm_change_program', 'change programme, change program, switch course, transfer programme', 'Can I change my programme after applying?', "Yes — programme changes before enrolment are handled by the admissions office. After enrolment, changes go through the registrar and may depend on space and entry requirements for the new programme.", 'all', 6],
    ['admissions', 'adm_application_fee', 'application fee, how much to apply, apply cost', 'Is there an application fee?', "Yes, a non-refundable application fee applies. Pay it at the accounts office or by bank deposit, and attach the deposit slip to your application as proof.", 'all', 6],

    // ===== Registration =====
    ['registration', 'reg_how_to_register', 'how to register, semester registration, register courses, course registration', 'How do I register for the semester?', "**Semester/term registration**\n\n1. Log into the student portal and open **Registration**.\n2. Confirm your programme, year and period, then submit the registration.\n3. Register your courses for the period.\n\nNote: an outstanding fee balance can block registration — clear it with accounts first.", 'student,all', 10],
    ['registration', 'reg_late', 'late registration, registration closed, missed registration deadline', 'What if I missed the registration deadline?', "Late registration needs approval: returning students may register as *Repeat*, while intake/semester/year changes require the **Systems Office**. Contact the registrar or Systems Office as soon as possible.", 'student,all', 8],
    ['registration', 'reg_blocked_fees', 'cannot register, registration blocked, balance blocking registration', 'Why can\'t I register?', "The most common blockers are:\n\n1. **Outstanding fee balance** — visit the accounts office to pay or agree a payment plan.\n2. **Closed registration window** — late registration needs approval.\n3. **Missing programme assignment** — the registrar can fix your programme record.", 'student,all', 8],
    ['registration', 'reg_repeat_course', 'repeat course, retake course, failed course register', 'How do I register for a repeat course?', "Failed courses should be registered as repeats in the next period they are offered. Your course recommendations on the registration page list suggested retakes. Confirm with your lecturer or the registrar before registering.", 'student,all', 7],
    ['registration', 'reg_proof', 'proof of registration, registration confirmation, registration slip', 'How do I get proof of registration?', "Once registered, your registration record appears on the portal dashboard and registration page. The registrar's office can print and stamp an official proof of registration on request.", 'student,all', 6],

    // ===== Fees / Accounts =====
    ['fees', 'fee_balance', 'fee balance, how much do i owe, outstanding balance, check balance', 'How do I check my fee balance?', "Open **Fees** in the student portal to see your total payable, amount paid, and outstanding balance. The accounts office can give a detailed statement.", 'student,all', 10],
    ['fees', 'fee_payment_methods', 'how to pay, payment methods, bank deposit, mobile money, pay fees', 'How can I pay my fees?', "Fees can be paid by bank deposit to the ITC account or at the accounts office (some campuses also accept mobile money). Always keep your deposit slip / reference number and present it so the payment is receipted to your student ID.", 'student,all', 9],
    ['fees', 'fee_payment_plan', 'payment plan, pay in instalments, instalment, cannot pay full', 'Can I pay fees in instalments?', "Yes — the accounts office can agree a **payment plan**. Note that some services (registration, exams, certificates) may require a minimum percentage paid. Speak to accounts before deadlines to avoid being blocked.", 'student,all', 8],
    ['fees', 'fee_receipt', 'receipt, proof of payment, payment not showing', 'My payment is not showing — what do I do?', "Bring your deposit slip or transaction reference to the accounts office. Payments are captured manually against your student ID, so an unreceipted deposit will not appear until it is verified.", 'student,all', 8],
    ['fees', 'fee_refund', 'refund, money back, overpaid', 'Can I get a refund?', "Refunds for overpayment or withdrawal follow institutional policy and need an application through the accounts office. Approval and processing times vary — ask accounts for the refund procedure.", 'student,all', 5],

    // ===== Exams / Results =====
    ['results', 'res_view_results', 'check results, view results, my marks, exam results, ca marks', 'How do I check my results?', "Published results appear in the student portal under **Results / CA Report**. Results only show once they have been approved and published — unpublished marks are not visible.", 'student,all', 10],
    ['results', 'res_missing_marks', 'missing marks, no marks showing, marks not uploaded, missing ca', 'Why are some of my marks missing?', "Marks appear only after your lecturer uploads them and they pass the approval workflow (Draft → Submitted → Approved → Published). If a course mark is missing long after assessment, ask the lecturer or the registrar's office to check the upload.", 'student,all', 9],
    ['results', 'res_failed_course', 'failed course, what happens if i fail, supplementary, retake exam', 'What happens if I fail a course?', "A failed course normally needs to be repeated when next offered, or a supplementary assessment may apply depending on the programme rules. Speak to your lecturer or head of department about your options.", 'student,all', 8],
    ['results', 'res_transcript', 'transcript, academic record, statement of results', 'How do I get my transcript?', "Official transcripts are issued by the registrar/exams office. You must be in good financial standing. Portal users can view their exam transcript or CA report under **Results**.", 'student,all', 7],
    ['results', 'res_appeal', 'appeal results, remark, query marks, wrong mark', 'How do I query a mark I think is wrong?', "Raise it first with the course lecturer. If unresolved, submit a formal query to the head of department or the exams office within the appeal window. Include the course code and assessment concerned.", 'student,all', 6],

    // ===== eLearning =====
    ['elearning', 'el_access', 'access elearning, e-learning login, learning portal, online classes', 'How do I access eLearning?', "Log into the student portal and open the **eLearning** section for your course materials, assignments and live sessions. Use the same student login.", 'student,all', 9],
    ['elearning', 'el_materials', 'course materials, lecture notes, download notes, study materials', 'Where do I find course materials?', "Course materials are under **eLearning → your course → Materials**. Lecturers also post lesson notes there. If a course shows no materials, ask the lecturer to publish them.", 'student,all', 8],
    ['elearning', 'el_submit_assignment', 'submit assignment, upload assignment, assignment deadline', 'How do I submit an assignment?', "Open the assignment in **eLearning**, attach your file before the due date, and submit. Late submissions are flagged. Contact your lecturer if you have a genuine reason for lateness.", 'student,all', 8],
    ['elearning', 'el_live_sessions', 'live session, online class, google meet, join class link', 'How do I join a live session?', "Scheduled live sessions appear in **eLearning → Live Sessions** with a personal join link near the start time. Links are per-student — do not share yours.", 'student,all', 7],

    // ===== Library =====
    ['library', 'lib_access', 'library, digital library, borrow books, library resources', 'How do I use the library?', "The **Academic Resource Centre** offers physical borrowing at the library desk and a **digital library** in the student portal with resources scoped to your programme and courses.", 'student,all', 7],
    ['library', 'lib_overdue', 'overdue book, return book, library fine', 'What if my library book is overdue?', "Return the book to the library desk as soon as possible; fines may apply per institutional policy. Outstanding library items can block clearance at the end of your programme.", 'student,all', 5],

    // ===== Transport / Driver Training =====
    ['transport', 'tr_courses', 'driving course, driver training, driving school, learn to drive', 'What driver training courses are offered?', "The Transport section offers TEVETA-accredited driver training (light and heavy duty) plus related short courses. Enrolment, eligibility and fees are handled by the transport office — bookings only open after your payment is verified by accounts.", 'all', 7],
    ['transport', 'tr_booking', 'book driving lesson, booking slot, training schedule', 'How do I book my training sessions?', "Training bookings open once accounts has **verified your payment** (BR001 rule). After verification, the transport office schedules your cohort sessions. Unverified payments cannot book.", 'all', 6],
    ['transport', 'tr_certificate', 'driving certificate, transport certificate, course certificate', 'When do I get my driver training certificate?', "Certificates are issued after you **complete the course** and your payments are fully verified. Collect from the transport office once notified.", 'all', 5],

    // ===== Portal / General =====
    ['portal', 'gen_password_reset', 'forgot password, reset password, cannot login, locked out', 'I forgot my password — how do I reset it?', "Use the **Forgot password** link on the login page if available, or contact the ICT/systems office to reset your account. Repeated wrong attempts can temporarily lock the account — wait a few minutes and try again.", 'all', 10],
    ['portal', 'gen_update_details', 'update phone number, change email, update details, edit profile', 'How do I update my personal details?', "Basic contact details can be updated from your portal **Profile** page. Identity fields (name, NRC, date of birth) must be corrected by the registrar with supporting documents.", 'all', 7],
    ['portal', 'gen_contact_offices', 'contact office, phone number office, who do i talk to, help contact', 'Who do I contact for help?', "- **Admissions** — applications and admission letters\n- **Registrar** — registration, records, transcripts\n- **Accounts** — fees, receipts, payment plans\n- **Exams office** — results and appeals\n- **ICT/systems office** — portal and login issues", 'all', 8],
    ['portal', 'gen_opening_hours', 'opening hours, office hours, what time open', 'What are the office opening hours?', "Administrative offices are generally open on weekdays during working hours. Exact times vary by office and campus — confirm with the specific office or the ITC website.", 'all', 4],
    ['portal', 'gen_student_id', 'student id, student number, my sid', 'Where do I find my student ID?', "Your student ID (SID) is on your admission letter, your student card, and in the portal — it appears in your profile and on your dashboard. Use it in all payments and correspondence.", 'student,all', 6],

    // ===== Staff-facing =====
    ['staff', 'stf_upload_ca', 'upload ca, upload marks, capture marks, enter results', 'How do I upload CA marks?', "Open **Upload Assessments / Upload CA** in your module, choose the course and period, and capture the marks. Uploads follow the workflow Draft → Submitted → Approved → Published; students only see published marks.", 'lecturer,staff,registrar', 9],
    ['staff', 'stf_missing_students', 'student not on my list, missing student class list, class register', 'A student is missing from my class list — why?', "Class lists come from **active course registrations**. If a student attends but is not listed, they have not registered the course — send them to the registrar before accepting further work from them.", 'lecturer,staff', 7],
    ['staff', 'stf_risk_watchlist', 'risk watchlist, at risk students, student risk', 'Where do I see at-risk students?', "The **Risk Watchlist** (registrar module) and the risk panels on lecturer/HOD dashboards show rule-based risk scores with stored reasons — low attendance, weak marks, missing coursework, unpaid fees, or incomplete registration.", 'lecturer,staff,registrar,head_of_department', 6],
    ['staff', 'stf_report_insights', 'report insights, generate report, academic reports', 'How do report insights work?', "Report pages include a **rule-based insight block** (summary, warnings, trends, recommended actions) computed from the report rows — always available even with AI offline. The optional **AI summary** button adds a narrative when the local model is up.", 'staff,registrar,dean,head_of_department', 5],
];

$inserted = 0;
$skipped = 0;

$check = $db->prepare('SELECT COUNT(*) AS total FROM faq_knowledge_base WHERE intent_key = ?');
$insert = $db->prepare(
    'INSERT INTO faq_knowledge_base (category, intent_key, keywords, question, answer_template, roles_allowed, priority)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
if (!$check || !$insert) {
    fwrite(STDERR, "Failed to prepare statements: {$db->error}\n");
    exit(3);
}

foreach ($faqs as [$category, $intentKey, $keywords, $question, $answer, $roles, $priority]) {
    $check->bind_param('s', $intentKey);
    $check->execute();
    $row = $check->get_result()->fetch_assoc() ?: [];
    if ((int)($row['total'] ?? 0) > 0) {
        $skipped++;
        continue;
    }
    $insert->bind_param('ssssssi', $category, $intentKey, $keywords, $question, $answer, $roles, $priority);
    if ($insert->execute()) {
        $inserted++;
    } else {
        fwrite(STDERR, "Insert failed for {$intentKey}: {$insert->error}\n");
    }
}
$check->close();
$insert->close();

echo "FAQ seed complete: {$inserted} inserted, {$skipped} already present.\n";
