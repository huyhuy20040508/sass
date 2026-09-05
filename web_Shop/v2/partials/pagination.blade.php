{{--
    PHÂN TRANG CỦA KHU V2 — dựng lại đúng thứ bản v2 cũ in ra.

    Bản v2 gọi `{{ $list->links() }}` với `Paginator::useBootstrap()`, tức là
    khuôn `pagination::bootstrap-4` của Laravel. Bên này danh sách không phải
    LengthAwarePaginator (controller tự cắt trang từ mảng API trả về) nên phải
    dựng tay, nhưng dựng ĐÚNG cái mà khuôn kia in ra:

      <nav><ul class="pagination"> … </ul></nav>

    Bốn chỗ bản dựng tay trước đây làm sai, nay sửa hết:

      1. THIẾU <nav>. CSS của v2 canh giữa bằng `div.form_pagi nav { display:flex;
         justify-content:center }` — không có <nav> thì dãy số dạt hẳn về trái.
      2. Mũi tên dùng « » (&laquo;), bản v2 dùng ‹ › (&lsaquo;) — to hơn hẳn và
         lệch hàng với phần còn lại.
      3. Nút lùi/tiến ở hai đầu tuy tô mờ nhưng VẪN là thẻ <a href> bấm được,
         nhảy sang ?page=0. Laravel in ra <span>, không bấm được.
      4. Cửa sổ số trang cứng ±2 và KHÔNG có dấu "…". Sổ 40 trang thì không có
         cách nào nhảy tới trang cuối ngoài việc bấm tiến 38 lần.

      5. Ba nhánh if chép theo Illuminate\Pagination\UrlWindow nhưng để
         $moiBen = 1, trong khi UrlWindow chỉ đúng với mặc định 3 của nó: cụm
         quanh trang đang xem ĐÈ LÊN cụm đầu/cuối và in ra số lặp kèm dấu "…"
         không giấu gì cả — 10 trang đứng ở trang 3 ra "1 2 … 2 3 4 … 9 10",
         đứng ở trang 8 ra "1 2 … 7 8 9 … 9 10".

         Nay không chia nhánh nữa: gom các số cần bày vào một tập, rồi chỉ chèn
         "…" ở chỗ THẬT SỰ đứt quãng. Cách này không có nhánh nào để sai, và
         đổi $moiBen sang số khác vẫn đúng.

    Nhận vào `$meta` = ['page' => int, 'total_pages' => int].
--}}
@php
    $trang = (int) ($meta['page'] ?? 1);
    $tong = (int) ($meta['total_pages'] ?? 1);
    $trang = max(1, min($trang, $tong));

    // $moiBen = 1 chứ không phải 3 như mặc định của Laravel: cột nội dung của v2
    // đã hẹp sẵn (khung lọc chiếm 2/12 bên trái), để 3 thì dãy nút kéo dài hết bề
    // ngang. Để 1 thì nhiều nhất 7 số, vẫn còn "…" để nhảy tới trang cuối.
    $moiBen = 1;

    // Ít trang thì bày hết. Dãy có "…" rộng nhất là 7 số + 2 dấu = 9 ô, nên tới
    // 9 trang thì bày đủ KHÔNG rộng hơn mà bấm thẳng tới trang nào cũng được.
    //
    // Nhiều trang: hai trang đầu + hai trang cuối + cụm quanh trang đang xem.
    // Trùng nhau thì array_unique gộp lại, không có chuyện một số in hai lần.
    $soTrang = $tong <= 9 ? range(1, $tong) : array_unique(array_merge(
        [1, 2],
        range(max(1, $trang - $moiBen), min($tong, $trang + $moiBen)),
        [$tong - 1, $tong],
    ));
    $soTrang = array_values(array_filter($soTrang, fn ($p) => $p >= 1 && $p <= $tong));
    sort($soTrang);

    // Chèn "…" đúng chỗ nhảy cóc. Cách nhau đúng 1 thì hai số liền nhau, không
    // giấu trang nào — dấu "…" ở đó chỉ làm người đọc tưởng còn trang bị ẩn.
    $o = [];
    $truoc = 0;
    foreach ($soTrang as $p) {
        if ($truoc > 0 && $p > $truoc + 1) {
            $o[] = '...';
        }
        $o[] = $p;
        $truoc = $p;
    }

    $duong = fn ($p) => request()->fullUrlWithQuery(['page' => $p]);
@endphp

@if ($tong > 1)
    <nav>
        <ul class="pagination">
            {{-- Lùi một trang --}}
            @if ($trang <= 1)
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link" aria-hidden="true">&lsaquo;</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $duong($trang - 1) }}" rel="prev">&lsaquo;</a>
                </li>
            @endif

            @foreach ($o as $p)
                @if (is_string($p))
                    <li class="page-item disabled" aria-disabled="true"><span class="page-link">{{ $p }}</span></li>
                @elseif ($p === $trang)
                    <li class="page-item active" aria-current="page"><span class="page-link">{{ $p }}</span></li>
                @else
                    <li class="page-item"><a class="page-link" href="{{ $duong($p) }}">{{ $p }}</a></li>
                @endif
            @endforeach

            {{-- Tiến một trang --}}
            @if ($trang >= $tong)
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link" aria-hidden="true">&rsaquo;</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $duong($trang + 1) }}" rel="next">&rsaquo;</a>
                </li>
            @endif
        </ul>
    </nav>
@endif
