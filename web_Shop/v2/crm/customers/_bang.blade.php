{{-- Bảng của CRM → Danh sách khách hàng — cột theo crm/customers/list của v2:
     Mã · Tên (kèm nhóm, bánh sinh nhật) · SĐT · Địa chỉ · Loại · Số đơn · Tổng
     mua · Đã thanh toán · Còn nợ · Chi tiêu gần nhất · Hành động.

     Bốn cột số bấm tiêu đề để sắp xếp — bấm lại là đảo chiều. Mũi tên chỉ chiều
     đang sắp; cột chưa sắp thì mũi tên hai đầu nhạt màu, đúng như v2. --}}
@php
    $C = \App\Http\Controllers\CustomerController::class;
    $homNay = now()->startOfDay();

    // Sinh nhật trong 7 ngày tới (tính cả hôm nay) thì gắn bánh — như v2.
    $sapSinhNhat = function ($ngaySinh) use ($homNay) {
        if (! $ngaySinh) {
            return false;
        }
        $sn = \Illuminate\Support\Carbon::parse($ngaySinh)->setYear($homNay->year);
        if ($sn->lt($homNay)) {
            $sn->addYear();
        }

        return $sn->diffInDays($homNay, true) <= 7;
    };

    $tieuDeSap = function (string $cot, string $nhan, string $lop) use ($filters, $columns) {
        $dang = $filters['sort_by'] === $cot;
        $mui = $dang ? 'fa-sort-'.($filters['sort_dir'] === 'asc' ? 'up' : 'down') : 'fa-sort text-muted';
        $an = $columns['show_'.$lop] ? '' : 'hide';

        return '<th class="text-right sortable show_'.$lop.' '.$an.'" data-sort="'.$cot.'" role="button">'
            .e($nhan).' <i class="fa-solid '.$mui.'"></i></th>';
    };
@endphp

{{-- `--kh-rong` = sàn bề rộng tính theo đúng những cột đang bật (xem index).
     Để ở biến CSS vì luật nén chữ dưới 1536px cần hạ sàn này theo tỉ lệ. --}}
<table class="table-striped table-customer table-crm none_mobile" style="--kh-rong: {{ $rongToiThieu }}px">
    <thead>
    <tr class="header-table-list">
        <th class="text-center not-export"><input class="form-check-input item-select-all" type="checkbox"></th>
        <th class="text-center">{{ __('message.stt') }}</th>
        <th class="text-left show_code {{ $columns['show_code'] ? '' : 'hide' }}">{{ __('message.customer-code') }}</th>
        <th class="text-left show_name {{ $columns['show_name'] ? '' : 'hide' }}">{{ __('message.customer-name') }}</th>
        <th class="text-left show_phone {{ $columns['show_phone'] ? '' : 'hide' }}">{{ __('message.phone-number') }}</th>
        <th class="text-left show_address {{ $columns['show_address'] ? '' : 'hide' }}">{{ __('message.address') }}</th>
        <th class="text-left show_type {{ $columns['show_type'] ? '' : 'hide' }}">{{ __('message.customer_type') }}</th>
        {!! str_replace('text-right', 'text-center', $tieuDeSap('orders_count', __('message.orders_count'), 'orders_count')) !!}
        {!! $tieuDeSap('total_purchases', __('message.total_purchases'), 'total_purchases') !!}
        {!! $tieuDeSap('total_paid', __('message.total_paid'), 'total_paid') !!}
        {!! $tieuDeSap('total_debt', __('message.still_in_debt'), 'still_in_debt') !!}
        <th class="text-right show_last_payment {{ $columns['show_last_payment'] ? '' : 'hide' }}">{{ __('message.last_payment') }}</th>
        <th class="text-center not-export show_action {{ $columns['show_action'] ? '' : 'hide' }}">{{ __('message.action') }}</th>
    </tr>
    </thead>

    <tbody class="list-customer">
    @forelse ($list as $i => $kh)
        @php $id = (int) ($kh['id'] ?? 0); @endphp
        <tr class="item not-export" data-id="{{ $id }}">
            <td class="text-center not-export">
                <input class="form-check-input item-select" type="checkbox" value="{{ $id }}">
            </td>
            <td class="text-center">{{ $stt + $i + 1 }}</td>
            <td class="text-left item-code show_code {{ $columns['show_code'] ? '' : 'hide' }}">{{ $kh['code'] ?? '' }}</td>
            <td class="text-left show_name {{ $columns['show_name'] ? '' : 'hide' }}">
                <span class="fw-bold">{{ $kh['full_name'] ?? '' }}</span>
                @if ($sapSinhNhat($kh['date_of_birth'] ?? ''))
                    <i class="fa-solid fa-cake-candles text-warning ms-1" title="Sắp sinh nhật"></i>
                @endif
                {{-- Cột "Nhóm khách hàng" của v2 là NHÃN dưới tên chứ không phải
                     cột riêng — tắt nó trong ô chọn cột là giấu nhãn này. --}}
                @if ($columns['show_customer_group'] && ($kh['group_name'] ?? '') !== '')
                    <div class="mt-1"><span class="badge bg-info text-white kh-nhan">{{ $kh['group_name'] }}</span></div>
                @endif
                @if (($kh['rank_name'] ?? '') !== '')
                    <div class="mt-1"><span class="badge bg-warning text-dark kh-nhan">{{ $kh['rank_name'] }}</span></div>
                @endif
            </td>
            <td class="text-left show_phone {{ $columns['show_phone'] ? '' : 'hide' }}">{{ $kh['phone'] ?? '' }}</td>
            <td class="text-left show_address {{ $columns['show_address'] ? '' : 'hide' }}">{{ $kh['address'] ?? '' }}</td>
            <td class="text-left show_type {{ $columns['show_type'] ? '' : 'hide' }}">
                {{ ((int) ($kh['type'] ?? 0)) === 0 ? __('message.personal') : __('message.business') }}
            </td>
            <td class="text-center show_orders_count {{ $columns['show_orders_count'] ? '' : 'hide' }}">{{ (int) ($kh['total_orders'] ?? 0) }}</td>
            <td class="text-right la-tien show_total_purchases {{ $columns['show_total_purchases'] ? '' : 'hide' }}">{{ $tien($kh['total_spent'] ?? 0) }}</td>
            <td class="text-right la-tien show_total_paid {{ $columns['show_total_paid'] ? '' : 'hide' }}">{{ $tien($kh['total_paid'] ?? 0) }}</td>
            <td class="text-right la-tien show_still_in_debt {{ $columns['show_still_in_debt'] ? '' : 'hide' }}">{{ $tien($kh['still_in_debt'] ?? 0) }}</td>
            <td class="text-right show_last_payment {{ $columns['show_last_payment'] ? '' : 'hide' }}">
                @if (($kh['last_payment_at'] ?? '') !== '')
                    <div class="la-tien">{{ $tien($kh['last_payment_amount'] ?? 0) }}</div>
                    <div class="text-muted">{{ $ngayVN($kh['last_payment_at']) }}</div>
                @else
                    <span class="text-muted">-</span>
                @endif
            </td>
            <td class="text-center action not-export show_action {{ $columns['show_action'] ? '' : 'hide' }}">
                <a class="detail-item" type="button" title="{{ __('message.detail') }}"><i class="fa fa-eye"></i></a>
                <a class="edit_bt edit-item" type="button" title="{{ __('message.edit') }}"><i class="fa fa-edit"></i></a>
                <a class="dele_bt delete-item" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                <a class="copy_bt copy-item" type="button" title="{{ __('message.copy') }}"><i class="fa fa-copy"></i></a>
            </td>
        </tr>
    @empty
        <tr>
            {{-- colspan theo số cột ĐANG BẬT (2 cột cố định + cột bật/tắt có
                 bề rộng thật; `customer_group` là nhãn dưới tên, không phải cột).
                 Để cứng 13 thì tắt bớt cột là dòng này thừa ô, kẻ bảng lệch hẳn. --}}
            <td colspan="{{ 2 + collect($columns)->filter()->keys()
                ->filter(fn ($k) => $k !== 'show_customer_group')->count() }}"
                class="text-center py-4">
                {{ $hasFilter ? 'Không có khách hàng nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}
            </td>
        </tr>
    @endforelse
    </tbody>
</table>
