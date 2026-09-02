# Finance staff guide

## Invoice workflow

Confirm the customer, currency, line items, tax, discount, due date, terms, and quotation reference. Issue only an approved draft. Once issued, use supported status actions; do not edit database totals. The backend is authoritative for all calculations.

## Manual payment workflow

1. Receive external payment evidence through the approved channel.
2. Record amount, currency, method, transaction reference, date, evidence, and notes. The payment starts as **pending**.
3. Independently confirm the funds in the bank/POS/cash control.
4. Allocate the complete payment to eligible invoices for the same customer and currency.
5. Verify. The platform records verifier/time and recalculates invoice balance/status transactionally.

Partial allocation makes an invoice partially paid; full allocation makes it paid. Never verify from a screenshot alone, reuse a bank reference, allocate across customers/currencies, or trust browser-supplied totals.

## Corrections and refunds

Verified financial records cannot be deleted. Use the refund/reversal action with a clear reason; it creates a separate immutable record and restores invoice balance. Escalate incorrect currency, tax, customer, or duplicate-payment cases before further allocation.

## Subscription handoff and reconciliation

Notify administration only after the invoice is fully paid. Activation/renewal requires the paid invoice to contain the subscribed product and match the customer/currency. Daily reconciliation should compare bank activity, pending payments, verified allocations, invoice balances, refunds, and the immutable financial audit log.
