# AI Guardrails

## Role of AI

AI is a support layer over authorised portal data. It may explain, summarise, recommend, and route a user to the correct human workflow. It is not an academic-record or administrative decision engine.

## Allowed assistance

- Identify the authenticated user’s role, active portal, page, and authorised course context.
- Explain topics and generate revision questions.
- Summarise authorised learning materials with source references.
- Show the learner’s own assignments/deadlines and relevant learning resources.
- Explain evidence-backed skills, gaps, next steps, and career relevance.
- Surface scoped learner risk to assigned lecturers or authorised officers.
- Refer admissions, fee, registration, moderation, or disciplinary decisions to the responsible human role.

## Prohibited assistance

The AI must never:

- create, change, approve, moderate, or publish marks;
- approve/reject admissions or convert an applicant;
- register/deregister a student or change programme assignment;
- create, waive, allocate, or modify fees/payments;
- make disciplinary or graduation-clearance decisions;
- reveal another learner’s, applicant’s, staff member’s, or employer’s protected data;
- bypass course assignment, portal grants, or record ownership;
- claim a skill or credential without stored evidence;
- present an unverified model response as an institutional fact.

## Enforcement layers

1. **Authentication:** API bootstrap requires a valid portal session.
2. **CSRF:** stateful assistant requests require the session token.
3. **Role/portal context:** context resolution uses the current user, role, active portal, and module.
4. **Record scope:** student context binds `Sid`; lecturer context binds assigned courses; management extracts are permission-bounded.
5. **Read-only tools:** no AI tool exposes protected academic mutations.
6. **Bounded queries:** context builders limit selected columns, row counts, and time ranges.
7. **Prompt-injection defence:** retrieved text is treated as untrusted content, not as system instructions.
8. **Sensitive-data filtering:** unnecessary passwords, NRC/passport details, payment tokens, and cross-user data are excluded.
9. **Sources:** institutional answers identify the underlying authorised record or content source.
10. **Rate limits and usage logs:** usage, conversations, messages, and denial/error events are recorded.
11. **Human escalation:** protected decisions receive a refusal plus the responsible office/workflow.
12. **Graceful fallback:** if the provider is unavailable, the portal continues working and returns a clear non-destructive fallback.

## Prompt-injection response

Instructions found inside uploaded course content, user messages, URLs, or database text cannot override the system role, permission checks, data scope, or prohibited-action list. Requests to ignore these rules must be refused and audit-logged where appropriate.

## Evidence-backed skills

`StudentSkillDiscoveryService` derives skills from registered/programme courses and published assessment evidence. Each displayed card includes a source, evidence, proficiency description, improvement action, and career relevance. Inferred curriculum exposure is explicitly distinguished from assessment evidence and is not presented as certification.

## Demonstration prompts

- Student: “Explain network switches and routers using my current course context.”
- Student: “What evidence supports the skills shown on my profile?”
- Lecturer: “Which learners in my assigned DCSE-101 class may need support?”
- Admissions: “Summarise the required human checks before an application decision.”

Do not demonstrate a prompt that asks the AI to mutate records. If asked by a visitor, use it to show the refusal and human-escalation behaviour.

## Production controls still required

- Provider credentials must be supplied through protected environment configuration.
- Retention periods for prompts/responses must be approved by institutional policy.
- Model/provider changes require regression evaluation for privacy, citations, refusal behaviour, and cost.
- Incident monitoring and an AI kill switch should be part of production operations.
