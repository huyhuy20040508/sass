{{-- Khung lọc của CRM → Danh sách khách hàng — chín khối, đúng thứ tự bản v2
     (crm/customers/index): Thời gian · Tìm kiếm · Loại khách & Giới tính · Địa
     chỉ · Sinh nhật gần nhất · Giao dịch lần cuối · Điểm · Tuổi · Hạng thành viên.

     Tên các ô khớp đúng danh sách O_LOC trong JS của màn: đổi ô nào là lọc lại
     ngay, không có nút "Lọc".

     Hai khối Sinh nhật / Giao dịch có hai lối: mốc nhanh (ô chọn) hoặc tự chọn
     khoảng ngày (ô lịch). Bật lối này thì lối kia khoá lại — lọc theo cả hai
     cùng lúc là không đọc ra được bảng đang nói gì. --}}
@php
    $C = \App\Http\Controllers\CustomerController::class;
    $hienNgay = fn ($tu, $den) => $tu && $den
        ? \Illuminate\Support\Carbon::parse($tu)->format('d/m/Y').' - '.\Illuminate\Support\Carbon::parse($den)->format('d/m/Y')
        : '';
@endphp

<div id="filterCreatedAt" class="mb-3">
    <div class="inner-modal-in-mobile">
        <span class="title_search d-none d-lg-block">{{ __('message.time') }}</span>
        <div class="input-group">
            <input type="text" class="form-control created_range" autocomplete="off"
                value="{{ $hienNgay($filters['created_from'], $filters['created_to']) }}"
                placeholder="{{ __('message.all') }}">
            <span class="input-group-text"><i class="fa-regular fa-calendar"></i></span>
        </div>
        <input type="hidden" name="created_from" class="created_from" value="{{ $filters['created_from'] }}">
        <input type="hidden" name="created_to" class="created_to" value="{{ $filters['created_to'] }}">
    </div>
</div>

<div id="filterSearch" class="mb-3">
    <div class="inner-modal-in-mobile">
        <span class="title_search d-none d-lg-block">{{ __('message.search') }}</span>
        <div class="input-group">
            <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                class="form-control search" autocomplete="off"
                placeholder="{{ __('message.enter-name-or-code') }}">
            <button class="btn seach-item" type="button">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
        </div>
    </div>
</div>

<div id="filterCustomer" class="mb-3">
    <div class="inner-modal-in-mobile d-flex flex-lg-column flex-xl-row justify-content-xl-between gap-3 gap-xl-2">
        <div>
            <span class="title_search d-none d-lg-block">{{ __('message.customer_type') }}</span>
            @foreach ([['0', __('message.personal')], ['1', __('message.business')]] as [$ma, $ten])
                <div class="form-check">
                    <input class="me-2 form-check-input check-to-search" type="checkbox"
                        value="{{ $ma }}" name="type[]" id="type_{{ $ma }}_search"
                        {{ in_array($ma, $filters['type'], true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="type_{{ $ma }}_search">{{ $ten }}</label>
                </div>
            @endforeach
        </div>
        <div>
            <span class="title_search d-none d-lg-block">{{ __('message.gender') }}</span>
            @foreach ($C::GENDERS as $ma => $ten)
                <div class="form-check">
                    <input class="me-2 form-check-input" type="checkbox" value="{{ $ma }}"
                        name="gender[]" id="gender_{{ $ma }}"
                        {{ in_array($ma, $filters['gender'], true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="gender_{{ $ma }}">{{ $ten }}</label>
                </div>
            @endforeach
        </div>
    </div>
</div>

<div id="filterAddress" class="mb-3">
    <div class="inner-modal-in-mobile">
        <span class="title_search d-none d-lg-block">{{ __('message.address') }}</span>
        <input type="text" name="address" value="{{ $filters['address'] }}" class="form-control address"
            autocomplete="off" placeholder="{{ __('message.all') }}">
    </div>
</div>

@foreach ([
    ['khoi' => 'birthday', 'id' => 'filterBirthday', 'nhan' => 'Sinh nhật gần nhất', 'moc' => 'tới'],
    ['khoi' => 'last_tx', 'id' => 'filterLastTx', 'nhan' => 'Giao dịch lần cuối', 'moc' => 'qua'],
] as $k)
    @php $tuChon = $filters[$k['khoi'].'_mode'] === 'custom'; @endphp
    <div id="{{ $k['id'] }}" class="mb-3">
        <div class="inner-modal-in-mobile">
            <span class="title_search d-none d-lg-block">{{ $k['nhan'] }}</span>
            <div class="d-flex align-items-center gap-2 mb-2">
                <input class="form-check-input m-0" type="radio" name="{{ $k['khoi'] }}_mode" value="preset"
                    id="{{ $k['khoi'] }}_mode_preset" {{ $tuChon ? '' : 'checked' }}>
                <select name="{{ $k['khoi'] }}_preset" class="form-control {{ $k['khoi'] }}_preset" {{ $tuChon ? 'disabled' : '' }}>
                    <option value="">{{ __('message.all') }}</option>
                    @foreach ($C::MOC_NGAY as $so => $ten)
                        <option value="{{ $so }}" {{ $filters[$k['khoi'].'_preset'] === (string) $so ? 'selected' : '' }}>
                            {{ $ten }} {{ $k['moc'] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="d-flex align-items-center gap-2">
                <input class="form-check-input m-0" type="radio" name="{{ $k['khoi'] }}_mode" value="custom"
                    id="{{ $k['khoi'] }}_mode_custom" {{ $tuChon ? 'checked' : '' }}>
                <div class="input-group">
                    <input type="text" class="form-control {{ $k['khoi'] }}_range" autocomplete="off"
                        value="{{ $hienNgay($filters[$k['khoi'].'_from'], $filters[$k['khoi'].'_to']) }}"
                        placeholder="Tự chọn" {{ $tuChon ? '' : 'disabled' }}>
                    <span class="input-group-text"><i class="fa-regular fa-calendar"></i></span>
                </div>
            </div>
            <input type="hidden" name="{{ $k['khoi'] }}_from" class="{{ $k['khoi'] }}_from" value="{{ $filters[$k['khoi'].'_from'] }}">
            <input type="hidden" name="{{ $k['khoi'] }}_to" class="{{ $k['khoi'] }}_to" value="{{ $filters[$k['khoi'].'_to'] }}">
        </div>
    </div>
@endforeach

<div id="filterPoint" class="mb-3">
    <div class="inner-modal-in-mobile">
        <span class="title_search d-none d-lg-block">{{ __('message.point') }}</span>
        <div class="row g-2">
            <div class="col-6">
                <input type="number" name="point_from" value="{{ $filters['point_from'] }}"
                    class="form-control point_from" placeholder="{{ __('message.from') }}">
            </div>
            <div class="col-6">
                <input type="number" name="point_to" value="{{ $filters['point_to'] }}"
                    class="form-control point_to" placeholder="{{ __('message.to') }}">
            </div>
        </div>
    </div>
</div>

<div id="filterAge" class="mb-3">
    <div class="inner-modal-in-mobile">
        <span class="title_search d-none d-lg-block">{{ __('message.age') }}</span>
        <div class="row g-2">
            <div class="col-6">
                <input type="number" min="0" name="age_from" value="{{ $filters['age_from'] }}"
                    class="form-control age_from" placeholder="{{ __('message.from') }}">
            </div>
            <div class="col-6">
                <input type="number" min="0" name="age_to" value="{{ $filters['age_to'] }}"
                    class="form-control age_to" placeholder="{{ __('message.to') }}">
            </div>
        </div>
    </div>
</div>

<div id="filterLevelMembership" class="mb-3">
    <div class="inner-modal-in-mobile">
        <span class="title_search d-none d-lg-block">{{ __('message.level-membership') }}</span>
        <select name="rank" class="form-control select_rank">
            <option value="">{{ __('message.all') }}</option>
            @foreach ($ranks as $hang)
                <option value="{{ $hang['id'] }}" {{ $filters['rank'] === (string) $hang['id'] ? 'selected' : '' }}>
                    {{ $hang['name'] }}
                </option>
            @endforeach
        </select>
    </div>
</div>
