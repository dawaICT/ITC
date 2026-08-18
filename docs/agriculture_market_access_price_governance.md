# Agriculture Market Access — Price Governance

## Types
- official_procurement_price
- government_reference_price
- commodity_exchange_price
- verified_buyer_offer
- local_market_reference
- farmer_asking_price

## Publication gates
No source → no publish. No effective date → no publish. Expired (`effective_to` < today) → excluded from `ep_current_commodity_prices()`.

## Dual approval
`AGRICULTURE_OFFICIAL_PRICE_APPROVAL_MODE=dual` (default) for official/government types: first verify, then distinct second approver publishes.

## Corrections
Do not UPDATE published price amounts in place. Insert a new row with `supersedes_price_id` / incremented `version_no` (service extension point).

## Display
Web, SMS, and USSD must show type label, source name, and effective date. Never present buyer offers as government-regulated prices.

## Data sourcing
Human-controlled entry only in MVP. No scraping. Attach `source_reference` / document path where available.
