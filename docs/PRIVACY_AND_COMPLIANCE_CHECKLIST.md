# Privacy, compliance, and operational checklist

This checklist is an engineering control record, not legal advice. The
institution's privacy/compliance owner must approve the completed register
before production use.

## Data register

Record an accountable owner, purpose, access group, source, retention period,
and deletion method for each category:

- student identity/contact and next-of-kin data;
- admissions evidence, NRC/passport and transfer documents;
- academic registration, assessment, attendance and transcripts;
- financial invoices, receipts, payment proofs and gateway references;
- staff identity, roles, authentication and employment records;
- security/audit logs, IP addresses and user-agent data;
- learning submissions, recordings and third-party Drive links.

Do not collect a field merely because the schema permits it. Mask identifiers
in routine reports and exports, and provide full records only to authorised
roles with a documented business need.

## Required approvals before go-live

- [ ] Privacy notice identifies purposes, data categories, sharing, retention,
      rights/contact channel, and automated/AI processing where applicable.
- [ ] Retention schedule and defensible deletion/archival process are approved.
- [ ] Quarterly role/access review owner and evidence location are assigned.
- [ ] Joiner/mover/leaver procedure removes obsolete access promptly.
- [ ] Third-party processors (SMS, email, payments, cloud storage and AI) have
      approved contracts, security review and data-location assessment.
- [ ] Data-subject request, correction and deletion workflow is tested.
- [ ] Incident/breach assessment and notification procedure is approved by
      counsel against applicable Zambian requirements.
- [ ] Finance and academic record-retention requirements are reconciled with
      privacy deletion requests.
- [ ] Backup encryption, off-site replication and restore evidence are recorded.

## Minimum technical evidence

- Successful `composer audit`, PHP lint, production preflight and E2E test logs.
- Migration ledger with immutable checksums.
- Daily backup result and quarterly restore-drill record.
- Health/availability history and alert test.
- Privileged-action and authentication audit samples without passwords,
  session identifiers, document contents or payment secrets.
- Access-review sign-off and incident-response exercise at least annually.

## Retention implementation notes

The application currently enforces a 30-day local backup default and bounded
local error-log rotation. Business-record retention must not be hard-coded until
the institution approves its schedule. Once approved, implement deletion jobs
with dry-run output, legal-hold exclusions, audit records, and restore-safe
testing before enabling them.
