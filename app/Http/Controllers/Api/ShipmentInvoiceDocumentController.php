<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Shipment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ShipmentInvoiceDocumentController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, string $document): View
    {
        abort_unless(in_array($document, ['predracun', 'a4-faktura'], true), 404);

        $shipment->load([
            'freightLoad.customer',
            'freightLoad.consignee',
            'freightLoad.company',
            'freightLoad.stops',
        ]);

        $load = $shipment->freightLoad;
        abort_if($load === null, 404);

        $invoice = Invoice::query()
            ->with('items')
            ->where('load_id', $load->id)
            ->latest('issued_at')
            ->latest('id')
            ->first();

        $currency = (string) ($invoice?->currency ?: $load->currency ?: 'EUR');
        $amount = (float) ($invoice?->subtotal ?? $load->budget ?? 0);
        $tax = (float) ($invoice?->tax ?? 0);
        $total = (float) ($invoice?->total ?? ($amount + $tax));
        $issuedAt = $invoice?->issued_at ?? Carbon::today();
        $dueAt = $invoice?->due_at ?? Carbon::parse($issuedAt)->addDays((int) ($load->payment_due_days ?: 7));
        $isProforma = $document === 'predracun';
        $origin = $load->stops->first();
        $destination = $load->stops->last();
        $trackingNumber = (string) ($shipment->tracking_number ?: $load->public_id ?: $load->id);
        $invoiceStatusLabels = ['draft' => 'Nacrt', 'sent' => 'Poslano', 'paid' => 'Plaćeno', 'overdue' => 'Kasni', 'cancelled' => 'Otkazano'];

        $items = $invoice?->items->map(fn ($item): array => [
            'description' => (string) $item->description,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'total' => (float) $item->total,
        ])->values()->all() ?? [];

        if ($items === []) {
            $route = collect([$origin?->city, $destination?->city])->filter()->implode(' - ');
            $items[] = [
                'description' => trim((string) $load->title) . ($route !== '' ? " ({$route})" : ''),
                'quantity' => 1,
                'unit_price' => $amount,
                'total' => $amount,
            ];
        }

        return view('documents.shipment-invoice', [
            'documentTitle' => $isProforma ? 'Predračun' : 'A4 faktura',
            'invoice' => [
                'number' => (string) ($invoice?->number ?: 'SF-'.$shipment->tracking_number),
                'currency' => $currency,
                'issued_at' => $issuedAt,
                'due_at' => $dueAt,
                'subtotal' => $amount,
                'tax' => $tax,
                'total' => $total,
                'items' => $items,
                'payment_reference' => (string) ($invoice?->number ?: 'SF-'.$shipment->tracking_number),
                'status' => (string) ($invoice?->status ?: 'draft'),
                'status_label' => $invoiceStatusLabels[$invoice?->status ?: 'draft'] ?? ucfirst((string) ($invoice?->status ?: 'draft')),
            ],
            'shipment' => $shipment,
            'shipmentStatus' => (string) ($shipment->status ?: $load->status ?: '—'),
            'trackingNumber' => $trackingNumber,
            'load' => $load,
            'seller' => $load->company,
            'buyer' => $load->consignee ?: $load->customer,
            'origin' => $origin,
            'destination' => $destination,
            'notes' => (string) ($load->external_comments ?: $load->notes ?: ''),
        ]);
    }
}
