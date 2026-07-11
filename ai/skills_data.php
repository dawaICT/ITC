<?php
/**
 * Seed skill taxonomy for the offline AI module.
 *
 * A compact, university/job-seeker-oriented starter set so the pipeline works
 * end-to-end today. Later, swap/extend this with the full open ESCO dataset
 * (https://esco.ec.europa.eu/en/use-esco/download) or Tabiya's taxonomy CSV —
 * load_skills.php can ingest a CSV the same way (see --csv option there).
 *
 * Each skill: code, label, type, group, and a few alt labels (synonyms) that
 * get folded into the text we embed, so informal phrasings still match.
 */

function ai_seed_skills(): array
{
    return [
        // --- Digital / IT ---
        ['LOC-001', 'Use word processing software',        'skill',     'Digital', ['typing documents', 'Microsoft Word', 'writing letters on computer']],
        ['LOC-002', 'Use spreadsheet software',            'skill',     'Digital', ['Excel', 'making tables', 'formulas', 'keeping records in spreadsheets']],
        ['LOC-003', 'Manage data and records',             'skill',     'Digital', ['data entry', 'keeping records', 'filing information', 'updating databases']],
        ['LOC-004', 'Use the internet to find information', 'skill',     'Digital', ['searching online', 'browsing', 'research on the web']],
        ['LOC-005', 'Use social media for communication',  'skill',     'Digital', ['Facebook', 'WhatsApp groups', 'posting online', 'managing a page']],
        ['LOC-006', 'Basic computer programming',          'knowledge', 'Digital', ['coding', 'writing scripts', 'Python', 'building a website']],

        // --- Communication ---
        ['LOC-010', 'Communicate clearly in writing',      'skill',     'Communication', ['writing reports', 'drafting emails', 'taking minutes']],
        ['LOC-011', 'Speak in public',                     'skill',     'Communication', ['presenting', 'giving a speech', 'addressing a group', 'facilitating']],
        ['LOC-012', 'Listen actively',                     'attitude',  'Communication', ['paying attention', 'understanding others', 'counselling']],
        ['LOC-013', 'Speak multiple languages',            'knowledge', 'Communication', ['translating', 'interpreting', 'bilingual', 'local languages']],
        ['LOC-014', 'Negotiate',                           'skill',     'Communication', ['bargaining', 'reaching agreement', 'mediating disputes']],

        // --- Customer / Service ---
        ['LOC-020', 'Assist customers',                    'skill',     'Service', ['serving customers', 'helping clients', 'front desk', 'attending to people']],
        ['LOC-021', 'Handle complaints',                   'skill',     'Service', ['resolving issues', 'dealing with unhappy customers']],
        ['LOC-022', 'Sell products or services',           'skill',     'Service', ['selling', 'marketing goods', 'convincing buyers', 'trading']],
        ['LOC-023', 'Handle cash and payments',            'skill',     'Service', ['cashier', 'taking money', 'mobile money', 'making change']],

        // --- Management / Organisation ---
        ['LOC-030', 'Organise and plan work',              'skill',     'Management', ['scheduling', 'planning tasks', 'time management', 'setting priorities']],
        ['LOC-031', 'Lead a team',                         'skill',     'Management', ['supervising', 'managing people', 'team leader', 'coordinating staff']],
        ['LOC-032', 'Manage a budget',                     'skill',     'Management', ['budgeting', 'controlling expenses', 'financial planning']],
        ['LOC-033', 'Keep financial records',              'skill',     'Management', ['bookkeeping', 'accounting', 'tracking income and expenses']],
        ['LOC-034', 'Manage a project',                    'skill',     'Management', ['running a project', 'coordinating activities', 'meeting deadlines']],
        ['LOC-035', 'Train and mentor others',             'skill',     'Management', ['teaching colleagues', 'coaching', 'onboarding new staff']],

        // --- Practical / Trades ---
        ['LOC-040', 'Repair and maintain equipment',       'skill',     'Practical', ['fixing machines', 'maintenance', 'servicing tools']],
        ['LOC-041', 'Drive a vehicle',                     'skill',     'Practical', ['driving', 'delivering goods', 'transport']],
        ['LOC-042', 'Prepare and cook food',               'skill',     'Practical', ['cooking', 'catering', 'kitchen work', 'preparing meals']],
        ['LOC-043', 'Build and construct',                 'skill',     'Practical', ['bricklaying', 'carpentry', 'construction work']],
        ['LOC-044', 'Farm and grow crops',                 'skill',     'Practical', ['farming', 'agriculture', 'gardening', 'keeping livestock']],
        ['LOC-045', 'Tailor and sew',                      'skill',     'Practical', ['sewing', 'making clothes', 'tailoring', 'designing garments']],

        // --- Analytical / Cognitive ---
        ['LOC-050', 'Solve problems',                      'skill',     'Cognitive', ['troubleshooting', 'finding solutions', 'figuring things out']],
        ['LOC-051', 'Analyse data and information',        'skill',     'Cognitive', ['interpreting numbers', 'making sense of data', 'drawing conclusions']],
        ['LOC-052', 'Think critically',                    'skill',     'Cognitive', ['evaluating', 'questioning', 'weighing options']],
        ['LOC-053', 'Pay attention to detail',             'attitude',  'Cognitive', ['being thorough', 'careful work', 'checking for errors']],
        ['LOC-054', 'Do calculations',                     'skill',     'Cognitive', ['arithmetic', 'measuring', 'working with numbers']],

        // --- Personal / Work attitudes ---
        ['LOC-060', 'Work in a team',                      'attitude',  'Personal', ['cooperating', 'collaborating', 'working with others']],
        ['LOC-061', 'Work independently',                  'attitude',  'Personal', ['self-motivated', 'taking initiative', 'working without supervision']],
        ['LOC-062', 'Adapt to change',                     'attitude',  'Personal', ['flexible', 'learning new things', 'handling change']],
        ['LOC-063', 'Be reliable and punctual',            'attitude',  'Personal', ['dependable', 'on time', 'trustworthy', 'responsible']],
        ['LOC-064', 'Manage stress',                       'attitude',  'Personal', ['staying calm', 'working under pressure', 'resilience']],

        // --- Health / Care ---
        ['LOC-070', 'Provide care to others',              'skill',     'Care', ['caregiving', 'looking after people', 'nursing assistance', 'helping the elderly']],
        ['LOC-071', 'Apply basic first aid',              'knowledge', 'Care', ['first aid', 'emergency response', 'treating injuries']],
        ['LOC-072', 'Promote health and hygiene',          'skill',     'Care', ['community health', 'sanitation', 'health education']],
    ];
}
