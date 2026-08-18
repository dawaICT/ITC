# Agriculture Market Access — SMS Integration

## Adapter
`EnterpriseSmsProviderAdapter` in `includes/enterprise_portal/adapters.php`.

## Config
- `ENTERPRISE_SMS_PROVIDER=mock` (default) | future provider id
- Messages persisted in `enterprise_sms_messages`
- `provider_reference` unique → idempotent callbacks / replays

## Rules
- Queue outbound messages; web requests enqueue rather than depending on live gateway latency.
- Do not SMS sensitive financial account details or full identity dossiers.
- Include price type, source, and date in price SMS (`ep_format_price_sms`).

## Live provisioning
External dependency. Mock completes development and tests without credentials.
