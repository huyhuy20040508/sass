<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Tổng quan') — {{ config('app.name') }}</title>

    {{-- Favicon Sellio, dùng chung bộ ảnh với Shop Admin và trang giới thiệu
         selliotech.store: .svg cho trình duyệt mới, .ico dự phòng,
         apple-touch-icon cho màn hình chính iOS. Khác Shop Admin ở chỗ khu điều
         hành nền tảng không cho khách đổi thương hiệu, nên gắn cứng. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=3" sizes="32x32">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=3">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=3">

    {{-- Ba vai chữ, cả ba đều có bộ dấu tiếng Việt (đã đối chiếu subset
         `vietnamese` của Google Fonts — thiếu là chữ có dấu rơi sang phông dự
         phòng, mỗi chữ một kiểu):
           Archivo       — tiêu đề, nhãn, menu. Chữ vuông vức, đọc chắc ở cỡ nhỏ.
           IBM Plex Sans — chữ chạy trong câu.
           IBM Plex Mono — CON SỐ và giá trị của máy (địa chỉ API, mã vai trò).
         Cùng họ Plex nên sans và mono khớp nhau, Archivo đứng riêng làm giọng
         tiêu đề. Cố tình không dùng Inter: nó là phông mặc định của mọi bảng
         điều khiển dựng nhanh, nhìn là biết chưa ai chọn phông cả. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">

    {{-- Bootstrap ở đây chỉ để lấy lưới, dropdown và toast. Phần nhìn (nút, thẻ,
         huy hiệu) viết đè bên dưới: để nguyên kiểu mặc định thì trang nào cũng
         giống trang nào. Bootstrap Icons đã bỏ hẳn — biểu tượng rắc khắp nơi là
         thứ làm một trang quản trị nội bộ trông như bản mẫu. --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* ==========================================================
           Bảng màu lấy thẳng từ logo Sellio: tím cà #3A1266 và vàng
           #FFC20F. Bản trước dùng #4f46e5 — indigo mặc định của
           Tailwind, không liên quan gì tới thương hiệu.

           Vàng KHÔNG bao giờ làm chữ trên nền sáng (tương phản 1.7:1,
           không đọc được). Nó chỉ xuất hiện đúng hai chỗ mỗi trang:
           vạch đánh dấu mục đang mở ở thanh trái, và gạch đầu trang.
           Hiếm thì mới còn là dấu hiệu.
           ========================================================== */
        :root {
            --ink:        #1B1027;
            --ink-2:      #5B5266;
            --ink-3:      #8B8394;

            --plum:       #3A1266;
            --plum-deep:  #2A0D4B;
            --plum-lift:  #4C1D82;
            --gold:       #FFC20F;

            --paper:      #F6F4F1;   /* trắng ngà ấm: vàng đặt cạnh xám lạnh bị xỉn */
            --surface:    #FFFFFF;
            --rule:       #E4E0DB;
            --rule-soft:  #EFECE8;

            /* Ba màu trạng thái đậm và trầm hơn bản mặc định của Bootstrap:
               đây là màn hình nhìn cả ngày, đỏ tươi xanh tươi làm mỏi mắt. */
            --good:       #1F7A5C;
            --warn:       #A96A0B;
            --bad:        #B42318;

            --font-display: 'Archivo', 'Segoe UI', sans-serif;
            --font-body:    'IBM Plex Sans', 'Segoe UI', sans-serif;
            --font-data:    'IBM Plex Mono', ui-monospace, 'Cascadia Mono', monospace;
        }

        * { box-sizing: border-box; }

        /* Cỡ chữ nền lấy đúng bên Shop Admin: 14px / 20px / -0.2px. Hai app hay
           mở cạnh nhau, chữ lệch cỡ là nhận ra ngay. */
        body {
            margin: 0;
            background: var(--paper);
            font-family: var(--font-body);
            font-size: 14px;
            line-height: 20px;
            letter-spacing: -.2px;
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }

        .layout { display: flex; min-height: 100vh; }
        .content { flex: 1; min-width: 0; display: flex; flex-direction: column; }

        /* ===== Thanh trái =====
           Không biểu tượng. Menu chỉ là chữ, nên chữ phải tự làm được việc của
           biểu tượng: giãn khoảng cách chữ cái, cỡ nhỏ, chữ hoa — đọc ra ngay
           đây là mục điều hướng chứ không phải câu văn. */
        /* Đứng yên khi cuộn trang, cùng cách làm với Shop Admin
           (partials/sidebar.blade.php bên admin).

           height:100vh không thừa: nó vừa cho thanh một chiều cao cố định để
           `sticky` có chỗ bám, vừa chặn `align-items:stretch` của flex kéo thanh
           cao bằng cả trang — bị kéo cao rồi thì không còn gì để dính, thanh sẽ
           cuộn đi mất như thường. */
        .rail {
            position: sticky; top: 0; height: 100vh;
            width: 230px; flex: 0 0 230px;    /* 230px — đúng số đo bên Shop Admin */
            overflow-x: hidden;
            /* Chuyển màu rất nhẹ xuống đáy. Menu chỉ có một mục nên phần dưới
               thanh là một mảng tím dài; phẳng lì thì đọc như trang chưa dựng
               xong, tối dần xuống thì thành chiều sâu. */
            background: linear-gradient(180deg, var(--plum) 0%, var(--plum) 52%, var(--plum-deep) 100%);
            display: flex; flex-direction: column;
        }

        /* Hàng logo cao đúng 56px bằng thanh trên, nên vạch kẻ dưới logo và vạch
           kẻ dưới thanh trên nằm trên cùng một đường ngang — cùng cách bố trí
           với Shop Admin. Dòng "Điều hành nền tảng" trước nằm ở đây, giờ chuyển
           xuống làm tiêu đề nhóm trong menu để giữ được chiều cao 56px. */
        .rail-brand {
            display: flex; align-items: center; height: 56px; flex: 0 0 56px;
            padding: 0 12px; text-decoration: none;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }
        /* max-width chặn bề ngang: logo chữ dài hơn chỗ trống thì tự co lại thay
           vì tràn ra khỏi thanh. */
        .rail-brand img { height: 42px; width: auto; max-width: 100%; object-fit: contain; object-position: left; }

        /* min-height:0 để phần menu tự cuộn được khi sau này nhiều mục hơn chiều
           cao màn hình; thiếu nó thì ô flex không co xuống dưới min-content và
           dải tình trạng ở đáy bị đẩy khỏi tầm nhìn. */
        .rail-nav {
            flex: 1; min-height: 0; overflow-y: auto;
            display: flex; flex-direction: column; gap: 4px;
            padding: 8px 5px;
        }
        .rail-group {
            margin: 0; padding: 12px 12px 2px;
            font-family: var(--font-display);
            font-size: 11px; font-weight: 600; line-height: 16px;
            letter-spacing: .05em; text-transform: uppercase;
            color: rgba(255, 255, 255, .42);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        /* padding 8px 12px + line-height 20px = hàng cao 36px, khớp .jh-item bên
           Shop Admin. Chữ hoa cỡ 13 thay vì 14 thường: chữ hoa nhìn to hơn cùng
           cỡ, để 14 là hàng menu lấn át nội dung trang. */
        .rail-link {
            display: block;
            padding: 8px 12px; border-radius: 6px;
            font-family: var(--font-display);
            font-size: 12px; font-weight: 600; line-height: 20px; letter-spacing: .02em;
            text-transform: uppercase; text-decoration: none;
            color: rgba(255, 255, 255, .62);
            transition: color .12s ease, background-color .12s ease;
            /* MỘT DÒNG, KHÔNG XUỐNG HÀNG.
               Tên mục ở đây là NHÃN, không phải câu văn: "Phương thức thanh toán"
               gãy làm hai dòng thì hàng cao gấp đôi các mục khác, và cả thanh mất
               nhịp — mắt không còn quét được danh sách như một cột đều nhau.

               Cỡ chữ và giãn chữ đã hạ vừa đủ để hai nhãn dài nhất nằm gọn trong
               230px (đo thật: 182px và 173px, chỗ trống 196px và 180px). Đây là lý
               do KHÔNG tăng lại hai con số đó khi thêm mục mới — thêm nhãn dài hơn
               thì rút gọn CHỮ, đừng nới cỡ.

               ellipsis là lưới an toàn cho ngày ai đó thêm một nhãn dài hơn nữa:
               cắt cuối chữ kèm dấu … vẫn đọc ra là bị cắt, còn cắt giữa nét chữ
               (hệ quả của overflow-x:hidden trên .rail) thì đọc như lỗi hiển thị. */
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .rail-link:hover { color: #fff; background: rgba(255, 255, 255, .06); }
        /* Vạch vàng đánh dấu mục đang mở, vẽ bằng inset box-shadow để nó ăn theo
           góc bo 6px của mục — một vạch vuông góc nằm trong khung bo tròn thì lộ
           ra là hai thứ dán vào nhau. */
        .rail-link.is-current {
            color: #fff; background: var(--plum-lift);
            box-shadow: inset 3px 0 0 var(--gold);
        }

        /* ----- Nhóm xổ -----
           Nút cha dùng chung .rail-link để cao đúng 36px và cùng giọng chữ với
           mục thường; chỉ thêm phần bố trí mũi tên. reset nút: <button> mang
           sẵn nền, viền và cỡ chữ riêng của trình duyệt. */
        .rail-drop-toggle {
            display: flex; align-items: center; gap: 8px;
            width: 100%; text-align: left;
            background: none; border: 0; cursor: pointer;
            font: inherit; font-family: var(--font-display);
            font-size: 12px; font-weight: 600; letter-spacing: .02em;
            /* Nút cha có ít chỗ hơn mục thường đúng bằng mũi tên + khoảng cách
               (16px), nên nó là chỗ chật nhất thanh — đo theo nó chứ đừng đo theo
               mục thường. */
            overflow: hidden;
        }
        /* min-width:0 mới cho phép ô flex co xuống dưới min-content; thiếu nó thì
           ellipsis bên trong không bao giờ có hiệu lực và chữ cứ đẩy mũi tên ra
           khỏi thanh. */
        .rail-drop-toggle > span:first-child {
            flex: 1; min-width: 0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        /* Nhóm đang chứa trang hiện tại: sáng chữ lên nhưng KHÔNG tô nền và
           không kẻ vạch vàng — hai dấu hiệu đó dành riêng cho mục con đang mở.
           Cha và con cùng được đánh dấu như nhau thì không đọc ra cái nào là
           trang đang đứng. */
        .rail-drop-toggle.is-parent { color: #fff; }

        /* Mũi tên xuống vẽ bằng hai cạnh của một ô vuông xoay 45°. */
        .rail-caret {
            width: 6px; height: 6px; flex: 0 0 6px; margin-right: 2px;
            border-right: 1.5px solid currentColor;
            border-bottom: 1.5px solid currentColor;
            transform: translateY(-2px) rotate(45deg);
            transition: transform .15s ease;
        }
        .rail-drop.is-open .rail-caret { transform: translateY(1px) rotate(-135deg); }

        /* Mục con: KHÔNG chữ hoa. Cả hai cấp cùng chữ hoa thì nhìn thành một
           danh sách phẳng, thụt đầu dòng thôi không đủ tách cấp. Cỡ 13 thường
           nhỏ hơn 13 hoa nên tự lùi xuống hàng hai. */
        .rail-drop-panel { display: flex; flex-direction: column; padding: 2px 0 4px; }
        .rail-sublink {
            display: block;
            padding: 7px 12px 7px 22px; border-radius: 6px;
            font-family: var(--font-body);
            font-size: 13px; font-weight: 400; line-height: 20px; letter-spacing: -.1px;
            text-decoration: none;
            color: rgba(255, 255, 255, .58);
            /* Cùng luật với mục cha: một dòng. Mục con hiện đều ngắn, nhưng thanh
               trái phải giữ được nhịp hàng đều nhau kể cả khi thêm tên dài. */
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            transition: color .12s ease, background-color .12s ease;
        }
        .rail-sublink:hover { color: #fff; background: rgba(255, 255, 255, .06); }
        .rail-sublink.is-current {
            color: #fff; background: var(--plum-lift);
            box-shadow: inset 3px 0 0 var(--gold);
        }

        /* Dải tình trạng đáy thanh trái: sức khoẻ của máy thuộc về khung phần
           mềm, luôn nhìn thấy ở mọi trang — chứ không nằm trong một cái thẻ chỉ
           trang Tổng quan mới có. Số đo theo .jh-footer bên Shop Admin. */
        .rail-status {
            padding: 8px; flex: 0 0 auto;
            border-top: 1px solid rgba(255, 255, 255, .08);
        }
        .rail-status-line {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 12px;
            font-size: 13px; line-height: 20px; color: rgba(255, 255, 255, .78);
        }
        .dot { width: 7px; height: 7px; border-radius: 50%; flex: 0 0 7px; }
        .dot-good { background: #46C08D; }
        .dot-bad  { background: #F2705F; }

        /* ===== Thanh trên =====
           Cũng dính đỉnh như thanh trái: tên trang và nút tài khoản luôn trong
           tầm với, không phải cuộn ngược lên đầu mới thấy. */
        .topbar {
            position: sticky; top: 0; z-index: 20;
            background: var(--surface);
            border-bottom: 1px solid var(--rule);
            height: 56px; flex: 0 0 56px; padding: 0 16px;   /* 56px / 16px — theo .jh-topbar */
            display: flex; align-items: center; gap: 16px;
        }
        .topbar h1 {
            margin: 0;
            font-family: var(--font-display);
            font-size: 15px; font-weight: 600; line-height: 22px; letter-spacing: -.005em;
        }

        /* Nút mở ngăn kéo — chỉ hiện dưới 860px, xem media query cuối tệp.
           Ba vạch vẽ bằng CSS chứ không mượn phông biểu tượng. Đây là nút điều
           khiển chứ không phải trang trí cho menu, nên nó không phạm vào quy tắc
           "menu chỉ có chữ": ba vạch là ký hiệu mọi người đã biết sẵn, còn để
           chữ "Menu" thì tốn chỗ đúng cái chỗ đang thiếu. */
        .rail-toggle {
            display: none; align-items: center; justify-content: center;
            width: 34px; height: 34px; flex: 0 0 34px; margin-left: -4px;
            border: 1px solid var(--rule); border-radius: 4px;
            background: none; cursor: pointer; color: var(--ink);
            transition: background-color .12s ease;
        }
        .rail-toggle:hover { background: var(--paper); }
        .rail-toggle span, .rail-toggle span::before, .rail-toggle span::after {
            display: block; width: 16px; height: 1.5px;
            background: currentColor; border-radius: 1px;
        }
        .rail-toggle span { position: relative; }
        .rail-toggle span::before, .rail-toggle span::after {
            content: ''; position: absolute; left: 0;
        }
        .rail-toggle span::before { top: -5px; }
        .rail-toggle span::after  { top: 5px; }

        /* Lớp phủ nền khi ngăn kéo mở. Ẩn hẳn ở màn hình rộng. */
        .rail-backdrop { display: none; }
        @media (max-width: 860px) { .rail-backdrop { display: block; } }

        /* Nút tài khoản: ô vuông chữ đầu tên thay cho biểu tượng người chung
           chung — nó nói được đang đăng nhập bằng ai, biểu tượng thì không. */
        .account {
            display: flex; align-items: center; gap: 10px;
            padding: 5px 10px 5px 5px;
            background: none; border: 1px solid transparent; border-radius: 3px;
            font-family: var(--font-body); font-size: 13px; color: var(--ink);
            cursor: pointer; transition: border-color .12s ease, background-color .12s ease;
        }
        .account:hover { background: var(--paper); border-color: var(--rule); }
        .account-mark {
            width: 28px; height: 28px; flex: 0 0 28px; border-radius: 3px;
            background: var(--plum); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-family: var(--font-display); font-size: 11px; font-weight: 600;
            letter-spacing: .04em;
        }

        .dropdown-menu {
            border: 1px solid var(--rule); border-radius: 4px; padding: 0;
            box-shadow: 0 8px 28px rgba(27, 16, 39, .12);
            font-size: 13px; min-width: 240px;
        }
        .dropdown-head { padding: 13px 15px; border-bottom: 1px solid var(--rule-soft); }
        .dropdown-item { padding: 11px 15px; font-size: 13px; }
        .dropdown-item:active { background: var(--paper); color: var(--ink); }

        /* ===== Vùng nội dung =====
           padding 24px = đúng `main.p-4` bên Shop Admin. Bỏ max-width: bên đó
           nội dung chạy hết bề ngang, chặn lại thì màn hình rộng sẽ thấy hai app
           bố cục khác nhau. */
        .page { padding: 24px; flex: 1; }

        /* Gạch vàng đầu trang — chỗ thứ hai và cũng là cuối cùng màu vàng xuất
           hiện. Nó nối tiêu đề trang với thương hiệu ở thanh trái. */
        .page-head { margin-bottom: 26px; }
        .eyebrow {
            display: inline-block; padding-top: 9px;
            border-top: 2px solid var(--gold);
            font-family: var(--font-display);
            font-size: 10px; font-weight: 600; letter-spacing: .16em;
            text-transform: uppercase; color: var(--ink-3);
        }
        .page-head h2 {
            margin: 12px 0 0;
            font-family: var(--font-display);
            font-size: 27px; font-weight: 600; letter-spacing: -.02em; line-height: 1.2;
        }
        .page-head p { margin: 6px 0 0; color: var(--ink-2); font-size: 13.5px; }

        /* Đầu trang có NÚT VIỆC CHÍNH. Bọc thêm một lớp thay vì cho .page-head
           thành flex: mọi trang khác đang xếp eyebrow / h2 / p chồng dọc làm con
           trực tiếp của nó, đổi nó thành flex là ba thứ đó nằm ngang hết.

           Nút của cả TRANG phải ở đây chứ không nằm trong hàng công cụ của bảng:
           đứng cạnh mấy ô lọc thì nó đọc như một nút của bảng, và "thêm khách
           hàng mới" thì không phải việc của bảng.

           align-items: flex-end để nút thẳng hàng với dòng cuối cùng của khối chữ
           chứ không lửng lơ giữa. */
        .page-bar {
            display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap;
            margin-bottom: 26px;
        }
        .page-bar .page-head { flex: 1; min-width: 260px; margin-bottom: 0; }

        /* ===== Vật liệu chung ===== */
        .panel {
            background: var(--surface);
            border: 1px solid var(--rule);
            border-radius: 4px;   /* bo rất nhẹ: đây là bảng đồng hồ, không phải thẻ bài */
        }
        .panel-head {
            padding: 15px 20px; border-bottom: 1px solid var(--rule-soft);
            font-family: var(--font-display);
            font-size: 11px; font-weight: 600; letter-spacing: .12em;
            text-transform: uppercase; color: var(--ink-3);
        }
        .panel-body { padding: 20px; }

        .muted { color: var(--ink-2); }
        /* overflow-wrap:anywhere chứ không phải break-word: chỉ `anywhere` mới hạ
           được min-content, tức là mới ngăn được một chuỗi không có chỗ ngắt
           (đường dẫn API, tên bảng) banh cột grid chứa nó ra. */
        /* letter-spacing: 0 — body đang để -0.2px cho chữ thường, nhưng phông
           mono vốn đã rộng đều, bóp thêm là chữ dính vào nhau. */
        .mono  { font-family: var(--font-data); font-size: .9em; letter-spacing: 0; overflow-wrap: anywhere; }

        .state {
            font-family: var(--font-data); font-size: 11px; font-weight: 500;
            letter-spacing: .04em; text-transform: uppercase; white-space: nowrap;
        }
        .state-good { color: var(--good); }
        .state-wait { color: var(--ink-3); }
        .state-bad  { color: var(--bad); }

        /* ===== Nút ===== */
        .btn-plum {
            background: var(--plum); border: 1px solid var(--plum); color: #fff;
            border-radius: 3px; padding: 9px 18px;
            font-family: var(--font-display); font-size: 13px; font-weight: 600;
            transition: background-color .12s ease;
        }
        .btn-plum:hover, .btn-plum:focus { background: var(--plum-deep); border-color: var(--plum-deep); color: #fff; }

        /* ===== Bảng =====
           Dùng chung cho mọi màn hình danh sách. Bootstrap có .table nhưng viền
           và khoảng cách của nó không khớp .panel ở đây — bảng nằm trong panel
           mà lại kẻ khung riêng thì thành hai cái hộp lồng nhau.

           Bảng đặt THẲNG vào .panel (không qua .panel-body): đệm 20px của
           panel-body sẽ cắt các đường kẻ ngang cụt hai đầu. */
        .tbl { overflow-x: auto; }
        .tbl table { width: 100%; border-collapse: collapse; }
        .tbl th, .tbl td {
            padding: 11px 20px; text-align: left; vertical-align: middle;
            border-bottom: 1px solid var(--rule-soft);
        }
        .tbl th {
            font-family: var(--font-display);
            font-size: 10px; font-weight: 600; letter-spacing: .1em;
            text-transform: uppercase; color: var(--ink-3);
            white-space: nowrap;
            background: var(--surface);
            box-shadow: inset 0 -1px 0 var(--rule);
        }
        /* KHÔNG dính theo màn hình (`top: 56px` cho khớp thanh trên) — đã thử và
           nó hỏng: `overflow-x: auto` ở .tbl biến khối này thành vùng cuộn của CẢ
           HAI chiều, nên `sticky` bám vào mép trên của .tbl chứ không phải mép
           trên cửa sổ. Kết quả là một dải trắng cao 56px phía trên hàng tiêu đề,
           và hàng dữ liệu đầu tiên bị tiêu đề đè lên.
           Muốn có cả hai thì phải bỏ cuộn ngang đi, mà bảng chín cột thì không bỏ
           được. Chọn cuộn ngang, bỏ tiêu đề dính. */
        .tbl tbody tr:last-child td { border-bottom: 0; }
        .tbl tbody tr:hover td { background: var(--paper); }
        /* Bảng nhiều cột: bóp đệm ngang lại. Chín cột × 20px hai bên là 360px chỉ
           riêng đệm — đủ để đẩy cột cuối ra ngoài mép ở màn hình 1440. Chỉ dùng
           cho bảng thật sự chật, đừng đặt mặc định: mấy bảng năm sáu cột đang thở
           được, bóp thêm là chữ dính vào đường kẻ. */
        .tbl.dense th, .tbl.dense td { padding: 10px 12px; }
        /* Cột số: canh phải và dùng phông mono để các chữ số thẳng hàng dọc —
           đó là thứ cho phép liếc mắt so độ lớn mà không cần đọc từng số. */
        .tbl .num { text-align: right; font-family: var(--font-data); font-size: .9em; letter-spacing: 0; white-space: nowrap; }
        /* Dòng phụ trong ô số ("mỗi tháng") là CHỮ, không phải số — trả nó về phông
           chữ thường. Để mono thì nó đọc như một giá trị máy sinh ra. */
        .tbl .num .s { font-family: var(--font-body); font-size: 12px; letter-spacing: -.1px; }
        .tbl .tight { white-space: nowrap; }

        /* Dòng thay cho bảng rỗng. Bảng trống trơn không nói được là "chưa có
           khách" hay "trang hỏng". */
        .empty { padding: 34px 20px; text-align: center; color: var(--ink-2); }
        .empty strong { display: block; font-family: var(--font-display); font-size: 14px; color: var(--ink); margin-bottom: 4px; }

        /* Dải báo lỗi trong trang (khác toast: lỗi này là lý do bảng đang trống,
           nó phải ở lại chứ không tự tan sau 5 giây). */
        .notice {
            display: flex; gap: 10px; align-items: flex-start;
            padding: 12px 16px; margin-bottom: 16px;
            background: #FDF3F2; border: 1px solid #F3D6D3; border-left: 3px solid var(--bad);
            border-radius: 3px; color: #7A241C; font-size: 13.5px;
        }

        /* Nhãn trạng thái có nền — dùng trong ô bảng, nơi .state (chỉ đổi màu
           chữ) chìm mất giữa những ô chữ khác. */
        .tag {
            display: inline-block; padding: 3px 8px; border-radius: 3px;
            font-family: var(--font-display); font-size: 10.5px; font-weight: 600;
            letter-spacing: .06em; text-transform: uppercase; white-space: nowrap;
        }
        .tag-good { background: #E6F3EE; color: var(--good); }
        .tag-warn { background: #FBF0DC; color: var(--warn); }
        .tag-bad  { background: #FBE9E7; color: var(--bad); }
        .tag-mute { background: var(--paper); color: var(--ink-3); }

        /* Hàng công cụ trên đầu bảng: đếm số dòng bên trái, nút bên phải. */
        .toolbar {
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            padding: 13px 20px; border-bottom: 1px solid var(--rule-soft);
            font-size: 13px; color: var(--ink-2);
        }
        .toolbar .spacer { flex: 1; }
        /* Hàng tổng kết dưới CHÂN bảng: "đang hiện 3 / 27". Chỗ của nó là dưới,
           đúng nơi mắt dừng lại sau khi đọc hết bảng — đặt lên trên thì nó đọc
           như thanh phân trang, và người ta đi tìm nút sang trang. */
        .toolbar.is-foot { border-bottom: 0; border-top: 1px solid var(--rule-soft); }
        /* Ô tìm và bộ lọc trong hàng công cụ: thấp hơn .form-control một nấc để
           cả hàng vẫn cao bằng dòng chữ đếm bên trái — hàng công cụ cao lên là
           bảng bị đẩy xuống mất một dòng ở màn hình laptop. */
        .toolbar .form-control, .toolbar .form-select {
            padding: 5px 10px; font-size: 13px; width: auto; min-width: 0;
        }
        .toolbar .tim { width: 230px; }
        @media (max-width: 560px) { .toolbar .tim { width: 100%; } }

        /* ----- Ô bảng hai dòng -----
           Dòng dưới là thứ ĐỊNH DANH (mã cửa hàng, số điện thoại), dòng trên là
           thứ ĐỌC ĐƯỢC (tên). Gộp vào một dòng thì hoặc bảng rộng gấp đôi, hoặc
           mất một trong hai — mà tên thì trùng nhau được, mã thì không. */
        .cell2 { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .cell2 > div { min-width: 0; }
        .cell2 .b { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cell2 .s { font-size: 12px; color: var(--ink-3); white-space: nowrap; }

        /* Ô vuông chữ đầu tên. Cùng cách làm với nút tài khoản ở thanh trên: nó
           nói được đây là cửa hàng nào, còn một biểu tượng cửa hàng chung chung
           thì dòng nào cũng như dòng nào. */
        .avatar {
            width: 28px; height: 28px; flex: 0 0 28px; border-radius: 3px;
            display: flex; align-items: center; justify-content: center;
            background: var(--paper); border: 1px solid var(--rule);
            font-family: var(--font-display); font-size: 11px; font-weight: 600;
            text-transform: uppercase; color: var(--ink-2);
        }

        /* Ba hạn mức gộp một ô, ngăn bằng dấu chấm giữa. Ba cột riêng cho ba con
           số hiếm khi phải so sánh với nhau là ba lần chiều ngang, và chiều ngang
           ở bảng này đang phải chia cho chín cột. */
        .limits { font-family: var(--font-data); font-size: 12px; white-space: nowrap; color: var(--ink-2); }
        .limits b { color: var(--ink); font-weight: 500; }

        /* ----- Thanh tiến độ dùng thử -----
           Con số "còn 3 ngày" không nói được 3 ngày đó là nhiều hay ít: còn 3
           trên 7 khác hẳn còn 3 trên 30. Thanh này trả lời phần đã đi qua, thứ mà
           một con số đứng một mình không nói. */
        /* width cố định, KHÔNG kéo hết bề ngang ô: kéo hết thì nó nằm sát dưới
           dòng ngày tháng và đọc thành cái gạch chân của chữ, không ra thanh đo. */
        .meter {
            width: 72px; height: 4px; margin-top: 7px; border-radius: 2px;
            background: var(--rule-soft); overflow: hidden;
        }
        .meter > i { display: block; height: 100%; border-radius: 2px; background: var(--ink-3); }
        .meter.is-warn > i { background: var(--warn); }
        .meter.is-bad  > i { background: var(--bad); }

        /* ----- Tiêu đề cột bấm được -----
           Mũi tên chỉ hiện ở cột đang sắp. Hiện mờ ở mọi cột thì hàng tiêu đề đầy
           mũi tên và không đọc ra cột nào đang có hiệu lực. */
        .tbl th.sortable { cursor: pointer; user-select: none; }
        .tbl th.sortable:hover { color: var(--ink); }
        .tbl th.sortable::after {
            content: ''; display: inline-block; width: 5px; height: 5px; margin-left: 6px;
            border-right: 1.5px solid currentColor; border-bottom: 1.5px solid currentColor;
            transform: translateY(-2px) rotate(45deg);
            opacity: 0;
        }
        .tbl th.sortable[aria-sort]::after { opacity: 1; }
        .tbl th.sortable[aria-sort="descending"]::after { transform: translateY(1px) rotate(-135deg); }

        /* ----- Cột thao tác -----
           Neo phải và không co: nút nhảy vị trí giữa các dòng là thứ làm người ta
           bấm nhầm dòng bên cạnh. */
        .tbl td.act { text-align: right; white-space: nowrap; }
        .btn-mini {
            display: inline-block; padding: 4px 10px; margin-left: 6px;
            border: 1px solid var(--rule); border-radius: 3px; background: var(--surface);
            font-family: var(--font-display); font-size: 11.5px; font-weight: 600;
            color: var(--ink-2); text-decoration: none; cursor: pointer;
            transition: border-color .12s ease, color .12s ease, background-color .12s ease;
        }
        .btn-mini:hover { border-color: var(--ink-3); color: var(--ink); background: var(--paper); }
        .btn-mini.is-primary { border-color: var(--plum); color: var(--plum); }
        .btn-mini.is-primary:hover { background: var(--plum); color: #fff; }
        .btn-mini.is-bad:hover { border-color: var(--bad); color: var(--bad); background: #FDF3F2; }

        /* ----- Nút lọc nhanh theo mốc hạn -----
           Bật/tắt được, và con số nằm NGAY TRÊN nút thay vì ở một dải riêng: đọc
           thấy "3 quá hạn" rồi bấm luôn vào đúng chỗ đó, không phải đọc một con
           số ở trên rồi đi tìm bộ lọc ở dưới. */
        .loc-han { display: flex; gap: 6px; flex-wrap: wrap; }
        .chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border: 1px solid var(--rule); border-radius: 999px;
            background: var(--surface); color: var(--ink-2);
            font-size: 12.5px; line-height: 18px; cursor: pointer;
            transition: border-color .12s ease, color .12s ease, background-color .12s ease;
        }
        .chip:hover { border-color: var(--ink-3); color: var(--ink); }
        /* Đang bật: nền tím đặc. Phải khác hẳn trạng thái rê chuột — nếu chỉ đậm
           hơn một chút thì không đọc ra được cái nào đang có hiệu lực, và người
           dùng không hiểu vì sao bảng chỉ còn vài dòng. */
        .chip.is-on {
            background: var(--plum); border-color: var(--plum); color: #fff;
        }
        .chip.is-on .tag { background: rgba(255, 255, 255, .18); color: #fff; }

        /* Dòng "không khớp bộ lọc" — khác .empty (bảng chưa có dữ liệu). Hai câu
           đó dẫn tới hai việc khác nhau: xoá bớt bộ lọc, hay đi tạo dữ liệu. */
        .no-hit td { padding: 28px 20px; text-align: center; color: var(--ink-2); }

        /* ==========================================================
           HỘP THOẠI CÓ FORM

           Dựng theo đúng khuôn form của Shop Admin (xem .sup-* trong
           admin/resources/views/suppliers/index.blade.php): rộng tối đa 720,
           cao tối đa 92vh, CẢ HỘP cuộn với đầu và chân dính lại, lưới hai cột,
           nhãn chữ thường 13px, ô cao 36px, nút ở chân canh giữa.

           Giống về KHUÔN chứ không chép màu: bên đó là xanh Ant, bên này là tím
           thương hiệu. Người dùng mở hai app cạnh nhau nhận ra cùng một cái form
           qua bố cục và cỡ chữ, không phải qua màu nút.

           Vỏ vẫn là <dialog> của trình duyệt chứ không phải overlay tự dựng như
           bên kia: nó tự khoá tiêu điểm bàn phím trong hộp, tự đóng bằng Esc và
           tự nằm trên mọi thứ, không cần một dòng JS nào cho ba việc đó.
           ========================================================== */
        dialog.sheet {
            width: min(720px, calc(100vw - 32px));
            /* Cuộn CẢ HỘP, không phải chỉ phần thân: đầu và chân dính lại bằng
               sticky nên vẫn luôn nhìn thấy, mà thanh cuộn thì chỉ có một —
               cuộn riêng phần thân giữa hai dải cố định đọc như một khung nhúng. */
            max-height: 92vh; overflow-y: auto;
            padding: 0; border: 1px solid var(--rule); border-radius: 6px;
            background: var(--surface); color: var(--ink);
            box-shadow: 0 10px 40px rgba(27, 16, 39, .22);
        }
        dialog.sheet::backdrop { background: rgba(27, 16, 39, .45); }

        .sheet-head {
            position: sticky; top: 0; z-index: 2;
            display: flex; align-items: flex-start; gap: 12px;
            padding: 12px 20px; border-bottom: 1px solid var(--rule-soft); background: var(--surface);
        }
        .sheet-head h3 {
            margin: 0; flex: 1;
            font-family: var(--font-display); font-size: 15px; font-weight: 700; letter-spacing: -.01em;
        }
        .sheet-head p { margin: 4px 0 0; font-size: 12.5px; color: var(--ink-2); line-height: 1.5; }
        .sheet-x {
            border: 0; background: none; padding: 0; margin-top: 2px;
            display: inline-flex; color: var(--ink-3); cursor: pointer; transition: color .15s;
        }
        .sheet-x:hover { color: var(--ink); }

        .sheet-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
        .sheet-foot {
            position: sticky; bottom: 0; z-index: 2;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 20px; border-top: 1px solid var(--rule-soft); background: var(--surface);
        }

        /* ----- Lưới hai cột -----
           CSS grid chứ không phải .row/.col của Bootstrap: hai ô cạnh nhau phải
           CAO BẰNG NHAU kể cả khi một bên có lời chú hai dòng còn bên kia không —
           grid làm được điều đó, float-grid thì để lại một khoảng thụt.

           Ô chiếm cả hàng đặt tên `.rong`, KHÔNG phải `.col-2` như bên Shop
           Admin: bên đó không nạp Bootstrap, còn ở đây `.col-2` là class có sẵn
           của Bootstrap và nó ép width xuống 16.66% — ô rộng nhất form thành ô
           hẹp nhất, mà không dòng CSS nào của mình sai cả. */
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        .grid2 .rong { grid-column: 1 / -1; }
        @media (max-width: 560px) { .grid2 { grid-template-columns: 1fr; } }

        /* ----- Ô nhập trong hộp thoại -----
           Nhãn CHỮ THƯỜNG 13px, khác .form-label chữ hoa giãn cách dùng ngoài
           trang. Chữ hoa hợp với nhãn đứng một mình trên một khối; xếp mười cái
           liền nhau trong một form thì nó thành một bức tường chữ hoa và mắt
           không còn phân biệt được nhãn với nội dung. */
        .f-label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500; color: var(--ink); }
        /* Dấu sao đỏ đánh ô bắt buộc. Nói TRƯỚC khi người ta bấm Lưu, thay vì để
           máy chủ trả về "trường này là bắt buộc" sau đó. */
        .req { color: var(--bad); }

        /* Ô tích kèm nhãn nằm cùng dòng, đặt DƯỚI ô nó bổ nghĩa (vd "Chưa công
           bố giá" dưới ô giá). Cả cụm là <label> nên bấm vào chữ cũng tích được
           — ô vuông 13px là một cái đích nhỏ đến mức phải bấm hai lần mới trúng.
           Chữ nhỏ hơn .f-label một nấc: đây là một lựa chọn phụ của ô bên trên,
           không phải một trường ngang hàng. */
        .f-check {
            display: flex; align-items: center; gap: 7px; margin: 7px 0 0;
            font-size: 12.5px; color: var(--ink-2); cursor: pointer;
        }
        .f-check input { width: 13px; height: 13px; margin: 0; accent-color: var(--plum); cursor: pointer; }

        dialog.sheet .form-control,
        dialog.sheet .form-select {
            width: 100%; height: 36px; padding: 0 12px;
            font-size: 13px; border-radius: 4px;
        }
        /* <select> mặc định của Windows trông khác hẳn ô nhập bên cạnh. Vẽ lại
           mũi tên bằng SVG nhúng thẳng, cùng cách với Shop Admin. */
        dialog.sheet .form-select {
            cursor: pointer; padding-right: 32px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%235B5266' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        /* ----- Ô số kèm nút đổi ĐƠN VỊ nằm trong khung -----
           "12 tháng" và "1 năm" là cùng một thứ, nhưng gõ 12 rồi tự nhân nhẩm là
           chỗ sai số. Nút nằm TRONG khung ô chứ không đứng cạnh: nó thuộc về ô
           đó, và đặt ra ngoài thì hàng ô bị đẩy rộng thêm một nấc.

           Ô nhập chừa đệm phải đúng bằng chỗ của nút — thiếu thì con số dài chui
           xuống dưới nút và không đọc được nữa. */
        .o-don-vi { position: relative; }
        .o-don-vi .form-control { padding-right: 104px; }
        .don-vi {
            position: absolute; right: 4px; top: 50%; transform: translateY(-50%);
            display: flex; gap: 2px;
        }
        .don-vi button {
            border: 0; background: none; padding: 4px 9px; border-radius: 3px;
            font-family: var(--font-body); font-size: 12px; line-height: 16px;
            color: var(--ink-3); cursor: pointer;
            transition: background-color .12s ease, color .12s ease;
        }
        .don-vi button:hover { color: var(--ink); background: var(--paper); }
        /* Đơn vị đang chọn: nền tím đặc. Phải khác hẳn trạng thái rê chuột — nhìn
           nhầm đơn vị nghĩa là bán nhầm gấp mười hai lần. */
        .don-vi button.is-on { background: var(--plum); color: #fff; }

        /* ----- Bộ chọn có ô tìm -----
           Thay cho <select> khi danh sách dài: <select> của trình duyệt không
           tìm được, và cuộn qua hàng chục cửa hàng để kiếm một cái tên là việc
           không ai muốn làm lần thứ hai.

           Ô tìm nằm TRONG bảng xổ, không phải trên nút: nó chỉ có việc khi bảng
           đang mở, và đặt ngoài thì nó chiếm chỗ vĩnh viễn cho một việc thỉnh
           thoảng mới cần. */
        .chon { position: relative; }
        .chon-nut {
            display: flex; align-items: center; gap: 8px;
            width: 100%; text-align: left; cursor: pointer;
        }
        .chon-nut > span:first-child { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        /* Mũi tên vẽ bằng hai cạnh ô vuông xoay 45°, cùng cách với thanh trái —
           không mượn phông biểu tượng cho một dấu ba nét. */
        .chon-nut::after {
            content: ''; flex: 0 0 6px; width: 6px; height: 6px;
            border-right: 1.5px solid var(--ink-3); border-bottom: 1.5px solid var(--ink-3);
            transform: translateY(-2px) rotate(45deg);
        }
        .chon.is-open .chon-nut { border-color: var(--plum-lift); box-shadow: 0 0 0 3px rgba(76, 29, 130, .14); }

        .chon-bang {
            position: absolute; z-index: 5; left: 0; right: 0; top: calc(100% + 4px);
            background: var(--surface); border: 1px solid var(--rule); border-radius: 4px;
            box-shadow: 0 10px 28px rgba(27, 16, 39, .16);
            overflow: hidden;
        }
        .chon-tim {
            display: block; width: 100%; border: 0; border-bottom: 1px solid var(--rule-soft);
            padding: 9px 12px; font-family: var(--font-body); font-size: 13px; color: var(--ink);
            outline: none;
        }
        .chon-tim::placeholder { color: #B6AFBF; }
        /* Cao vừa đủ ~5 dòng rồi cuộn: dài hơn thì bảng che mất phần form bên
           dưới, và người dùng mất chỗ đứng. */
        .chon-ds { max-height: 208px; overflow-y: auto; padding: 4px; }
        .chon-muc {
            display: block; width: 100%; text-align: left;
            padding: 7px 9px; border: 0; border-radius: 3px; background: none;
            font-family: var(--font-body); font-size: 13px; color: var(--ink); cursor: pointer;
        }
        .chon-muc .s { display: block; font-size: 11.5px; color: var(--ink-3); }
        /* is-nhay = dòng bàn phím đang đứng. Cùng hình thức với rê chuột: hai
           cách điều khiển phải cho cùng một tín hiệu, nếu không thì người dùng
           bàn phím không biết Enter sẽ chọn cái nào. */
        .chon-muc:hover, .chon-muc.is-nhay { background: var(--paper); }
        .chon-muc.is-chon { background: var(--plum); color: #fff; }
        .chon-muc.is-chon .s { color: rgba(255, 255, 255, .7); }
        .chon-trong { margin: 0; padding: 14px 9px; text-align: center; font-size: 12.5px; color: var(--ink-3); }

        /* Ô ghi chú và mấy ô chữ dài: cao hơn một nấc cho dễ đọc lại cái đã gõ. */
        dialog.sheet textarea.form-control { height: auto; padding: 8px 12px; line-height: 1.5; }

        /* Nút phụ, đứng cạnh .btn-plum. Số đo lấy ĐÚNG của .btn-plum (đệm 9px 18px,
           bo 3px, viền 1px) chứ không đặt chiều cao cố định: hai nút cạnh nhau mà
           lệch vài pixel thì cả hàng trông như xếp nhầm, và chiều cao cố định sẽ
           lệch lại ngay lần đầu ai đó đổi cỡ chữ. */
        .btn-ghost {
            padding: 9px 18px; border: 1px solid var(--rule); border-radius: 3px;
            background: var(--surface); color: var(--ink-2);
            font-family: var(--font-display); font-size: 13px; font-weight: 600; line-height: 20px;
            cursor: pointer;
            transition: border-color .15s, color .15s, background-color .15s;
        }
        /* Rê chuột thì ĂN MÀU THƯƠNG HIỆU, không chỉ đậm chữ lên. Nút phụ vẫn là
           nút — phải cho thấy nó bấm được, chỉ là không giành chỗ với nút chính. */
        .btn-ghost:hover, .btn-ghost:focus-visible {
            border-color: var(--plum); color: var(--plum); background: var(--paper);
        }
        /* Nút việc NGUY HIỂM: đứng yên như mọi nút khác, chỉ đỏ lên lúc rê chuột.
           Đỏ sẵn từ đầu thì cả trang lúc nào cũng có một chấm đỏ báo động trong
           khi chẳng có gì hỏng, và mắt thôi để ý nó — đúng lúc cần để ý nhất. */
        .btn-ghost.is-bad:hover, .btn-ghost.is-bad:focus-visible {
            border-color: var(--bad); color: var(--bad); background: #FDF3F2;
        }
        /* Trong hộp thoại thì cả hai nút cùng thấp một nấc — hàng chân hộp chật
           hơn đầu trang. Đè cho CẢ HAI để chúng vẫn bằng nhau. */
        dialog.sheet .btn-plum,
        dialog.sheet .btn-ghost { height: 34px; padding: 0 18px; border-radius: 4px; font-size: 13px; }

        /* ----- Danh sách nhãn · giá trị -----
           Dùng cho khối CHỈ ĐỌC ở trang chi tiết. Lưới hai cột thay vì <dl> mặc
           định (nhãn một dòng, giá trị thụt xuống dòng dưới): ở đây mắt cần quét
           dọc cột giá trị để so, mà xuống dòng thì cột đó không thẳng hàng nữa.

           Cột nhãn cố định 130px chứ không auto: auto thì mỗi khối có một bề rộng
           khác nhau, và hai panel xếp cạnh nhau trông như hai bản thiết kế. */
        .ke { display: grid; grid-template-columns: 130px 1fr; gap: 9px 14px; margin: 0; }
        .ke dt { font-size: 13px; font-weight: 400; color: var(--ink-2); }
        .ke dd { margin: 0; font-size: 13.5px; }
        @media (max-width: 480px) { .ke { grid-template-columns: 110px 1fr; } }

        /* Nhãn tách nhóm ô trong một form dài. Không kèm đường kẻ: chữ đứng sau
           một khoảng trống rộng đã đủ nói "sang phần khác". */
        .nhom-o {
            margin: 4px 0 -2px; font-family: var(--font-display);
            font-size: 11px; font-weight: 600; letter-spacing: .08em;
            text-transform: uppercase; color: var(--ink-3);
        }

        /* Lời chú dưới ô nhập, và lời báo lỗi của chính ô đó. */
        .hint { margin: 4px 0 0; font-size: 11px; line-height: 1.5; color: var(--ink-3); }
        .field-bad { margin: 4px 0 0; font-size: 11px; line-height: 1.5; color: var(--bad); }
        .form-control.is-bad, .form-select.is-bad { border-color: var(--bad); }

        /* Khối lưu ý trong hộp thoại — nền nhạt, không phải màu báo động. Dùng
           cho câu "việc này KHÔNG làm X", thứ phải đọc nhưng không phải lỗi. */
        .sheet-note {
            margin: 0; padding: 10px 12px; border: 1px solid var(--rule); border-radius: 4px;
            background: var(--paper); font-size: 12px; line-height: 1.55; color: var(--ink-2);
        }
        .sheet-note.is-bad { border-color: #F3D6D3; background: #FDF3F2; color: #7A241C; }

        /* ===== Ô nhập ===== */
        .form-label {
            font-family: var(--font-display);
            font-size: 11px; font-weight: 600; letter-spacing: .08em;
            text-transform: uppercase; color: var(--ink-2); margin-bottom: 6px;
        }
        .form-control, .form-select {
            border: 1px solid var(--rule); border-radius: 3px;
            padding: 9px 12px; font-size: 14px; color: var(--ink);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--plum-lift);
            box-shadow: 0 0 0 3px rgba(76, 29, 130, .14);
        }
        .form-control::placeholder { color: #B6AFBF; }

        /* ===== Bàn phím =====
           Viền tím trên nền sáng, viền vàng trên nền tím: người dùng bàn phím
           phải thấy mình đang đứng ở đâu trên cả hai nền. */
        :focus-visible { outline: 2px solid var(--plum-lift); outline-offset: 2px; }
        .rail :focus-visible { outline-color: var(--gold); }

        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; animation: none !important; }
        }

        /* ==========================================================
           Co giãn theo bề ngang.

           Thanh trái giữ nguyên 230px cho tới lúc không còn chỗ — giống Shop
           Admin, bên đó cũng không tự bóp theo màn hình. Khác một điểm: bên đó
           thu gọn còn 48px chỉ chừa biểu tượng, ở đây không có biểu tượng nên
           thu như thế là mất luôn nghĩa. Thay vào đó dưới 860px thanh nằm ngang
           thành dải đầu trang.
           ========================================================== */

        /* Màn hình rất rộng: nới đệm ra một nấc cho nội dung không dính mép. */
        @media (min-width: 1600px) {
            .page { padding: 28px 32px; }
        }

        @media (max-width: 860px) {
            /* Thanh trái thành ngăn kéo trượt từ mép trái, mặc định đóng.

               position:fixed nên nó ra khỏi dòng chảy — .content tự chiếm hết bề
               ngang, không cần đổi hướng của .layout. Giữ nguyên bố cục dọc bên
               trong (logo trên, menu giữa, tình trạng dưới) để mở ra là thấy đúng
               thanh quen thuộc, chỉ khác là nó nằm đè lên trang.

               visibility (không phải chỉ transform): thanh đóng mà vẫn "nhìn thấy"
               được thì phím Tab vẫn nhảy vào mấy đường dẫn nằm ngoài màn hình.
               Trễ 0s sau khi trượt xong để lúc đóng vẫn kịp nhìn thấy nó trượt. */
            .rail {
                position: fixed; top: 0; left: 0; bottom: 0; height: auto;
                width: 268px; max-width: 84vw; flex: none;
                z-index: 60;
                transform: translateX(-100%); visibility: hidden;
                transition: transform .22s ease, visibility 0s linear .22s;
                box-shadow: 0 0 40px rgba(27, 16, 39, .35);
            }
            .rail.is-open {
                transform: none; visibility: visible;
                transition: transform .22s ease;
            }

            .rail-backdrop {
                position: fixed; inset: 0; z-index: 50;
                background: rgba(27, 16, 39, .45);
                opacity: 0; visibility: hidden;
                transition: opacity .22s ease, visibility 0s linear .22s;
            }
            .rail-backdrop.is-open {
                opacity: 1; visibility: visible;
                transition: opacity .22s ease;
            }

            .rail-toggle { display: inline-flex; }
        }

        @media (max-width: 560px) {
            .rail-brand { height: 52px; flex-basis: 52px; }
            .rail-brand img { height: 34px; }
            .topbar { padding: 0 12px; gap: 10px; }
            .page { padding: 16px 12px 28px; }
            .page-head { margin-bottom: 18px; }
            .page-head h2 { font-size: 21px; }
            .panel-head { padding: 12px 14px; }
            .panel-body { padding: 14px; }
            .toolbar { padding: 11px 14px; }
            .tbl th, .tbl td { padding: 10px 14px; }
        }
    </style>
    @stack('styles')
</head>
<body>
<div class="layout">
    @include('partials.sidebar')

    {{-- Nền mờ sau ngăn kéo. Bấm vào là đóng — ở màn hình nhỏ đây là cách đóng
         mà người ta thử trước tiên, trước cả khi đi tìm nút. --}}
    <div class="rail-backdrop" id="railBackdrop"></div>

    <div class="content">
        @include('partials.topbar')
        <main class="page">
            @yield('content')
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Ngăn kéo thanh trái ở màn hình nhỏ. Trên 860px thanh luôn hiện, script này
    // không đụng tới gì cả.
    (function () {
        var rail = document.getElementById('rail');
        var backdrop = document.getElementById('railBackdrop');
        var toggle = document.getElementById('railToggle');
        if (!rail || !backdrop || !toggle) return;

        var HEP = window.matchMedia('(max-width: 860px)');

        function dat(mo) {
            // Trên 860px thanh trái luôn hiện, không có gì để mở — chặn ở đây để
            // dù có ai gọi nhầm thì cũng không khoá cuộn trang của màn hình rộng.
            if (!HEP.matches) mo = false;

            rail.classList.toggle('is-open', mo);
            backdrop.classList.toggle('is-open', mo);
            toggle.setAttribute('aria-expanded', mo ? 'true' : 'false');
            toggle.setAttribute('aria-label', mo ? 'Đóng menu' : 'Mở menu');
            // Khoá cuộn trang phía sau: mở ngăn kéo rồi mà quệt tay vẫn cuộn được
            // trang bên dưới thì đọc như ngăn kéo bị rời ra khỏi trang.
            document.body.style.overflow = mo ? 'hidden' : '';
        }

        toggle.addEventListener('click', function () {
            dat(!rail.classList.contains('is-open'));
        });

        backdrop.addEventListener('click', function () { dat(false); });

        // Bấm vào một mục menu là chuyển trang — đóng luôn để lúc trang mới hiện
        // ra không còn ngăn kéo che mặt.
        rail.querySelectorAll('a[href]').forEach(function (a) {
            a.addEventListener('click', function () { dat(false); });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && rail.classList.contains('is-open')) {
                dat(false);
                toggle.focus();
            }
        });

        // Xoay ngang máy / kéo rộng cửa sổ qua mốc 860px: trả về trạng thái đóng,
        // nếu không thì body vẫn bị khoá cuộn trong khi thanh đã hiện sẵn.
        HEP.addEventListener('change', function (e) { if (!e.matches) dat(false); });
    })();

    // Nhóm xổ ở thanh trái.
    //
    // Trạng thái mở ban đầu do PHP quyết định (nhóm chứa trang hiện tại thì mở
    // sẵn) — script này chỉ lo chuyện bấm tay. Cố ý KHÔNG nhớ trạng thái vào
    // localStorage: người ta cụp nhóm lại rồi mở một trang trong nhóm đó ở tab
    // khác, thanh trái sẽ hiện ra không chỉ được mình đang đứng ở đâu.
    (function () {
        document.querySelectorAll('[data-rail-drop]').forEach(function (drop) {
            var nut = drop.querySelector('.rail-drop-toggle');
            var bang = drop.querySelector('.rail-drop-panel');
            if (!nut || !bang) return;

            nut.addEventListener('click', function () {
                var mo = !drop.classList.contains('is-open');
                drop.classList.toggle('is-open', mo);
                nut.setAttribute('aria-expanded', mo ? 'true' : 'false');
                // hidden chứ không phải display:none trong CSS: nó vừa ẩn hình
                // vừa rút các đường dẫn bên trong ra khỏi thứ tự phím Tab.
                bang.hidden = !mo;
            });
        });
    })();
</script>

@include('partials.toasts')
@stack('scripts')
</body>
</html>
