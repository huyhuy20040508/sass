{{--
    Nút hiện/ẩn cho một ô mật khẩu. Truyền $target = id của <input>.

    Gõ mật khẩu mà không xem lại được là nguồn của hầu hết lần "đổi xong không
    đăng nhập được": người dùng gõ nhầm một phím rồi gõ nhầm y hệt ở ô nhắc lại.
--}}
<button type="button" class="prf-eye" data-eye="{{ $target }}" aria-label="Hiện mật khẩu">
    <svg class="prf-eye-on" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>
    </svg>
    <svg class="prf-eye-off" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M10.6 6.2A9.6 9.6 0 0 1 12 6c6 0 9.5 6 9.5 6a17 17 0 0 1-2.6 3.3M6.4 7.9A16.5 16.5 0 0 0 2.5 12S6 18 12 18a9.7 9.7 0 0 0 3.9-.8"/>
        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="m3 3 18 18"/>
    </svg>
</button>
