<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Proforma {{ $proforma->number ?? 'Draft' }}</title>
  <style>
    :root {
      --bg: #F4F6F8; --fg: #12181F; --muted: #5C6B7A; --card: #FFFFFF;
      --border: #D5DCE3; --primary: #0B6E4F; --accent: #C45C26; --radius: 8px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh;
      font-family: "IBM Plex Sans", "Segoe UI", sans-serif;
      font-size: 15px; color: var(--fg); background: var(--bg);
    }
    .wrap { max-width: 800px; margin: 24px auto; padding: 0 16px 48px; }
    .banner {
      background: #FFF8F3; border: 2px solid var(--accent);
      border-radius: var(--radius); padding: 12px 16px;
      font-weight: 700; color: var(--accent); letter-spacing: 0.04em;
      text-transform: uppercase; font-size: 13px; margin-bottom: 16px;
    }
    .doc {
      background: var(--card); border: 1px solid var(--border);
      border-radius: 12px; padding: 28px; position: relative; overflow: hidden;
    }
    .watermark {
      position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
      pointer-events: none; font-family: Outfit, "Segoe UI", sans-serif;
      font-size: 72px; font-weight: 700; color: rgba(196, 92, 38, 0.08);
      transform: rotate(-18deg); letter-spacing: 0.08em;
    }
    .doc-h { display: flex; justify-content: space-between; margin-bottom: 24px; position: relative; }
    .doc-h h2 { margin: 0; font-size: 22px; font-weight: 600; }
    .meta { text-align: right; font-size: 13px; color: var(--muted); }
    .meta .num { font-family: "IBM Plex Mono", monospace; color: var(--fg); font-weight: 600; }
    .parties {
      display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
      margin-bottom: 24px; font-size: 13px; position: relative;
    }
    .parties h3 { margin: 0 0 6px; font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; position: relative; }
    th {
      text-align: left; padding: 8px; border-bottom: 2px solid var(--fg);
      font-weight: 600; font-size: 12px;
    }
    th.r, td.r { text-align: right; }
    td { padding: 10px 8px; border-bottom: 1px solid var(--border); }
    .mono { font-family: "IBM Plex Mono", monospace; }
    .totals {
      margin-top: 16px; margin-left: auto; width: 260px;
      font-size: 13px; position: relative;
    }
    .totals .row { display: flex; justify-content: space-between; margin-bottom: 6px; color: var(--muted); }
    .totals .grand {
      display: flex; justify-content: space-between; margin-top: 10px;
      padding-top: 10px; border-top: 2px solid var(--fg);
      font-size: 18px; font-weight: 700; color: var(--fg);
    }
    .footnote {
      margin-top: 28px; font-size: 12px; color: var(--muted);
      border-top: 1px dashed var(--border); padding-top: 12px; position: relative;
    }
    .status {
      display: inline-block; margin-top: 4px; padding: 2px 8px;
      background: #F0F7F4; color: var(--primary); border-radius: 4px;
      font-size: 11px; font-weight: 600; text-transform: capitalize;
    }
    @media print {
      body { background: #fff; }
      .wrap { margin: 0; max-width: none; }
    }
  </style>
</head>
<body>
@php
  $org = $proforma->store?->organization;
  $store = $proforma->store;
  $customer = $proforma->customer;
  $currency = $store?->currency ?? $org?->default_currency ?? 'NGN';
  $formatMoney = static function (?string $amount) use ($currency): string {
      if ($amount === null) {
          return $currency.' 0.00';
      }
      return $currency.' '.number_format((float) $amount, 2, '.', ',');
  };
  $storeAddress = collect([
      $store?->address_line1,
      $store?->address_line2,
      $store?->city,
      $store?->state,
      $store?->postal_code,
      $store?->country,
  ])->filter()->implode(', ');
@endphp
  <div class="wrap">
    <div class="banner">Proforma — Not a tax invoice</div>
    <div class="doc">
      <div class="watermark">PROFORMA</div>
      <div class="doc-h">
        <div>
          <h2>Proforma Invoice</h2>
          <span class="status">{{ $proforma->status->value }}</span>
        </div>
        <div class="meta">
          <div class="num">{{ $proforma->number ?? 'Draft' }}</div>
          <div>{{ $proforma->issued_at?->format('d M Y') ?? $proforma->created_at?->format('d M Y') }}</div>
          @if ($proforma->valid_until)
            <div>Valid until {{ $proforma->valid_until->format('d M Y') }}</div>
          @endif
        </div>
      </div>
      <div class="parties">
        <div>
          <h3>From</h3>
          <strong>{{ $org?->name ?? 'Organization' }} · {{ $store?->name }}</strong><br />
          @if ($storeAddress !== '')
            {{ $storeAddress }}<br />
          @endif
        </div>
        <div>
          <h3>Bill to</h3>
          <strong>{{ $customer?->name }}</strong><br />
          {{ $customer?->account_number }}@if ($customer?->email) · {{ $customer->email }}@endif
        </div>
      </div>
      <table>
        <thead>
          <tr>
            <th>SKU</th>
            <th>Description</th>
            <th class="r">Qty</th>
            <th class="r">Unit</th>
            <th class="r">Amount</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($proforma->items as $item)
            <tr>
              <td class="mono">{{ $item->productVariant?->sku }}</td>
              <td>{{ $item->productVariant?->name }}</td>
              <td class="r mono">{{ rtrim(rtrim($item->qty, '0'), '.') }}</td>
              <td class="r mono">{{ $formatMoney((string) $item->unit_price) }}</td>
              <td class="r mono">{{ $formatMoney((string) $item->line_total) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <div class="totals">
        <div class="row"><span>Subtotal</span><span class="mono">{{ $formatMoney((string) $proforma->subtotal) }}</span></div>
        @if (bccomp((string) $proforma->discount_total, '0', 4) > 0)
          <div class="row"><span>Discount</span><span class="mono">-{{ $formatMoney((string) $proforma->discount_total) }}</span></div>
        @endif
        <div class="row"><span>Tax</span><span class="mono">{{ $formatMoney((string) $proforma->tax_total) }}</span></div>
        <div class="grand"><span>Total</span><span class="mono">{{ $formatMoney((string) $proforma->total) }}</span></div>
      </div>
      @if ($proforma->notes)
        <p class="footnote"><strong>Notes:</strong> {{ $proforma->notes }}</p>
      @endif
      <p class="footnote">
        This document is a proforma invoice for quotation purposes only. It does not create accounts receivable,
        transfer stock, or constitute a tax invoice until converted to a sale or issued tax invoice.
      </p>
    </div>
  </div>
</body>
</html>
