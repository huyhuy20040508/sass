{{--
    Nhãn của một ô cấu hình. Truyền $f (field từ API) và $id (id của ô nhập).

    Tách ra vì có hai kiểu dòng dùng chung nhãn này: ô nhập bình thường (nhãn nằm
    trên ô) và dòng công tắc (nhãn nằm bên trái, công tắc bên phải).

    Chỉ gắn thẻ cho ô KHÔNG công khai: phần lớn ô trên các trang cấu hình đều hiện
    ra cho khách, dán chữ "Công khai" lên từng ô thì hàng chục cái giống hệt nhau,
    không phân biệt được gì — nhãn chỉ có ích khi nó đánh dấu ngoại lệ.
--}}
<label class="set-label" for="{{ $id }}">
    {{ $f['label'] }}
    @if(!empty($f['required']))
        <span class="set-req" title="Bắt buộc">*</span>
    @endif
    @if(empty($f['public']))
        <span class="set-tag" title="Chỉ dùng trong trang quản trị, khách không thấy giá trị này">Nội bộ</span>
    @endif
</label>
