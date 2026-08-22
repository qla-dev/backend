@php
  $money = static fn ($value): string => number_format((float) $value, 2, ',', '.') . ' ' . $invoice['currency'];
  $date = static fn ($value): string => \Illuminate\Support\Carbon::parse($value)->format('d.m.Y');
  $sellerName = $seller?->name ?: ($shipment?->carrier ?: 'Freightbook partner');
  $buyerName = $buyer?->company_name ?: $buyer?->name ?: 'Kupac';
  $buyerContact = $buyer?->company_name ? $buyer?->name : null;
  $buyerAddress = $buyer?->billing_address ?: null;
  $buyerCity = $buyer?->city ?: null;
  $buyerTaxNumber = $buyer?->tax_number ?: null;
  $buyerVatNumber = $buyer?->vat_number ?: null;
  $buyerEmail = $buyer?->billing_email ?: $buyer?->email ?: null;
  $trackingNumber = $trackingNumber ?? $shipment?->tracking_number ?? $load->public_id ?? $load->id;
  $shipmentStatus = $shipmentStatus ?? $shipment?->status ?? $load->status ?? '—';
  $notes = $notes ?? null;
  $pickupStop = $origin;
  $deliveryStop = $destination;
  $cargoLabel = $load->title ?: $load->goods_type ?: '—';
@endphp
<!doctype html>
<html lang="bs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $documentTitle }} {{ $invoice['number'] }}</title>
  <style>
    :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #0f172a; background: #f8fafc; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #f8fafc; }
    .toolbar { position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 14px 20px; border-bottom: 1px solid #e2e8f0; background: rgba(255,255,255,.96); backdrop-filter: blur(12px); }
    .toolbar strong { display: block; font-size: 15px; }
    .toolbar span { color: #64748b; font-size: 12px; }
    .print-button { border: 0; border-radius: 11px; padding: 10px 16px; background: #2563eb; color: white; font: inherit; font-size: 13px; font-weight: 800; cursor: pointer; box-shadow: 0 6px 18px rgba(37,99,235,.22); }
    .shell { padding: 24px; overflow-x: auto; }
    .paper { width: 210mm; min-height: 297mm; margin: auto; padding: 15mm; border: 1px solid #e2e8f0; border-radius: 14px; background: white; box-shadow: 0 22px 60px rgba(15,23,42,.09); }
    header { display: flex; align-items: flex-start; justify-content: space-between; gap: 30px; padding-bottom: 28px; border-bottom: 2px solid #0f172a; }
    .brand { display: flex; align-items: center; gap: 10px; }
    .brand-mark { width: 34px; height: 34px; flex-shrink: 0; }
    .brand-name { font-size: 21px; font-weight: 900; letter-spacing: -.04em; }
    .brand-name em { color: #2563eb; font-style: normal; }
    .muted { color: #64748b; }
    h1 { margin: 0; font-size: 31px; letter-spacing: -.04em; text-align: right; }
    .number { margin-top: 6px; color: #64748b; text-align: right; font: 700 12px ui-monospace, monospace; }
    .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 42px; padding: 28px 0; }
    .label { margin: 0 0 9px; color: #64748b; font-size: 10px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; }
    .party-name { margin-bottom: 5px; font-size: 15px; font-weight: 800; }
    .party-line { margin: 2px 0; color: #475569; font-size: 12px; }
    .meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; margin-bottom: 24px; overflow: hidden; border: 1px solid #e2e8f0; border-radius: 10px; background: #e2e8f0; }
    .meta div { padding: 12px; background: #f8fafc; }
    .meta b { display: block; margin-top: 4px; font-size: 12px; }
    .section-title { margin: 26px 0 12px; font-size: 10px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; color: #0f172a; }
    .shipment-box { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1px; overflow: hidden; border: 1px solid #e2e8f0; border-radius: 10px; background: #e2e8f0; }
    .shipment-box div { padding: 11px 14px; background: #f8fafc; display: flex; justify-content: space-between; gap: 12px; font-size: 12px; }
    .shipment-box span:first-child { color: #64748b; font-weight: 700; }
    .shipment-box span:last-child { font-weight: 700; text-align: right; }
    table { width: 100%; border-collapse: collapse; font-size: 12px; }
    th { padding: 11px 9px; background: #0f172a; color: white; font-size: 9px; letter-spacing: .08em; text-align: left; text-transform: uppercase; }
    td { padding: 13px 9px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .summary { width: 280px; margin: 28px 0 0 auto; }
    .summary-row { display: flex; justify-content: space-between; gap: 20px; padding: 6px 0; color: #475569; font-size: 12px; }
    .summary-row.total { margin-top: 7px; padding-top: 13px; border-top: 2px solid #0f172a; color: #0f172a; font-size: 16px; font-weight: 900; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 42px; margin-top: 26px; }
    .info-grid .party-line { display: flex; justify-content: space-between; gap: 10px; }
    .info-grid .party-line span:first-child { color: #64748b; }
    .notes-box { margin-top: 8px; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; font-size: 12px; color: #334155; white-space: pre-line; min-height: 20px; }
    footer { margin-top: 40px; padding-top: 18px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 10px; line-height: 1.6; }
    @media print {
      @page { size: A4 portrait; margin: 0; }
      body { width: 210mm; background: white; }
      .no-print { display: none !important; }
      .shell { padding: 0; overflow: visible; }
      .paper { width: 210mm; min-height: 297mm; margin: 0; padding: 15mm; border: 0; border-radius: 0; box-shadow: none; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
      thead { display: table-header-group; }
      tr { break-inside: avoid; }
    }
  </style>
</head>
<body>
  <div class="toolbar no-print">
    <div><strong>{{ $documentTitle }} {{ $invoice['number'] }}</strong><span>{{ $buyerName }}</span></div>
    <button type="button" class="print-button" id="print-document">Preuzmi PDF</button>
  </div>
  <main class="shell">
    <article class="paper">
      <header>
        <div>
          <div class="brand">
            <svg class="brand-mark" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
              <rect width="64" height="64" rx="16" fill="#2563EB"/>
              <path d="M15 17.5A6.5 6.5 0 0 1 21.5 11H43a10 10 0 0 1 10 10v25.5a2 2 0 0 1-3.15 1.64A12.6 12.6 0 0 0 42 45.5H21.5A6.5 6.5 0 0 1 15 39V17.5Z" fill="#fff" fill-opacity=".16" stroke="#fff" stroke-width="4" stroke-linejoin="round"/>
              <path d="M24 25h15M24 32h10" stroke="#fff" stroke-width="4" stroke-linecap="round"/>
              <path d="M34 36h13l4 5v7H34V36Z" fill="#fff"/>
              <circle cx="38" cy="49" r="4" fill="#2563EB" stroke="#fff" stroke-width="2"/>
              <circle cx="48" cy="49" r="4" fill="#2563EB" stroke="#fff" stroke-width="2"/>
            </svg>
            <div class="brand-name">Freightbook<em>.ai</em></div>
          </div>
          <div class="muted" style="margin-top:6px;font-size:11px">Digitalna logistička platforma</div>
        </div>
        <div><h1>{{ $documentTitle }}</h1><div class="number">#{{ $invoice['number'] }}</div></div>
      </header>

      <section class="parties">
        <div>
          <p class="label">Izdavalac</p><div class="party-name">{{ $sellerName }}</div>
          @if($seller?->address)<p class="party-line">{{ $seller->address }}{{ $seller->city ? ', '.$seller->city : '' }}</p>@endif
          @if($seller?->tax_number)<p class="party-line">ID broj: {{ $seller->tax_number }}</p>@endif
          @if($seller?->vat_number)<p class="party-line">PDV broj: {{ $seller->vat_number }}</p>@endif
          @if($seller?->email)<p class="party-line">{{ $seller->email }}</p>@endif
        </div>
        <div>
          <p class="label">Kupac</p><div class="party-name">{{ $buyerName }}</div>
          @if($buyerContact)<p class="party-line">{{ $buyerContact }}</p>@endif
          @if($buyerAddress)<p class="party-line">{{ $buyerAddress }}{{ $buyerCity ? ', '.$buyerCity : '' }}</p>@endif
          @if($buyerTaxNumber)<p class="party-line">ID broj: {{ $buyerTaxNumber }}</p>@endif
          @if($buyerVatNumber)<p class="party-line">PDV broj: {{ $buyerVatNumber }}</p>@endif
          @if($buyerEmail)<p class="party-line">{{ $buyerEmail }}</p>@endif
        </div>
      </section>

      <section class="meta">
        <div><span class="label">Datum izdavanja</span><b>{{ $date($invoice['issued_at']) }}</b></div>
        <div><span class="label">Rok plaćanja</span><b>{{ $date($invoice['due_at']) }}</b></div>
        <div><span class="label">Uslovi plaćanja</span><b>{{ $load->payment_terms ?: '—' }}</b></div>
        <div><span class="label">Valuta</span><b>{{ $invoice['currency'] }}</b></div>
      </section>

      <p class="section-title">Pošiljka</p>
      <div class="shipment-box">
        <div><span>ID naloga</span><span>{{ $trackingNumber }}</span></div>
        <div><span>Relacija</span><span>{{ $pickupStop?->city ?: '—' }} → {{ $deliveryStop?->city ?: '—' }}</span></div>
        <div><span>Preuzimanje</span><span>{{ $pickupStop?->window_starts_at ? $date($pickupStop->window_starts_at) : '—' }}</span></div>
        <div><span>Isporuka</span><span>{{ $deliveryStop?->window_starts_at ? $date($deliveryStop->window_starts_at) : '—' }}</span></div>
        <div><span>Roba</span><span>{{ $cargoLabel }}</span></div>
        <div><span>Težina</span><span>{{ $load->weight_kg ? number_format((float) $load->weight_kg, 0, ',', '.').' kg' : '—' }}</span></div>
        <div><span>Dužina utovara</span><span>{{ $load->length_m ? number_format((float) $load->length_m, 1, ',', '.').' m' : '—' }}</span></div>
        <div><span>Vozilo</span><span>{{ $load->vehicle_type ?: '—' }}</span></div>
      </div>

      <table style="margin-top:26px">
        <thead><tr><th>Opis usluge</th><th class="num">Količina</th><th class="num">Jed. cijena</th><th class="num">Ukupno</th></tr></thead>
        <tbody>
          @foreach($invoice['items'] as $item)
            <tr><td>{{ $item['description'] }}</td><td class="num">{{ number_format($item['quantity'], 2, ',', '.') }}</td><td class="num">{{ $money($item['unit_price']) }}</td><td class="num">{{ $money($item['total']) }}</td></tr>
          @endforeach
        </tbody>
      </table>

      <div class="summary">
        <div class="summary-row"><span>Međuzbir</span><b>{{ $money($invoice['subtotal']) }}</b></div>
        <div class="summary-row"><span>PDV</span><b>{{ $money($invoice['tax']) }}</b></div>
        <div class="summary-row total"><span>Za platiti</span><span>{{ $money($invoice['total']) }}</span></div>
      </div>

      <div class="info-grid">
        <div>
          <p class="section-title" style="margin-top:0">Podaci za plaćanje</p>
          <p class="party-line"><span>Banka</span><span>{{ $seller?->bank_name ?: '—' }}</span></p>
          <p class="party-line"><span>IBAN</span><span>{{ $seller?->iban ?: '—' }}</span></p>
          <p class="party-line"><span>SWIFT/BIC</span><span>{{ $seller?->swift_bic ?: '—' }}</span></p>
          <p class="party-line"><span>Poziv na broj</span><span>{{ $invoice['payment_reference'] }}</span></p>
        </div>
        <div>
          <p class="section-title" style="margin-top:0">Napomene / uslovi</p>
          <div class="notes-box">{{ $notes ?: '—' }}</div>
        </div>
      </div>

      <footer>
        Status pošiljke: {{ $shipmentStatus }} · Status fakture: {{ $invoice['status_label'] }}
      </footer>
    </article>
  </main>
  <script>
    document.getElementById('print-document').addEventListener('click', function () { window.print(); });
  </script>
</body>
</html>
