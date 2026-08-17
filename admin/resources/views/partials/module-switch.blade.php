{{--
    Nút ĐỔI MODULE — dùng chung cho cả hai thanh trên cùng.

    Chỗ này trước đây là nút dấu hỏi "Trợ giúp" trên topbar quản trị: một cái nút
    bấm vào không xảy ra gì cả, đứng ngay cạnh chuông thông báo — vị trí dễ thấy
    nhất của cả thanh. Đổi nó thành lối đi giữa hai module là dùng đúng chỗ đó
    cho việc người ta thật sự làm nhiều lần mỗi ngày: chủ tiệm nhảy sang quầy
    bán đỡ lúc đông khách rồi quay về xem kho.

    Một partial cho cả hai màn hình vì hai bên phải nói GIỐNG HỆT nhau — hai bản
    sao thì sẽ có bản quên cập nhật khi thêm module thứ ba. Nền của hai thanh
    khác nhau nên có hai tông, chọn bằng biến `tone`:

        'sang' (mặc định) — topbar quản trị, nền trắng
        'toi'             — thanh trên cùng của quầy, nền xanh đậm
--}}
@php
    // CỬA HÀNG HẾT HẠN HỢP ĐỒNG: không hiện nút đổi module.
    //
    // Cùng lý do thanh trái bỏ hẳn điều hướng lúc đó (xem partials/sidebar):
    // mọi đường của cả hai module đều bị `admin.khoa` dồn về trang Các gói dịch
    // vụ, nên một nút "sang Thu ngân" chỉ mời người ta bấm rồi quay lại đúng
    // chỗ cũ.
    if (\App\Services\HanSuDung::daKhoa()) {
        return;
    }

    $mdswTone = $tone ?? 'sang';
    $mdswHienTai = \App\Services\ModuleLamViec::hienTai();
    $mdswDs = \App\Services\ModuleLamViec::danhSach();
    $mdswDangO = collect($mdswDs)->firstWhere('ma', $mdswHienTai) ?? $mdswDs[0];
@endphp

<div class="mdsw mdsw--{{ $mdswTone }}" id="mdsw">
    <button type="button" class="mdsw-btn" id="mdswBtn"
            aria-haspopup="true" aria-expanded="false"
            title="Đổi module — đang ở {{ $mdswDangO['ten'] }}">
        <svg class="mdsw-btn__ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            {!! $mdswDangO['icon'] !!}
        </svg>
        <span class="mdsw-btn__ten">{{ $mdswDangO['ten'] }}</span>
        <svg class="mdsw-btn__caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m6 9 6 6 6-6"/>
        </svg>
    </button>

    <div class="mdsw-menu" role="menu">
        <p class="mdsw-menu__title">Chuyển sang</p>
        @foreach($mdswDs as $m)
            @php $dangO = $m['ma'] === $mdswHienTai; @endphp
            <a href="{{ $m['href'] }}" role="menuitem"
               class="mdsw-item {{ $dangO ? 'is-on' : '' }}">
                <span class="mdsw-item__ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        {!! $m['icon'] !!}
                    </svg>
                </span>
                <span class="mdsw-item__text">
                    <b>{{ $m['ten'] }}</b>
                    <em>{{ $m['mo_ta'] }}</em>
                </span>
                @if($dangO)
                    {{-- Dấu tích ở module đang mở: danh sách chỉ có hai dòng, thiếu
                         nó thì người dùng bấm nhầm vào chỗ mình đang đứng. --}}
                    <svg class="mdsw-item__tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m5 12.5 4.5 4.5L19 7"/>
                    </svg>
                @endif
            </a>
        @endforeach
    </div>
</div>

<style>
    .mdsw { position: relative; font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }

    .mdsw-btn {
        display: inline-flex; align-items: center; gap: 7px;
        height: 34px; padding: 0 10px;
        border-radius: 6px; border: 1px solid transparent;
        background: transparent; cursor: pointer;
        font-family: inherit; font-size: 13px; font-weight: 600; line-height: 1;
        transition: background .15s, color .15s, border-color .15s;
    }
    .mdsw-btn__ico { width: 17px; height: 17px; flex-shrink: 0; }
    .mdsw-btn__caret { width: 14px; height: 14px; flex-shrink: 0; opacity: .7; transition: transform .2s; }
    .mdsw.open .mdsw-btn__caret { transform: rotate(180deg); }
    /* Dưới 640px chỉ còn icon: tên module là thứ bỏ được đầu tiên, còn nút thì
       không — nó là lối đi duy nhất sang module kia. */
    @media (max-width: 640px) { .mdsw-btn__ten { display: none; } }

    /* Tông SÁNG — topbar khu quản trị (nền trắng) */
    .mdsw--sang .mdsw-btn { color: #475569; border-color: #e2e8f0; background: #fff; }
    .mdsw--sang .mdsw-btn:hover { background: #f1f5f9; color: #1e293b; }
    .mdsw--sang.open .mdsw-btn { background: #f1f5f9; color: #1e293b; }

    /* Tông TỐI — thanh trên cùng của quầy (nền xanh đậm) */
    .mdsw--toi .mdsw-btn { color: #fff; border-color: rgba(255, 255, 255, .22); }
    .mdsw--toi .mdsw-btn:hover { background: rgba(255, 255, 255, .12); }
    .mdsw--toi.open .mdsw-btn { background: rgba(255, 255, 255, .12); }

    /* Menu — nền trắng ở cả hai tông: đây là hộp thoại đứng trên trang, không
       phải một phần của thanh, nên nó không đổi màu theo thanh. */
    .mdsw-menu {
        position: absolute; right: 0; top: 100%; margin-top: 8px;
        width: 268px; max-width: calc(100vw - 24px);
        border: 1px solid #e2e8f0; border-radius: 8px; background: #fff;
        padding: 6px; z-index: 60; display: none;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, .1), 0 4px 6px -4px rgba(0, 0, 0, .1);
    }
    .mdsw.open .mdsw-menu { display: block; }
    .mdsw-menu__title {
        margin: 0; padding: 6px 8px 8px;
        font-size: 11px; font-weight: 600; text-transform: uppercase;
        letter-spacing: .05em; color: #94a3b8;
    }

    .mdsw-item {
        display: flex; align-items: center; gap: 10px;
        padding: 9px 8px; border-radius: 6px;
        text-decoration: none; color: #1e293b;
        transition: background .15s;
    }
    .mdsw-item:hover { background: #f8fafc; }
    .mdsw-item.is-on { background: #eff6ff; }
    .mdsw-item__ico {
        display: inline-flex; align-items: center; justify-content: center;
        width: 32px; height: 32px; flex-shrink: 0;
        border-radius: 6px; background: #f1f5f9; color: #475569;
    }
    .mdsw-item.is-on .mdsw-item__ico { background: #dbeafe; color: #1d4ed8; }
    .mdsw-item__ico svg { width: 17px; height: 17px; }
    .mdsw-item__text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .mdsw-item__text b { font-size: 13px; font-weight: 600; }
    .mdsw-item__text em { font-size: 11px; font-style: normal; color: #64748b; line-height: 1.35; }
    .mdsw-item__tick { width: 16px; height: 16px; margin-left: auto; flex-shrink: 0; color: #2563eb; }
</style>

<script>
    (function () {
        var boc = document.getElementById('mdsw');
        var nut = document.getElementById('mdswBtn');
        if (!boc || !nut) return;

        function dong() {
            boc.classList.remove('open');
            nut.setAttribute('aria-expanded', 'false');
        }

        nut.addEventListener('click', function (e) {
            e.stopPropagation();
            var mo = !boc.classList.contains('open');
            boc.classList.toggle('open', mo);
            nut.setAttribute('aria-expanded', mo ? 'true' : 'false');
        });

        document.addEventListener('click', function (e) {
            if (!boc.contains(e.target)) dong();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') dong();
        });
    })();
</script>
