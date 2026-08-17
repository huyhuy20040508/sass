@extends('layouts.thu-ngan')

@section('title', \App\Http\Controllers\CaLamViecController::TITLE)

@section('content')
    {{--
        Lịch sử ca làm việc.

        Bảng này chỉ có MỘT cột thật sự đáng nhìn: CHÊNH LỆCH. Ca khớp sổ thì
        không có gì để làm; ca lệch mới là thứ phải mở ra xem. Nên chênh lệch
        được tô màu, còn mọi cột khác để xám — người mở trang này đang đi tìm chỗ
        lệch, không đi đọc từng con số.

        Mở và đóng ca KHÔNG làm ở đây mà làm ở màn hình Bán tại quầy: đó là nơi
        người trực đang đứng lúc bắt đầu và lúc kết thúc buổi bán.
    --}}
    @php
        $TITLE = \App\Http\Controllers\CaLamViecController::TITLE;
        $STATUSES = \App\Http\Controllers\CaLamViecController::STATUSES;
        $EMPTY_TEXT = \App\Http\Controllers\CaLamViecController::EMPTY_TEXT;

        $so = fn ($n) => number_format((float) $n, 0, ',', '.').'₫';
        $gio = fn ($s) => filled($s) ? \Illuminate\Support\Carbon::parse($s)->format('H:i d/m/Y') : '—';
    @endphp

    <div class="clv">
        <div class="clv-head">
            <h1 class="clv-title">{{ $TITLE }}</h1>
            <a href="{{ route('thu-ngan.ban-hang.index') }}" class="clv-link">Mở / đóng ca ở màn hình Bán tại quầy →</a>
        </div>

        @isset($error)
            <p class="clv-error">{{ $error }}</p>
        @endisset

        <form method="GET" class="clv-filter">
            <select name="status" class="clv-select" onchange="this.form.submit()">
                <option value="">Mọi trạng thái</option>
                @foreach($STATUSES as $v => $l)
                    <option value="{{ $v }}" {{ $filters['status'] === $v ? 'selected' : '' }}>{{ $l }}</option>
                @endforeach
            </select>
            <input type="date" name="from_date" value="{{ $filters['from_date'] }}" class="clv-select" onchange="this.form.submit()">
            <input type="date" name="to_date" value="{{ $filters['to_date'] }}" class="clv-select" onchange="this.form.submit()">
            @if($filters['status'] !== '' || $filters['from_date'] !== '' || $filters['to_date'] !== '')
                <a href="{{ route('thu-ngan.ca-lam-viec.index') }}" class="clv-clear">Xoá lọc</a>
            @endif
        </form>

        <div class="clv-box">
            @if($list)
                <table class="clv-table">
                    <thead>
                        <tr>
                            <th>Mở ca</th>
                            <th>Đóng ca</th>
                            <th>Người trực</th>
                            <th class="num">Đầu ca</th>
                            <th class="num">Thu</th>
                            <th class="num">Chi</th>
                            <th class="num">Theo sổ</th>
                            <th class="num">Đếm được</th>
                            <th class="num">Chênh lệch</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($list as $c)
                            @php $lech = $c['difference']; @endphp
                            <tr onclick="window.location='{{ route('thu-ngan.ca-lam-viec.show', $c['id']) }}'">
                                <td>{{ $gio($c['opened_at'] ?? null) }}</td>
                                <td>
                                    @if(empty($c['closed_at']))
                                        <span class="clv-badge">Đang mở</span>
                                    @else
                                        {{ $gio($c['closed_at']) }}
                                    @endif
                                </td>
                                <td>{{ $c['opened_by_name'] ?? '—' }}</td>
                                <td class="num muted">{{ $so($c['opening_cash'] ?? 0) }}</td>
                                <td class="num">{{ $so($c['tong_thu'] ?? 0) }}</td>
                                <td class="num muted">{{ $so($c['tong_chi'] ?? 0) }}</td>
                                <td class="num">{{ $c['expected_cash'] === null ? '—' : $so($c['expected_cash']) }}</td>
                                <td class="num">{{ $c['counted_cash'] === null ? '—' : $so($c['counted_cash']) }}</td>
                                <td class="num">
                                    @if($lech === null)
                                        —
                                    @elseif((float) $lech == 0)
                                        <span class="clv-ok">Khớp</span>
                                    @else
                                        {{-- Con số duy nhất đáng tô màu trên cả bảng. --}}
                                        <b class="clv-lech">{{ (float) $lech > 0 ? '+' : '' }}{{ $so($lech) }}</b>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="clv-empty">{{ $EMPTY_TEXT }}</p>
            @endif
        </div>

        @if(($meta['total_pages'] ?? 1) > 1)
            <div class="pg">
                <span class="pg-info">Trang <b>{{ $meta['page'] }}</b> / {{ $meta['total_pages'] }} · {{ $meta['total'] }} ca</span>
                <div class="pg-nav">
                    @if($meta['page'] > 1)
                        <a class="pg-btn" href="{{ request()->fullUrlWithQuery(['page' => $meta['page'] - 1]) }}">‹</a>
                    @endif
                    @if($meta['page'] < $meta['total_pages'])
                        <a class="pg-btn" href="{{ request()->fullUrlWithQuery(['page' => $meta['page'] + 1]) }}">›</a>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <style>
        .clv-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
        .clv-title { margin: 0; font-size: 20px; font-weight: 600; color: #262626; }
        .clv-link { font-size: 13px; color: #1890ff; text-decoration: none; }
        .clv-link:hover { text-decoration: underline; }
        .clv-error { padding: 10px 14px; border-radius: 6px; background: #fff1f0; color: #cf1322; font-size: 13px; }
        .clv-filter { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }

        .clv-select {
            height: 34px; padding: 0 10px; border: 1px solid #d9d9d9; border-radius: 6px;
            background: #fff; font-size: 13px; color: #262626;
        }

        .clv-clear { align-self: center; font-size: 13px; color: #8c8c8c; text-decoration: none; }
        .clv-box { background: #fff; border: 1px solid #f0f0f0; border-radius: 8px; overflow: hidden; }
        .clv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .clv-table th, .clv-table td { padding: 10px 14px; border-bottom: 1px solid #f5f5f5; text-align: left; }
        .clv-table th { background: #fafafa; font-weight: 600; color: #8c8c8c; font-size: 12px; }
        .clv-table td.num, .clv-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .clv-table tbody tr { cursor: pointer; }
        .clv-table tbody tr:hover { background: #f0f8ff; }
        .clv-table .muted { color: #8c8c8c; }
        .clv-ok { color: #389e0d; }
        .clv-lech { color: #cf1322; }

        .clv-badge {
            padding: 2px 8px; border-radius: 10px; background: #e6f7ff;
            color: #1890ff; font-size: 12px; font-weight: 500;
        }

        .clv-empty { margin: 0; padding: 40px 16px; text-align: center; color: #bfbfbf; font-size: 13px; }
    </style>
@endsection
