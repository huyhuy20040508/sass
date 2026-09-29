{{-- CRM → Thẻ thành viên → một hạng — dựng theo trang detail của bản v2: ba ô
     thông tin thẻ, bảng "Chính sách thẻ", rồi "Danh sách khách hàng" đang ở hạng
     (tìm theo mã / tên, Hiển thị 10). Bảng KHÔNG xuống dòng, trừ ô địa chỉ. --}}
@extends('v2::layouts.master')

@section('title', 'Thẻ thành viên')

@php
    $tienMoiDiem = ! empty($conversion['earn_point']) ? (float) $conversion['earn_money'] / (int) $conversion['earn_point'] : 0;
    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $tien = fn ($v) => $v === null ? null : number_format((float) $v);
@endphp

@push('styles')
    <style>
        .tv-sidebar a { display: block; border: 1px solid #dee2e6; border-radius: 6px; padding: 8px; font-weight: 700; color: #212529; text-decoration: none; }
        .tv-ct { padding: 0 12px 12px; }
        .tv-ct table { width: 100%; }
        .tv-ct th, .tv-ct td { white-space: nowrap; vertical-align: middle; padding: 8px; font-size: 14px; }
        .tv-ct th { font-weight: 700; background: #D3DAEB; }
        .tv-ct td.dia-chi { white-space: normal; }
        .tv-ct .tv-nho { font-size: 12px; color: #6c757d; }
    </style>
@endpush

@section('content')
    <div class="row">
        <div class="col-12 col-lg-2 pe-lg-0 fillter-box-wrapper">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">Cấu Hình Giảm Giá</div>
                    <div class="card-body px-2 tv-sidebar">
                        <a href="{{ route('admin.crm.membership.index') }}">Giảm giá theo xếp hạng</a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-10">
            <div class="content_midd tv-ct">
                <div class="content_midd_title d-flex align-items-center gap-2">
                    <a href="{{ route('admin.crm.membership.index') }}" class="text-decoration-none" title="Quay lại"><i class="fa fa-arrow-left fa-lg"></i></a>
                    <h4 class="mb-0 ms-auto">Thẻ thành viên</h4>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-4"><label class="form-label">Tên thẻ</label><input type="text" class="form-control" value="{{ $rank['name'] ?? '' }}" disabled></div>
                    <div class="col-12 col-md-4"><label class="form-label">Tiền tích lũy tối thiểu</label>
                        <input type="text" class="form-control" value="{{ number_format(($rank['point'] ?? 0) * $tienMoiDiem) }}" disabled></div>
                    <div class="col-12 col-md-4"><label class="form-label">Tỉ lệ tích điểm (= 1 điểm)</label>
                        <input type="text" class="form-control" value="{{ number_format($tienMoiDiem) }} đ" disabled></div>
                </div>

                <div class="fw-bold mb-2">Chính sách thẻ:</div>
                <div class="table-responsive mb-4">
                    <table class="table-striped">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 60px;">STT</th>
                                <th class="text-left">Chính sách</th>
                                <th class="text-left">Nội dung chính sách</th>
                                <th class="text-center">Hình thức</th>
                                <th class="text-right">Giá trị</th>
                                <th class="text-right">Giá trị đơn hàng tối thiểu</th>
                                <th class="text-right">Giá trị đơn hàng tối đa</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="text-center">1</td>
                                <td class="text-left">Chính sách về giá</td>
                                <td class="text-left">Giảm theo hạng (tối thiểu {{ $rank['point'] ?? 0 }} điểm)</td>
                                <td class="text-center">{{ ($rank['discount_type'] ?? '') === 'percent' ? '%' : 'đ' }}</td>
                                <td class="text-right">{{ number_format((float) ($rank['discount_value'] ?? 0)) }}</td>
                                <td class="text-right">{{ ! empty($rank['apply_all_order_values']) ? 0 : ($tien($rank['min_order_value'] ?? null) ?? 0) }}</td>
                                <td class="text-right">{{ ! empty($rank['apply_all_order_values']) ? 'vô hạn' : ($tien($rank['max_order_value'] ?? null) ?? 'vô hạn') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="fw-bold mb-2">Danh sách khách hàng</div>
                <form method="GET" action="{{ route('admin.crm.membership.detail', $rank['id'] ?? 0) }}" class="row g-2 mb-2" id="tv-tim">
                    <div class="col-12 col-md-4"><input type="text" name="keyword" value="{{ $keyword }}" class="form-control" placeholder="Tìm theo mã/tên khách hàng"></div>
                    <div class="col-8 col-md-3">
                        <select name="page_size" class="form-control">
                            @foreach (\App\Http\Controllers\MembershipController::PAGE_SIZES as $muc)
                                <option value="{{ $muc }}" {{ $pageSize == $muc ? 'selected' : '' }}>{{ __('message.display', ['name' => $muc]) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-4 col-md-2"><button type="submit" class="bt btn_green">Tìm kiếm</button></div>
                </form>
                <div class="list">
                    <div class="table-responsive">
                        <table class="table-striped">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width: 60px;">STT</th>
                                    <th class="text-left">Mã khách hàng</th>
                                    <th class="text-left">Tên khách hàng</th>
                                    <th class="text-left">Địa chỉ / Số điện thoại</th>
                                    <th class="text-right">Tổng số điểm</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($members as $i => $m)
                                    <tr>
                                        <td class="text-center">{{ $stt + $i + 1 }}</td>
                                        <td class="text-left">{{ $m['customer_code'] ?: 'cus-'.str_pad((string) $m['id'], 5, '0', STR_PAD_LEFT) }}</td>
                                        <td class="text-left">{{ $m['full_name'] }}</td>
                                        <td class="text-left dia-chi">
                                            @if (! empty($m['address']))<div>{{ $m['address'] }}</div>@endif
                                            @if (! empty($m['phone']))<div class="tv-nho">({{ $m['phone'] }})</div>@endif
                                        </td>
                                        <td class="text-right">{{ $m['total_points'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center py-3">Chưa có khách hàng nào ở hạng này.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="form_pagi">@include('v2::partials.pagination', ['meta' => $meta])</div>
                </div>
            </div>
        </div>
    </div>
@endsection
