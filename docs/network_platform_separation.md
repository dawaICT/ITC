# Skills and Enterprise Network — separation from WUCPortal

## Overview

The `/network/` area is the **Skills and Enterprise Network** entry point with three access layers:

| Layer | URL | Auth | Purpose |
|-------|-----|------|---------|
| **Public** | `/network/public/` | None | Browse verified listings (`network.public.browse` RBAC key) |
| **Member** | `/network/login.php` → `/network/dashboard.php` | `WUC_NETWORK_SESS` cookie, `auth_realm=network` | Participants and org officers (`network.member.access` or active membership) |
| **Platform admin** | `/network/admin/` | Network session + `platform.admin` | Network configuration—not WUC `systems_admin` academic console |

Academic portal sessions do **not** grant network admin access, and network login does **not** grant access to eLearning, registrar, or other WUC modules.

## Deployment modes

- **`ENTERPRISE_DEPLOYMENT_MODE=integrated`** (default): Network public + network login coexist with portal selection. Members typically use campus login for `/enterprise/*`; network login targets platform operators and standalone-style testing.
- **`ENTERPRISE_DEPLOYMENT_MODE=standalone`**: `/enterprise/*` uses the network session cookie; redirects go to `/network/login.php` instead of staff/student login.

## Roles and permissions

Run migration:

```bash
php migrations/20260725_network_platform_roles.php
```

| Permission | Role usage |
|------------|------------|
| `platform.admin` | Full platform admin console |
| `platform.support` | Support / elevated read (optional) |
| `network.member.access` | Explicit member app access |
| `network.public.browse` | Documented public capability (anonymous pages do not enforce login) |

Assign the **`platform_admin`** role (via `user_roles`) to network operators. WUC **`systems_admin`** receives `platform.admin` and `platform.support` automatically from the migration for integrated hosts.

## Local URLs

- Home: `http://localhost/wucportal/network/`
- Public directory: `http://localhost/wucportal/network/public/`
- Member login: `http://localhost/wucportal/network/login.php`
