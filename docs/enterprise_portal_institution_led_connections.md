# INSTITUTION-LED EMPLOYER AND BUSINESS CONNECTIONS

The Skills and Enterprise Portal must enable the institution to actively connect students and graduates with:

- Employers.
- Local businesses.
- Contractors.
- Product buyers.
- Service customers.
- Industry partners.
- Mentors.
- Training providers.
- Equipment suppliers.
- Distributors.
- Financial and enterprise-development organizations.

The institution must not only approve student profiles and wait for public enquiries.

It must be able to identify opportunities, match suitable participants, make controlled introductions, track follow-up and record actual outcomes.

The institutional connection process must support:

```text
Employer or Business Need
        ↓
Institution Records or Verifies Opportunity
        ↓
System Identifies Suitable Participants
        ↓
Authorized Officer Reviews Matches
        ↓
Students Give Consent to Referral
        ↓
Institution Makes Introduction
        ↓
Employer or Business Reviews Candidates
        ↓
Interview, Meeting, Quotation or Demonstration
        ↓
Follow-Up
        ↓
Employment, Contract, Order, Partnership or Closure
```

This must be permanent institutional functionality and must not be described as event-specific.

---

# 1. INSTITUTIONAL INTERMEDIARY ROLE

The institution must act as a trusted facilitator between students and external organizations.

Authorized institutional officers must be able to:

* Register employer and business partners.
* Verify partner contact details.
* Record partner interests and sector needs.
* Receive employment and business opportunities.
* Create opportunities on behalf of verified partners.
* Search for suitable students and graduates.
* Generate recommended participant matches.
* Review matches manually.
* Request student consent before referral.
* Refer selected participants.
* Schedule interviews, meetings or demonstrations.
* Record communications.
* Track employer or business feedback.
* Track successful and unsuccessful referrals.
* Record employment and commercial outcomes.
* Maintain long-term partner relationships.

Do not allow the system to automatically send private student information to employers or businesses without participant consent and institutional authorization.

---

# 2. PARTNER TYPES

Support these external partner types:

```text
employer
small_business
large_business
contractor
buyer
service_customer
industry_association
mentor
training_provider
equipment_provider
distributor
financial_institution
development_partner
government_agency
non_governmental_organization
```

A partner may have more than one role.

Example:

A company may be both:

* An employer.
* A product buyer.
* An industry mentor.
* An equipment-support partner.

Store partner roles in a normalized structure rather than one rigid field where practical.

---

# 3. PARTNER REGISTRATION MODEL

Support two registration methods.

## Institution-created partner

An authorized institutional officer registers a partner after receiving information through:

* Physical visit.
* Phone call.
* Email.
* Partnership meeting.
* Memorandum of understanding.
* Existing institutional relationship.
* Business-development activity.

## Partner self-registration

An employer or business may submit a registration request through a public form.

Self-registration must not immediately create a trusted partner account.

Workflow:

```text
Partner Registration Submitted
        ↓
Contact and Organization Verification
        ↓
Compliance or Identity Review
        ↓
Approved, Changes Requested or Declined
        ↓
Partner Account Activated
```

Recommended partner statuses:

```text
pending
changes_requested
verified
active
suspended
declined
inactive
archived
```

Only verified and active partners may receive institution-mediated referrals.

---

# 4. PARTNER VERIFICATION

The institution must verify partners before releasing student information.

Verification may include:

* Organization name.
* Registration number where applicable.
* Physical or operating address.
* Contact person.
* Official email.
* Phone number.
* Website or public presence where available.
* Industry sector.
* Nature of opportunity.
* Previous institutional relationship.
* Reputation or risk notes.
* Terms and conditions.
* Data-use acceptance.

Do not claim legal due diligence beyond what the institution actually performs.

Store:

* Verification level.
* Verified by.
* Verification date.
* Verification notes.
* Next review date.
* Supporting documents.
* Suspension reason where applicable.

Suggested verification levels:

```text
unverified
basic_verified
institutional_partner
strategic_partner
restricted
```

Publicly display only approved partner information.

---

# 5. PARTNER DIRECTORY

Create an internal partner relationship directory.

Authorized enterprise officers must be able to search partners by:

* Organization name.
* Partner type.
* Industry.
* Province.
* District.
* Opportunity type.
* Skills required.
* Programme relevance.
* Verification level.
* Active relationship status.
* Number of opportunities provided.
* Number of successful outcomes.
* Last engagement date.

The directory must support relationship management rather than functioning only as a contact list.

Track:

* First contact date.
* Relationship owner.
* Meetings.
* Notes.
* Agreements.
* Opportunities submitted.
* Referrals made.
* Outcomes achieved.
* Complaints or risk flags.
* Next follow-up date.

---

# 6. EMPLOYMENT AND BUSINESS OPPORTUNITIES

Partners or authorized institutional officers must be able to create external opportunities.

Supported opportunity types:

```text
full_time_employment
part_time_employment
internship
apprenticeship
industrial_attachment
temporary_work
consultancy
service_contract
product_supply
quotation_request
subcontracting
distribution_partnership
mentorship
equipment_support
training_opportunity
business_partnership
innovation_support
funding_referral
```

Do not treat every external opportunity as a job vacancy.

Each opportunity type must use relevant fields.

## Employment opportunity

Require:

* Job title.
* Organization.
* Employment type.
* Location.
* Required skills.
* Minimum qualifications.
* Experience level.
* Number of positions.
* Application or referral deadline.
* Salary or compensation visibility.
* Working arrangement.
* Contact method.
* Selection process.

## Service contract

Require:

* Service required.
* Scope of work.
* Location.
* Expected start date.
* Expected completion date.
* Required skills.
* Budget visibility.
* Quotation requirements.
* Tools or equipment requirements.

## Product supply opportunity

Require:

* Product required.
* Quantity.
* Quality or specification.
* Delivery location.
* Delivery date.
* Budget or price-request method.
* Supplier requirements.
* Sample or demonstration requirement.

## Mentorship opportunity

Require:

* Mentor organization or individual.
* Expertise offered.
* Target participant type.
* Duration.
* Meeting frequency.
* Expected outcomes.

## Equipment support

Require:

* Equipment available.
* Support type.
* Donation, loan, shared use or sponsorship terms.
* Eligibility.
* Recipient responsibilities.
* Delivery or collection terms.

---

# 7. OPPORTUNITY CREATION SOURCES

Store the source of every opportunity.

Possible values:

```text
partner_self_service
institution_created
public_enquiry
existing_partnership
industry_visit
career_office
enterprise_office
alumni_referral
government_programme
development_partner
```

This allows the institution to measure where useful opportunities come from.

---

# 8. OPPORTUNITY APPROVAL

External opportunities must pass institutional review before students receive them.

Review checks should include:

* Partner verification.
* Opportunity legitimacy.
* Required information.
* Deadline validity.
* Student safety.
* Compensation clarity where applicable.
* Location.
* Data requested from students.
* Possible discrimination or exploitation.
* Unrealistic requirements.
* Prohibited fees.
* Reputational risk.

Recommended statuses:

```text
draft
submitted
under_review
changes_requested
approved
open
paused
closed
cancelled
expired
archived
```

Only approved and open opportunities should be available for matching.

The system must prevent publication or referral of expired, suspended or unverified opportunities.

---

# 9. PROHIBITED OR HIGH-RISK OPPORTUNITIES

The system must flag or block opportunities that:

* Require students to pay unlawful or unexplained recruitment fees.
* Request unnecessary sensitive information.
* Contain discriminatory requirements.
* Appear fraudulent.
* Offer unsafe working conditions.
* Misrepresent compensation.
* Request illegal activity.
* Lack a verifiable organization or responsible contact.
* Conflict with institutional policy.
* Exploit unpaid labour outside approved training arrangements.

Flagged opportunities must enter a management review queue.

Do not automatically delete them before authorized review unless there is an immediate security risk.

---

# 10. STUDENT EMPLOYABILITY AND BUSINESS PROFILE

Participants must be able to create institutionally useful matching profiles.

A matching profile may include:

* Skills.
* Programme.
* Qualifications.
* Certifications.
* Work experience.
* Portfolio evidence.
* Products.
* Services.
* Innovation interests.
* Preferred opportunity types.
* Preferred industry.
* Preferred location.
* Availability.
* Employment type preference.
* Business interests.
* Equipment available.
* Transport availability where voluntarily provided.
* Willingness to relocate.
* Public and private contact preferences.

Private matching information must remain invisible to public users.

Students must control whether they are:

```text
open_to_opportunities
limited_availability
not_currently_available
```

The institution must not refer a participant marked unavailable without renewed consent.

---

# 11. INSTITUTIONAL MATCHING LOGIC

Create a centralized service such as:

```text
EnterpriseMatchingService
```

The system may generate recommended matches but institutional officers must review them before referral.

Matching criteria may include:

* Skill match.
* Programme relevance.
* Verified competencies.
* Experience.
* Location.
* Availability.
* Opportunity preference.
* Employment preference.
* Product or service category.
* Production capacity.
* Required tools.
* Readiness level.
* Partner-specific requirements.
* Previous referrals.
* Conflict or restriction status.

Suggested matching model:

```text
Required skill match             30%
Programme or qualification       15%
Verified evidence                15%
Availability                     10%
Location compatibility           10%
Opportunity preference           10%
Experience or readiness          10%
                                 ----
                                 100%
```

The weights must be configurable.

Do not automatically reject users based only on the score.

Use match labels such as:

```text
strong_match
good_match
possible_match
manual_review_required
not_eligible
```

Every recommendation must explain its main matching factors.

---

# 12. MATCHING FAIRNESS

Matching must not unfairly favor students based only on:

* Number of profile views.
* Ability to pay.
* Personal connections.
* Gender.
* Disability.
* Income.
* Programme prestige.
* Social status.
* Previous employer access.

Only use protected personal characteristics where lawful, necessary and tied to a legitimate targeted programme.

Support:

* Human review.
* Explainable recommendations.
* Manual inclusion.
* Manual exclusion with recorded reason.
* Rotation among similarly qualified participants.
* Assisted profile completion.
* Appeal or correction of inaccurate profile data.

Do not describe matching as a guarantee of selection.

---

# 13. INSTITUTIONAL REFERRAL WORKFLOW

Implement this workflow:

```text
Opportunity Approved
        ↓
Candidate Matches Generated
        ↓
Enterprise or Career Officer Reviews Matches
        ↓
Shortlist Created
        ↓
Selected Participants Asked for Consent
        ↓
Participant Accepts or Declines Referral
        ↓
Institution Submits Referral
        ↓
Partner Acknowledges Referral
        ↓
Interview, Meeting, Quotation or Demonstration
        ↓
Partner Feedback
        ↓
Outcome Recorded
```

Referral statuses:

```text
draft
awaiting_student_consent
student_accepted
student_declined
submitted_to_partner
partner_acknowledged
shortlisted
interview_scheduled
meeting_scheduled
quotation_requested
demonstration_requested
selected
not_selected
withdrawn
expired
closed
```

A student declining one referral must not be suspended or penalized.

Store an optional decline reason without requiring disclosure of sensitive personal information.

---

# 14. CONSENT BEFORE REFERRAL

Before a referral is sent, the student must see:

* Organization name.
* Opportunity title.
* Opportunity type.
* Location.
* Key requirements.
* Information that will be shared.
* Referral deadline.
* Institutional contact.
* Applicable terms.

Require explicit consent.

Do not use preselected consent.

Store:

* Referral ID.
* Student ID.
* Consent decision.
* Consent timestamp.
* Information-sharing scope.
* Consent version.

The student must be able to decline.

---

# 15. INFORMATION SHARING

Use progressive disclosure.

Before consent, the partner may see only anonymized or summary matching data where appropriate.

After student consent and institutional approval, the institution may share approved information such as:

* Full name.
* Professional profile.
* Skills.
* Qualification or programme.
* Portfolio.
* Public contact information.
* Availability.
* Relevant certifications.
* Approved CV.

Do not share:

* NRC number.
* Academic records unrelated to the opportunity.
* Home address.
* Private health information.
* Disciplinary information.
* Financial information.
* Unapproved phone or email.
* Internal reviewer comments.

Record every referral and the fields shared.

---

# 16. SHORTLIST MANAGEMENT

Authorized officers must be able to create a shortlist.

Shortlist functions:

* Add candidate.
* Remove candidate with reason.
* Rank manually.
* View system match explanation.
* Request missing information.
* Check consent.
* Submit selected candidates.
* Export an approved referral summary.
* Track partner response.

Do not allow an employer to download the entire student database.

Partners may only access students referred or made visible under approved rules.

---

# 17. EMPLOYER AND BUSINESS FEEDBACK

Partners must be able to provide structured feedback after:

* Reviewing a referral.
* Conducting an interview.
* Receiving a quotation.
* Viewing a product demonstration.
* Completing a contract.
* Rejecting a candidate.
* Closing an opportunity.

Feedback may include:

* Candidate selected.
* Candidate not selected.
* More information required.
* Interview result.
* Skill strengths.
* Skill gaps.
* Product suitability.
* Service suitability.
* Reason for no selection.
* Opportunity withdrawn.
* Future interest.

Separate internal feedback from feedback visible to the student.

Do not display harmful, discriminatory or inappropriate comments directly to students without institutional moderation.

---

# 18. INTERVIEW, MEETING AND DEMONSTRATION MANAGEMENT

Allow the institution to coordinate:

* Interviews.
* Business meetings.
* Product demonstrations.
* Service demonstrations.
* Site visits.
* Mentorship sessions.
* Quotation presentations.

Store:

* Event type.
* Related opportunity.
* Related partner.
* Related participants.
* Date and time.
* Location.
* Online meeting link where applicable.
* Organizer.
* Attendance.
* Outcome.
* Follow-up action.

Integrate with the existing calendar or notification system where possible.

Do not create duplicate calendar logic where a reusable service exists.

---

# 19. OPPORTUNITY REFERRAL OWNERSHIP

Every partner and opportunity must have an institutional relationship owner.

Possible owners:

* Career officer.
* Enterprise officer.
* Alumni officer.
* Head of section.
* Designated lecturer.
* Industry liaison officer.

Store:

* Primary owner.
* Backup owner.
* Assignment date.
* Last engagement.
* Next action.
* Escalation status.

Important opportunities must not remain without an owner.

---

# 20. SERVICE-LEVEL TARGETS

Make response targets configurable.

Suggested defaults:

```env
ENTERPRISE_PARTNER_VERIFICATION_TARGET_DAYS=5
ENTERPRISE_OPPORTUNITY_REVIEW_TARGET_DAYS=3
ENTERPRISE_REFERRAL_CONSENT_TARGET_DAYS=3
ENTERPRISE_PARTNER_FOLLOWUP_TARGET_DAYS=5
ENTERPRISE_OUTCOME_CONFIRMATION_TARGET_DAYS=14
```

Dashboard indicators:

```text
On Time
Due Soon
Overdue
Escalated
```

Do not hard-code dates in page templates.

---

# 21. PARTNER RELATIONSHIP MANAGEMENT

Create a centralized service such as:

```text
EnterprisePartnerService
EnterprisePartnerEngagementService
```

Track partner engagement activities:

* Calls.
* Emails.
* Meetings.
* Visits.
* Agreements.
* Opportunities.
* Referrals.
* Feedback.
* Complaints.
* Outcomes.
* Follow-up commitments.

Recommended relationship stages:

```text
prospective
contacted
engaged
verified
active_partner
strategic_partner
inactive
restricted
archived
```

The institution should be able to identify:

* Active partners.
* Partners that have not been contacted recently.
* Partners providing frequent opportunities.
* Partners producing successful outcomes.
* Partners with unresolved complaints.
* Partners requiring renewed verification.

---

# 22. INSTITUTION-CREATED MATCHES

Authorized officers must be able to create a manual match where:

* The employer contacted the institution offline.
* A business opportunity was discussed during a visit.
* The system lacks sufficient data.
* A student was recommended by a department.
* A special institutional programme applies.

Manual matches must include:

* Officer.
* Reason.
* Opportunity.
* Participant.
* Consent status.
* Supporting notes.
* Date.
* Audit trail.

Do not bypass student consent.

---

# 23. STUDENT NOMINATION

Allow authorized lecturers, heads of section or officers to nominate students for opportunities.

A nomination is not a completed referral.

Nomination workflow:

```text
Staff Nomination
        ↓
Eligibility Check
        ↓
Student Notification
        ↓
Student Reviews Opportunity
        ↓
Student Accepts or Declines
        ↓
Institutional Referral
```

Store:

* Nominator.
* Reason.
* Relevant skills.
* Opportunity.
* Student response.
* Referral result.

Do not allow staff to expose student information directly to partners without consent.

---

# 24. BUSINESS SUPPLIER MATCHING

The institution must also connect student enterprises to buyers and businesses needing products or services.

Examples:

* A contractor needs welding services.
* A company requires furniture.
* A business needs website development.
* A farmer needs equipment repairs.
* A buyer needs locally produced items.
* A distributor wants new suppliers.

For business supplier matching, compare:

* Product or service category.
* Required quantity.
* Participant capacity.
* Price range.
* Delivery location.
* Availability.
* Required deadline.
* Verification status.
* Business-readiness level.

Do not refer a participant when the required quantity clearly exceeds their declared capacity unless the opportunity supports group fulfilment or phased delivery.

---

# 25. GROUP FULFILMENT

Support opportunities that require several participants.

Examples:

* Multiple artisans completing a large order.
* A team delivering a software project.
* Several trainees providing services.
* A group producing a required quantity.

Create group fulfilment records containing:

* Lead participant or coordinator.
* Group members.
* Roles.
* Shared capacity.
* Contribution.
* Revenue-sharing declaration where applicable.
* Institutional coordinator.
* Delivery milestones.

Do not make the institution responsible for commercial revenue distribution unless a separately approved policy exists.

---

# 26. BUSINESS QUOTATION WORKFLOW

For product and service opportunities, support controlled quotation requests.

Workflow:

```text
Buyer Request
        ↓
Institution Reviews Request
        ↓
Suitable Participants Identified
        ↓
Participants Consent
        ↓
Quotation Request Sent
        ↓
Participant Prepares Quotation
        ↓
Institution Reviews Where Required
        ↓
Quotation Submitted to Buyer
        ↓
Buyer Response
        ↓
Contract, Revision or Closure
```

The first version may store quotation metadata and uploaded documents.

It must not become a complete accounting or invoicing system.

Track:

* Quotation number.
* Participant.
* Buyer.
* Amount.
* Currency.
* Submission date.
* Expiry date.
* Status.
* Buyer response.
* Related outcome.

---

# 27. EMPLOYER VACANCY MANAGEMENT

For employment opportunities, support:

* Vacancy creation.
* Approval.
* Candidate matching.
* Student consent.
* Referral.
* Interview tracking.
* Employer feedback.
* Placement confirmation.
* Employment-start confirmation.
* Retention follow-up where policy allows.

Differentiate:

```text
referred
shortlisted
interviewed
offered
accepted
started
retained
not_selected
```

Do not count `offered` as `employment_started`.

---

# 28. PARTNERSHIP AGREEMENTS

Allow authorized officers to record non-sensitive partnership agreement metadata.

Store:

* Partner.
* Agreement type.
* Effective date.
* Expiry date.
* Responsible office.
* Main purpose.
* Applicable programmes.
* Opportunity commitments.
* Document reference.
* Renewal status.

Do not expose confidential agreements publicly.

Generate reminders before expiry.

---

# 29. EXTERNAL PARTNER ACCESS

Where external partner accounts are implemented, use limited permissions.

Suggested permissions:

```text
enterprise.partner.portal.access
enterprise.partner.profile.manage_own
enterprise.partner.opportunity.create
enterprise.partner.opportunity.view_own
enterprise.partner.referral.view
enterprise.partner.feedback.submit
enterprise.partner.meeting.view
enterprise.partner.outcome.confirm
```

Partners must not:

* Browse private student records.
* Search the entire student database.
* View unrelated referrals.
* Modify institutional decisions.
* Publish opportunities directly.
* View internal notes.
* Access academic records.
* Access enterprise management reports.

All partner-created opportunities must pass institutional approval.

---

# 30. NEW DATABASE ENTITIES

Create equivalent normalized tables after inspecting existing schema conventions.

## enterprise_partners

Fields:

```text
id
organization_name
organization_type
registration_number nullable
industry_sector
description nullable
province nullable
district nullable
physical_address nullable
website nullable
official_email nullable
official_phone nullable
verification_level
relationship_status
relationship_owner_id nullable
verified_by nullable
verified_at nullable
next_review_at nullable
risk_status
created_at
updated_at
archived_at nullable
```

## enterprise_partner_contacts

```text
id
enterprise_partner_id
full_name
job_title nullable
email
phone
is_primary
is_verified
consent_status
created_at
updated_at
```

## enterprise_partner_roles

```text
id
enterprise_partner_id
partner_role
created_at
```

## enterprise_partner_documents

```text
id
enterprise_partner_id
document_type
file_path
verification_status
uploaded_by
verified_by nullable
uploaded_at
verified_at nullable
```

## enterprise_external_opportunities

```text
id
enterprise_partner_id
created_by_user_id
opportunity_type
title
description
industry_sector
location
requirements_json
skills_required_json
programme_preferences_json nullable
number_of_positions nullable
quantity_required nullable
budget_min nullable
budget_max nullable
currency nullable
compensation_visibility nullable
start_date nullable
closing_date nullable
delivery_date nullable
contact_method
source
status
reviewed_by nullable
approved_at nullable
published_at nullable
closed_at nullable
created_at
updated_at
archived_at nullable
```

## enterprise_opportunity_matches

```text
id
external_opportunity_id
membership_id
enterprise_profile_id
match_score nullable
match_level
match_reasons_json
matching_method
recommended_by nullable
review_status
created_at
updated_at
```

## enterprise_referrals

```text
id
external_opportunity_id
opportunity_match_id nullable
membership_id
partner_id
referred_by
referral_status
student_consent_status
student_consent_at nullable
shared_fields_json nullable
submitted_to_partner_at nullable
partner_acknowledged_at nullable
next_follow_up_at nullable
closed_at nullable
closure_reason nullable
created_at
updated_at
```

## enterprise_partner_feedback

```text
id
enterprise_referral_id
partner_id
feedback_stage
decision
strengths nullable
development_needs nullable
internal_comments nullable
participant_visible_comments nullable
submitted_by
submitted_at
reviewed_by nullable
reviewed_at nullable
```

## enterprise_engagement_activities

```text
id
partner_id
external_opportunity_id nullable
referral_id nullable
activity_type
subject
notes
activity_date
next_action_date nullable
recorded_by
created_at
updated_at
```

## enterprise_interviews_meetings

```text
id
external_opportunity_id
referral_id nullable
event_type
title
start_at
end_at
location nullable
meeting_link nullable
organizer_id
status
outcome_notes nullable
created_at
updated_at
```

## enterprise_group_fulfilments

```text
id
external_opportunity_id
coordinator_membership_id
institutional_coordinator_id
combined_capacity nullable
status
terms_summary nullable
created_at
updated_at
```

## enterprise_group_fulfilment_members

```text
id
group_fulfilment_id
membership_id
role_description
capacity_contribution nullable
consent_status
created_at
updated_at
```

## enterprise_quotations

```text
id
external_opportunity_id
referral_id
quotation_number
membership_id
partner_id
amount
currency
document_path nullable
status
submitted_at nullable
expires_at nullable
partner_response nullable
created_at
updated_at
```

## enterprise_partner_agreements

```text
id
partner_id
agreement_type
reference_number nullable
effective_date
expiry_date nullable
purpose
programmes_json nullable
document_path nullable
status
responsible_user_id
created_at
updated_at
```

Use foreign keys, indexes and archive fields according to existing project conventions.

Do not destroy referral or outcome history through cascade deletion.

---

# 31. NEW PERMISSIONS

Create or map:

```text
enterprise.partners.create
enterprise.partners.review
enterprise.partners.verify
enterprise.partners.manage
enterprise.partners.suspend

enterprise.external_opportunities.create
enterprise.external_opportunities.review
enterprise.external_opportunities.approve
enterprise.external_opportunities.close

enterprise.matches.generate
enterprise.matches.review
enterprise.matches.manage

enterprise.referrals.create
enterprise.referrals.submit
enterprise.referrals.manage
enterprise.referrals.view_own

enterprise.partner_feedback.review
enterprise.partner_relationships.manage

enterprise.quotations.manage
enterprise.group_fulfilment.manage
enterprise.agreements.manage
```

Do not automatically assign these permissions to normal students.

---

# 32. NEW SERVICES

Create or adapt centralized services:

```text
EnterprisePartnerService
EnterprisePartnerVerificationService
EnterprisePartnerRelationshipService
EnterpriseExternalOpportunityService
EnterpriseMatchingService
EnterpriseReferralService
EnterpriseReferralConsentService
EnterprisePartnerFeedbackService
EnterpriseEngagementService
EnterpriseMeetingService
EnterpriseQuotationService
EnterpriseGroupFulfilmentService
EnterpriseAgreementService
```

These services must enforce:

* Permissions.
* Validation.
* Partner verification.
* Student consent.
* Status transitions.
* Privacy.
* Transactions.
* Audit logging.
* Conflict checks.
* Error handling.

Do not place matching or referral business logic directly inside page templates.

---

# 33. MANAGEMENT PAGES

Add enterprise management pages equivalent to:

```text
/enterprise/management/partners.php
/enterprise/management/partner_create.php
/enterprise/management/partner_view.php
/enterprise/management/partner_verify.php
/enterprise/management/partner_engagement.php

/enterprise/management/external_opportunities.php
/enterprise/management/external_opportunity_create.php
/enterprise/management/external_opportunity_review.php

/enterprise/management/matches.php
/enterprise/management/match_review.php
/enterprise/management/referrals.php
/enterprise/management/referral_view.php

/enterprise/management/interviews.php
/enterprise/management/quotations.php
/enterprise/management/group_fulfilments.php
/enterprise/management/agreements.php
```

Adapt paths to the existing routing architecture.

---

# 34. PARTICIPANT PAGES

Add pages equivalent to:

```text
/enterprise/opportunities/available.php
/enterprise/opportunities/recommended.php
/enterprise/referrals/index.php
/enterprise/referrals/view.php
/enterprise/referrals/respond.php
/enterprise/interviews/index.php
/enterprise/quotations/index.php
/enterprise/quotations/create.php
```

Participants must be able to:

* View approved opportunities.
* View recommended matches.
* Accept or decline a referral.
* Review the information that will be shared.
* View interview or meeting details.
* Submit quotation information where applicable.
* Track their referral outcomes.

---

# 35. PARTNER PAGES

Where partner accounts are enabled, add equivalent pages:

```text
/enterprise/partner/index.php
/enterprise/partner/profile.php
/enterprise/partner/opportunities.php
/enterprise/partner/opportunity_create.php
/enterprise/partner/referrals.php
/enterprise/partner/referral_view.php
/enterprise/partner/feedback.php
/enterprise/partner/meetings.php
```

Use a separate restricted partner layout.

Do not use the student or management sidebar.

---

# 36. REPORTS AND METRICS

Add reports for:

* Active employer and business partners.
* Partners by sector.
* Partner verification status.
* Opportunities by partner.
* Opportunities by type.
* Opportunities by programme.
* Students matched.
* Students referred.
* Student referral acceptance rate.
* Employer acknowledgement rate.
* Interview rate.
* Selection rate.
* Employment-offer rate.
* Employment-start rate.
* Product quotation rate.
* Product-order rate.
* Service-contract rate.
* Partnership outcomes.
* Average referral response time.
* Average partner follow-up time.
* Overdue opportunities.
* Partner outcome contribution.
* Partners with no recent engagement.
* Partner complaints or restrictions.

Do not count:

* A system match as a referral.
* A referral as an interview.
* An interview as employment.
* A quotation as an order.
* A funding discussion as funding received.

---

# 37. INSTITUTIONAL DASHBOARD ADDITIONS

Add database-backed indicators:

* Verified partners.
* Active employers.
* Active business partners.
* Open external opportunities.
* Pending opportunity reviews.
* Students matched.
* Referrals awaiting student consent.
* Referrals submitted.
* Interviews scheduled.
* Quotations requested.
* Overdue partner follow-ups.
* Employment offers.
* Employment started.
* Product orders.
* Service contracts.
* Business partnerships.
* Equipment support received.
* Funding referrals.
* Verified funding received.
* Top partner sectors.
* Programmes with few partner connections.

Use these indicators to guide institutional action.

---

# 38. NOTIFICATIONS

Add notifications for:

* Partner registration submitted.
* Partner approved.
* Partner changes requested.
* Partner suspended.
* External opportunity submitted.
* Opportunity approved.
* Recommended match available.
* Student nominated.
* Referral consent requested.
* Student accepted referral.
* Student declined referral.
* Referral submitted to partner.
* Partner acknowledged referral.
* Interview scheduled.
* Meeting scheduled.
* Quotation requested.
* Feedback received.
* Candidate selected.
* Candidate not selected.
* Opportunity closing soon.
* Partner follow-up overdue.
* Outcome confirmation requested.

Every notification must link to the correct Skills and Enterprise Portal context.

---

# 39. TESTING REQUIREMENTS

Test:

## Partner management

* Unverified partner cannot receive student referrals.
* Suspended partner cannot create opportunities.
* Partner cannot browse all students.
* Partner-created opportunity requires institutional approval.
* Expired partner verification triggers review.

## Matching

* Match score uses relevant participant data.
* Unavailable students are excluded.
* Suspended students are excluded.
* Manual inclusion requires a reason.
* Match score does not automatically refer a student.
* Similar candidates are handled fairly.

## Referral consent

* Referral cannot be sent before student consent.
* Student can decline without penalty.
* Only approved fields are shared.
* Consent decision is logged.
* Direct URL access cannot bypass consent.

## Institutional referral

* Officer can create shortlist.
* Officer can request student consent.
* Officer can submit consented participants.
* Partner sees only referred participants.
* Partner acknowledgement is recorded.

## Employment workflow

* Referral, shortlist, interview, offer and start are separate.
* Employment offer does not count as employment started.
* Closing dates prevent late referral unless authorized.

## Business workflow

* Product enquiry captures quantity.
* Capacity is checked.
* Quotation can be prepared and tracked.
* Quotation does not count as an order.
* Order and completion are separate outcomes.

## Privacy

* Partner cannot see NRC or private records.
* Student data is shared only after consent.
* Internal notes remain private.
* Feedback is moderated before student visibility.

---

# 40. BUSINESS ACCEPTANCE CRITERIA

The institution-led connection functionality is complete only when:

1. The institution can register and verify employers and businesses.
2. Verified partners can submit opportunities.
3. Institutional officers can create partner opportunities manually.
4. Opportunities are reviewed before students see them.
5. The system can recommend suitable participants.
6. Officers can manually review matches.
7. Students must consent before referral.
8. Students can decline without penalty.
9. Only approved information is shared.
10. Partners can view only referred participants.
11. The institution can track referral progress.
12. Interviews and meetings can be recorded.
13. Employers can provide structured feedback.
14. Businesses can request quotations.
15. Product and service matches consider capacity.
16. Group fulfilment can be supported.
17. Every partner relationship has an owner.
18. Overdue follow-up is visible.
19. Employment offers and employment starts are measured separately.
20. Quotations and completed orders are measured separately.
21. The institution can report which partners produce real outcomes.
22. No partner receives unrestricted access to student data.
23. Existing Academic and eLearning permissions remain separate.
24. All matching, referral and sharing actions are audited.

---

# FINAL INSTITUTIONAL CONNECTION RULE

The institution must remain an active bridge between participants and external organizations.

Do not implement a passive system where students only publish profiles and wait.

Implement a managed relationship process in which the institution:

```text
Identifies Partners
        ↓
Verifies Opportunities
        ↓
Matches Participants
        ↓
Requests Participant Consent
        ↓
Makes Referrals
        ↓
Coordinates Engagement
        ↓
Tracks Feedback
        ↓
Records Real Outcomes
```

This makes the portal an institutional **career, enterprise and industry-linkage system**, while still supporting the broader goal of connecting student products and services to trade and investment opportunities.
