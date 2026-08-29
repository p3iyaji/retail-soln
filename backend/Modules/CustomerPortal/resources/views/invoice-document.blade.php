<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Invoice {{ $order->order_number }}</title>
  <style>
    :root {
      --bg: #F4F6F8; --fg: #12181F; --muted: #5C6B7A; --card: #FFFFFF;
      --border: #D5DCE3; --primary: #0B6E4F; --radius: 8px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh;
      font-family: "IBM Plex Sans", "Segoe UI", sans-serif;
      font-size: 15px; color: var(--fg); background: var(--bg);
    }
    .wrap { max-width: 800px; margin: 24px auto; padding: 0 16px 48px; }
    .banner {
      background: #F0F7F4; border: 1px solid var(--primary);
      border-radius: var(--radius); padding: 12px 16px;
      font-weight: 700; color: var(--primary); letter-spacing: 0.04em;
      text-transform: uppercase; font-size: 13px; margin-bottom: 16px;
    }
    .doc {
      background: var(--card); border: 1px solid var(--border);
      border-radius: 12px; padding: 28px; position: relative; overflow: hidden;
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
    @media print {
      body { background: #fff; }
      .wrap { margin: 0; max-width: none; }
    }
  </style>
</head>
<body>
@php
  $org = $order->organization;
  $customer = $order->customer;
  $fmt = fn ($v) => number_format((float) $v, 2, '.', ',');
@endphp
  <div class="wrap">
    <div class="banner">Portal invoice · non-fiscal / proforma-style</div>
    <div class="doc">
      <div class="doc-h">
        <div>
          <h2>{{ $org?->name ?? 'retail-soln' }}</h2>
          <div style="font-size:13px;color:var(--muted);margin-top:4px;">Online order invoice</div>
        </div>
        <div class="meta">
          <div>Order <span class="num">{{ $order->order_number }}</span></div>
          <div>{{ optional($order->paid_at ?? $order->created_at)->format('Y-m-d H:i') }}</div>
          <div style="margin-top:6px;text-transform:capitalize;">{{ str_replace('_', ' ', $order->payment_status) }}</div>
        </div>
      </div>

      <div class="parties">
        <div>
          <h3>Bill to</h3>
          <div><strong>{{ $customer?->name ?? 'Customer' }}</strong></div>
          @if($customer?->email)<div>{{ $customer->email }}</div>@endif
          @if($order->contact_phone)<div>{{ $order->contact_phone }}</div>@endif
          @if($order->address)
            <div>{{ $order->address->line1 }}</div>
            @if($order->address->line2)<div>{{ $order->address->line2 }}</div>@endif
            <div>
              {{ collect([$order->address->city, $order->address->region, $order->address->postal_code])->filter()->implode(', ') }}
            </div>
          @endif
        </div>
        <div>
          <h3>Fulfilment</h3>
          <div style="text-transform:capitalize;">{{ str_replace('_', ' ', $order->fulfilment_mode) }}</div>
          @if($order->store)
            <div>{{ $order->store->name }}</div>
          @endif
          @if($order->payment_reference)
            <div style="margin-top:8px;">Payment ref: <span class="mono">{{ $order->payment_reference }}</span></div>
          @endif
          @if($order->payment_provider)
            <div>Provider: {{ ucfirst($order->payment_provider) }}</div>
          @endif
        </div>
      </div>

      <table>
        <thead>
          <tr>
            <th>Item</th>
            <th>SKU</th>
            <th class="r">Qty</th>
            <th class="r">Unit</th>
            <th class="r">Tax</th>
            <th class="r">Line</th>
          </tr>
        </thead>
        <tbody>
          @foreach($order->items as $item)
            <tr>
              <td>
                <strong>{{ $item->product_name }}</strong>
                @if($item->variant_name && $item->variant_name !== 'Default')
                  <div style="color:var(--muted);font-size:12px;">{{ $item->variant_name }}</div>
                @endif
              </td>
              <td class="mono">{{ $item->sku }}</td>
              <td class="r mono">{{ $fmt($item->quantity) }}</td>
              <td class="r mono">{{ $fmt($item->unit_gross_price) }}</td>
              <td class="r mono">{{ $fmt($item->line_tax) }}</td>
              <td class="r mono">{{ $fmt($item->line_total) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>

      <div class="totals">
        <div class="row"><span>Subtotal</span><span class="mono">{{ $fmt($order->subtotal) }}</span></div>
        @if(bccomp((string) $order->discount_total, '0', 4) > 0)
          <div class="row"><span>Discount</span><span class="mono">-{{ $fmt($order->discount_total) }}</span></div>
        @endif
        <div class="row"><span>Tax</span><span class="mono">{{ $fmt($order->tax_total) }}</span></div>
        <div class="row"><span>Delivery</span><span class="mono">{{ $fmt($order->delivery_fee) }}</span></div>
        <div class="grand"><span>Total</span><span class="mono">{{ $fmt($order->total) }}</span></div>
      </div>

      <div class="footnote">
        This portal invoice is generated from the online order record and is not a POS fiscal tax invoice.
      </div>
    </div>
  </div>
</body>
</html>
