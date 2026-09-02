# KernelBridge commercial launch checklist

**Gate owner:** Release manager  
**Last reviewed:** 1 September 2026  
**Current decision:** **NO-GO — production infrastructure blockers remain**

Do not launch until every item in “Blocking production checks” is signed off with a date, operator, and evidence link. Passing local tests does not satisfy a production check.

## Blocking production checks

- [ ] Deploy the Laravel release to the `kernelbridge.com` Nginx document root. The live root currently exposes an `Index of /` directory listing instead of the application.
- [ ] Make `GET https://kernelbridge.com/health` return the sanitized application health response. It currently returns 404.
- [ ] Redirect all `http://kernelbridge.com` requests to HTTPS. HTTP currently returns 200 without redirecting.
- [ ] Redirect `https://www.kernelbridge.com` to the canonical apex domain. It currently returns the same directory index.
- [ ] Create DNS records for `alignex`, `edunexa`, `mediflow`, `marketlens`, and `staycontrol`. All five names failed DNS resolution during the audit.
- [ ] Deploy each product/demo application, configure its database/Redis namespace/worker, and verify its demo URL from an external network.
- [ ] Issue and validate TLS certificates for every product hostname; run `certbot renew --dry-run`.
- [ ] Replace every `REPLACE_*` deployment value with production secrets and remove `SUPER_ADMIN_PASSWORD` after one-time seeding.
- [ ] Configure real SMTP and prove delivery, bounce handling, and reply-to behavior for every commercial notification.
- [ ] Start Supervisor queue workers and prove queued email moves from pending to delivered; verify failed-job alerting.
- [ ] Install the scheduler cron and prove `schedule:run` execution in logs.
- [ ] Install nightly database/storage backups, copy them off-server, alert on age/failure, and complete a restore drill.
- [ ] Run the staging browser matrix: phone, tablet, desktop, Chrome, Firefox, Safari/WebKit; record screenshots and console output.
- [ ] Run Lighthouse/axe against public, login, customer, and admin pages and agree on any exceptions.
- [ ] Perform a production-like MySQL 8/Redis load test and review slow-query, queue-latency, disk, and memory telemetry.

## Verified in the release workspace

- [x] Full commercial journey automated: visitor → product → demo request → scheduled/completed demo → lead conversion → customer → quotation acceptance → invoice → verified payment → subscription → license → API activation/verification → renewal → portal → support.
- [x] Full PHPUnit suite: 112 tests, 839 assertions.
- [x] Production frontend build: 852 modules transformed successfully.
- [x] Product catalogue and pricing are database-backed.
- [x] Customer registration creates an isolated prospect customer record.
- [x] Customer dashboard shows linked subscriptions, licenses, quotations, invoices, payments, and support records.
- [x] Backend financial calculations and verified-payment allocation are covered.
- [x] Subscription activation and renewal require a paid, same-customer, same-currency invoice containing the subscribed product.
- [x] License issue, API activation, verification, deactivation, entitlement, rate-limit, token rotation, and isolation tests pass.
- [x] Notifications use queued database/email channels and idempotent automation tests pass.
- [x] Seven required scheduled tasks appear in `php artisan schedule:list`.
- [x] Deployment and backup shell scripts pass syntax validation.
- [x] Deployment artifacts exist for Nginx, Supervisor, cron, log rotation, backups, environment configuration, and independent product applications.
- [x] Placeholder/debug scan found no application TODO/FIXME/debug calls or browser console logging. `REPLACE_*` values remain only in deployment templates and must never be deployed unchanged.

## Release-day sequence

1. Freeze changes, identify the Git commit, confirm backup and rollback owners.
2. Deploy an immutable release using [PRODUCTION_DEPLOYMENT.md](PRODUCTION_DEPLOYMENT.md).
3. Run migrations, storage link, optimization, worker restart, and PHP-FPM reload.
4. Verify `/health`, canonical redirects, TLS, security headers, login, and password reset.
5. Complete the commercial smoke journey with a designated test customer and low-value test documents; reverse test financial records using the supported refund process.
6. Activate one installation for every deployed product and verify entitlements from that product.
7. Confirm notification delivery, queue depth, scheduler logs, backup freshness, and monitoring alerts.
8. Obtain release-manager, technical, sales, finance, support, and data-protection sign-off.

## Immediate rollback triggers

Rollback or disable affected traffic for cross-customer data exposure, incorrect financial totals, unauthorized admin/API access, inability to verify licenses, failing migrations, sustained queue failure, or unavailable database/Redis. Do not roll back financial migrations blindly; follow a migration-specific recovery plan.
