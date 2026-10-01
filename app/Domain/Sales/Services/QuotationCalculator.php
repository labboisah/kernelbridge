<?php

namespace App\Domain\Sales\Services;

use Illuminate\Validation\ValidationException;

final class QuotationCalculator
{
    /** @param list<array<string,mixed>> $items @return array{subtotal:string,discount:string,tax:string,total:string,items:list<array<string,mixed>>} */
    public function calculate(array $items, ?string $vatRate = null): array
    {
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $calculated = [];
        foreach (array_values($items) as $index => $item) {
            $quantity = $this->quantityUnits((string) $item['quantity']);
            $unit = $this->minor((string) $item['unit_price']);
            $itemDiscount = $this->minor((string) ($item['discount'] ?? 0));
            $itemTax = $this->minor((string) ($item['tax'] ?? 0));
            $gross = (int) round($quantity * $unit / 100);
            if ($itemDiscount > $gross) {
                throw ValidationException::withMessages(["items.$index.discount" => 'Item discount cannot exceed its gross amount.']);
            }
            if ($vatRate !== null) {
                $itemTax = (int) round(($gross - $itemDiscount) * $this->minor($vatRate) / 10000);
            }
            $total = $gross - $itemDiscount + $itemTax;
            $subtotal += $gross;
            $discount += $itemDiscount;
            $tax += $itemTax;
            $calculated[] = [...$item, 'quantity' => $this->quantityFormat($quantity), 'unit_price' => $this->format($unit), 'discount' => $this->format($itemDiscount), 'tax' => $this->format($itemTax), 'total' => $this->format($total), 'sort_order' => $index];
        }

        return ['subtotal' => $this->format($subtotal), 'discount' => $this->format($discount), 'tax' => $this->format($tax), 'total' => $this->format($subtotal - $discount + $tax), 'items' => $calculated];
    }

    private function minor(string $value): int
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Invalid monetary amount.']);
        }[$whole,$fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function quantityUnits(string $value): int
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value) || ($units = (int) round((float) $value * 100)) < 1) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be greater than zero.']);
        }

        return $units;
    }

    private function format(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    private function quantityFormat(int $units): string
    {
        return number_format($units / 100, 2, '.', '');
    }
}
