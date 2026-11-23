# Administrator guide

## Daily start

Open the admin dashboard, review health/monitoring, failed jobs, open urgent tickets, pending payments, overdue invoices, expiring subscriptions/licenses, and unassigned leads. Use least-privilege staff accounts; reserve `super_admin` for permission, credential, and emergency operations.

## Core administration

- Products: maintain public copy, media, links, status, featured flag, ordering, demo URL, and documentation URL. Only active products appear publicly.
- Plans: maintain price, billing cycle, public/active state, feature keys, and limits. Entitlements are database records—never duplicate them in product code.
- Customers: maintain legal/contact details, assigned owner, products, contacts, addresses, and internal notes.
- Demo mode: publish product-owned restrictions/reset instructions. KernelBridge never directly resets a product database.
- Staff access: assign only the role required for the job and review permission changes in audit logs.

## Partner applications

The Partners screen lists draft, submitted, approved, and declined applications. Search by applicant name, email or organization, and filter by status. Use View to review a submitted application, or Edit to update application details without changing its account, consent or review decision. The editor shows guided questions and only displays older open-ended response fields when they contain previous answers.

Deletion permanently removes the application and its private photo, but retains the user account and other records. Deleting an approved sales-agent application revokes approved-agent access. Applications with organization-upgrade records and sales-agent applications with commercial activity cannot be deleted. Follow the retention policy before deleting any application; edits and deletions are recorded in the audit log.

## Product installation delivery

For products sold with a downloadable installation:

1. In the product editor, configure its GitHub repository, visibility, stable version and exact Git tag. Add the customer-facing installation steps in Markdown.
2. For a private repository, install KernelBridge's GitHub App on that repository with **Contents: read-only** access. Set `GITHUB_APP_ID` and `GITHUB_APP_PRIVATE_KEY_PATH` on the server, keep the key outside the web root, and enter the installation ID in the product settings. Never put the App key or installation token in product metadata.
3. Open the product's admin page and select **Build selected release**. A ready archive is stored on the private `local` disk. A configured Redis queue worker must be running; monitor failures with `php artisan queue:failed`. A failed build displays a safe failure code and can be retried.
4. Confirm the selected release is ready before taking orders. Checkout rejects new purchases for a configured repository until its stable archive is ready and pins the exact release to the subscription.
5. After finance verifies full payment, the customer's invoice page exposes the pinned project download, customer-portal installation guide, and licensing setup/activation links. These resources require customer ownership and paid invoice status; never send archive storage paths or public storage URLs to customers.

The archive build requires PHP's Zip extension. Queue workers also require outbound HTTPS access to `api.github.com` and `codeload.github.com`. Changing the stable version does not change releases already pinned to paid subscriptions.

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
