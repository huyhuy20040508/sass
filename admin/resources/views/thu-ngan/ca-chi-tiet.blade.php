@extends('layouts.thu-ngan')

@section('title', 'Ca ngày '.\Illuminate\Support\Carbon::parse($ca['opened_at'] ?? now())->format('d/m/Y'))

@section('content')
    {{--
        Chi tiết một ca — màn hình dùng để TRUY một khoản chênh.

        Nên nó chỉ có hai khối: con số đối chiếu ở trên, và bên dưới là từng lần
        tiền vào/ra theo ĐÚNG THỨ TỰ đã xảy ra. Người đi tìm chỗ lệch đọc xuôi
        danh sách đó tới khi gặp dòng lạ; sắp xếp lại theo số tiền hay gộp nhóm
        đều làm hỏng cách đọc ấy.
    --}}
    @php
        $DIRECTIONS = \App\Http\Controllers\CaLamViecController::DIRECTIONS;
        $SOURCES = \App\Http\Controllers\CaLamViecController::SOURCES;

        $so = fn ($n) => number_format((float) $n, 0, ',', '.').'₫';
        $gio = fn ($s) => filled($s) ? \Illuminate\Support\Carbon::parse($s)->format('H:i d/m/Y') : '—';
        $lech = $ca['difference'] ?? null;
    @endphp

    <div class="clv">
        <div class="clv-head">
            <h1 class="clv-title">
                Ca {{ $gio($ca['opened_at'] ?? null) }}
                @if(empty($ca['closed_at']))
                    <span class="clv-badge">Đang mở</span>
                @endif
            </h1>
            <a href="{{ route('thu-ngan.ca-lam-viec.index') }}" class="clv-link">← Về danh sách ca</a>
        </div>

        <div class="clv-grid">
            <div class="clv-box clv-pad">
                <h2 class="clv-sub">Đối chiếu két</h2>
                <dl class="clv-dl">
                    <div><dt>Tiền đầu ca</dt><dd>{{ $so($ca['opening_cash'] ?? 0) }}</dd></div>
                    <div><dt>Thu tiền mặt <em>({{ $ca['so_don_tien_mat'] ?? 0 }} lượt bán)</em></dt><dd>{{ $so($ca['tong_thu'] ?? 0) }}</dd></div>
                    <div><dt>Chi tiền mặt</dt><dd>−{{ $so($ca['tong_chi'] ?? 0) }}</dd></div>
                    <div class="clv-dl-strong"><dt>Theo sổ, két phải có</dt><dd>{{ $ca['expected_cash'] === null ? '—' : $so($ca['expected_cash']) }}</dd></div>
                    <div><dt>Đếm được</dt><dd>{{ $ca['counted_cash'] === null ? '—' : $so($ca['counted_cash']) }}</dd></div>
                </dl>

                @if($lech !== null)
                    <div class="clv-lech-box {{ (float) $lech == 0 ? 'is-ok' : '' }}">
                        @if((float) $lech == 0)
                            Khớp sổ.
                        @else
                            {{ (float) $lech > 0 ? 'Thừa két' : 'Thiếu két' }}
                            <b>{{ $so(abs((float) $lech)) }}</b>
                        @endif
                    </div>
                @endif
            </div>

            <div class="clv-box clv-pad">
                <h2 class="clv-sub">Người trực</h2>
                <dl class="clv-dl">
                    <div><dt>Mở ca</dt><dd>{{ $ca['opened_by_name'] ?? '—' }}</dd></div>
                    <div><dt>Lúc</dt><dd>{{ $gio($ca['opened_at'] ?? null) }}</dd></div>
                    <div><dt>Đóng ca</dt><dd>{{ $ca['closed_by_name'] ?? '—' }}</dd></div>
                    <div><dt>Lúc</dt><dd>{{ $gio($ca['closed_at'] ?? null) }}</dd></div>
                </dl>
                @if(filled($ca['note'] ?? null))
                    <p class="clv-note">{{ $ca['note'] }}</p>
                @endif
            </div>
        </div>

        <div class="clv-box">
            <h2 class="clv-sub clv-pad-x">Sổ quỹ tiền mặt <em>— theo đúng thứ tự đã xảy ra</em></h2>
            @if($soQuy)
                <table class="clv-table">
                    <thead>
                        <tr>
                            <th>Lúc</th>
                            <th>Nguồn</th>
                            <th>Lý do</th>
                            <th class="num">Thu</th>
                            <th class="num">Chi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($soQuy as $d)
                            <tr>
                                <td>{{ $gio($d['created_at'] ?? null) }}</td>
                                <td class="muted">{{ $SOURCES[$d['reference_type'] ?? ''] ?? '—' }}</td>
                                <td>{{ $d['reason'] ?? '' }}</td>
                                <td class="num">{{ ($d['direction'] ?? '') === 'in' ? $so($d['amount']) : '' }}</td>
                                <td class="num muted">{{ ($d['direction'] ?? '') === 'out' ? $so($d['amount']) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="clv-empty">Ca này chưa có lượt thu chi tiền mặt nào.</p>
            @endif
        </div>
    </div>

    <style>
        .clv-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
        .clv-title { margin: 0; font-size: 20px; font-weight: 600; color: #262626; }
        .clv-link { font-size: 13px; color: #1890ff; text-decoration: none; }
        .clv-link:hover { text-decoration: underline; }
        .clv-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; margin-bottom: 12px; }
        .clv-box { background: #fff; border: 1px solid #f0f0f0; border-radius: 8px; overflow: hidden; }
        .clv-pad { padding: 16px; }
        .clv-pad-x { padding: 14px 16px 0; }
        .clv-sub { margin: 0 0 10px; font-size: 14px; font-weight: 600; color: #262626; }
        .clv-sub em { font-style: normal; font-weight: 400; font-size: 12px; color: #bfbfbf; }
        .clv-dl { margin: 0; font-size: 13px; }
        .clv-dl div { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; color: #595959; }
        .clv-dl dt em { font-style: normal; color: #bfbfbf; }
        .clv-dl dd { margin: 0; font-weight: 600; color: #262626; font-variant-numeric: tabular-nums; }
        .clv-dl-strong { border-top: 1px solid #f0f0f0; margin-top: 4px; padding-top: 8px !important; }
        .clv-dl-strong dt, .clv-dl-strong dd { font-weight: 700; color: #262626; }

        .clv-lech-box {
            margin-top: 12px; padding: 10px 12px; border-radius: 6px;
            background: #fff1f0; color: #cf1322; font-size: 13px;
        }

        .clv-lech-box.is-ok { background: #f6ffed; color: #389e0d; }
        .clv-lech-box b { font-size: 17px; }
        .clv-note { margin: 12px 0 0; font-size: 13px; color: #8c8c8c; }
        .clv-badge { padding: 2px 8px; border-radius: 10px; background: #e6f7ff; color: #1890ff; font-size: 12px; font-weight: 500; }
        .clv-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
        .clv-table th, .clv-table td { padding: 9px 16px; border-bottom: 1px solid #f5f5f5; text-align: left; }
        .clv-table th { background: #fafafa; font-weight: 600; color: #8c8c8c; font-size: 12px; }
        .clv-table td.num, .clv-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .clv-table .muted { color: #8c8c8c; }
        .clv-empty { margin: 0; padding: 30px 16px; text-align: center; color: #bfbfbf; font-size: 13px; }
    </style>
@endsection
