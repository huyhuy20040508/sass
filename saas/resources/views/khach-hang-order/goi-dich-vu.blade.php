{{--
    Bảng giá của phần mềm Sellio Order.

    MỖI DÒNG LÀ MỘT MỨC GIÁ, không phải một gói: gói "Cửa hàng" bán theo tháng
    và theo năm là hai dòng, hai ID khác nhau, và tính năng của chúng khai riêng.
    Vì vậy cột "Mã" lặp lại là chuyện bình thường, không phải dữ liệu trùng.

    Giá null in ra "Liên hệ" — đó là gói chưa có giá công khai, KHÁC hẳn giá 0
    (miễn phí). Gộp hai thứ đó lại là hứa sai với khách.
--}}
@extends('layouts.app')
@section('title', 'Các gói dịch vụ')

@section('content')
    <div class="page-head">
        <span class="eyebrow">QLTK khách hàng order</span>
        <h2>Các gói dịch vụ</h2>
        <p>Bảng giá đang áp cho phần mềm Sellio Order. Sửa ở đây không đụng tới khách đang dùng: hợp đồng đã ký chép hạn mức ra lúc ký và sống độc lập với bảng giá.</p>
    </div>

    @if ($loi)
        <div class="notice">{{ $loi }}</div>
    @endif

    <section class="panel">
        <div class="toolbar">
            @php $dang_ban = collect($plans)->where('status', 'active')->count(); @endphp
            <span><strong>{{ count($plans) }}</strong> mức giá</span>
            <span class="muted">·</span>
            <span>{{ $dang_ban }} đang bán</span>
            <span class="spacer"></span>
            <a href="{{ route('platform.khach-hang-order.tinh-nang-goi') }}" class="btn btn-plum">Sửa tính năng gói</a>
        </div>

        @if (count($plans))
            <div class="tbl">
                <table>
                    <thead>
                    <tr>
                        <th>Gói</th>
                        <th>Mã</th>
                        <th>Chu kỳ</th>
                        <th class="num">Giá</th>
                        <th class="num">Dùng thử</th>
                        <th>Trạng thái</th>
                        @if ($ghiDuoc) <th></th> @endif
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($plans as $p)
                        @php
                            $gia = data_get($p, 'price');
                            $ngay_thu = (int) data_get($p, 'trial_days');
                        @endphp
                        <tr>
                            <td>
                                <div>{{ data_get($p, 'name') ?: '—' }}</div>
                                @if ($tagline = data_get($p, 'tagline'))
                                    <div class="muted">{{ $tagline }}</div>
                                @endif
                            </td>
                            <td class="mono tight">{{ data_get($p, 'code') }}</td>
                            <td class="tight">{{ data_get($p, 'billing_cycle') === 'nam' ? 'Năm' : 'Tháng' }}</td>
                            <td class="num">
                                {{-- null (chưa công bố giá) khác 0 (miễn phí) — xem chú thích đầu tệp. --}}
                                {{ $gia === null ? 'Liên hệ' : number_format((float) $gia, 0, ',', '.') . ' ₫' }}
                            </td>
                            <td class="num">{{ $ngay_thu ? $ngay_thu . ' ngày' : 'Không' }}</td>
                            <td>
                                @if (data_get($p, 'status') === 'active')
                                    <span class="tag tag-good">Đang bán</span>
                                @else
                                    <span class="tag tag-mute">Ngừng bán</span>
                                @endif
                            </td>
                            @if ($ghiDuoc)
                                <td class="num">
                                    {{-- Mọi thứ hộp thoại cần đều nằm trên chính cái nút:
                                         một hộp thoại dùng chung cho mọi dòng, JS chép dữ
                                         liệu từ nút sang. Dựng mỗi dòng một <dialog> thì
                                         bảng giá mười dòng là mười bản sao của cùng một
                                         form trong DOM. --}}
                                    <button type="button" class="btn-mini is-primary"
                                            data-sua
                                            data-id="{{ data_get($p, 'id') }}"
                                            data-action="{{ route('platform.khach-hang-order.goi-dich-vu.luu', ['plan' => data_get($p, 'id')]) }}"
                                            data-ma="{{ data_get($p, 'code') }}"
                                            data-chu-ky="{{ data_get($p, 'billing_cycle') === 'nam' ? 'Theo năm' : 'Theo tháng' }}"
                                            data-ten="{{ data_get($p, 'name') }}"
                                            data-tagline="{{ data_get($p, 'tagline') }}"
                                            {{-- Chuỗi rỗng = "Liên hệ" (chưa công bố giá).
                                                 Không đổi thành "0": 0 là miễn phí. --}}
                                            data-gia="{{ $gia === null ? '' : (int) $gia }}"
                                            data-thu="{{ $ngay_thu }}"
                                            data-trang-thai="{{ data_get($p, 'status') }}">Sửa</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="empty">
                @if ($loi)
                    <strong>Chưa lấy được dữ liệu</strong>
                    Bảng trống vì lần gọi API vừa rồi hỏng, không phải vì bảng giá rỗng.
                @else
                    <strong>Bảng giá đang rỗng</strong>
                    Chưa có mức giá nào cho Sellio Order — chưa ai mua được phần mềm này.
                @endif
            </div>
        @endif
    </section>

    @if ($ghiDuoc)
        @include('khach-hang-order.partials.sua-goi')
    @endif
@endsection
