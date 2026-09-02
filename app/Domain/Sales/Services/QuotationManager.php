<?php

namespace App\Domain\Sales\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\QuotationSent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

final readonly class QuotationManager
{
    public function __construct(private QuotationCalculator $calculator, private AuditRecorder $audit) {}

    public function create(array $data, User $actor): Quotation
    {
        return DB::transaction(function () use ($data, $actor) {
            $calculated = $this->calculator->calculate($this->snapshotNames($data['items']));
            $q = Quotation::query()->create([...Arr::except($data, ['items']), 'created_by' => $actor->id, ...Arr::only($calculated, ['subtotal', 'discount', 'tax', 'total']), 'status' => 'draft']);
            $q->forceFill(['quotation_number' => sprintf('KBT-QTN-%s-%06d', $q->issue_date->format('Y'), $q->id)])->save();
            $q->items()->createMany($calculated['items']);
            $this->audit->record('quotation.created', $actor, ['quotation_uuid' => $q->uuid, 'total' => $q->total]);

            return $q;
        });
    }

    public function update(Quotation $q, array $data, User $actor): Quotation
    {
        if ($q->status->value !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only draft quotations can be edited.']);
        }

        return DB::transaction(function () use ($q, $data, $actor) {
            $calculated = $this->calculator->calculate($this->snapshotNames($data['items']));
            $q->update([...Arr::except($data, ['items']), 'created_by' => $q->created_by, ...Arr::only($calculated, ['subtotal', 'discount', 'tax', 'total'])]);
            $q->items()->delete();
            $q->items()->createMany($calculated['items']);
            $this->audit->record('quotation.updated', $actor, ['quotation_uuid' => $q->uuid, 'total' => $q->total]);

            return $q;
        });
    }

    public function send(Quotation $q, User $actor): void
    {
        DB::transaction(function () use ($q, $actor) {
            if ($q->status->value !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft quotations can be sent.']);
            }$q->update(['status' => 'sent', 'sent_at' => now()]);
            $this->audit->record('quotation.sent', $actor, ['quotation_uuid' => $q->uuid]);
        });
        $q->load(['customer', 'items']);
        Notification::route('mail', $q->customer->email)->notify(new QuotationSent($q));
    }

    public function decide(Quotation $q, string $decision): void
    {
        DB::transaction(function () use ($q, $decision) {
            $q = Quotation::query()->lockForUpdate()->findOrFail($q->id);
            if ($q->status->value !== 'sent') {
                throw ValidationException::withMessages(['status' => 'This quotation can no longer be changed.']);
            }if ($q->expiry_date->isPast()) {
                $q->update(['status' => 'expired']);
                throw ValidationException::withMessages(['status' => 'This quotation has expired.']);
            }$q->update(['status' => $decision, $decision.'_at' => now()]);
            $this->audit->record("quotation.$decision", metadata: ['quotation_uuid' => $q->uuid]);
        });
    }

    public function convert(Quotation $q, User $actor): Invoice
    {
        return DB::transaction(function () use ($q, $actor) {
            $q = Quotation::query()->lockForUpdate()->with('items')->findOrFail($q->id);
            if ($q->status->value === 'converted') {
                return Invoice::query()->where('quotation_id', $q->id)->firstOrFail();
            }if ($q->status->value !== 'accepted') {
                throw ValidationException::withMessages(['status' => 'Only accepted quotations can be converted.']);
            }$invoice = Invoice::query()->create(['quotation_id' => $q->id, 'customer_id' => $q->customer_id, 'issue_date' => today(), 'due_date' => today()->addDays(30), 'subtotal' => $q->subtotal, 'discount' => $q->discount, 'tax' => $q->tax, 'total' => $q->total, 'amount_paid' => 0, 'balance' => $q->total, 'currency' => $q->currency, 'status' => 'draft', 'notes' => $q->notes, 'terms' => $q->terms, 'created_by' => $actor->id]);
            $invoice->forceFill(['invoice_number' => sprintf('KBT-INV-%s-%06d', today()->format('Y'), $invoice->id)])->save();
            $invoice->items()->createMany($q->items->map(fn ($i) => Arr::except($i->toArray(), ['id', 'uuid', 'quotation_id', 'created_at', 'updated_at']))->all());
            $q->update(['status' => 'converted']);
            $this->audit->record('quotation.converted', $actor, ['quotation_uuid' => $q->uuid, 'invoice_uuid' => $invoice->uuid]);

            return $invoice;
        });
    }

    private function snapshotNames(array $items): array
    {
        return collect($items)->map(function ($item) {
            $item['product'] = $item['product_id'] ? Product::query()->find($item['product_id'])?->name : null;
            $item['plan'] = $item['plan_id'] ? Plan::query()->find($item['plan_id'])?->name : null;

            return $item;
        })->all();
    }
}
