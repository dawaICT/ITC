# Agriculture Market Access — Permissions

All keys prefixed `agriculture.` (not granted to students/farmers by default).

| Key | Purpose |
|-----|---------|
| agriculture.farmers.create | Register farmers |
| agriculture.farmers.manage_assigned | Update assigned farmers |
| agriculture.farmers.verify | Verification levels |
| agriculture.cooperatives.manage | Cooperatives |
| agriculture.produce.create_own | Own listings |
| agriculture.produce.manage_assigned | Assigned listings |
| agriculture.produce.verify | Verify listings |
| agriculture.buyers.manage | Buyer verification |
| agriculture.demands.create | Create demands |
| agriculture.demands.review | Review demands |
| agriculture.matches.create | Suggest matches |
| agriculture.matches.review | Approve matches |
| agriculture.referrals.manage | Consented introductions |
| agriculture.prices.create | Draft prices |
| agriculture.prices.verify | Verify prices |
| agriculture.prices.publish | Publish prices |
| agriculture.prices.correct | Corrections |
| agriculture.sms.manage | SMS ops |
| agriculture.ussd.manage | USSD ops |
| agriculture.reports.view | Reports |

Runtime: use `ep_staff_can($db, 'agriculture....')` for staff; ownership/assignment checks in services for agents.
