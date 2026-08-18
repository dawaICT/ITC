# Membership rules

## Statuses
`not_enrolled`, `pending`, `changes_requested`, `active`, `declined`, `suspended`, `withdrawn`, `archived`

## Transitions
Implemented in `ep_membership_allowed_transitions()` / `ep_transition_membership()`.

## Activation requirements
- Authenticated eligible student or graduate
- Required consents accepted
- No conflicting active membership
- Approval mode: `manual` (default), `automatic`, or `hybrid` via settings/env

## Withdrawal / suspension
Unpublishes public opportunities; preserves consent, reviews, leads, outcomes and audit history; blocks tool access via guards.
