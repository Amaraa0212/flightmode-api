<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>E-ticket {{ $pnr }}</title>
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 10px; color: #1f2933; margin: 0; }
        .header { border-bottom: 3px solid {{ $airline['brand_color'] ?? '#1e1e2d' }}; padding-bottom: 8px; margin-bottom: 12px; }
        .header td { vertical-align: middle; }
        .title { font-size: 18px; font-weight: bold; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        .box { border: 1px solid #d1d5db; margin-bottom: 10px; }
        .box th { background: #f3f4f6; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; }
        .box td { padding: 5px 6px; border-top: 1px solid #e5e7eb; }
        .big { font-size: 13px; font-weight: bold; }
        .conditions { font-size: 8px; color: #4b5563; margin-top: 14px; }
    </style>
</head>
<body>
<table class="header">
    <tr>
        <td>
            @if ($airline['logo_path'] && is_file($airline['logo_path']))
                <img src="{{ $airline['logo_path'] }}" style="height: 40px;" alt="">
            @endif
            <div class="title">{{ $airline['name'] }}</div>
            <div class="muted">Electronic ticket / Itinerary receipt</div>
        </td>
        <td style="text-align: right;">
            <div class="muted">Booking reference (PNR)</div>
            <div class="big">{{ $pnr }}</div>
            <div class="muted">Issued {{ $issued_at }}@if ($agency) · {{ $agency }}@endif</div>
        </td>
    </tr>
</table>

<table class="box">
    <tr><th>Passenger</th><th>Type</th><th>Ticket number</th></tr>
    <tr><td class="big">{{ $passenger['name'] }}</td><td>{{ $passenger['type'] }}</td><td>{{ $passenger['ticket_number'] }}</td></tr>
    @if ($infant)
        <tr><td>{{ $infant['name'] }}</td><td>INF</td><td>{{ $infant['ticket_number'] }}</td></tr>
    @endif
</table>

@foreach ($legs as $leg)
    <table class="box">
        <tr><th>Flight</th><th>From</th><th>To</th><th>Class</th><th>Status</th></tr>
        <tr>
            <td class="big">{{ $leg['flight_number'] }}</td>
            <td><strong>{{ $leg['from']['code'] }}</strong> {{ $leg['from']['city'] }}<br><span class="muted">{{ $leg['from']['airport'] }}</span><br>{{ $leg['departure'] }}@if ($leg['terminal']) · Terminal {{ $leg['terminal'] }}@endif</td>
            <td><strong>{{ $leg['to']['code'] }}</strong> {{ $leg['to']['city'] }}<br><span class="muted">{{ $leg['to']['airport'] }}</span><br>{{ $leg['arrival'] }}</td>
            <td>{{ $leg['cabin'] }}@if ($leg['fare_class']) ({{ $leg['fare_class'] }})@endif</td>
            <td>{{ $leg['status'] }}</td>
        </tr>
        <tr>
            <td colspan="5" class="muted">
                Checked baggage {{ $leg['baggage_kg'] }} kg · Cabin baggage {{ $leg['cabin_baggage_kg'] }} kg
                @if ($leg['prepaid_baggage_pieces']) · Prepaid extra bags {{ $leg['prepaid_baggage_pieces'] }}@endif
                · Duration {{ $leg['duration'] }}@if ($leg['aircraft']) · Aircraft {{ $leg['aircraft'] }}@endif
                @if ($leg['ssr']) · SSR {{ implode(', ', $leg['ssr']) }}@endif
            </td>
        </tr>
    </table>
@endforeach

<table class="box">
    <tr><th>Fare</th><th>Taxes & fees</th><th>Total</th></tr>
    <tr>
        <td>{{ $payment['fare'] }} {{ $payment['currency'] }}</td>
        <td>{{ $payment['tax'] }} {{ $payment['currency'] }}</td>
        <td class="big">{{ $payment['total'] }} {{ $payment['currency'] }}</td>
    </tr>
</table>

@if ($airline['conditions_of_carriage'])
    <div class="conditions">{!! strip_tags($airline['conditions_of_carriage'], '<p><br><b><strong><ul><ol><li><h3><h4>') !!}</div>
@endif
</body>
</html>
