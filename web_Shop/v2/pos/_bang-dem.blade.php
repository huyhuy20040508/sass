{{-- Bảng đếm theo mệnh giá đã lưu kèm ca (opening_count / counted_count của API).
     $ds rỗng = lúc ấy gõ thẳng tổng, không đếm theo tờ → không in gì. --}}
@if(! empty($ds))
    <table class="ca-dem-to">
        <caption>{{ $nhan }}</caption>
        <tbody>
            @foreach($ds as $d)
                <tr>
                    <td>{{ number_format((int) $d['menh_gia'], 0, ',', '.') }}</td>
                    <td>× {{ (int) $d['so_to'] }}</td>
                    <td>{{ number_format((int) $d['menh_gia'] * (int) $d['so_to'], 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
