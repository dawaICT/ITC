# Agriculture Market Access — USSD Integration

## Adapter
`EnterpriseUssdProviderAdapter` + `agriculture_ussd_service.php`.

## Config
- `ENTERPRISE_USSD_PROVIDER=simulator` (default)
- `ENTERPRISE_USSD_WEBHOOK_SECRET` optional; when unset, live signature checks are skipped and simulator is used
- Do not hard-code shortcodes

## MVP menu
1. Check crop prices  
2. View my listings  
3. View buyer offers  
4. Accept or decline offer  
5. Request agent assistance  

Sessions: short TTL, `provider_request_id` idempotency, SMS follow-up for long text.

## Acceptance
Missing credentials must not block portal boot or non-USSD features. Live USSD is complete only when a provider is configured and signature verification is enforced.
