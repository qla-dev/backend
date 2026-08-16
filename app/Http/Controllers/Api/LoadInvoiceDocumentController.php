<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Load;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LoadInvoiceDocumentController extends Controller
{
    public function __invoke(Request $request, Load $load, string $document): View
    {
        abort_unless(in_array($document, ['predracun', 'a4-faktura'], true), 404);

        $load->load(['customer', 'company', 'stops', 'shipment']);

        $invoice = Invoice::query()
            ->with('items')
            ->where('load_id', $load->id)
            ->latest('issued_at')
            ->latest('id')
            ->first();

        $shipment = $load->shipment;
        $trackingNumber = (string) ($shipment?->tracking_number ?: $load->public_id ?: $load->id);
        $currency = (string) ($invoice?->currency ?: $load->currency ?: 'EUR');
        $amount = (float) ($invoice?->subtotal ?? $load->budget ?? 0);
        $tax = (float) ($invoice?->tax ?? 0);
        $total = (float) ($invoice?->total ?? ($amount + $tax));
        $issuedAt = $invoice?->issued_at ?? Carbon::today();
        $dueAt = $invoice?->due_at ?? Carbon::parse($issuedAt)->addDays((int) ($load->payment_due_days ?: 7));
        $origin = $load->stops->first();
        $destination = $load->stops->last();

        $items = $invoice?->items->map(fn ($item): array => [
            'description' => (string) $item->description,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'total' => (float) $item->total,
        ])->values()->all() ?? [];

        if ($items === []) {
            $route = collect([$origin?->city, $destination?->city])->filter()->implode(' - ');
            $description = trim((string) ($load->title ?: 'Freight service'));
            $items[] = [
                'description' => $description.($route !== '' ? " ({$route})" : ''),
                'quantity' => 1,
                'unit_price' => $amount,
                'total' => $amount,
            ];
        }

        return view('documents.shipment-invoice', [
            'documentTitle' => $document === 'predracun' ? 'Predračun' : 'A4 faktura',
            'invoice' => [
                'number' => (string) ($invoice?->number ?: 'SF-'.$trackingNumber),
                'currency' => $currency,
                'issued_at' => $issuedAt,
                'due_at' => $dueAt,
                'subtotal' => $amount,
                'tax' => $tax,
                'total' => $total,
                'items' => $items,
            ],
            'shipment' => $shipment,
            'shipmentStatus' => (string) ($shipment?->status ?: $load->status ?: '—'),
            'trackingNumber' => $trackingNumber,
            'load' => $load,
            'seller' => $load->company,
            'buyer' => $load->customer,
            'origin' => $origin,
            'destination' => $destination,
        ]);
    }
}
