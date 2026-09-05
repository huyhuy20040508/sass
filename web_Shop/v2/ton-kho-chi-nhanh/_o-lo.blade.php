{{--
    BA Ô LÔ của một dòng bảng tồn kho: số lô · số lượng · hạn dùng.

    Tách ra vì chúng in ở hai chỗ giống hệt nhau — dòng đầu của mặt hàng (đứng
    cạnh mấy ô gộp rowspan) và mỗi dòng lô tiếp theo. Chép hai lần thì sớm muộn
    sửa một chỗ quên chỗ kia, mà lệch ở đây là hai lô cùng mặt hàng hiển thị
    theo hai kiểu.

    Màu hạn dùng lấy đúng luật của bản v2 (warehouse/list.blade.php): quá hạn
    hoặc hết hạn hôm nay → đỏ; còn trong 7 ngày tới → cam.

    Nhận vào `$l` = một phần tử của `lots`: ['lot_number', 'quantity', 'expire_date'].
--}}
@php
    $soLo = trim((string) ($l['lot_number'] ?? ''));
    $han = $l['expire_date'] ?? null;
    $hanTs = $han ? strtotime($han) : null;

    // Ngưỡng so theo NGÀY, không theo giờ: hàng hết hạn lúc 23h hôm nay vẫn là
    // hết hạn hôm nay, so bằng timestamp thô thì nó rơi nhầm sang nhóm "còn hạn".
    $homNay = strtotime('today');
    $mauHan = '';
    if ($hanTs !== null) {
        $ngay = strtotime(date('Y-m-d', $hanTs));
        if ($ngay <= $homNay) {
            $mauHan = 'qty-out';
        } elseif ($ngay <= strtotime('+7 days', $homNay)) {
            $mauHan = 'qty-low';
        }
    }
@endphp

{{-- Lô rỗng = domain.LoKhongXacDinh. Nhãn đặt ở đây, dưới database là chuỗi rỗng. --}}
<td class="text-right show_lot">
    {{ $soLo !== '' ? $soLo : __('message.unknown') }}
</td>
<td class="text-right show_lot_qty">
    {{ number_format((float) ($l['quantity'] ?? 0), 0, ',', '.') }}
</td>
{{-- Hạn dùng NULL = hàng không có hạn, khác hẳn "chưa ai khai" nên không bịa ra
     một ngày mốc — để dấu gạch như mọi ô trống khác của bảng. --}}
<td class="text-right show_expire {{ $mauHan }}">
    {{ $hanTs !== null ? date('d-m-Y', $hanTs) : '-' }}
</td>
