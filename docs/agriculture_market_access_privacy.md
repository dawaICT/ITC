# Agriculture Market Access — Privacy

## Principles
- Farmer contact details are never shared with buyers without recorded consent.
- Agents may access only assigned farmers (unless they hold verify/manage permissions).
- Buyers cannot browse the full farmer directory.
- Internal officer notes remain private.
- Phone numbers are communication channels, not permanent primary keys.
- SMS must not carry sensitive financial account details or full identity dossiers.

## Consent channels
web, ussd_pin, structured_sms, agent_assisted, call_centre — no preselected consent; decline is not penalized.

## Retention
Consent, referral, match, and outcome history must not be cascade-deleted. Configure retention windows operationally; do not purge audit trails for active disputes.

## Roles
Agriculture management permissions are not auto-granted to students or farmers.
