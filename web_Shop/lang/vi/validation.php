<?php

/*
|--------------------------------------------------------------------------
| CÂU LỖI MẶC ĐỊNH CỦA VALIDATOR — TIẾNG VIỆT
|--------------------------------------------------------------------------
|
| Laravel chỉ mang sẵn bộ câu tiếng Anh. Không có tệp này thì mọi luật nào
| controller quên khai câu riêng đều rơi thẳng ra màn hình bằng tiếng Anh:
|
|     "The quyen field is required when co tai khoan is 1."
|
| Đó là chuyện đã xảy ra ở màn Nhân sự — luật `quyen.required_if` có, mà mảng
| $messages thì thiếu đúng khoá ấy, trong khi `username` và `email` cùng luật
| lại có câu Việt. Vá từng chỗ như vậy là chạy theo lỗi mãi mãi: hôm nay hơn
| 600 luật nằm rải khắp các controller, chỉ cần quên một khoá là lộ ra tiếp.
|
| Tệp này là lưới hứng: câu riêng của controller vẫn thắng (nó cụ thể hơn và
| nói đúng nghiệp vụ), còn chỗ nào quên thì rơi vào đây và vẫn ra tiếng Việt.
|
| Khoá nào thiếu ở đây thì Laravel lùi tiếp về `fallback_locale` (en), nên
| thêm dần được, không cần dịch đủ một trăm luật ngay.
|
| Ngôn ngữ chạy được đặt ở EnsureAdminAuthenticated (app()->setLocale('vi')).
|
*/

return [
    'accepted' => 'Phải đồng ý :attribute.',
    'after' => 'Ô :attribute phải là ngày sau :date.',
    'after_or_equal' => 'Ô :attribute phải là ngày :date trở đi.',
    'alpha_dash' => 'Ô :attribute chỉ gồm chữ, số, gạch ngang và gạch dưới.',
    'array' => 'Ô :attribute phải là một danh sách.',
    'before' => 'Ô :attribute phải là ngày trước :date.',
    'before_or_equal' => 'Ô :attribute phải là ngày :date trở về trước.',
    'between' => [
        'array' => 'Ô :attribute phải chọn từ :min đến :max mục.',
        'file' => 'Ô :attribute phải nặng từ :min đến :max KB.',
        'numeric' => 'Ô :attribute phải trong khoảng :min đến :max.',
        'string' => 'Ô :attribute phải dài từ :min đến :max ký tự.',
    ],
    'boolean' => 'Ô :attribute chỉ nhận có hoặc không.',
    'confirmed' => 'Ô :attribute nhập lại không khớp.',
    'date' => 'Ô :attribute không phải là một ngày hợp lệ.',
    'date_format' => 'Ô :attribute không đúng khuôn :format.',
    'different' => 'Ô :attribute phải khác :other.',
    'digits' => 'Ô :attribute phải gồm :digits chữ số.',
    'digits_between' => 'Ô :attribute phải gồm :min đến :max chữ số.',
    'distinct' => 'Ô :attribute bị trùng.',
    'email' => 'Ô :attribute không đúng định dạng email.',
    'exists' => 'Ô :attribute không có trong danh sách.',
    'file' => 'Ô :attribute phải là một tệp.',
    'gt' => [
        'array' => 'Ô :attribute phải có nhiều hơn :value mục.',
        'file' => 'Ô :attribute phải nặng hơn :value KB.',
        'numeric' => 'Ô :attribute phải lớn hơn :value.',
        'string' => 'Ô :attribute phải dài hơn :value ký tự.',
    ],
    'gte' => [
        'array' => 'Ô :attribute phải có ít nhất :value mục.',
        'file' => 'Ô :attribute phải nặng ít nhất :value KB.',
        'numeric' => 'Ô :attribute phải từ :value trở lên.',
        'string' => 'Ô :attribute phải dài ít nhất :value ký tự.',
    ],
    'image' => 'Ô :attribute phải là tệp ảnh.',
    'in' => 'Ô :attribute không hợp lệ.',
    'integer' => 'Ô :attribute phải là số nguyên.',
    'lt' => [
        'array' => 'Ô :attribute phải có ít hơn :value mục.',
        'file' => 'Ô :attribute phải nhẹ hơn :value KB.',
        'numeric' => 'Ô :attribute phải nhỏ hơn :value.',
        'string' => 'Ô :attribute phải ngắn hơn :value ký tự.',
    ],
    'lte' => [
        'array' => 'Ô :attribute nhiều nhất :value mục.',
        'file' => 'Ô :attribute nặng nhất :value KB.',
        'numeric' => 'Ô :attribute nhiều nhất là :value.',
        'string' => 'Ô :attribute dài nhất :value ký tự.',
    ],
    'max' => [
        'array' => 'Ô :attribute chọn nhiều nhất :max mục.',
        'file' => 'Ô :attribute nặng nhất :max KB.',
        'numeric' => 'Ô :attribute nhiều nhất là :max.',
        'string' => 'Ô :attribute dài nhất :max ký tự.',
    ],
    'mimes' => 'Ô :attribute phải là tệp :values.',
    'mimetypes' => 'Ô :attribute phải là tệp :values.',
    'min' => [
        'array' => 'Ô :attribute phải chọn ít nhất :min mục.',
        'file' => 'Ô :attribute phải nặng ít nhất :min KB.',
        'numeric' => 'Ô :attribute phải từ :min trở lên.',
        'string' => 'Ô :attribute phải dài ít nhất :min ký tự.',
    ],
    'not_in' => 'Ô :attribute không hợp lệ.',
    'numeric' => 'Ô :attribute phải là một con số.',
    'regex' => 'Ô :attribute không đúng định dạng.',
    'required' => 'Chưa nhập :attribute.',
    'required_if' => 'Chưa nhập :attribute.',
    'required_unless' => 'Chưa nhập :attribute.',
    'required_with' => 'Chưa nhập :attribute.',
    'required_with_all' => 'Chưa nhập :attribute.',
    'required_without' => 'Chưa nhập :attribute.',
    'required_without_all' => 'Chưa nhập :attribute.',
    'same' => 'Ô :attribute phải trùng với :other.',
    'size' => [
        'array' => 'Ô :attribute phải có đúng :size mục.',
        'file' => 'Ô :attribute phải nặng đúng :size KB.',
        'numeric' => 'Ô :attribute phải bằng :size.',
        'string' => 'Ô :attribute phải dài đúng :size ký tự.',
    ],
    'string' => 'Ô :attribute phải là chữ.',
    'unique' => 'Ô :attribute đã có người dùng.',
    'uploaded' => 'Không tải :attribute lên được.',
    'url' => 'Ô :attribute phải là một địa chỉ web hợp lệ.',

    /*
    |--------------------------------------------------------------------------
    | Tên ô hiển thị trong câu lỗi
    |--------------------------------------------------------------------------
    |
    | Không có phần này thì `:attribute` in ra tên cột thô ("co tai khoan",
    | "id number") — đọc lên là ngôn ngữ của database, không phải của người bán
    | hàng. Chỉ khai những ô người dùng thật sự nhìn thấy trên form.
    |
    */
    'attributes' => [
        'address' => 'địa chỉ',
        'allowance' => 'phụ cấp',
        'avatar' => 'ảnh đại diện',
        'birth_date' => 'ngày sinh',
        'code' => 'mã',
        'commission_rate' => 'hoa hồng',
        'email' => 'email',
        'full_name' => 'họ tên',
        'hired_on' => 'ngày vào làm',
        'id_number' => 'CCCD',
        'items' => 'danh sách hàng',
        'keyword' => 'từ khoá',
        'mat_khau_moi' => 'mật khẩu mới',
        'name' => 'tên',
        'note' => 'ghi chú',
        'password' => 'mật khẩu',
        'phone' => 'số điện thoại',
        'quyen' => 'nhóm quyền',
        'salary' => 'mức lương',
        'shop_id' => 'chi nhánh',
        'shop_ids' => 'chi nhánh',
        'status' => 'trạng thái',
        'username' => 'tên đăng nhập',
        'work_shift' => 'ca làm việc',
    ],
];
