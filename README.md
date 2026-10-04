# SkyNoc License Management

Pure PHP 8.3+ license/reseller management platform for cPanel.

## Requirements
- PHP 8.3+
- MySQL/MariaDB
- PDO MySQL
- Apache with mod_rewrite
- cURL extension for Telegram, Cloudflare and Binance API requests

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
- `https://SkyNoc.Net/admin/settings` — Platform settings
- `https://SkyNoc.Net/admin/settings/binance` — Binance API settings
- `https://SkyNoc.Net/api/v1/` — Reseller API
- `https://SkyNoc.Net/api-docs` — API documentation
- `https://hostname.skynoc.net/` — Hostname/DNS ordering portal
- `https://SkyNoc.Net/admin/hostname` — Hostname/Cloudflare admin

Legacy `.php` routes redirect to clean URLs.

## Binance USDT BEP20 Verification
SkyNoc can use a Binance Exchange API key as an additional read-only verification source for USDT deposits on BSC/BEP20.

Admin setup:
1. Open `/admin/settings/binance`.
2. Create a Binance API key with read-only permissions required to read deposit history. Do **not** enable withdrawals or trading.
3. Enter the API key and secret in the SkyNoc admin page and enable Binance verification.
4. Use **Test Binance API** before enabling it for production.

The integration uses signed Binance API requests server-side and checks the USDT/BSC deposit history. A supplied TXID can be matched against Binance's credited deposit history. The existing direct BSC RPC verifier remains as a fallback.

The optional recent-payment scanner can find a unique recent Binance USDT/BSC deposit matching the requested amount. SkyNoc must not automatically credit an ambiguous payment when multiple users could have made the same-sized deposit.

### Important address limitation
A normal Binance Exchange API integration does **not** provide a generic unlimited unique BEP20 address generator per SkyNoc reseller. Therefore the current integration does not falsely assign the same Binance deposit address as if it were unique to each reseller.

If SkyNoc requires one unique on-chain address per reseller, use an appropriate wallet/address-derivation system or an eligible Binance business deposit-address product. The address must be mapped to the reseller before automatic address-specific crediting is enabled.

## Order Automation
Reseller orders are wallet-funded and protected by atomic wallet debit logic. Automatic fulfillment assigns an available license from the SkyNoc license inventory, assigns the reseller domain, sets the billing expiry for monthly/annual packages, creates the invoice and sends the reseller notification.

The project does **not** invent or assume an upstream provider API. Automatic upstream license purchasing requires the actual provider's API documentation, endpoint, authentication method and request/response format. Until those are supplied, admin/provider inventory remains the safe source for fulfillment.

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
- Active hostname renewals are charged from the reseller wallet by cron. If the wallet is insufficient, the hostname is suspended and its Cloudflare record is removed when possible.

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
The admin panel supports:
- Provider records
- Reseller creation
- Staff creation and role/permission overrides
- License creation, assignment and lifecycle status
- Reissue review/completion
- API key generation/revocation and scopes
- Support ticket status
- Telegram settings
- Hostname orders and Cloudflare domain availability
- Binance BEP20 verification settings

## Reseller
The reseller portal supports:
- Own-license isolation
- Package ordering with level discounts and coupons
- Wallet balance and transaction history
- Reissue requests/history
- API key generation/revocation
- Notifications
- Support tickets and replies
- Hostname/DNS ordering at `hostname.skynoc.net`

## API
Bearer API keys support license, reissue, package and order workflows according to the assigned scopes. Rate limit: 60 requests per minute per API key.

## Cron
Run the SkyNoc cron every 5–10 minutes from cPanel Cron Jobs. CLI execution is preferred:

```bash
/opt/alt/php83/usr/bin/php /home/skynoc/public_html/public/cron.php
```

The cron handles automatic reseller order fulfillment from available license inventory, license expiry sweeps, expiry reminders and hostname wallet renewals.

If HTTP cron is required, use `/cron?key=YOUR_CRON_KEY` and keep the key private. Never post the key publicly.

## CI / Testing
GitHub Actions runs PHP 8.3 syntax checks on pushes and pull requests to `main`.

Before production use, test on the live cPanel server:
1. Login/logout and role restrictions.
2. Reseller registration and activation deposit.
3. Deposit approval and wallet credit.
4. Binance API connection test and BEP20 TXID verification.
5. Package order, level discount, coupon and atomic wallet debit.
6. Automatic inventory fulfillment and invoice creation.
7. WHMCS module Create/Suspend/Unsuspend/ChangePackage/Terminate.
8. Reissue workflow and Telegram notifications.
9. Cloudflare zone sync, hostname activation and wallet renewal.
10. API scopes, rate limiting and reseller isolation.
11. Admin/staff permission boundaries.

## Security
- Passwords use `password_hash()`.
- API keys are stored as SHA-256 hashes and shown in plaintext only at creation.
- CSRF is required for web POST actions.
- Reseller queries are scoped by reseller ID.
- Wallet debits use an atomic balance condition to prevent overspending.
- Duplicate deposit transaction hashes are blocked at the database level.
- Binance API credentials are used server-side and should be created without withdrawal/trading permissions.
- Upstream provider credentials are never exposed to resellers.
- Cloudflare API credentials are server-side only and are never rendered in reseller/customer pages.
- Sensitive application/config/database paths are blocked by `.htaccess`.
- Security headers and secure session cookie settings are enabled.
