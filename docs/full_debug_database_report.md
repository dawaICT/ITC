# Full Debug Database Report

## Agriculture schema

Migration: `migrations/20260722_enterprise_agriculture_market_access.php` (additive, reversible drop documented in migration header).

## Integrity rules enforced in code

- Money: `DECIMAL(14,2)` on prices
- Quantities: `DECIMAL` + `quantity_base` normalization via `enterprise_measurement_units.to_base_factor`
- Expiry: produce and demands expired via batch helpers (not only UI)
- Price history: published rows not overwritten; corrections via new rows (service policy)
- Idempotency: `enterprise_sms_messages.provider_reference` UNIQUE; USSD `provider_request_id` UNIQUE

## Indexes (migration)

- Price publication + expiry composite index
- Listing/demand/match foreign keys
- SMS/USSD provider reference uniqueness

## Data repair

No destructive data repair run during audit. Test farmers/listings from domain tests may exist in dev DB.

## Orphan checks

Recommend periodic job: orphaned `enterprise_communication_channels` without farmer profile (low priority).
