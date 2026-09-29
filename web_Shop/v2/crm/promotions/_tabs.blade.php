{{-- Ba tab trong trang của v2 (crm/promotion-program index:321-331): gạch chân
     xanh 2px + chữ đậm cho tab đang mở, không viền. --}}
<style>
    .km-tabs { display: flex; gap: 22px; border-bottom: 1px solid #e5e7eb; margin-bottom: 10px; }
    .km-tabs a { padding: 6px 2px; color: #000; text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; }
    .km-tabs a.active { color: #0162df; font-weight: 700; border-bottom-color: #0162df; }
    .km-tabs a.mo { opacity: .45; cursor: not-allowed; }
</style>
<div class="km-tabs">
    <a href="{{ route('admin.crm.promotions.index') }}" class="{{ request()->routeIs('admin.crm.promotions.index') ? 'active' : '' }}">Chương trình khuyến mại</a>
    <a href="{{ route('admin.crm.promotions.dongGia') }}" class="{{ request()->routeIs('admin.crm.promotions.dongGia') ? 'active' : '' }}">Khuyến mại đồng giá</a>
    <a href="{{ route('admin.crm.promotions.voucher') }}" class="{{ request()->routeIs('admin.crm.promotions.voucher') ? 'active' : '' }}">Voucher/Coupon</a>
</div>
