@extends('layouts.app')

@section('title', 'Tổng quan')

@push('styles')
<style>
    /* ==========================================================
       Dải số liệu — thứ duy nhất trên trang được phép nổi bật.

       Một tấm liền chia bằng vạch kẻ, không phải bốn cái thẻ rời
       trôi cạnh nhau. Bốn thẻ rời kèm ô biểu tượng tô màu nhạt là
       kiểu bảng điều khiển ai dựng cũng ra; một tấm liền đọc như
       mặt đồng hồ của một cỗ máy, đúng thứ trang này đang là.

       Số đặt bằng phông mono để các cột thẳng hàng khi có số thật.
       ========================================================== */
    .gauge { display: grid; grid-template-columns: repeat(4, 1fr); }
    .gauge-cell { padding: 18px 20px 20px; border-left: 1px solid var(--rule-soft); }
    .gauge-cell:first-child { border-left: none; }
    .gauge-label {
        font-family: var(--font-display);
        font-size: 10px; font-weight: 600; letter-spacing: .11em;
        text-transform: uppercase; color: var(--ink-3);
    }
    .gauge-value {
        margin-top: 10px;
        font-family: var(--font-data); font-size: 30px; font-weight: 400;
        line-height: 1; color: var(--ink);
    }
    /* Ô chưa có số vẽ một vạch ngắn nằm đúng chỗ chữ số sẽ đứng, thay cho dấu
       gạch ngang. Dấu gạch ngang mảnh quá, ở cỡ này nhìn như ô bị lỗi không
       hiện được gì; vạch đặc thì đọc ra ngay là "chỗ này cố ý để trống". */
    .gauge-value.is-empty { display: flex; align-items: center; height: 30px; }
    .gauge-value.is-empty::before { content: ''; width: 26px; height: 3px; background: #D6D0DE; }
    .gauge-unit { margin-top: 7px; font-size: 12px; color: var(--ink-3); }

    /* Lý do bốn ô trống viết MỘT lần ở chân dải, không lặp trong từng ô.
       Màn hình trống là lúc phải chỉ việc tiếp theo, không phải lúc xin lỗi. */
    .gauge-note {
        margin: 0; padding: 12px 20px; border-top: 1px solid var(--rule-soft);
        background: var(--paper); font-size: 12.5px; color: var(--ink-2);
    }

    /* Lộ trình: cột trạng thái nằm trái, ghi đúng việc đã xong hay chưa.
       Cố tình không đánh số 01/02/03 — ba việc này không có thứ tự bắt buộc,
       đánh số vào là bịa ra một trình tự không có thật. */
    .track { display: grid; grid-template-columns: 74px 1fr; }
    .track > * { padding: 14px 0; border-top: 1px solid var(--rule-soft); }
    .track > :nth-child(1), .track > :nth-child(2) { border-top: none; padding-top: 0; }
    .track-body { padding-left: 4px; }
    .track-body strong { font-weight: 600; }
    .track-body p { margin: 3px 0 0; font-size: 13px; color: var(--ink-2); }

    /* Danh sách màn hình chưa dựng: chữ và vạch kẻ, không viên bo tròn.
       Viên bo tròn xám xếp hàng ngang trông như thẻ đính kèm bị hỏng.
       Xếp ba cột để sáu dòng không kéo trang dài ra vì một danh sách phụ. */
    .queue {
        list-style: none; margin: 0; padding: 0;
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 0 32px;
    }
    .queue li {
        padding: 9px 0; border-top: 1px solid var(--rule-soft);
        font-size: 13.5px; color: var(--ink-2);
    }
    .queue li:nth-child(-n+3) { border-top: none; padding-top: 0; }
    @media (max-width: 700px) {
        .queue { grid-template-columns: 1fr; }
        .queue li:nth-child(-n+3) { border-top: 1px solid var(--rule-soft); padding-top: 9px; }
        .queue li:first-child { border-top: none; padding-top: 0; }
    }

    /* Hai cột viết bằng grid chứ không mượn .row/.col của Bootstrap — cả trang
       đã dùng grid (dải số liệu, lộ trình, danh sách), thêm một hệ lưới thứ hai
       chỉ để chia đôi màn hình là thừa. Tỉ lệ 7/5 vì cột trái là chữ chạy, cột
       phải chỉ là mấy dòng nhãn–giá trị. */
    .cols { display: grid; grid-template-columns: 7fr 5fr; gap: 14px; align-items: start; }
    @media (max-width: 900px) { .cols { grid-template-columns: 1fr; } }

    /* Ô grid mặc định không co hẹp hơn nội dung dài nhất bên trong nó. Đặt
       min-width:0 để những đường dẫn mono trong Lộ trình về sau có dài thêm cũng
       không banh cột ra. Đi kèm overflow-wrap ở .mono (layouts/app). */
    .cols > *, .track > *, .gauge-cell, .queue li { min-width: 0; }

    .kv { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; }
    .kv + .kv { margin-top: 11px; padding-top: 11px; border-top: 1px solid var(--rule-soft); }
    .kv dt { font-size: 13px; color: var(--ink-2); font-weight: 400; }
    .kv dd { margin: 0; text-align: right; overflow-wrap: anywhere; }

    .warn-note {
        margin: 16px 0 0; padding: 12px 14px;
        border-left: 2px solid var(--warn); background: #FDF8EF;
        font-size: 12.5px; color: #6B4A08;
    }

    @media (max-width: 900px) {
        .gauge { grid-template-columns: repeat(2, 1fr); }
        .gauge-cell:nth-child(odd) { border-left: none; }
        .gauge-cell:nth-child(n+3) { border-top: 1px solid var(--rule-soft); }
    }
    @media (max-width: 520px) {
        .gauge { grid-template-columns: 1fr; }
        .gauge-cell { border-left: none; }
        .gauge-cell + .gauge-cell { border-top: 1px solid var(--rule-soft); }
    }
</style>
@endpush

@section('content')
    @php
        $thang = now()->month;
        // Tự ghép chuỗi ngày thay vì dùng translatedFormat(): app chưa nạp gói
        // ngôn ngữ Việt cho Carbon, gọi vào sẽ ra "August" giữa một trang tiếng Việt.
        $hom_nay = 'Ngày ' . now()->day . ' tháng ' . $thang . ', ' . now()->year;
    @endphp

    {{-- Eyebrow ghi ngày chứ không ghi "Khu điều hành nền tảng": dòng đó đã nằm
         ngay dưới logo ở thanh trái rồi, để lại đây là đọc hai lần cùng một câu
         trong một khung hình. Ngày thì chưa chỗ nào nói. --}}
    <div class="page-head">
        <span class="eyebrow">{{ $hom_nay }}</span>
        <h2>Xin chào, {{ data_get($user, 'full_name') ?: 'quản trị viên' }}</h2>
    </div>

    {{-- Bốn ô để "—" chứ không để 0: nền tảng chưa có bảng cửa hàng nào, nên 0
         sẽ bị đọc thành "chưa bán được shop nào" trong khi thực tế là "chưa làm
         tới phần đếm". Nối vào /platform/* rồi mới thay số. --}}
    <section class="panel" style="margin-bottom:22px">
        <div class="gauge">
            @foreach ([
                ['Cửa hàng hoạt động', 'cửa hàng'],
                ['Sắp hết hạn trong 30 ngày', 'cửa hàng'],
                ['Doanh thu tháng ' . $thang, 'đồng'],
                ['Yêu cầu chờ xử lý', 'yêu cầu'],
            ] as [$nhan, $donVi])
                <div class="gauge-cell">
                    <div class="gauge-label">{{ $nhan }}</div>
                    <div class="gauge-value is-empty"><span class="visually-hidden">chưa có số liệu</span></div>
                    <div class="gauge-unit">{{ $donVi }}</div>
                </div>
            @endforeach
        </div>
        <p class="gauge-note mb-0">
            Chưa có số liệu. Go API cần mở <span class="mono">/platform/tenants</span> và
            <span class="mono">/platform/subscriptions</span> thì bốn ô này mới đếm được.
        </p>
    </section>

    <div class="cols">
        <div>
            <section class="panel">
                <div class="panel-head">Lộ trình nền tảng</div>
                <div class="panel-body">
                    <div class="track">
                        <div class="state state-wait">Chưa làm</div>
                        <div class="track-body">
                            <strong>Mở nhóm <span class="mono">/platform</span> cho cửa hàng và thuê bao</strong>
                            <p>
                                Ba màn hình Người dùng thử, Người dùng chính thức và Doanh thu đọc từ
                                đây. Đã có <span class="mono">/platform/apps</span> và
                                <span class="mono">/platform/plans</span>.
                            </p>
                        </div>

                        <div class="state state-good">Xong</div>
                        <div class="track-body">
                            <strong>Tách vai trò khu điều hành khỏi tài khoản cửa hàng</strong>
                            <p>
                                Khu điều hành đăng nhập bằng sổ riêng
                                (<span class="mono">platform_users</span>); tài khoản cửa hàng không vào được.
                            </p>
                        </div>

                        <div class="state state-good">Xong</div>
                        <div class="track-body">
                            <strong>Phân tách dữ liệu theo cửa hàng</strong>
                            <p>
                                40/43 bảng có <span class="mono">tenant_id</span> — ba bảng còn lại
                                (<span class="mono">roles</span>, <span class="mono">tenants</span>,
                                <span class="mono">schema_migrations</span>) dùng chung có chủ ý. Khoá duy
                                nhất đã gắn tiền tố <span class="mono">tenant_id</span>.
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div>
            {{-- Không kéo cao bằng cột trái: thẻ này chỉ có ba dòng, kéo ra cho
                 bằng nhau thì được một ô trắng rỗng to hơn cả nội dung nó chứa. --}}
            <section class="panel">
                <div class="panel-head">Phiên làm việc</div>
                <div class="panel-body">
                    <dl class="mb-0">
                        <div class="kv">
                            <dt>Tài khoản</dt>
                            <dd class="mono">{{ data_get($user, 'email') ?: '—' }}</dd>
                        </div>
                        <div class="kv">
                            <dt>Vai trò</dt>
                            <dd class="state state-wait">{{ data_get($user, 'role') ?: '—' }}</dd>
                        </div>
                        <div class="kv">
                            <dt>Go API</dt>
                            <dd class="state {{ $apiOnline ? 'state-good' : 'state-bad' }}">
                                {{ $apiOnline ? 'Đang chạy' : 'Mất kết nối' }}
                            </dd>
                        </div>
                    </dl>

                    @unless ($apiOnline)
                        <p class="warn-note mb-0">
                            {{-- Không ghi cứng số cổng ở đây: máy cục bộ dùng 8080 còn máy
                                 thật dùng 8090 (8080 và 8081 đã bị dự án football chiếm),
                                 nên một con số cố định thì luôn sai ở một trong hai nơi.
                                 Chỉ trỏ vào chỗ khai báo là đủ. --}}
                            Không gọi được <span class="mono">/health</span>. Kiểm tra dịch vụ Go API
                            còn chạy không, và <span class="mono">API_BASE_URL</span> trong
                            <span class="mono">admin-Selliotech/.env</span> có trỏ đúng chỗ không.
                        </p>
                    @endunless
                </div>
            </section>
        </div>
    </div>

    {{-- Đây là chỗ ở mới của mấy mục "Sắp có" từng nằm trên thanh trái. Nằm
         trong menu thì chúng đọc như nút bấm hỏng; nằm ở đây thì đúng bản chất:
         danh sách màn hình chưa dựng. --}}
    <section class="panel" style="margin-top:14px">
        <div class="panel-head">Màn hình chưa dựng</div>
        <div class="panel-body">
            <ul class="queue">
                @foreach (['Cửa hàng', 'Gói dịch vụ', 'Hoá đơn', 'Người vận hành', 'Nhật ký', 'Cấu hình'] as $ten)
                    <li>{{ $ten }}</li>
                @endforeach
            </ul>
        </div>
    </section>
@endsection
