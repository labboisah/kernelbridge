# Commercial launch verification report

**Assessment:** 1 September 2026  
**Decision:** **NO-GO for public commercial launch**

## Automated business journey

`CommercialJourneyTest` completes the requested lifecycle in one database-isolated scenario: public product page, demo page/request, lead creation, demo scheduled/completed, conversion without duplication, customer portal membership, quotation creation/send/signed acceptance, invoice conversion/issue, manual payment record/verification/allocation, paid invoice, subscription creation/activation, license issue, machine credential, API activation/verification, paid renewal, customer dashboard, support ticket, and staff reply.

The full suite passed: **112 tests, 839 assertions**. The production Vite build passed with **852 modules**. Seven scheduled commands are registered. Deployment and backup scripts pass shell syntax checks.

## Live production observations

Read-only checks from an external network found:

| Check | Result | Launch impact |
|---|---|---|
| `https://kernelbridge.com` | TLS connection succeeds, but response is an `Index of /` directory listing | Blocker |
| `https://kernelbridge.com/health` | 404 | Blocker |
| `http://kernelbridge.com` | 200, no HTTPS redirect | Blocker |
| `https://www.kernelbridge.com` | 200 directory listing, no apex redirect | Blocker |
| AlignEx/EduNexa/MediFlow/MarketLens/StayControl subdomains | DNS name does not exist | Blocker |

Therefore public polish, mobile behavior, live demos, SMTP, workers, scheduler execution, backups, product SSL, and production API behavior cannot be confirmed on the deployed system. Source configuration exists, but infrastructure evidence is still required.

## Corrections made during this gate

- Replaced the placeholder customer dashboard with customer-isolated commercial summaries and recent records.
- Registration now creates and links an isolated prospect customer record.
- Added a controlled command to link a verified login to a converted customer.
- Added a one-time license issuance command with eligibility/duplicate checks.
- Hardened subscription activation and renewal so a generic paid invoice cannot activate an unrelated product subscription.
- Added customer portal isolation coverage and the full commercial journey test.

## Remaining technical risks

- No Playwright/Cypress, axe, Lighthouse CI, or visual regression suite is configured; responsive/accessibility sign-off remains manual.
- No Larastan/PHPStan/Psalm configuration exists.
- No production-like load test or MySQL slow-query review has been performed.
- SMTP delivery, queue supervision, scheduler locks, malware scanner implementation, off-site backup replication, and restore are not externally proven.
- Product applications and their licensing clients must be deployed and verified independently; their databases must remain separate.

Use [LAUNCH_CHECKLIST.md](LAUNCH_CHECKLIST.md) as the release gate. Do not change this report to “GO” until every production blocker has dated evidence and an accountable sign-off.
