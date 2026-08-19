{{-- Một dòng nhân viên trong cây bên trái. Nhận: $nv, $chon. --}}
@php
    $coTk = (int) ($nv['user_id'] ?? 0) > 0;
@endphp
<a href="{{ route('admin.phan-quyen.index', ['nv' => $nv['id']]) }}"
   class="pq-emp {{ (int) ($chon['id'] ?? 0) === (int) $nv['id'] ? 'is-active' : '' }}"
   data-pq-tim="{{ mb_strtolower($nv['full_name'].' '.($nv['code'] ?? '').' '.($nv['username'] ?? '')) }}">
    <span class="pq-emp-name">{{ $nv['full_name'] }}</span>
    <span class="pq-emp-sub">
        {{ $coTk ? 'Có tài khoản' : 'Chưa có tài khoản' }}
        @if($nv['code'] ?? '') · {{ $nv['code'] }} @endif
    </span>
</a>
