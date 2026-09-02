<?php

namespace App\Domain\Finance\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Sales\Services\QuotationCalculator;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

final readonly class InvoiceManager
{
    public function __construct(private QuotationCalculator $calculator, private AuditRecorder $audit) {}

    public function create(array $data, User $actor): Invoice
    {
        return DB::transaction(function () use ($data, $actor) {
            $calc = $this->calculator->calculate($this->names($data['items']));
            $i = Invoice::query()->create([...Arr::except($data, ['items']), 'created_by' => $actor->id, ...Arr::only($calc, ['subtotal', 'discount', 'tax', 'total']), 'amount_paid' => '0.00', 'balance' => $calc['total'], 'status' => 'draft']);
            $i->forceFill(['invoice_number' => sprintf('KBT-INV-%s-%06d', $i->issue_date->format('Y'), $i->id)])->save();
            $i->items()->createMany($calc['items']);
            $this->audit->record('invoice.created', $actor, ['invoice_uuid' => $i->uuid, 'total' => $i->total]);

            return $i;
        });
    }

    public function update(Invoice $i, array $data, User $actor): Invoice
    {
        if ($i->status->value !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only draft invoices can be edited.']);
        }

        return DB::transaction(function () use ($i, $data, $actor) {
            $calc = $this->calculator->calculate($this->names($data['items']));
            $i->update([...Arr::except($data, ['items']), 'created_by' => $i->created_by, ...Arr::only($calc, ['subtotal', 'discount', 'tax', 'total']), 'balance' => $calc['total']]);
            $i->items()->delete();
            $i->items()->createMany($calc['items']);
            $this->audit->record('invoice.updated', $actor, ['invoice_uuid' => $i->uuid]);

            return $i;
        });
    }

    public function issue(Invoice $i, User $actor): void
    {
        DB::transaction(function () use ($i, $actor) {
            if ($i->status->value !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft invoices can be issued.']);
            }$i->update(['status' => $i->due_date->isPast() ? 'overdue' : 'issued', 'issued_at' => now()]);
            $this->audit->record('invoice.issued', $actor, ['invoice_uuid' => $i->uuid]);
        });
        $i->load(['customer', 'items']);
        Notification::route('mail', $i->customer->email)->notify(new InvoiceIssued($i));
    }

    public function payment(Invoice $invoice, array $data, User $actor): void
    {
        DB::transaction(function () use ($invoice, $data, $actor) {
            $i = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($i->status->value, ['issued', 'partially_paid', 'overdue'], true)) {
                throw ValidationException::withMessages(['status' => 'Payments can only be recorded against an issued invoice.']);
            }$amount = $this->minor((string) $data['amount']);
            $balance = $this->minor($i->balance);
            if ($amount > $balance) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the outstanding balance.']);
            }$i->payments()->create([...$data, 'amount' => $this->format($amount), 'currency' => $i->currency, 'status' => 'successful', 'recorded_by' => $actor->id]);
            $paid = $this->minor($i->amount_paid) + $amount;
            $remaining = $this->minor($i->total) - $paid;
            $status = $remaining === 0 ? 'paid' : 'partially_paid';
            $i->update(['amount_paid' => $this->format($paid), 'balance' => $this->format($remaining), 'status' => $status, 'paid_at' => $remaining === 0 ? now() : null, 'payment_reference' => $data['reference']]);
            $this->audit->record('invoice.payment_recorded', $actor, ['invoice_uuid' => $i->uuid, 'amount' => $this->format($amount), 'reference' => $data['reference']]);
        });
    }

    public function cancel(Invoice $i, User $actor): void
    {
        if (in_array($i->status->value, ['paid', 'cancelled', 'refunded'], true)) {
            throw ValidationException::withMessages(['status' => 'This invoice cannot be cancelled.']);
        }$i->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->audit->record('invoice.cancelled', $actor, ['invoice_uuid' => $i->uuid]);
    }

    public function markOverdue(): int
    {
        return Invoice::query()->whereIn('status', ['issued', 'partially_paid'])->where('balance', '>', 0)->whereDate('due_date', '<', today())->update(['status' => 'overdue', 'updated_at' => now()]);
    }

    private function names(array $items): array
    {
        return collect($items)->map(function ($v) {
            $v['product'] = $v['product_id'] ? Product::find($v['product_id'])?->name : null;
            $v['plan'] = $v['plan_id'] ? Plan::find($v['plan_id'])?->name : null;

            return $v;
        })->all();
    }

    private function minor(string $v): int
    {
        [$w,$f] = array_pad(explode('.', $v, 2), 2, '');

        return (int) $w * 100 + (int) str_pad($f, 2, '0');
    }

    private function format(int $v): string
    {
        return number_format($v / 100, 2, '.', '');
    }
}
