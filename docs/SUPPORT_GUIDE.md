# Support staff guide

## Ticket handling

Review urgent/high-priority and SLA-risk queues first. Confirm customer/product, assign an owner, acknowledge the customer, investigate, reply, and keep status accurate:

- `open`: awaiting staff action
- `in_progress`: actively being investigated
- `waiting_customer`: customer information/action required
- `resolved`: solution supplied and awaiting closure
- `closed`: completed record

Internal notes are never customer-visible; customer replies are. Reopen when a resolved issue returns. Record diagnosis and reproducible steps without secrets.

## Security and licensing cases

Verify customer authority before discussing account, invoice, subscription, license, or activation details. Never ask for passwords or API tokens. If a license key is required for diagnosis, use a secure approved channel and redact it from the ticket. Escalate suspected credential compromise for immediate token/license review.

## Attachments and incidents

Allowed uploads are validated and pass through the malware-scanner abstraction. Do not download suspicious files outside the controlled environment. For widespread outage, cross-customer exposure, payment inconsistency, or API authentication failure, open an incident, capture request IDs/timestamps, and escalate immediately.

## Closure quality

Before closing, state the cause, resolution, customer action, and prevention/follow-up. Do not close merely to meet SLA. The scheduled SLA process marks breached unresolved tickets every five minutes; monitor the worker/scheduler rather than editing timestamps.
