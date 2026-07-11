# Teaching Planner template author guide

Templates must be genuine `.docx` files no larger than 10 MB. Keep the institution's logo, fonts, margins, orientation, headers and footers in the template; the exporter preserves them.

## Placeholder syntax

Use double braces and lowercase field names, for example `{{course_code}}`. Do not add spaces or punctuation inside a field name.

### Administrative fields

- `{{institution_name}}`
- `{{department_name}}`
- `{{programme_name}}`
- `{{course_code}}`
- `{{course_name}}`
- `{{lecturer_name}}`
- `{{academic_year}}`
- `{{academic_period}}`
- `{{document_number}}`
- `{{document_version}}`
- `{{approval_status}}`
- `{{watermark}}`

### Repeating Scheme of Work row

Put at least `{{week_number}}` or `{{topic}}` inside one Word table row. That row is cloned once for every structured plan item. Other row fields are:

- `{{week_dates}}`, `{{session_date}}`, `{{start_time}}`, `{{end_time}}`
- `{{topic}}`, `{{subtopics}}`, `{{learning_outcomes}}`
- `{{teaching_methods}}`, `{{lecturer_activities}}`, `{{learner_activities}}`
- `{{resources}}`, `{{assessment_method}}`, `{{references}}`
- `{{duration}}`, `{{remarks}}`

Keep every repeating field in the same table row. The row may contain merged cells and template styling. Do not use text boxes for repeating fields.

### Lesson Plan fields

- `{{prior_knowledge}}`, `{{introduction}}`, `{{conclusion}}`
- `{{homework}}`, `{{reflection}}`
- `{{stage_name}}`, `{{stage_duration}}`, `{{formative_assessment}}`

Lesson templates may also use the administrative, topic, outcome, activity, method, resource, assessment and duration fields above.

## Validation and activation

1. Uploading creates a new immutable version; it never overwrites an approved file.
2. The validation report lists missing, unknown and duplicated placeholders.
3. Missing required or unknown placeholders block activation. Duplicates are reported because they may be intentional in a header and body.
4. Use **Preview** to download a copy filled with non-confidential test data.
5. Activate only after opening the preview in Word and checking every page.
6. A plan always retains the exact template version and SHA-256 checksum used to generate it.

## Common errors

- **No repeating row found:** place `{{topic}}` or `{{week_number}}` in a Word table row.
- **Unresolved placeholder:** correct the field spelling and upload a new version.
- **Unknown placeholder:** choose a supported field; custom fields are not silently invented.
- **Broken placeholder:** retype the complete placeholder in one continuous Word text run if an editor split it across incompatible objects.

