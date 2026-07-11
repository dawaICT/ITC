# WUCPortal Business Logic Rules

The portal must support multiple training models:

1. Short courses
- Flexible duration.
- May not follow strict term or semester structure.
- Some may be internally examined.

2. Certificate and Diploma programmes
- Usually follow academic year and term structures.
- Must support TEVETA-style registration, assessment, and reporting.

3. Transport and Logistics
- This programme is an exception where 6 months makes up a semester.
- Do not force it into the normal term-only model.

4. Academic portal and eLearning portal
- Academic portal must show academic information only.
- eLearning portal must show learning content, online classes, resources, assignments, quizzes, and learning progress only.
- Shared authentication is acceptable, but module data must remain separated.

5. Student numbers
- Do not use WUC prefixes for ITC users.
- Student numbers should follow ITC/programme logic.
- Staff IDs should use ITC-based staff identification logic.

Always check existing tables before changing registration, student, lecturer, assessment, programme, course, or fee logic.