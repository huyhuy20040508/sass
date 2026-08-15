{{--
    Database — SỔ KHÁCH HÀNG của phần mềm Sellio Order.

    Không phải màn hình quản trị cơ sở dữ liệu máy chủ: Go API không mở đường nào
    cho việc đó, và một trang như thế cũng không nên có ở đây. Đây là bản ghi
    từng khách: mã, tên, người liên hệ, ngày vào sổ.

    Nó đứng độc lập với hai màn hình hợp đồng phía trên. Một khách có thể có
    nhiều hợp đồng nối nhau và vẫn còn trong sổ sau khi hợp đồng cuối đã hết —
    `so_hop_dong` là thứ nói ra khách đang dùng hay đã dừng hẳn.

    `trang_thai` ở đây là trạng thái CỬA HÀNG (mở / bị khoá), KHÔNG phải trạng
    thái hợp đồng. Hai thứ đó rời nhau: cửa hàng vẫn mở mà hợp đồng đã hết hạn là
    chuyện có thật và phải nhìn ra được.
--}}
@extends('layouts.app')
@section('title', 'Database')

@section('content')
    <div class="page-head">
        <span class="eyebrow">QLTK khách hàng order</span>
        <h2>Database</h2>
        <p>Sổ khách hàng của Sellio Order. Chỉ khách <strong>có</strong> hợp đồng: quan hệ "khách của phần mềm này" tồn tại qua hợp đồng chứ không qua bảng khách hàng.</p>
    </div>

    @if ($loi)
        <div class="notice">{{ $loi }}</div>
    @endif

    <section class="panel">
        <div class="toolbar">
            @php $bi_khoa = collect($khachHang)->where('trang_thai', 'suspended')->count(); @endphp
            <span><strong>{{ count($khachHang) }}</strong> khách hàng</span>
            <span class="spacer"></span>
            @if ($bi_khoa)
                <span class="tag tag-bad">{{ $bi_khoa }} cửa hàng đang bị khoá</span>
            @endif
        </div>

        @if (count($khachHang))
            <div class="tbl">
                <table>
                    <thead>
                    <tr>
                        <th>Mã</th>
                        <th>Tên</th>
                        <th>Người liên hệ</th>
                        <th>Điện thoại</th>
                        <th>Email</th>
                        <th class="num">Hợp đồng</th>
                        <th>Cửa hàng</th>
                        <th class="tight">Vào sổ</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($khachHang as $kh)
                        @php $ngay_vao_so = data_get($kh, 'ngay_vao_so'); @endphp
                        <tr>
                            <td class="mono tight">{{ data_get($kh, 'ma') }}</td>
                            <td>{{ data_get($kh, 'ten') ?: '—' }}</td>
                            <td>{{ data_get($kh, 'nguoi_lien_he') ?: '—' }}</td>
                            <td class="mono tight">{{ data_get($kh, 'dien_thoai') ?: '—' }}</td>
                            <td class="mono">{{ data_get($kh, 'email') ?: '—' }}</td>
                            <td class="num">{{ (int) data_get($kh, 'so_hop_dong') }}</td>
                            <td>
                                @if (data_get($kh, 'trang_thai') === 'active')
                                    <span class="tag tag-good">Đang mở</span>
                                @else
                                    <span class="tag tag-bad">Bị khoá</span>
                                @endif
                            </td>
                            <td class="tight mono">
                                {{ $ngay_vao_so ? \Illuminate\Support\Carbon::parse($ngay_vao_so)->format('d/m/Y') : '—' }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="empty">
                @if ($loi)
                    <strong>Chưa lấy được dữ liệu</strong>
                    Bảng trống vì lần gọi API vừa rồi hỏng, không phải vì sổ khách rỗng.
                @else
                    <strong>Sổ khách đang rỗng</strong>
                    Chưa khách nào có hợp đồng Sellio Order. Hợp đồng tạo bằng công cụ <span class="mono">cmd/thue-bao</span> trên máy chủ.
                @endif
            </div>
        @endif
    </section>
@endsection
