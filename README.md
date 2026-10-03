# SkyNoc License Management

Pure PHP 8.3+ license/reseller management platform for cPanel.

## Requirements
- PHP 8.3+
- MySQL/MariaDB
- PDO MySQL
- Apache with mod_rewrite
- cURL extension for Telegram and Cloudflare API requests

## Install
1. Create a MySQL database/user in cPanel.
2. Import `database/schema.sql`.
3. Copy `config/config.example.php` to `config/config.php` and set DB values.
4. Point the domain document root to the project root.
5. Set writable permission for `storage/logs` if logging is enabled.
6. Create the first owner/admin account with `bin/create_admin.php` or the provided demo SQL.
7. For an existing installation, run the statements in `database/migration_existing.sql` once.

Never store upstream provider passwords. Provider records contain only identification metadata.

## Main Routes
- `https://SkyNoc.Net/` — Public home
- `https://SkyNoc.Net/login` — Login
- `https://SkyNoc.Net/reseller` — Reseller portal
- `https://SkyNoc.Net/admin` — Admin panel
- `https://SkyNoc.Net/api/v1/` — Reseller API
- `https://SkyNoc.Net/api-docs` — API documentation
- `https://hostname.skynoc.net/` — Hostname/DNS ordering portal
- `https://SkyNoc.Net/admin/hostname` — Hostname/Cloudflare admin

Legacy `.php` routes redirect to clean URLs.

## Hostname / Cloudflare Service
The separate Hostname product is designed for multiple domains/zones managed from one Cloudflare account.

Default product:
- Price: **৳10/month**
- Currency: BDT
- Record limit: **exactly 1 DNS record per hostname**
- Supported record types: A, AAAA, CNAME, TXT
- Customer/reseller does not receive the Cloudflare API token.
- Orders are submitted with a payment reference and remain `pending_review` until admin approval.
- On approval, SkyNoc creates the DNS record automatically through Cloudflare API.

### Cloudflare setup
Keep the Cloudflare token only in the server-side `config/config.php` or ignored `config/config.local.php`:

```php
'cloudflare' => [
    'enabled' => true,
    'api_token' => 'YOUR_CLOUDFLARE_API_TOKEN',
    'account_id' => 'YOUR_CLOUDFLARE_ACCOUNT_ID',
],
```

Use a scoped Cloudflare API Token with permission to read zones and edit DNS records for the zones being sold. Never commit the real token to GitHub.

After deployment, open `/admin/hostname` and use **Sync Cloudflare Zones**. Admin can then mark individual domains sellable/hidden.

For the subdomain, create `hostname.skynoc.net` in DNS and point it to the same SkyNoc/cPanel document root. The included `.htaccess` routes that host to the Hostname portal.

## Admin / Staff
The admin panel now supports:
- Provider records
- Reseller creation
- Staff creation and role/permission overrides
- License creation, assignment and lifecycle status
- Reissue review/completion
- API key generation/revocation and scopes
- Support ticket status
- Telegram settings
- Hostname orders and Cloudflare domain availability

## Reseller
The reseller portal supports:
- Own-license isolation
- Reissue requests/history
- API key generation/revocation
- Notifications
- Support tickets and replies
- Hostname/DNS ordering at `hostname.skynoc.net`

## API
Bearer API keys support:
- `GET /api/v1/licenses`
- `GET /api/v1/licenses/{id}`
- `GET /api/v1/reissues`
- `POST /api/v1/licenses/{id}/reissue`

Default scopes:
- `licenses:read`
- `reissue:create`
- `reissue:read`

Rate limit: 60 requests per minute per API key.

## Automatic Expiry
Run this script from a cron job, for example every 10 minutes:

```bash
/opt/alt/php83/usr/bin/php /home/skynoc/public_html/bin/expire_licenses.php
```

Adjust the PHP binary and project path for the server.

## Security
- Passwords use `password_hash()`.
- API keys are stored as SHA-256 hashes and shown in plaintext only at creation.
- CSRF is required for web POST actions.
- Reseller queries are scoped by reseller ID.
- Upstream provider credentials are never exposed to resellers.
- Cloudflare API credentials are server-side only and are never rendered in reseller/customer pages.
