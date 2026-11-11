{{-- E-ticket / itinerary receipt (pss-itinerary-ticket-mockup.html), laid out with tables for DomPDF --}}
<!doctype html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <title>{{ $t('title', ['pnr' => $pnr]) }}</title>
    <style>
        @page { margin: 22px 24px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { margin: 0; color: #172036; font-size: 9px; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        .muted { color: #667085; }
        .ticket { border: 1px solid #e7eaf0; }
        .head { background: {{ $airline['brand_color'] }}; color: #fff; }
        .head td { padding: 16px 22px 10px; }
        .airline { font-size: 13px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; }
        .carrier { text-align: right; color: #d8e1ff; font-size: 7.5px; }
        .carrier b { color: #fff; font-size: 8px; letter-spacing: .6px; }
        .trip td { padding: 7px 22px 14px; border-top: 1px solid #5b6cc4; }
        .trip-label { color: #9ffff1; font-size: 7px; font-weight: bold; letter-spacing: .8px; }
        .trip-route { font-family: 'DejaVu Sans Mono', monospace; font-size: 12px; font-weight: bold; padding: 0 8px; }
        .trip-route span { color: #53dfcb; }
        .trip-date { color: #d8e1ff; font-size: 7.5px; font-weight: bold; }
        .details td { padding: 11px 16px; border-right: 1px solid #e7eaf0; border-bottom: 1px solid #e7eaf0; }
        .details td:last-child { border-right: 0; }
        .label { display: block; text-transform: uppercase; font-size: 7px; color: #667085; letter-spacing: .7px; font-weight: bold; margin-bottom: 3px; }
        .value { font-size: 9.5px; font-weight: bold; }
        .reference { font-family: 'DejaVu Sans Mono', monospace; color: #273aa7; font-size: 10px; }
        .journey { padding: 16px 18px 12px; border-right: 1px solid #e7eaf0; }
        .sidebar { padding: 16px 16px; background: #fbfcff; width: 32%; }
        h2 { margin: 0; font-size: 11px; }
        .day { color: #667085; font-size: 8px; font-weight: bold; text-align: right; }
        .heading td { padding-bottom: 8px; }
        .heading.next td { padding-top: 14px; border-top: 1px solid #e7eaf0; }
        .card { border: 1px solid #e7eaf0; border-radius: 8px; margin-bottom: 10px; }
        .card td { padding: 10px 12px; }
        .meta { font-size: 8px; font-weight: bold; color: #667085; }
        .meta b { color: #273aa7; }
        .time { font-size: 16px; font-weight: bold; }
        .time sup { font-size: 7px; color: #ff7358; }
        .place { color: #667085; font-size: 7.5px; font-weight: bold; }
        .duration { text-align: center; color: #667085; font-size: 7.5px; font-family: 'DejaVu Sans Mono', monospace; padding-top: 6px; }
        .line { border-top: 1px solid #d7dce7; margin: 3px 14px 0; }
        .aircraft td { border-top: 1px solid #e7eaf0; color: #667085; font-size: 7.5px; font-weight: bold; padding-top: 7px; }
        .aircraft b { color: #172036; }
        .notice { background: #fff7eb; color: #945f1b; font-size: 8px; padding: 8px 10px; border-radius: 6px; }
        .notice b { color: #774500; }
        .status { color: #187968; font-size: 9px; font-weight: bold; margin-bottom: 10px; }
        .status.unpaid { color: #b54708; }
        .block { border-top: 1px solid #e7eaf0; padding: 10px 0; }
        .block h3 { margin: 0 0 7px; font-size: 9px; }
        .avatar { width: 22px; height: 22px; border-radius: 11px; background: #e8edff; color: #273aa7; font-size: 8px; font-weight: bold; text-align: center; line-height: 22px; }
        .person td { padding-bottom: 6px; }
        .person b { font-size: 8.5px; }
        .person .type { color: #273aa7; font-weight: bold; font-size: 7.5px; }
        .facts td { font-size: 8px; color: #667085; padding: 2px 0; }
        .facts td.v { text-align: right; color: #172036; font-weight: bold; }
        .fare { border-top: 1px solid #e7eaf0; background: #fcfdff; padding: 16px 18px 18px; }
        .total { text-align: right; }
        .total span { display: block; color: #667085; font-size: 7.5px; font-weight: bold; }
        .total b { font-size: 14px; }
        .fare-table { border: 1px solid #e7eaf0; margin-top: 10px; font-size: 8.5px; }
        .fare-table th { background: #f3f6fc; color: #667085; text-transform: uppercase; letter-spacing: .6px; font-size: 7px; text-align: left; padding: 7px 9px; }
        .fare-table td { padding: 8px 9px; border-top: 1px solid #e7eaf0; }
        .fare-table .num { text-align: right; white-space: nowrap; }
        .fare-table tfoot td { font-weight: bold; }
        .fare-table tfoot tr.grand td { color: #273aa7; font-size: 9.5px; }
        .conditions { border-top: 1px solid #e7eaf0; padding: 16px 18px; }
        .terms { border: 1px solid #e7eaf0; border-radius: 8px; }
        .terms h3 { margin: 0; padding: 8px 10px; font-size: 9px; border-bottom: 1px solid #e7eaf0; }
        .terms-body { padding: 9px 10px; color: #667085; font-size: 7.5px; }
        .terms-body ul { margin: 4px 0 0 12px; padding: 0; }
        .foot { border-top: 1px dashed #ccd2df; }
        .foot td { padding: 11px 18px; color: #667085; font-size: 7.5px; }
        .foot b { color: #172036; }
    </style>
</head>
<body>
<div class="ticket">
    <table class="head">
        <tr>
            <td>
                @if ($airline['logo_path'] && is_file($airline['logo_path']))
                    <img src="{{ $airline['logo_path'] }}" style="height: 26px; margin-bottom: 4px;" alt=""><br>
                @endif
                <span class="airline">{{ $airline['name'] }}</span>
            </td>
            <td class="carrier">
                <b>{{ $t('document_type') }}</b><br>
                {{ collect([$airline['name'], $airline['address']])->filter()->implode(' · ') }}<br>
                {{ collect([$airline['phone'], $airline['email']])->filter()->implode(' · ') }}
            </td>
        </tr>
    </table>
    <table class="head trip">
        <tr>
            @foreach ($legs as $leg)
                <td style="width: {{ 100 / count($legs) }}%;">
                    <span class="trip-label">{{ $leg['short'] }}</span>
                    <span class="trip-route">{{ $leg['from']['code'] }} <span>&rarr;</span> {{ $leg['to']['code'] }}</span>
                    <span class="trip-date">{{ $leg['date_short'] }}</span>
                </td>
            @endforeach
        </tr>
    </table>

    <table class="details">
        <tr>
            <td style="width: 22%;"><span class="label">{{ $t('reference') }}</span><span class="value reference">{{ $pnr }}</span></td>
            <td style="width: 30%;"><span class="label">{{ $t('tickets') }}</span><span class="value reference">{{ $ticket_numbers }}</span></td>
            <td @if ($with_issued) style="width: 23%;" @endif><span class="label">{{ $t('status') }}</span><span class="value">{{ $status }}</span></td>
            @if ($with_issued)
                <td><span class="label">{{ $t('issued') }}</span><span class="value">{{ $issued_at }}</span></td>
            @endif
        </tr>
    </table>

    <table>
        <tr>
            <td class="journey">
                @foreach ($legs as $leg)
                    <table class="heading {{ $loop->first ? '' : 'next' }}">
                        <tr><td><h2>{{ $leg['title'] }}</h2></td><td class="day">{{ $leg['date_long'] }}</td></tr>
                    </table>
                    <table class="card">
                        <tr>
                            <td>
                                <table>
                                    <tr>
                                        <td class="meta"><b>{{ $leg['flight_number'] }}</b> · {{ $leg['cabin'] }}</td>
                                        <td class="meta" style="text-align: right;">{{ $leg['operated'] }}</td>
                                    </tr>
                                </table>
                                <table style="margin-top: 8px;">
                                    <tr>
                                        <td style="width: 38%;"><div class="time">{{ $leg['departure_time'] }}</div><div class="place">{{ $leg['from']['place'] }}</div></td>
                                        <td class="duration" style="width: 24%;">{{ $leg['duration'] }}<div class="line"></div></td>
                                        <td style="width: 38%; text-align: right;"><div class="time">{{ $leg['arrival_time'] }}@if ($leg['next_day'])<sup> {{ $leg['next_day'] }}</sup>@endif</div><div class="place">{{ $leg['to']['place'] }}</div></td>
                                    </tr>
                                </table>
                                <table class="aircraft" style="margin-top: 8px;">
                                    <tr>
                                        <td><b>{{ $t('seat') }}</b> {{ $leg['seats'] }}</td>
                                        <td><b>{{ $t('cabin') }}</b> {{ $leg['cabin'] }}</td>
                                        <td style="text-align: right;">@if ($leg['aircraft'])<b>{{ $t('aircraft') }}</b> {{ $leg['aircraft'] }}@endif</td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                @endforeach
                <div class="notice"><b>{{ $t('checkin_bold', ['hours' => $checkin_hours]) }}</b> {{ $t('checkin_text') }}</div>
            </td>
            <td class="sidebar">
                <div class="status {{ $paid ? '' : 'unpaid' }}">&#9679; {{ $paid ? $t('paid') : $t('unpaid') }}</div>
                <div class="block" style="border-top: 0; padding-top: 0;">
                    <h3>{{ $t('passengers', ['count' => count($travellers)]) }}</h3>
                    <table class="person">
                        @foreach ($travellers as $traveller)
                            <tr>
                                <td style="width: 28px;"><div class="avatar">{{ mb_substr($traveller['name'], 0, 1).mb_substr(\Illuminate\Support\Str::after($traveller['name'], '/'), 0, 1) }}</div></td>
                                <td>
                                    <b>{{ $traveller['name'] }}</b><br>
                                    <span class="type">{{ $traveller['type'] }}</span>
                                    <span class="muted"> · {{ $loop->first ? $t('bag', ['kg' => $legs[0]['baggage_kg']]) : $t('infant_bag') }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
                <div class="block">
                    <h3>{{ $t('essentials') }}</h3>
                    <table class="facts">
                        <tr><td>{{ $t('checkin') }}</td><td class="v">{{ $legs[0]['checkin'] }}</td></tr>
                        <tr><td>{{ $t('gate') }}</td><td class="v">{{ $legs[0]['gate'] ?: $t('gate_later') }}</td></tr>
                    </table>
                </div>
                <div class="block">
                    <h3>{{ $t('baggage_title') }}</h3>
                    <div><b>{{ $t('baggage_bold', ['kg' => $legs[0]['baggage_kg']]) }}</b></div>
                    <div class="muted">{{ $t('baggage_text', ['kg' => $cabin_baggage_kg]) }}</div>
                    @if ($legs[0]['prepaid_baggage_pieces'])
                        <div class="muted">{{ $t('prepaid', ['pieces' => $legs[0]['prepaid_baggage_pieces']]) }}</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    @if ($with_fare)
        <div class="fare">
            <table>
                <tr>
                    <td><h2>{{ $t('fare_title') }}</h2><div class="muted">{{ $t('fare_caption', ['currency' => $payment['currency']]) }}</div></td>
                    <td class="total"><span>{{ $paid ? $t('total_paid') : $t('total_due') }}</span><b>{{ $payment['total'] }}</b></td>
                </tr>
            </table>
            <table class="fare-table">
                <thead>
                    <tr><th>{{ $t('traveller') }}</th><th>{{ $t('fare_basis') }}</th><th class="num">{{ $t('base_fare') }}</th><th class="num">{{ $t('taxes') }}</th><th class="num">{{ $t('amount') }}</th></tr>
                </thead>
                <tbody>
                    @foreach ($travellers as $traveller)
                        <tr>
                            <td><b>{{ $traveller['name'] }}</b> · {{ $traveller['type'] }}</td>
                            <td class="mono">{{ $traveller['fare_basis'] }}</td>
                            <td class="num">{{ $traveller['fare_text'] }}</td>
                            <td class="num">{{ $traveller['tax_text'] }}</td>
                            <td class="num">{{ $traveller['total_text'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td colspan="4">{{ $t('fare_subtotal') }}</td><td class="num">{{ $payment['fare'] }}</td></tr>
                    <tr><td colspan="4">{{ $t('tax_subtotal') }}</td><td class="num">{{ $payment['tax'] }}</td></tr>
                    <tr class="grand"><td colspan="4">{{ $paid ? $t('total_paid') : $t('total_due') }}</td><td class="num">{{ $payment['total'] }}</td></tr>
                </tfoot>
            </table>
        </div>
    @endif

    <div class="conditions">
        <div class="terms">
            <h3>{{ $t('conditions_title') }}</h3>
            <div class="terms-body">
                @if ($airline['conditions_of_carriage'])
                    {!! strip_tags($airline['conditions_of_carriage'], '<p><br><b><strong><ul><ol><li><h3><h4>') !!}
                @else
                    {{ $t('conditions_default') }}
                @endif
            </div>
        </div>
    </div>

    <table class="foot">
        <tr><td><b>{{ $t('important') }}</b> {{ $t('footer') }}</td><td class="mono" style="text-align: right; color: #172036; font-size: 10px; width: 25%;">{{ $pnr }}</td></tr>
    </table>
</div>
</body>
</html>
