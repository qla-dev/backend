@php
  $money = static fn ($value): string => number_format((float) $value, 2, ',', '.') . ' ' . $invoice['currency'];
  $date = static fn ($value): string => \Illuminate\Support\Carbon::parse($value)->format('d.m.Y');
  $sellerName = $seller?->name ?: ($shipment?->carrier ?: 'SmartFreight partner');
  $buyerName = $buyer?->name ?: 'Kupac';
  $trackingNumber = $trackingNumber ?? $shipment?->tracking_number ?? $load->public_id ?? $load->id;
  $shipmentStatus = $shipmentStatus ?? $shipment?->status ?? $load->status ?? '—';
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
    .brand { font-size: 23px; font-weight: 900; letter-spacing: -.04em; }
    .brand em { color: #2563eb; font-style: normal; }
    .muted { color: #64748b; }
    h1 { margin: 0; font-size: 31px; letter-spacing: -.04em; text-align: right; }
    .number { margin-top: 6px; color: #64748b; text-align: right; font: 700 12px ui-monospace, monospace; }
    .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 42px; padding: 28px 0; }
    .label { margin: 0 0 9px; color: #64748b; font-size: 10px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; }
    .party-name { margin-bottom: 5px; font-size: 15px; font-weight: 800; }
    .party-line { margin: 2px 0; color: #475569; font-size: 12px; }
    .meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px; margin-bottom: 24px; overflow: hidden; border: 1px solid #e2e8f0; border-radius: 10px; background: #e2e8f0; }
    .meta div { padding: 12px; background: #f8fafc; }
    .meta b { display: block; margin-top: 4px; font-size: 12px; }
    table { width: 100%; border-collapse: collapse; font-size: 12px; }
    th { padding: 11px 9px; background: #0f172a; color: white; font-size: 9px; letter-spacing: .08em; text-align: left; text-transform: uppercase; }
    td { padding: 13px 9px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .summary { width: 280px; margin: 28px 0 0 auto; }
    .summary-row { display: flex; justify-content: space-between; gap: 20px; padding: 6px 0; color: #475569; font-size: 12px; }
    .summary-row.total { margin-top: 7px; padding-top: 13px; border-top: 2px solid #0f172a; color: #0f172a; font-size: 16px; font-weight: 900; }
    footer { margin-top: 48px; padding-top: 18px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 10px; line-height: 1.6; }
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
          <div class="brand">smart<em>freight</em></div>
          <div class="muted" style="margin-top:6px;font-size:11px">Digitalna logistička platforma</div>
        </div>
        <div><h1>{{ $documentTitle }}</h1><div class="number">#{{ $invoice['number'] }}</div></div>
      </header>

      <section class="parties">
        <div>
          <p class="label">Izdavalac</p><div class="party-name">{{ $sellerName }}</div>
          @if($seller?->address)<p class="party-line">{{ $seller->address }}, {{ $seller->city }}</p>@endif
          @if($seller?->tax_number)<p class="party-line">ID: {{ $seller->tax_number }}</p>@endif
          @if($seller?->email)<p class="party-line">{{ $seller->email }}</p>@endif
        </div>
        <div>
          <p class="label">Kupac</p><div class="party-name">{{ $buyerName }}</div>
          @if($buyer?->email)<p class="party-line">{{ $buyer->email }}</p>@endif
          @if($buyer?->phone)<p class="party-line">{{ $buyer->phone }}</p>@endif
          @if($buyer?->country_code)<p class="party-line">{{ $buyer->country_code }}</p>@endif
        </div>
      </section>

      <section class="meta">
        <div><span class="label">Datum izdavanja</span><b>{{ $date($invoice['issued_at']) }}</b></div>
        <div><span class="label">Rok plaćanja</span><b>{{ $date($invoice['due_at']) }}</b></div>
        <div><span class="label">Broj pošiljke</span><b>{{ $trackingNumber }}</b></div>
      </section>

      <table>
        <thead><tr><th>Opis usluge</th><th class="num">Količina</th><th class="num">Jed. cijena</th><th class="num">Ukupno</th></tr></thead>
        <tbody>
          @foreach($invoice['items'] as $item)
            <tr><td>{{ $item['description'] }}</td><td class="num">{{ number_format($item['quantity'], 2, ',', '.') }}</td><td class="num">{{ $money($item['unit_price']) }}</td><td class="num">{{ $money($item['total']) }}</td></tr>
          @endforeach
        </tbody>
      </table>

      <div class="summary">
        <div class="summary-row"><span>Međuzbir</span><b>{{ $money($invoice['subtotal']) }}</b></div>
        <div class="summary-row"><span>Porez</span><b>{{ $money($invoice['tax']) }}</b></div>
        <div class="summary-row total"><span>Za platiti</span><span>{{ $money($invoice['total']) }}</span></div>
      </div>

      <footer>
        Relacija: {{ $origin?->city ?: '—' }} → {{ $destination?->city ?: '—' }}<br>
        Status pošiljke: {{ $shipmentStatus }} · Uslovi plaćanja: {{ $load->payment_terms ?: '—' }}
      </footer>
    </article>
  </main>
  <script>
    document.getElementById('print-document').addEventListener('click', function () { window.print(); });
  </script>
</body>
</html>
