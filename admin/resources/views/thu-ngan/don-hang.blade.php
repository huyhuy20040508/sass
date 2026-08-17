@extends('layouts.thu-ngan')

@section('title', \App\Http\Controllers\ThuNganController::TITLE)

@section('content')
    {{--
        Đơn quầy — danh sách CHỈ ĐỌC những lượt bán ra từ chính module này.

        Không có nút đổi trạng thái, không có chọn hàng loạt, không có xuất
        Excel: đơn quầy xong ngay lúc tạo, nên chỗ này chỉ phục vụ đúng hai lý
        do khiến người trực quay lại — khách cầm phiếu tới hỏi, và phiếu in
        hỏng phải in lại. Muốn sửa hay huỷ một đơn thì đó là việc của khu quản
        trị, và cũng là chỗ có quyền làm việc đó.

        Mặc định chỉ hiện đơn HÔM NAY (xem ThuNganController::filters). Gõ mã
        đơn vào ô tìm thì bỏ kẹp ngày — người gõ mã là đang tìm đúng đơn đó.
    --}}
    @php
        $PAY = \App\Http\Controllers\OrderController::PAYMENT_METHODS;
        $so = fn ($n) => number_format((float) $n, 0, ',', '.').'₫';
        $gio = fn ($s) => filled($s) ? \Illuminate\Support\Carbon::parse($s)->format('H:i · d/m') : '—';

        $locHomNay = $filters['from_date'] === $filters['to_date']
            && $filters['from_date'] === \Illuminate\Support\Carbon::now()->format('Y-m-d');

        // Tổng tiền của ĐÚNG những dòng đang hiện. Nói rõ "trên trang này" ở nhãn:
        // một con số tổng đứng cạnh danh sách phân trang rất dễ bị đọc thành doanh
        // thu cả ngày, mà doanh thu cả ngày thì đã có trên phiếu đóng ca.
        $tongTrang = collect($list)->sum(fn ($o) => (float) ($o['total_amount'] ?? 0));
    @endphp

    <div class="tnd">
        <div class="tnd-head">
            <h1 class="tnd-title">{{ \App\Http\Controllers\ThuNganController::TITLE }}</h1>
            <span class="tnd-sub">
                @if($locHomNay)
                    Đơn bán ra hôm nay
                @else
                    {{ $filters['from_date'] !== '' ? 'Từ '.$filters['from_date'] : 'Mọi ngày' }}
                    {{ $filters['to_date'] !== '' ? ' đến '.$filters['to_date'] : '' }}
                @endif
            </span>
        </div>

        @isset($error)
            <p class="tnd-error">{{ $error }}</p>
        @endisset

        <form method="GET" class="tnd-filter">
            <input type="search" name="keyword" value="{{ $filters['keyword'] }}" class="tnd-input"
                   placeholder="Mã đơn, tên hoặc số điện thoại khách" autocomplete="off">
            <input type="date" name="from_date" value="{{ $filters['from_date'] }}" class="tnd-input tnd-input--ngay">
            <input type="date" name="to_date" value="{{ $filters['to_date'] }}" class="tnd-input tnd-input--ngay">
            <button type="submit" class="tnd-btn">Tìm</button>
            @if($filters['keyword'] !== '' || ! $locHomNay)
                <a href="{{ route('thu-ngan.don-hang.index') }}" class="tnd-clear">Về hôm nay</a>
            @endif
        </form>

        <div class="tnd-box">
            @if($list)
                <table class="tnd-table">
                    <thead>
                        <tr>
                            <th>Mã đơn</th>
                            <th>Lúc</th>
                            <th>Khách</th>
                            <th>Thanh toán</th>
                            <th class="num">Tổng tiền</th>
                            <th class="act"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($list as $o)
                            @php $id = (int) ($o['id'] ?? 0); @endphp
                            <tr>
                                <td><b class="tnd-code">{{ $o['order_code'] ?? '—' }}</b></td>
                                <td class="muted">{{ $gio($o['created_at'] ?? null) }}</td>
                                <td>
                                    {{ trim((string) ($o['recipient_name'] ?? '')) !== '' ? $o['recipient_name'] : 'Khách lẻ' }}
                                    @if(trim((string) ($o['recipient_phone'] ?? '')) !== '')
                                        <span class="tnd-phone">{{ $o['recipient_phone'] }}</span>
                                    @endif
                                </td>
                                <td class="muted">{{ $PAY[$o['payment_method'] ?? ''] ?? '—' }}</td>
                                <td class="num"><b>{{ $so($o['total_amount'] ?? 0) }}</b></td>
                                <td class="act">
                                    {{-- Mở tab mới: phiếu tự bật hộp thoại in khi mở, mà người
                                         trực còn phải quay lại đúng danh sách này ngay sau đó. --}}
                                    <a class="tnd-print" target="_blank" rel="noopener"
                                       href="{{ route('thu-ngan.ban-hang.phieu', ['id' => $id]) }}">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6"/><path d="M6 18H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/></svg>
                                        In lại phiếu
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="muted">{{ count($list) }} đơn trên trang này</td>
                            <td class="num"><b>{{ $so($tongTrang) }}</b></td>
                            <td class="act"></td>
                        </tr>
                    </tfoot>
                </table>
            @else
                <p class="tnd-empty">
                    @if($filters['keyword'] !== '')
                        Không tìm thấy đơn quầy nào khớp “{{ $filters['keyword'] }}”.
                    @elseif($locHomNay)
                        Hôm nay chưa bán đơn nào tại quầy.
                    @else
                        Không có đơn quầy nào trong khoảng ngày đã chọn.
                    @endif
                </p>
            @endif
        </div>

        @if(($meta['total_pages'] ?? 1) > 1)
            <div class="pg">
                <span class="pg-info">Trang <b>{{ $meta['page'] }}</b> / {{ $meta['total_pages'] }} · {{ $meta['total'] }} đơn</span>
                <div class="pg-nav">
                    <a class="pg-btn {{ $meta['page'] <= 1 ? 'is-disabled' : '' }}"
                       href="{{ request()->fullUrlWithQuery(['page' => max(1, $meta['page'] - 1)]) }}">‹</a>
                    <a class="pg-btn {{ $meta['page'] >= $meta['total_pages'] ? 'is-disabled' : '' }}"
                       href="{{ request()->fullUrlWithQuery(['page' => $meta['page'] + 1]) }}">›</a>
                </div>
            </div>
        @endif
    </div>

    <style>
        .tnd-head { display: flex; align-items: baseline; gap: 10px; margin-bottom: 14px; }
        .tnd-title { margin: 0; font-size: 20px; font-weight: 600; color: #262626; }
        .tnd-sub { font-size: 13px; color: #8c8c8c; }
        .tnd-error { padding: 10px 14px; border-radius: 6px; background: #fff1f0; color: #cf1322; font-size: 13px; }

        .tnd-filter { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 12px; }
        .tnd-input {
            height: 34px; padding: 0 10px; border: 1px solid #d9d9d9; border-radius: 6px;
            background: #fff; font-family: inherit; font-size: 13px; color: #262626; outline: none;
        }
        .tnd-input:focus { border-color: #1890ff; box-shadow: 0 0 0 3px rgba(24, 144, 255, .15); }
        .tnd-input[type=search] { width: 280px; max-width: 100%; }
        .tnd-input--ngay { width: 150px; }
        .tnd-btn {
            height: 34px; padding: 0 16px; border: 0; border-radius: 6px;
            background: #1890ff; color: #fff; font-family: inherit; font-size: 13px;
            font-weight: 600; cursor: pointer;
        }
        .tnd-btn:hover { background: #0f7ae5; }
        .tnd-clear { font-size: 13px; color: #8c8c8c; text-decoration: none; }
        .tnd-clear:hover { text-decoration: underline; }

        .tnd-box { background: #fff; border: 1px solid #f0f0f0; border-radius: 8px; overflow: hidden; }
        .tnd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .tnd-table th, .tnd-table td { padding: 10px 14px; border-bottom: 1px solid #f5f5f5; text-align: left; }
        .tnd-table th { background: #fafafa; font-weight: 600; color: #8c8c8c; font-size: 12px; }
        .tnd-table td.num, .tnd-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .tnd-table td.act, .tnd-table th.act { text-align: right; width: 1%; white-space: nowrap; }
        .tnd-table tbody tr:hover { background: #f0f8ff; }
        .tnd-table tfoot td { background: #fafafa; border-bottom: 0; }
        .tnd-table .muted { color: #8c8c8c; }
        .tnd-code { font-variant-numeric: tabular-nums; }
        .tnd-phone { color: #8c8c8c; }

        .tnd-print {
            display: inline-flex; align-items: center; gap: 6px;
            height: 28px; padding: 0 10px; border: 1px solid #d9d9d9; border-radius: 6px;
            color: #262626; font-size: 12px; text-decoration: none; background: #fff;
        }
        .tnd-print:hover { border-color: #1890ff; color: #1890ff; }

        .tnd-empty { margin: 0; padding: 40px 16px; text-align: center; color: #bfbfbf; font-size: 13px; }
    </style>
@endsection
