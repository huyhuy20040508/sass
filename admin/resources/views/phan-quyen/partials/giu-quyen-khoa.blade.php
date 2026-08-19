{{-- Ô KHOÁ mà ĐANG BẬT: gửi kèm giá trị để lượt Lưu không âm thầm gỡ mất.

     Ô disabled không đi theo form. Không có dòng này thì mở màn Phân quyền của
     một thu ngân từng được cấp quyền khu quản trị rồi bấm Lưu là quyền ấy bay
     mất — mà người bấm không tick gì và cũng không được báo gì.

     Nhận: $khoa, $bat, $maGiu (và $chiDoc thừa hưởng từ ma-tran). --}}
@if(($khoa ?? false) && ($bat ?? false) && ! ($chiDoc ?? false))
    <input type="hidden" name="quyen[]" value="{{ $maGiu }}">
@endif
