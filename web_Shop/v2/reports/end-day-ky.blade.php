{{-- Kỳ THẬT server vừa xem — đặt TRONG khối `.list` của tab. Xem v2::reports.end-day. --}}
<span id="tk-ky" hidden data-from="{{ \Illuminate\Support\Carbon::parse($filters['from_date'])->format('d-m-Y') }}"
    data-to="{{ \Illuminate\Support\Carbon::parse($filters['to_date'])->format('d-m-Y') }}"
    data-quick="{{ $filters['quick'] }}"
    data-chu="{{ \App\Http\Controllers\ReportController::kyChu($filters, $chiNhanh) }}"></span>
