<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PaymentInvoiceDocumentController extends Controller
{
    public function __invoke(Request $request, Payment $payment): View
    {
        abort_unless($payment->user_id === $request->user()->id, 404);

        $payment->load(['user.companies', 'user.customerProfile', 'subscriptionPackage']);

        $issuedAt = $payment->created_at;
        $invoiceNumber = sprintf('FB-PAY-%s-%06d', $issuedAt->format('Y'), $payment->id);
        $packageName = $payment->subscriptionPackage?->name;
        $description = $payment->type === 'package'
            ? 'Kupovina paketa'.($packageName ? ' - '.$packageName : '')
            : sprintf('LenaAI dopuna - %s poruka', number_format((int) $payment->tokens, 0, ',', '.'));
        $buyer = $payment->user->companies->first()
            ?: $payment->user->customerProfile
            ?: $payment->user;

        return view('documents.shipment-invoice', [
            'documentTitle' => 'Faktura',
            'invoice' => [
                'number' => $invoiceNumber,
                'currency' => (string) $payment->currency,
                'issued_at' => $issuedAt,
                'due_at' => $issuedAt,
                'subtotal' => (float) $payment->amount,
                'tax' => 0,
                'total' => (float) $payment->amount,
                'items' => [[
                    'description' => $description,
                    'quantity' => 1,
                    'unit_price' => (float) $payment->amount,
                    'total' => (float) $payment->amount,
                ]],
                'payment_reference' => $invoiceNumber,
                'status' => 'paid',
                'status_label' => 'Plaćeno',
            ],
            'shipment' => null,
            'shipmentStatus' => null,
            'trackingNumber' => (string) $payment->id,
            'load' => null,
            'seller' => (object) [
                'name' => 'Freightbook.ai',
                'email' => config('mail.from.address'),
                'address' => null,
                'city' => null,
                'tax_number' => null,
                'vat_number' => null,
                'bank_name' => null,
                'iban' => null,
                'swift_bic' => null,
            ],
            'buyer' => $buyer,
            'origin' => null,
            'destination' => null,
            'notes' => 'Automatski generisana faktura za završenu uplatu na Freightbook.ai platformi.',
            'paymentMode' => true,
            'paymentDetails' => [
                'id' => $payment->id,
                'type' => $payment->type === 'package' ? 'Kupovina paketa' : 'Dopuna',
                'tokens' => (int) $payment->tokens,
                'status' => 'Plaćeno',
            ],
        ]);
    }
}
