# Agriculture Market Access — Business Rules

1. Farmers may exist without web login (`user_id` nullable); identity is `farmer_code` + channels.
2. Agents only access assigned farmers unless they hold verify/manage permissions.
3. Produce quantity must be > 0; units must exist; listings auto-expire.
4. Unverified/suspended buyers cannot receive farmer match referrals.
5. Matches are officer-reviewed suggestions — **not sales**.
6. Farmer consent required before sharing contact; channels: web, ussd_pin, structured_sms, agent_assisted, call_centre; no preselected consent; decline is not punished.
7. Prices require source + effective date to publish; expired prices never returned as current.
8. Price types are distinct; buyer offers and farmer asking prices are never labelled official/government.
9. Official procurement / government reference may require dual approval (`AGRICULTURE_OFFICIAL_PRICE_APPROVAL_MODE=dual`).
10. Published prices are not overwritten — corrections create new versions.
11. SMS is queued (mock or provider); not sent synchronously as the only path.
12. Reports must separate price checks, matches, accepted offers, promised qty, delivered qty, confirmed sales.
