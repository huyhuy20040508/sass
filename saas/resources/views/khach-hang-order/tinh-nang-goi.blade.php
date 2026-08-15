{{--
    Tính năng gói — hạn mức từng mức giá của Sellio Order.

    Danh sách khoá KHÔNG viết tay ở đây mà lấy từ `fields` do API trả về (registry
    trong plan_service.go là nguồn sự thật). Chép bảng khoá ra Blade thì thêm một
    hạn mức bên Go là phải nhớ sửa cả ở đây, và quên là màn hình im lặng giấu mất
    hạn mức mới.

    BA trạng thái của một hạn mức, đừng gộp lại còn hai:
      · vắng mặt   → "Không quy định" — bảng giá không nói, số chốt lúc ký hợp đồng
      · "vo_han"   → không giới hạn
      · một con số → đúng con số đó
    Ô để trống lúc lưu chính là trạng thái thứ nhất, KHÁC hẳn gõ số 0.

    Mỗi gói một <form> riêng vì API ghi theo từng gói và ghi trọn gói hoặc từ chối
    cả yêu cầu. Một nút "Lưu tất cả" cho cả bảng sẽ phải gọi nhiều lượt, và lượt
    thứ ba hỏng thì hai lượt đầu đã ghi rồi — không có cách nào lùi lại.
--}}
@extends('layouts.app')
@section('title', 'Tính năng gói')

@section('content')
    <div class="page-head">
        <span class="eyebrow">QLTK khách hàng order</span>
        <h2>Tính năng gói</h2>
        <p>Hạn mức của bảng giá Sellio Order. Sửa ở đây <strong>không</strong> đụng tới khách đang dùng — hợp đồng đã ký chép hạn mức ra lúc ký và sống độc lập với bảng giá.</p>
    </div>

    @if ($loi)
        <div class="notice">{{ $loi }}</div>
    @endif

    @if (count($plans) && count($fields))
        {{-- Bảng so sánh: hàng là hạn mức, cột là mức giá. Đây là hình dạng người
             ta cần để trả lời "gói này hơn gói kia ở chỗ nào" — thứ mà mấy khối
             sửa bên dưới, mỗi gói một khối, không nói được. --}}
        <section class="panel" style="margin-bottom:22px">
            <div class="panel-head">So sánh</div>
            <div class="tbl">
                <table>
                    <thead>
                    <tr>
                        <th>Hạn mức</th>
                        @foreach ($plans as $p)
                            <th class="num">
                                {{ data_get($p, 'name') }}
                                <div class="muted" style="font-weight:400;text-transform:none;letter-spacing:0">
                                    {{ data_get($p, 'billing_cycle') === 'nam' ? 'theo năm' : 'theo tháng' }}
                                </div>
                            </th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($fields as $f)
                        <tr>
                            <td>
                                {{ data_get($f, 'label') }}
                                <div class="mono muted">{{ data_get($f, 'key') }}</div>
                            </td>
                            @foreach ($plans as $p)
                                @php
                                    $khoa = data_get($f, 'key');
                                    $co_dong = array_key_exists($khoa, (array) data_get($p, 'features', []));
                                    $gia_tri = data_get($p, 'features.' . $khoa);
                                @endphp
                                <td class="num">
                                    @if (! $co_dong)
                                        <span class="muted">{{ data_get($f, 'type') === 'co_khong' ? 'Không' : 'Không quy định' }}</span>
                                    @elseif ($gia_tri === 'vo_han')
                                        Không giới hạn
                                    @elseif (data_get($f, 'type') === 'co_khong')
                                        {{ $gia_tri === '1' ? 'Có' : 'Không' }}
                                    @else
                                        {{ number_format((int) $gia_tri, 0, ',', '.') }}
                                        <span class="muted">{{ data_get($f, 'don_vi') }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if (! count($plans))
        <section class="panel">
            <div class="empty">
                @if ($loi)
                    <strong>Chưa lấy được dữ liệu</strong>
                    Trang trống vì lần gọi API vừa rồi hỏng, không phải vì bảng giá rỗng.
                @else
                    <strong>Bảng giá đang rỗng</strong>
                    Chưa có mức giá nào cho Sellio Order nên chưa có gì để khai hạn mức.
                @endif
            </div>
        </section>
    @elseif (! $ghiDuoc)
        {{-- support chỉ xem. Nói ra thay vì hiện ô nhập rồi để họ ăn 403 lúc bấm
             Lưu — API vẫn là chốt chặn thật, dòng này chỉ để giao diện không hứa
             thứ nó không làm được. --}}
        <p class="muted">Vai trò của bạn trong khu điều hành chỉ được xem, không sửa được bảng giá.</p>
    @else
        @foreach ($plans as $p)
            @php
                $features = (array) data_get($p, 'features', []);
                $id_goi = (int) data_get($p, 'id');
            @endphp
            <section class="panel" style="margin-bottom:14px">
                <div class="panel-head">
                    {{ data_get($p, 'name') }} · {{ data_get($p, 'billing_cycle') === 'nam' ? 'theo năm' : 'theo tháng' }}
                    @if (data_get($p, 'status') !== 'active')
                        — ngừng bán
                    @endif
                </div>
                <form method="POST" action="{{ route('platform.khach-hang-order.tinh-nang-goi.luu', $id_goi) }}">
                    @csrf
                    <div class="panel-body">
                        <div class="row g-3">
                            @foreach ($fields as $f)
                                @php
                                    $khoa = data_get($f, 'key');
                                    $kieu = data_get($f, 'type');
                                    // array_key_exists chứ không phải data_get(...) ?? : giá trị "0"
                                    // là hợp lệ và ?? không phân biệt được nó với khoá vắng mặt.
                                    $hien_tai = array_key_exists($khoa, $features) ? (string) $features[$khoa] : '';
                                    $tran = (float) data_get($f, 'max_num');
                                @endphp
                                <div class="col-12 col-md-6 col-xl-3">
                                    <label class="form-label" for="{{ $id_goi }}-{{ $khoa }}">{{ data_get($f, 'label') }}</label>

                                    @if ($kieu === 'co_khong')
                                        <select class="form-select" id="{{ $id_goi }}-{{ $khoa }}" name="items[{{ $khoa }}]">
                                            <option value=""  @selected($hien_tai === '')>Không quy định (mặc định: không)</option>
                                            <option value="1" @selected($hien_tai === '1')>Có</option>
                                            <option value="0" @selected($hien_tai === '0')>Không</option>
                                        </select>
                                    @else
                                        {{-- type="text" chứ không "number": ô số của trình duyệt
                                             không nhận được chữ "vo_han", mà đó là một giá trị hợp
                                             lệ của khoá này. Trần do API kiểm — 422 trả về lỗi
                                             theo từng khoá và controller in ra. --}}
                                        <input type="text" inputmode="numeric" class="form-control"
                                               id="{{ $id_goi }}-{{ $khoa }}" name="items[{{ $khoa }}]"
                                               value="{{ $hien_tai }}"
                                               placeholder="để trống = không quy định">
                                        <div class="muted" style="font-size:12px;margin-top:5px">
                                            {{ data_get($f, 'don_vi') }}@if (data_get($f, 'cho_vo_han')) · gõ <span class="mono">vo_han</span> nếu không giới hạn @endif
                                            @if ($tran > 0) · tối đa {{ number_format($tran, 0, ',', '.') }} @endif
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="toolbar" style="border-top:1px solid var(--rule-soft);border-bottom:0">
                        <span class="muted">Ô để trống nghĩa là bảng giá không quy định hạn mức đó — khác với số 0.</span>
                        <span class="spacer"></span>
                        <button type="submit" class="btn btn-plum">Lưu gói {{ data_get($p, 'name') }}</button>
                    </div>
                </form>
            </section>
        @endforeach
    @endif
@endsection
