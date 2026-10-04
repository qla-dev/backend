<!doctype html>
<html lang="bs"><head><meta charset="utf-8"><title>{{ $snapshot['number'] }}</title>
<style>body{font:14px Arial,sans-serif;color:#172033;max-width:900px;margin:40px auto;padding:24px}header{display:flex;justify-content:space-between;gap:30px}h1{font-size:28px}table{width:100%;border-collapse:collapse;margin:30px 0}td,th{padding:12px;border-bottom:1px solid #ddd;text-align:right}td:first-child,th:first-child{text-align:left}.total{text-align:right;font-size:20px}button{padding:12px;cursor:pointer}@media print{button{display:none}body{margin:0;max-width:none}}</style></head>
<body><button onclick="window.print()">Štampaj / sačuvaj PDF</button>
<h1>{{ $snapshot['corrects_invoice_id'] ? 'Korektivni račun / Credit note' : 'Račun / Invoice' }} {{ $snapshot['number'] }}</h1>
<header><div><strong>{{ $snapshot['seller']['name'] }}</strong><p>{{ $snapshot['seller']['address'] ?? '' }} {{ $snapshot['seller']['city'] ?? '' }}</p><p>ID: {{ $snapshot['seller']['tax_number'] ?? '' }} · PDV: {{ $snapshot['seller']['vat_number'] ?? '' }}</p></div><div><strong>{{ $snapshot['buyer']['name'] }}</strong><p>ID: {{ $snapshot['buyer']['tax_number'] ?? '' }}</p></div></header>
<p>Datum / Date: {{ $snapshot['issued_at'] }} · Dospijeće / Due: {{ $snapshot['due_at'] }} · {{ $snapshot['currency'] }}</p>
@if($snapshot['corrects_invoice_id'])<p>Ispravlja račun / Corrects invoice ID: {{ $snapshot['corrects_invoice_id'] }}</p>@endif
<table><thead><tr><th>Opis / Description</th><th>Količina / Quantity</th><th>Cijena / Price</th><th>Osnovica / Net</th><th>PDV / VAT</th></tr></thead><tbody>
@foreach($snapshot['items'] as $item)<tr><td>{{ $item['description'] }}</td><td>{{ $item['quantity'] }}</td><td>{{ $item['unit_price'] }}</td><td>{{ $snapshot['corrects_invoice_id'] ? '-' : '' }}{{ $item['total'] }}</td><td>{{ $snapshot['corrects_invoice_id'] ? '-' : '' }}{{ $item['tax_amount'] }} ({{ $item['tax_rate'] }}%)</td></tr>@endforeach
</tbody></table><p class="total">Ukupno / Total: {{ $snapshot['corrects_invoice_id'] ? '-' : '' }}{{ $snapshot['total'] }} {{ $snapshot['currency'] }}</p>
<p>Referenca za plaćanje / Payment reference: {{ $snapshot['number'] }}</p></body></html>
