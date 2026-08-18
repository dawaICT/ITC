# Agriculture Market Access — Database

## Migration
`migrations/20260722_enterprise_agriculture_market_access.php` (additive).

## New tables (enterprise_ prefix)
- `enterprise_commodities`, `enterprise_commodity_varieties`
- `enterprise_measurement_units` (`to_base_factor` for normalized qty)
- `enterprise_communication_channels` (phone_e164 normalized; never PK)
- `enterprise_farmer_profiles` (`user_id` NULL allowed; `farmer_code` unique)
- `enterprise_farmer_farms`, `enterprise_farmer_crops`
- `enterprise_produce_listings` (qty + `quantity_base`, `expires_at`)
- `enterprise_buyer_crop_demands`
- `enterprise_produce_matches` (officer decision + farmer consent)
- `enterprise_commodity_price_sources`, `enterprise_commodity_prices` (versioned; published not overwritten)
- `enterprise_sms_messages` (provider_reference unique for idempotency)
- `enterprise_ussd_sessions` (provider_request_id unique)

## Extended existing
- Opportunity types in code: `produce_listing`, `crop_demand` (`ep_opportunity_types()`)
- Settings keys: `agriculture_enabled`, SMS/USSD flags, dual price approval
- Permissions: `agriculture.*` seeded; granted to registrar/HOD/dean/systems_admin

## Not in MVP migration
aggregation_groups, produce_collections, payments, transport, warehouse.
