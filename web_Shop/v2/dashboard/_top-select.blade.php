{{-- Ô "Top N" ở đầu các thẻ của Tổng quan. Nhận: $id, $param, $chon, $ds. --}}
<select class="form-select form-select-sm w-auto db-top" id="{{ $id }}" data-param="{{ $param }}" aria-label="Số dòng">
    @foreach ($ds as $n)
        <option value="{{ $n }}" {{ $chon === $n ? 'selected' : '' }}>Top {{ $n }}</option>
    @endforeach
</select>
