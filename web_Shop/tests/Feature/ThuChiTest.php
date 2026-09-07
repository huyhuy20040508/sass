<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Màn Quản lý thu chi — sổ phiếu thu / phiếu chi.
 *
 * Bốn thứ bài này gác, đều là chỗ bản v2 cũ làm sai và dễ chép sai theo:
 *
 *   1. Phiếu BỊ KHOÁ không được bày nút Sửa / Xoá. Ba lớp khoá của v2 (phiếu
 *      của người khác, phiếu tự phát sinh từ đơn, phiếu thuộc ca đã đóng) do API
 *      chốt và trả về cờ `locked`; trang chỉ nghe theo cờ ấy. Bày nút rồi báo
 *      lỗi lúc bấm là bẫy người dùng.
 *
 *   2. Bốn ô quỹ in số của API, KHÔNG tự cộng lấy. Bản v2 cộng thêm một lần nữa
 *      khi bỏ trống ngày bắt đầu nên quỹ đầu kỳ nhân đôi.
 *
 *   3. Hai ô ngày luôn có mặt trong query khi lọc. Controller chỉ tự điền tháng
 *      này khi tham số VẮNG MẶT — gửi rỗng và không gửi là hai chuyện khác nhau.
 *
 *   4. Bảng và thẻ điện thoại phải cùng bày một bộ dòng. Dưới 992px v2 giấu hẳn
 *      bảng, nên một chốt chỉ đúng ở bảng là chốt hở đúng nửa số người dùng.
 */
class ThuChiTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-admin',
            'api.refresh_token' => 'refresh-admin',
            'api.user' => ['id' => 7, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly'],
            'api.tenant' => ['code' => 'quochuy', 'name' => 'Tiệm Quốc Huy'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $ds
     * @param  array<string, mixed>  $summary
     */
    protected function fakeApi(array $ds = [], array $summary = []): void
    {
        Http::fake([
            // Bốn ô quỹ nằm CHUNG trong `meta` với phân trang — API không có khoá
            // `summary` riêng ở tầng ngoài cùng.
            '*/admin/thu-chi*' => Http::response([
                'data' => $ds,
                'meta' => array_merge(
                    ['page' => 1, 'page_size' => 10, 'total' => count($ds), 'total_pages' => 1],
                    $summary,
                ),
            ]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /**
     * Gieo cho cửa hàng có HAI chi nhánh.
     *
     * GỌI TRƯỚC fakeApi(). `Http::fake()` GỘP THÊM mẫu chứ không thay bộ cũ, và
     * lượt gọi khớp mẫu ĐĂNG KÝ TRƯỚC là thắng — mà fakeApi() có mẫu `'*'` bắt
     * mọi đường. Gọi sau là mẫu chi nhánh ở đây không bao giờ tới lượt, danh
     * sách về rỗng, và bài kiểm đỏ vì thứ tự chứ không vì code sai.
     *
     * Xoá luôn bộ nhớ static của ChiNhanhDangLam: service nhớ kết quả cho cả
     * request, đổi câu trả lời của API mà không quên bộ nhớ thì nó vẫn trả bản cũ.
     */
    protected function haiChiNhanh(): void
    {
        Http::fake([
            '*/admin/chi-nhanh*' => Http::response(['data' => [
                ['id' => 1, 'name' => 'Chi nhánh 1', 'is_main' => true],
                ['id' => 2, 'name' => 'Chi nhánh 2'],
            ]]),
        ]);
        \App\Services\ChiNhanhDangLam::quenCache();
    }

    protected function phieu(array $ghiDe = []): array
    {
        return array_merge([
            'id' => 1,
            'code' => 'PT00001',
            'type' => 0,
            'category_id' => 3,
            'category_name' => 'Thu khác',
            'amount' => 250000,
            'branch_name' => 'Chi nhánh 1',
            'payer_type' => 'other',
            'payer_id' => 5,
            'payer_name' => 'Khách lẻ',
            'created_by_name' => 'Quản trị',
            'payment_method' => 'cash',
            'payment_method_name' => 'Tiền mặt',
            'note' => 'Thu tiền lẻ',
            'source' => 'manual',
            'created_at' => '2026-09-01 08:30:00',
            'locked' => false,
        ], $ghiDe);
    }

    /**
     * Phiếu bị khoá: không nút Sửa, không nút Xoá — ở CẢ bảng lẫn thẻ điện thoại.
     *
     * Đếm nút chứ không chỉ tìm chuỗi: 'edit-item' trần còn nằm trong đoạn JS bắt
     * sự kiện ở cuối trang, đếm nó là đếm nhầm.
     */
    public function test_phieu_bi_khoa_khong_co_nut_sua_xoa(): void
    {
        $this->fakeApi([
            $this->phieu(['id' => 1, 'code' => 'PT00001']),
            $this->phieu(['id' => 2, 'code' => 'PC00002', 'type' => 1, 'locked' => true, 'locked_reason' => 'Phiếu thuộc ca đã đóng.']),
            $this->phieu(['id' => 3, 'code' => 'PT00003', 'source' => 'order', 'source_code' => 'HD001']),
        ]);

        $html = $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->getContent();

        // Chỉ dòng 1 lập tay và không khoá mới có nút. Nút chỉ nằm ở bảng (thẻ
        // điện thoại bấm cả thẻ để mở tấm trượt), nên đúng 1 nút mỗi loại.
        //
        // Đếm KÈM dấu nháy đóng: 'edit_bt edit-item' trần còn khớp cả
        // 'edit_bt edit-item-canvas' của tấm trượt, đếm nó là đếm thừa một lượt.
        $this->assertSame(1, substr_count($html, 'edit_bt edit-item"'), 'Số nút Sửa không khớp — phiếu bị khoá đang được bày nút.');
        $this->assertSame(1, substr_count($html, 'dele_bt delete-item"'), 'Số nút Xoá không khớp — phiếu bị khoá đang được bày nút.');

        // Lý do khoá phải tới được người dùng, không im lặng giấu nút.
        $this->assertStringContainsString('Phiếu thuộc ca đã đóng.', $html);
        $this->assertStringContainsString('Phiếu tự phát sinh từ chứng từ khác', $html);
    }

    /**
     * Con mắt Xem chi tiết có ở MỌI dòng, kể cả dòng bị khoá — và mã phiếu thôi
     * không còn là liên kết.
     *
     * Hai vế của cùng một quyết định: cửa vào hộp chi tiết chuyển hẳn sang cột
     * Hành động, để mã phiếu là chữ trần bôi đen chép lại được.
     */
    public function test_moi_dong_deu_co_con_mat_xem_va_ma_phieu_la_chu_tran(): void
    {
        $this->fakeApi([
            $this->phieu(['id' => 1, 'code' => 'PT00001']),
            $this->phieu(['id' => 2, 'code' => 'PC00002', 'locked' => true, 'locked_reason' => 'Phiếu thuộc ca đã đóng.']),
            $this->phieu(['id' => 3, 'code' => 'PT00003', 'source' => 'order', 'source_code' => 'HD001']),
        ]);

        $html = $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->getContent();

        // Ba dòng, ba con mắt — khoá hay không cũng xem được.
        $this->assertSame(3, substr_count($html, 'class="detail-item"'), 'Số con mắt không khớp — dòng bị khoá đang mất cửa xem chi tiết.');
        // Mã phiếu không còn bọc trong thẻ <a> nữa.
        $this->assertStringNotContainsString('edit_bt detail-item', $html);
    }

    /** Cả ba dòng hiện ở CẢ hai bản — bảng và thẻ điện thoại. */
    public function test_bang_va_the_dien_thoai_cung_bay_du_dong(): void
    {
        $this->fakeApi([
            $this->phieu(['id' => 1, 'code' => 'PT00001']),
            $this->phieu(['id' => 2, 'code' => 'PC00002', 'type' => 1]),
        ]);

        $html = $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->getContent();

        // Mỗi mã xuất hiện ở data-code của bảng, ô mã của bảng, data-code của thẻ
        // và chữ trong thẻ = 4 lượt. Đếm data-code cho chắc: đúng 2 bản × 1 dòng.
        $this->assertSame(2, substr_count($html, 'data-code="PT00001"'), 'PT00001 không có đủ ở cả bảng lẫn thẻ điện thoại.');
        $this->assertSame(2, substr_count($html, 'data-code="PC00002"'), 'PC00002 không có đủ ở cả bảng lẫn thẻ điện thoại.');
    }

    /**
     * Bốn ô quỹ in đúng số API trả, không cộng thêm lượt nào.
     *
     * Quỹ cuối kỳ ở đây (900.000) CỐ Ý không bằng đầu kỳ + thu − chi
     * (100.000 + 1.000.000 − 250.000 = 850.000): nếu trang tự tính lại thì con số
     * in ra sẽ là 850.000 và bài này đổ.
     */
    public function test_bon_o_quy_in_so_cua_api(): void
    {
        $this->fakeApi([$this->phieu()], [
            'begin_balance' => 100000,
            'total_income' => 1000000,
            'total_expense' => 250000,
            'end_balance' => 900000,
        ]);

        $html = $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->getContent();

        foreach (['100.000', '1.000.000', '250.000', '900.000'] as $so) {
            $this->assertStringContainsString('>'.$so.'</p>', $html, "Ô quỹ thiếu số $so.");
        }
        $this->assertStringNotContainsString('>850.000</p>', $html, 'Trang đang tự cộng lại quỹ cuối kỳ thay vì in số của API.');
    }

    /** Số từ một tỷ trở lên rút gọn — đúng formatVietnameseMoneySmart của v2. */
    public function test_so_tu_mot_ty_rut_gon(): void
    {
        $this->fakeApi([$this->phieu()], ['begin_balance' => 2500000000]);

        $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->assertSee('~ 2,5 tỷ');
    }

    /**
     * Bỏ trống ngày là bỏ trống thật — không tự kéo tháng này về.
     *
     * Controller chỉ điền tháng này khi tham số VẮNG MẶT. Gửi `from_date=` rỗng
     * nghĩa là "tôi cố ý không giới hạn ngày", phải tôn trọng.
     */
    public function test_ngay_gui_rong_thi_khong_tu_dien_thang_nay(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->get(route('admin.thu-chi.index', ['from_date' => '', 'to_date' => '']))
            ->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/thu-chi')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return ($q['from_date'] ?? null) === '' && ($q['to_date'] ?? null) === '';
        });
    }

    /** Không gửi ngày thì lấy tháng này, đúng như v2 tự điền sẵn hai ô. */
    public function test_khong_gui_ngay_thi_lay_thang_nay(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())->get(route('admin.thu-chi.index'))->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/thu-chi')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return ($q['from_date'] ?? null) === date('Y-m-01') && ($q['to_date'] ?? null) === date('Y-m-d');
        });
    }

    /**
     * Khung lọc có ĐÚNG bảy ô của v2, không thừa không thiếu.
     *
     * Ô Chi nhánh chỉ hiện khi cửa hàng có TỪ HAI chi nhánh — xem bài
     * test_o_chi_nhanh_chi_hien_khi_co_tu_hai — nên bài này gieo sẵn hai cái.
     */
    public function test_khung_loc_dung_bay_o_cua_v2(): void
    {
        $this->haiChiNhanh();
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->getContent();

        foreach ([
            'filterReceiptCode', 'filterBranch', 'filterTime', 'filterCreator',
            'filterType', 'filterCategory', 'filterSource',
        ] as $khoi) {
            $this->assertStringContainsString('id="'.$khoi.'"', $html, "Khung lọc thiếu khối $khoi.");
        }

        // Ô KHÔNG được có: v2 không hề dựng ô này (controller v2 có đọc tham số
        // `payment_method` nhưng chẳng ô nào gửi lên — một nhánh chết).
        $this->assertStringNotContainsString('id="filterPaymentMethod"', $html);

        // "Loại thu chi" là ô chọn nhiều như v2, không phải cặp ô tick.
        $this->assertStringContainsString('name="type" multiple', $html, 'Ô Loại thu chi phải là ô chọn nhiều như v2.');
    }

    /**
     * Ô Chi nhánh KHÔNG đẻ ra tham số lọc riêng.
     *
     * Chi nhánh đang làm việc đi qua `chi_nhanh` (middleware ChiNhanhTheoTab) và
     * ApiClient tự đính vào mọi lượt gọi. Thêm một `branch_id` riêng là hai chỗ
     * cùng nói một chuyện — màn hình nói một đằng, hàng vào một nẻo.
     */
    public function test_o_chi_nhanh_di_qua_co_che_tab(): void
    {
        $this->haiChiNhanh();
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->getContent();

        // Ô có mặt nhưng không mang `name` — nó không gửi lên như một ô lọc.
        $this->assertStringContainsString('id="tc-branch"', $html);
        $this->assertStringNotContainsString('name="branch_id"', $html);
        $this->assertStringContainsString('V2.doiChiNhanhTab', $html, 'Ô Chi nhánh phải đi qua V2.doiChiNhanhTab như nút trên thanh đầu trang.');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/thu-chi')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return ! array_key_exists('branch_id', $q);
        });
    }

    /**
     * Cửa hàng MỘT chi nhánh thì không bày ô Chi nhánh.
     *
     * Ô chỉ có đúng một lựa chọn không lọc được gì, mà vẫn ăn nhãn + ô chọn +
     * khoảng cách của cả cột lọc — trong khi cột ấy vốn đã chật.
     *
     * Tách làm HAI hàm chứ không kiểm cả hai cảnh trong một hàm: `Http::fake()`
     * gộp thêm mẫu chứ không thay, nên cảnh sau vẫn ăn mẫu `'*'` của cảnh trước
     * và bài đỏ vì thứ tự giả lập, không phải vì code sai.
     */
    public function test_mot_chi_nhanh_thi_khong_bay_o_chi_nhanh(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->assertDontSee('id="filterBranch"', false);
    }

    /** Từ hai chi nhánh trở lên thì ô Chi nhánh phải có mặt. */
    public function test_tu_hai_chi_nhanh_thi_bay_o_chi_nhanh(): void
    {
        $this->haiChiNhanh();
        $this->fakeApi();

        $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->assertSee('id="filterBranch"', false);
    }

    /** Ô "Loại" có đúng bốn lựa chọn của v2. */
    public function test_o_loai_co_bon_lua_chon_cua_v2(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->assertSee('Bán hàng')
            ->assertSee('Mua hàng')
            ->assertSee('Tự tạo')
            ->assertSee('Trả hàng');
    }

    /** Hộp lập phiếu có đủ tám ô của v2, kể cả ô Đính kèm. */
    public function test_hop_lap_phieu_du_o_cua_v2(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->getContent();

        foreach ([
            'tc-type', 'tc-category-id', 'tc-amount', 'tc-payer-type',
            'tc-payer-id', 'tc-payment-method', 'tc-attachment', 'tc-note',
        ] as $o) {
            $this->assertStringContainsString('id="'.$o.'"', $html, "Hộp lập phiếu thiếu ô $o.");
        }

        // Ô chọn tệp dùng đúng khuôn của v2 — CSS đã có sẵn trong custom.css.
        $this->assertStringContainsString('custom-file-wrapper', $html);
    }

    /**
     * Ô thả xuống trong hộp thoại chạy select2 như phần còn lại của hệ thống.
     *
     * Vỏ v2 chỉ tự gắn cho `.fillter-box select`; hộp thoại thì màn phải tự lo,
     * không thì ô trong hộp hiện ô thả xuống mặc định của trình duyệt — mỗi hệ
     * điều hành vẽ một kiểu và cao thấp khác hẳn mấy ô ngay bên cạnh.
     */
    public function test_o_tha_xuong_trong_hop_dung_select2(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function ganSelect2', $html);
        // `dropdownParent` là bắt buộc: thiếu nó thì bảng thả xuống dựng ở cuối
        // <body>, nằm dưới lớp phủ của modal và bấm không trúng.
        $this->assertStringContainsString('dropdownParent', $html);
        $this->assertStringContainsString('#addIncomeExpense .select2-dropdown', $html);
    }

    /**
     * Ô "Loại đối tượng" bày đúng bốn mục dựng được.
     *
     * v2 có sáu (Quản lý · Quầy bếp · Thu ngân · NV order · NCC · Khác) vì nó là
     * phần mềm nhà hàng. Nhân sự bên này chỉ có hai vai — Quản lý và Thu ngân —
     * nên "Quầy bếp" với "NV order" là hai ô không bao giờ có ai đứng trong đó.
     */
    public function test_o_loai_doi_tuong_theo_vai_co_that(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->getContent();

        foreach (['quan_ly', 'thu_ngan', 'supplier', 'other'] as $ma) {
            $this->assertStringContainsString('value="'.$ma.'"', $html, "Ô Loại đối tượng thiếu mục $ma.");
        }

        foreach (['kitchen', 'order-staff'] as $ma) {
            $this->assertStringNotContainsString('value="'.$ma.'"', $html, "Vai $ma không có bên này, đừng bày ra.");
        }
    }

    /** Người nộp lọc theo vai: chỉ ai mang cửa đó mới vào danh sách. */
    public function test_nguoi_nop_loc_theo_vai(): void
    {
        Http::fake([
            '*/admin/users*' => Http::response(['data' => [
                ['id' => 1, 'full_name' => 'Chị Quản Lý', 'quyen' => ['quan_ly']],
                ['id' => 2, 'full_name' => 'Anh Thu Ngân', 'quyen' => ['thu_ngan']],
            ]]),
            '*' => Http::response(['data' => [], 'meta' => [], 'summary' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->getContent();

        // Vai đi kèm từng người trong danh sách đổ sang JS — thiếu nó thì ô
        // "Người nộp" không lọc được và bày cả cửa hàng cho mọi lựa chọn.
        $this->assertStringContainsString('"quyen":["quan_ly"]', $html);
        $this->assertStringContainsString('"quyen":["thu_ngan"]', $html);
    }

    /** Tệp đính kèm: chỉ nhận ảnh và PDF, không nhận đuôi chạy được. */
    public function test_dinh_kem_chan_tep_la(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->postJson(route('admin.thu-chi.dinhKem'), [
                'file' => \Illuminate\Http\UploadedFile::fake()->create('hack.php', 8, 'text/x-php'),
            ])
            ->assertStatus(422);
    }

    /** Lọc nguồn phát sinh: giá trị lạ bị gạn, giá trị hợp lệ đi qua nguyên vẹn. */
    public function test_loc_nguon_gan_gia_tri_la(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->get(route('admin.thu-chi.index', ['source' => 'order,linh-tinh,purchase']))
            ->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/thu-chi')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return ($q['source'] ?? null) === 'order,purchase';
        });
    }

    /** Số tiền phải lớn hơn 0 — chặn ở máy chủ, không đẩy sang API. */
    public function test_so_tien_khong_duong_bi_chan(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->postJson(route('admin.thu-chi.store'), [
                'type' => 0,
                'amount' => 0,
                'payment_method' => 'cash',
            ])
            ->assertStatus(422);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/admin/thu-chi'));
    }

    /** Lượt hỏng trả về ĐÚNG mã của API, không quy hết về 422. */
    public function test_ma_loi_giu_theo_api(): void
    {
        Http::fake([
            '*/admin/thu-chi/99' => Http::response(['message' => 'Không tìm thấy phiếu.'], 404),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())
            ->deleteJson(route('admin.thu-chi.destroy', 99))
            ->assertStatus(404)
            ->assertJsonPath('message', 'Không tìm thấy phiếu.');
    }

    /** Cột bị tắt qua ?hide= thì cả tiêu đề lẫn ô dữ liệu đều mang class `hide`. */
    public function test_tat_cot_qua_query(): void
    {
        $this->fakeApi([$this->phieu()]);

        $html = $this->withSession($this->phien())
            ->get(route('admin.thu-chi.index', ['hide' => 'note,branch']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('show_note hide', $html);
        $this->assertStringContainsString('show_branch hide', $html);
        // Cột không nằm trong ?hide= thì vẫn hiện.
        $this->assertStringNotContainsString('show_amount hide', $html);
    }

    /** API hỏng thì trang vẫn dựng được, có câu báo và bảng rỗng. */
    public function test_api_hong_van_ra_trang(): void
    {
        Http::fake(['*' => Http::response(['message' => 'API sập'], 500)]);

        $this->withSession($this->phien())->get(route('admin.thu-chi.index'))
            ->assertOk()
            ->assertSee('API sập');
    }
}
