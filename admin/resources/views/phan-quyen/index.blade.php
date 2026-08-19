@extends('layouts.app')

@section('title', \App\Http\Controllers\PhanQuyenController::TITLE)

@section('content')
    {{-- Tab "Theo nhân viên": [cây chi nhánh → nhân viên] + [bảng tick của người đó].
         Đúng lối đi của bản ERP cũ: chọn chi nhánh, chọn người, tick từng ô rồi Lưu. --}}
    @php
        $C = \App\Http\Controllers\PhanQuyenController::class;

        // Cây ba tầng: khu -> nhóm -> mục.
        $tongQuyen = collect($danhMuc)->sum(
            fn ($khu) => collect($khu['nhom'] ?? [])->sum(
                fn ($nh) => collect($nh['mucs'] ?? [])->sum(
                    fn ($m) => count($m['viec'] ?? []) + count($m['le'] ?? [])
                )
            )
        );

        // Xếp nhân viên vào đúng chi nhánh; ai chưa gán thì gom vào một mục cuối.
        $theoChiNhanh = collect($nhanVien)->groupBy(fn ($nv) => (int) ($nv['shop_id'] ?? 0));

        $userId = (int) ($chon['user_id'] ?? 0);
        $laChinhMinh = $userId > 0 && $userId === (int) $toiLaAi;
        $coTaiKhoan = $userId > 0;
        $suaDuoc = $coTaiKhoan && ! $laChinhMinh && ! empty($danhMuc);

    @endphp

    <div class="pq">
        <div class="pq-head">
            <div class="pq-head-text">
                <h1 class="pq-title">{{ $C::TITLE }}</h1>
                <p class="pq-sub">{{ $C::SUB }}</p>
            </div>

            <div class="pq-head-actions">
                <button type="button" class="pq-btn-ghost" id="pqCloseAll">Đóng tất cả</button>
                <button type="button" class="pq-btn-ghost" id="pqOpenAll">Mở tất cả</button>
                <label class="pq-checkall">
                    <input type="checkbox" id="pqAll" @disabled(! $suaDuoc)> Chọn tất cả
                </label>
                <button type="submit" form="pqNvForm" class="pq-btn-primary" @disabled(! $suaDuoc)>
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
                    Lưu thay đổi
                </button>
            </div>
        </div>

        @if(!empty($error))
            <p class="pq-callout is-error">{{ $error }}</p>
        @endif

        <div class="pq-body">
            {{-- Cột trái: chi nhánh → nhân viên, đúng như màn phân quyền của bản cũ. --}}
            <aside class="pq-side">
                <div class="pq-side-head">
                    <span>Chi nhánh &amp; nhân viên</span>
                    <span class="pq-side-count">{{ count($nhanVien) }}</span>
                </div>

                <div class="pq-search">
                    <input type="text" id="pqTimNv" placeholder="Tìm nhân viên theo tên hoặc mã" autocomplete="off">
                </div>

                <div class="pq-side-list">
                    @forelse($chiNhanh as $cn)
                        @php
                            $ds = $theoChiNhanh[(int) $cn['id']] ?? collect();
                            // Mở sẵn đúng chi nhánh của người đang chọn, còn lại gập.
                            $mo = $ds->contains(fn ($nv) => (int) $nv['id'] === (int) ($chon['id'] ?? 0));
                        @endphp
                        <div class="pq-branch {{ $mo ? 'is-open' : '' }}">
                            <button type="button" class="pq-branch-btn" data-pq-branch>
                                <svg class="pq-caret" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                <span>{{ $cn['name'] }}</span>
                                <span class="pq-branch-num">{{ $ds->count() }}</span>
                            </button>

                            <div class="pq-branch-emps">
                                @forelse($ds as $nv)
                                    @include('phan-quyen.partials.nhan-vien', ['nv' => $nv, 'chon' => $chon])
                                @empty
                                    <p class="pq-side-empty">Chi nhánh này chưa có ai.</p>
                                @endforelse
                            </div>
                        </div>
                    @empty
                        <p class="pq-side-empty">Chưa có chi nhánh nào.</p>
                    @endforelse

                    @php $chuaGan = $theoChiNhanh[0] ?? collect(); @endphp
                    @if($chuaGan->isNotEmpty())
                        {{-- Cửa hàng một điểm bán thì hồ sơ thường không khai chi nhánh.
                             Gom lại một mục thay vì để họ biến mất khỏi cây. --}}
                        <div class="pq-branch is-open">
                            <button type="button" class="pq-branch-btn" data-pq-branch>
                                <svg class="pq-caret" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                <span>Chưa gán chi nhánh</span>
                                <span class="pq-branch-num">{{ $chuaGan->count() }}</span>
                            </button>
                            <div class="pq-branch-emps">
                                @foreach($chuaGan as $nv)
                                    @include('phan-quyen.partials.nhan-vien', ['nv' => $nv, 'chon' => $chon])
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </aside>

            {{-- Cột phải: nhóm quyền của người đang chọn + bảng quyền hiệu lực. --}}
            <section class="pq-main">
                @if(! $chon)
                    <div class="pq-empty">
                        <p class="pq-empty-title">Chưa chọn ai</p>
                        <p class="pq-empty-sub">Bấm một chi nhánh ở cột bên trái rồi chọn nhân viên cần phân quyền.</p>
                    </div>
                @else
                    <div class="pq-main-head">
                        <div class="pq-main-text">
                            <h2 class="pq-main-title">Phân quyền cho: {{ $chon['full_name'] }}</h2>
                            <p class="pq-main-note">
                                @if($chon['code'] ?? '')<code>{{ $chon['code'] }}</code> · @endif
                                {{ $chon['shop_name'] ?: 'Chưa gán chi nhánh' }}
                                @if($chon['username'] ?? '')
                                    · Tài khoản <code>{{ $chon['username'] }}</code>
                                    @if(($chon['user_status'] ?? '') !== 'active')<span class="pq-chip is-off">đã khoá</span>@endif
                                @endif
                            </p>
                        </div>

                    </div>

                    @if(! $coTaiKhoan)
                        <p class="pq-callout is-info">
                            Người này chưa có tài khoản đăng nhập nên chưa phân quyền được.
                            Cấp tài khoản trong hồ sơ ở <a href="{{ route('admin.nhan-su.index') }}">Nhân sự</a> rồi quay lại đây.
                        </p>
                    @elseif($laChinhMinh)
                        {{-- API từ chối lượt tự sửa quyền của chính mình: phiên đang chạy
                             bằng token cũ nên màn hình vẫn bình thường, tới lần đăng nhập
                             sau mới phát hiện mất quyền — lúc đó hết đường vào để sửa. --}}
                        <p class="pq-callout is-warn">
                            Đây là tài khoản bạn đang đăng nhập. Không tự sửa quyền của chính mình —
                            nhờ một quản trị viên khác làm giúp.
                        </p>
                    @elseif($toanQuyen)
                        {{-- Cờ toàn quyền = mọi quyền HIỆN CÓ VÀ SẼ CÓ. Lưu bảng tick là API
                             gỡ cờ, người này thành danh sách cụ thể và không tự nhận quyền
                             của module ra mắt sau này. --}}
                        <p class="pq-callout is-warn">
                            Người này đang có <b>toàn quyền</b> — gồm cả quyền của module ra mắt sau này.
                            Bấm Lưu ở đây là chuyển thành danh sách cụ thể bên dưới, và từ đó module
                            mới sẽ phải tick tay.
                        </p>
                    @endif

                    {{-- Nói TRƯỚC lý do những ô xám ở dưới, chứ không để người dùng
                         bấm vào một ô không nhúc nhích rồi tự đoán. --}}
                    @if($coTaiKhoan && $chiQuay)
                        <p class="pq-callout is-info">
                            Người này chỉ được giao khu <b>Thu ngân</b> nên chỉ tick được những việc ở quầy.
                            Muốn giao thêm việc trong khu quản trị thì mở cửa <b>Quản lý</b> cho họ ở
                            <a href="{{ route('admin.nhan-su.index') }}">hồ sơ Nhân sự</a> trước, rồi quay lại đây.
                        </p>
                    @endif

                    @if($coTaiKhoan && !empty($danhMuc))
                        <form method="POST" action="{{ route('admin.phan-quyen.datQuyenNhanVien', $userId) }}"
                              id="pqNvForm" class="pq-form">
                            @csrf
                            @method('PUT')
                            {{-- id hồ sơ nhân sự, chỉ để quay lại đúng người sau khi lưu. --}}
                            <input type="hidden" name="nv" value="{{ $chon['id'] }}">

                            @include('phan-quyen.partials.ma-tran', ['chiDoc' => ! $suaDuoc])
                        </form>

                        <div class="pq-foot">
                            <span id="pqCount">{{ count($dangBat) }}</span>/{{ $tongQuyen }} quyền đang bật
                        </div>
                    @elseif(empty($danhMuc))
                        <div class="pq-empty">
                            <p class="pq-empty-title">Chưa tải được danh mục quyền</p>
                            <p class="pq-empty-sub">Máy chủ API không trả về cây quyền. Kiểm tra API rồi tải lại trang.</p>
                        </div>
                    @endif
                @endif
            </section>
        </div>
    </div>

    @include('phan-quyen.partials.style')

    <script>
        (function () {
            // Gập/mở một chi nhánh.
            document.querySelectorAll('[data-pq-branch]').forEach(function (b) {
                b.addEventListener('click', function () {
                    b.closest('.pq-branch').classList.toggle('is-open');
                });
            });

            // Tìm nhân viên: lọc ngay trong cây, chi nhánh nào còn người thì bung ra.
            var oTim = document.getElementById('pqTimNv');
            if (oTim) {
                oTim.addEventListener('input', function () {
                    var tu = oTim.value.trim().toLowerCase();
                    document.querySelectorAll('.pq-branch').forEach(function (br) {
                        var con = 0;
                        br.querySelectorAll('.pq-emp').forEach(function (e) {
                            var khop = tu === '' || (e.dataset.pqTim || '').indexOf(tu) !== -1;
                            e.classList.toggle('is-hidden', !khop);
                            if (khop) con++;
                        });
                        br.hidden = tu !== '' && con === 0;
                        if (tu !== '' && con > 0) br.classList.add('is-open');
                    });
                });
            }

        })();
    </script>
@endsection
