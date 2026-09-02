# Administrator guide

## Daily start

Open the admin dashboard, review health/monitoring, failed jobs, open urgent tickets, pending payments, overdue invoices, expiring subscriptions/licenses, and unassigned leads. Use least-privilege staff accounts; reserve `super_admin` for permission, credential, and emergency operations.

## Core administration

- Products: maintain public copy, media, links, status, featured flag, ordering, demo URL, and documentation URL. Only active products appear publicly.
- Plans: maintain price, billing cycle, public/active state, feature keys, and limits. Entitlements are database records—never duplicate them in product code.
- Customers: maintain legal/contact details, assigned owner, products, contacts, addresses, and internal notes.
- Demo mode: publish product-owned restrictions/reset instructions. KernelBridge never directly resets a product database.
- Staff access: assign only the role required for the job and review permission changes in audit logs.

## Portal provisioning

The customer first creates a login at `/register`. After identity and customer authority are verified, link that login to the commercial account:

```bash
php artisan customer:link-user KBT-CUS-000001 customer@example.com --primary
```

The command accepts a customer UUID or number. Do not link an address based only on an unauthenticated phone request. A self-registered prospect record may coexist with an organisation membership and does not expose another customer automatically.

## Subscription and licensing operations

Activate subscriptions only after finance has verified and fully allocated the matching invoice. Issue the initial key once:

```bash
php artisan licenses:issue KBT-SUB-2026-000001 --max-activations=1
```

The command accepts a subscription UUID or number, rejects ineligible subscriptions and duplicate active licenses, and displays the key once. Transfer it through an approved secure channel. Issue product machine credentials independently and copy their tokens immediately:

```bash
php artisan api-client:issue ALIGNEX production
php artisan api-client:rotate CREDENTIAL_UUID production-rotated
php artisan api-client:revoke CREDENTIAL_UUID
```

## Never do

Never edit financial/audit rows directly, share product tokens, expose license keys in tickets, hard-delete verified records, run general seeders blindly in production, regenerate `APP_KEY`, disable authorization to resolve an access problem, or place customer/product databases in the central schema.

## Incident escalation

Capture request ID, time, actor/customer/product, affected UUID, and symptoms without copying secrets. Revoke exposed API credentials immediately. For suspected customer isolation or financial integrity issues, stop the affected workflow and notify the release/security owner before attempting repair.
