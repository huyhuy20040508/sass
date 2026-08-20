<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hàng hóa → Danh sách hàng hóa, khuôn BÁN LẺ.
 *
 * API giả lập (Http::fake) nên bài này chạy được cả khi không có Go API. Phần
 * nghiệp vụ thật (ghép tên biến thể, chặn tổ hợp sai, bất biến "luôn có ít nhất
 * một dòng biến thể") do api/internal/service + api/internal/apitest kiểm; bài
 * này gác phần của trang quản trị: bảng bày đúng cột nào, và payload gửi đi có
 * đúng hình dạng API cần không.
 */
class HangHoaBanLeTest extends TestCase
{
    protected function phienQuanTri(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    protected function fakeApi(): void
    {
        Http::fake([
            // Danh sách hàng hóa — một mặt hàng nhiều biến thể, đủ trường bán lẻ.
            '*/products?*' => Http::response([
                'data' => [[
                    'id' => 7,
                    'sku' => 'IP15-0001',
                    'name' => 'iPhone 15',
                    'category_id' => 3,
                    'category' => ['id' => 3, 'name' => 'Điện thoại'],
                    'unit' => ['id' => 2, 'code' => 'CAI', 'name' => 'Cái'],
                    'location' => ['id' => 5, 'code' => 'VT001', 'name' => 'Kệ A'],
                    'vat' => 10,
                    'base_price' => 22000000,
                    'sale_price' => null,
                    'cost_price' => 19500000,
                    // Có gán chi nhánh -> bảng in tên; không gán thì in "Mọi chi nhánh".
                    'shops' => [['id' => 1, 'name' => 'Chi nhánh trung tâm']],
                    'tags' => [['id' => 4, 'name' => 'Bán chạy nhất']],
                    'is_multi_variant' => true,
                    'status' => 'active',
                    'is_active' => true,
                    'variants' => [
                        // Kèm tổ hợp thuộc tính như API thật trả về: thiếu nó thì hai
                        // dòng cùng khoá rỗng và hộp thoại báo "tổ hợp bị trùng".
                        ['id' => 11, 'sku' => 'IP15-0001-128GB-DEN', 'name' => '128GB · Đen', 'stock_quantity' => 4, 'is_active' => true,
                            'attributes' => [['attribute_id' => 1, 'value_id' => 11]]],
                        ['id' => 12, 'sku' => 'IP15-0001-256GB-DEN', 'name' => '256GB · Đen', 'stock_quantity' => 0, 'is_active' => true,
                            'attributes' => [['attribute_id' => 1, 'value_id' => 12]]],
                    ],
                ]],
                'meta' => ['page' => 1, 'page_size' => 20, 'total' => 1, 'total_pages' => 1],
            ]),
            '*/admin/thue*' => Http::response(['data' => [
                ['id' => 1, 'loai' => 'mac-dinh', 'ten' => 'Thuế mặc định', 'muc' => [0, 8, 10], 'is_active' => true],
            ]]),
            '*/admin/don-vi-tinh*' => Http::response(['data' => [
                ['id' => 2, 'code' => 'CAI', 'name' => 'Cái', 'is_active' => true],
            ]]),
            '*/admin/thuoc-tinh*' => Http::response(['data' => [
                [
                    'id' => 1, 'code' => 'DUNGLUONG', 'name' => 'Dung lượng', 'is_active' => true, 'raw_material' => false,
                    'values' => [['id' => 11, 'code' => 'DL01', 'name' => '128GB'], ['id' => 12, 'code' => 'DL02', 'name' => '256GB']],
                ],
            ]]),
            '*/admin/vi-tri*' => Http::response(['data' => [
                ['id' => 5, 'code' => 'VT001', 'name' => 'Kệ A', 'is_active' => true],
            ]]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [
                ['id' => 1, 'code' => 'CN01', 'name' => 'Chi nhánh trung tâm', 'is_active' => true],
                ['id' => 2, 'code' => 'CN02', 'name' => 'Chi nhánh Quận 7', 'is_active' => true],
            ]]),
            '*/admin/the-hang-hoa*' => Http::response(['data' => [
                ['id' => 4, 'name' => 'Bán chạy nhất'],
                ['id' => 5, 'name' => 'Món mới'],
            ]]),
            // Cây nhóm hàng: hai nhóm gốc cố định, mỗi nhánh một nhóm lá.
            '*/categories*' => Http::response(['data' => [
                ['id' => 1, 'parent_id' => null, 'slug' => 'hang-ban', 'name' => 'Hàng bán', 'vat' => 8, 'is_active' => true],
                ['id' => 3, 'parent_id' => 1, 'slug' => 'NH0003', 'name' => 'Điện thoại', 'vat' => 10, 'is_active' => true],
                ['id' => 2, 'parent_id' => null, 'slug' => 'hang-hoa-khac', 'name' => 'Hàng hóa khác', 'vat' => 0, 'is_active' => true],
                ['id' => 4, 'parent_id' => 2, 'slug' => 'NH0004', 'name' => 'Vật tư', 'vat' => 0, 'is_active' => true],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /**
     * Bảng bày ĐÚNG bộ cột của bản cũ v2 — không thừa, không thiếu.
     *
     * v2 có 12 cột; khuôn bán lẻ bỏ "Loại hàng hóa" (không còn hàng bán / nguyên
     * vật liệu) và thay bằng ĐVT, còn lại giữ nguyên.
     */
    public function test_bang_dung_bo_cot_v2(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/products');

        $res->assertOk();
        $res->assertSee('Danh sách hàng hóa', false);

        // Có đủ.
        $res->assertSee('>STT</th>', false);
        $res->assertSee('>Mã hàng</th>', false);
        $res->assertSee('Tên hàng hóa', false);
        $res->assertSee('Nhóm hàng hóa', false);
        $res->assertSee('>VAT</th>', false);
        $res->assertSee('>ĐVT</th>', false);
        $res->assertSee('Giá bán', false);
        $res->assertSee('>Chi nhánh</th>', false);
        $res->assertSee('>Trạng thái</th>', false);

        // Không thừa: sáu cột v2 không có đã bỏ hẳn khỏi bảng.
        $res->assertDontSee('prd-c-img', false);
        $res->assertDontSee('prd-c-code', false);
        $res->assertDontSee('prd-c-loc', false);
        $res->assertDontSee('prd-c-wsale', false);
        $res->assertDontSee('prd-c-norm', false);
        $res->assertDontSee('prd-c-stock', false);
        $res->assertDontSee('prd-c-feat', false);

        // Khuôn áo bóng đá đã đi hẳn.
        $res->assertDontSee('Loại áo', false);
        $res->assertDontSee('Đội bóng', false);
    }

    /**
     * Ba tiêu đề cột bấm được để sắp xếp — v2 sắp xếp bằng cách này chứ không có
     * ô chọn "sắp xếp theo".
     */
    public function test_tieu_de_cot_bam_duoc_de_sap_xep(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        // Bấm lần đầu là tăng dần.
        $this->assertStringContainsString('sort=name_asc', $html);
        $this->assertStringContainsString('sort=group_asc', $html);
        $this->assertStringContainsString('sort=price_asc', $html);
        // Không còn ô chọn sắp xếp trong bộ lọc.
        $this->assertStringNotContainsString('title="Sắp xếp"', $html);
    }

    /** Bấm lại cột đang sắp thì đảo chiều, không sắp lại từ đầu. */
    public function test_bam_lai_cot_dang_sap_thi_dao_chieu(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products?sort=name_asc')->getContent();

        $this->assertStringContainsString('sort=name_desc', $html);
    }

    /** Dữ liệu bán lẻ của từng dòng hiện ra đúng: ĐVT, VAT, chi nhánh, số biến thể. */
    public function test_dong_hien_du_lieu_ban_le(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/products');

        $res->assertOk();
        $res->assertSee('IP15-0001', false);
        $res->assertSee('Cái', false);      // đơn vị tính
        $res->assertSee('10%', false);      // mức thuế
        $res->assertSee('Chi nhánh trung tâm', false);   // chi nhánh quản lý
        $res->assertSee('2 biến thể', false);
    }

    /**
     * Lượt Lưu gửi lên đúng hình dạng API cần: tổ hợp thuộc tính đi kèm từng
     * biến thể, và cờ nhiều biến thể suy từ chính bảng đó chứ không hỏi lại.
     */
    public function test_luu_gui_to_hop_thuoc_tinh(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->post('/admin/products', [
            'name' => 'iPhone 15',
            'sku' => 'IP15-0001',
            'category_id' => 3,
            'unit_id' => 2,
            'location_id' => 5,
            'vat' => 10,
            'base_price' => 22000000,
            'variants_loaded' => 1,
            'variants' => [
                ['id' => 0, 'name' => '128GB', 'sku' => '', 'barcode' => '', 'price' => '', 'cost_price' => '',
                    'attributes' => [['attribute_id' => 1, 'value_id' => 11]]],
                ['id' => 0, 'name' => '256GB', 'sku' => '', 'barcode' => '', 'price' => '', 'cost_price' => '',
                    'attributes' => [['attribute_id' => 1, 'value_id' => 12]]],
            ],
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/products') || $request->method() !== 'POST') {
                return false;
            }
            $d = $request->data();

            return ($d['unit_id'] ?? null) === 2
                && ($d['vat'] ?? null) === 10
                && ($d['is_multi_variant'] ?? null) === true
                && count($d['variants'] ?? []) === 2
                && ($d['variants'][0]['attributes'][0]['value_id'] ?? null) === 11;
        });
    }

    /**
     * Hàng đơn: bảng chỉ có một dòng không tổ hợp. Dòng ấy PHẢI được gửi lên —
     * bỏ đi là mặt hàng không có biến thể nào, tức không nhập kho và không bán
     * được.
     */
    public function test_hang_don_van_gui_dong_mac_dinh(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->post('/admin/products', [
            'name' => 'Cáp sạc Type-C',
            'sku' => 'CSTC-0001',
            'category_id' => 3,
            'base_price' => 120000,
            'variants_loaded' => 1,
            'variants' => [
                ['id' => 0, 'name' => '', 'sku' => '', 'barcode' => '', 'price' => '', 'cost_price' => '', 'attributes' => []],
            ],
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/products') || $request->method() !== 'POST') {
                return false;
            }
            $d = $request->data();

            return count($d['variants'] ?? []) === 1
                && ($d['variants'][0]['name'] ?? null) === ''
                && ($d['variants'][0]['attributes'] ?? null) === []
                && ($d['is_multi_variant'] ?? null) === false;
        });
    }

    /**
     * Quy tắc chọn nhóm hàng của bản cũ v2: chỉ NHÓM LÁ dưới nhánh "Hàng bán"
     * mới gắn được mặt hàng.
     *
     * Ô chọn dựng bằng JS từ hằng số CATEGORIES, nên bài này canh đúng ba cờ mà
     * hằng số ấy mang theo — sai cờ là ô chọn sai.
     */
    public function test_cay_nhom_hang_mang_co_la_va_hang_ban(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        // Nhóm gốc "Hàng bán": thuộc nhánh bán được nhưng KHÔNG phải nhóm lá.
        $this->assertStringContainsString('{"id":1,"name":"H\u00e0ng b\u00e1n","level":0,"leaf":false,"sellable":true,"vat":8}', $html);
        // Nhóm lá dưới "Hàng bán": gắn hàng được, và mang mức thuế riêng của nó.
        $this->assertStringContainsString('"name":"\u0110i\u1ec7n tho\u1ea1i","level":1,"leaf":true,"sellable":true,"vat":10', $html);
        // Nhánh "Hàng hóa khác": là lá nhưng KHÔNG bán, ô chọn sẽ bỏ qua.
        $this->assertStringContainsString('"name":"V\u1eadt t\u01b0","level":1,"leaf":true,"sellable":false', $html);
    }

    /** Hộp thoại chỉ còn MỘT ảnh đại diện — bản cũ v2 không có thư viện ảnh. */
    public function test_hop_thoai_chi_co_mot_anh(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        $this->assertStringContainsString('Ảnh đại diện', $html);
        $this->assertStringNotContainsString('Thư viện ảnh', $html);
        $this->assertStringNotContainsString('mGalleryPick', $html);
        // Không quản lý thư viện ảnh thì cũng không được gửi khoá images lên API:
        // mảng rỗng ở đó nghĩa là "xoá sạch ảnh của mặt hàng".
        $this->assertStringNotContainsString('images_loaded', $html);
    }

    /**
     * Không khai thuế ở form thì gửi null, để API lấy mức MẶC ĐỊNH CỦA NHÓM.
     *
     * Gửi 0 ở đây là ghi đè thành "0%" — mất hẳn quy tắc thuế đi theo nhóm.
     */
    public function test_khong_khai_thue_thi_gui_null(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->post('/admin/products', [
            'name' => 'Ốp lưng silicon',
            'sku' => 'OLS-0001',
            'category_id' => 3,
            'base_price' => 120000,
            'variants_loaded' => 1,
            'variants' => [
                ['id' => 0, 'name' => '', 'sku' => '', 'barcode' => '', 'price' => '', 'cost_price' => '', 'attributes' => []],
            ],
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/products') || $request->method() !== 'POST') {
                return false;
            }

            return array_key_exists('vat', $request->data()) && $request->data()['vat'] === null;
        });
    }

    /**
     * Hộp thoại dựng theo đúng khuôn bản cũ v2: HAI tab (Chi tiết · Thuộc tính),
     * tab Chi tiết chia hai cột — trái là ảnh + công tắc, phải là lưới ô nhập.
     *
     * Không còn kiểu bốn tab trình hướng dẫn (Thông tin / Giá / Hình ảnh / SEO).
     */
    public function test_hop_thoai_theo_khuon_v2(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        // Đúng hai tab.
        $this->assertStringContainsString('data-tab="info" role="tab">Chi tiết<', $html);
        $this->assertStringContainsString('data-tab="attr" role="tab">Thuộc tính<', $html);
        $this->assertStringNotContainsString('data-tab="media"', $html);
        $this->assertStringNotContainsString('data-tab="publish"', $html);

        // Hai cột của tab Chi tiết, cột phải là lưới BỐN ô mỗi hàng.
        $this->assertStringContainsString('class="prd-two"', $html);
        $this->assertStringContainsString('class="prd-side"', $html);
        $this->assertStringContainsString('class="prd-body"', $html);
        $this->assertStringContainsString('class="prd-grid4"', $html);
        // Cột trái: nhãn ảnh, nút Tải lên, khung có viền bao công tắc.
        $this->assertStringContainsString('prd-side-title">Hình ảnh<', $html);
        $this->assertStringContainsString('id="mImgPick">Tải lên<', $html);
        $this->assertStringContainsString('class="prd-side-box"', $html);
        // Chân hộp thoại: Đóng / Xác nhận.
        $this->assertStringContainsString('id="prdModalSave">Xác nhận<', $html);

        // Ba ô của v2 mà bản trước chưa có.
        $this->assertStringContainsString('id="mBarcode"', $html);        // mã vạch ở tab Chi tiết
        $this->assertStringContainsString('id="mPriceAfterTax"', $html);  // giá sau thuế
        $this->assertStringContainsString('id="mVarWrap"', $html);        // bảng biến thể tách riêng
    }

    /**
     * Hai mũi tên đổi thứ tự ở cột Thao tác, đúng như bản cũ.
     *
     * Chúng chỉ bấm được khi danh sách đang ở thứ tự tự xếp — đang lọc hay đang
     * sắp theo cột thì "lên một bậc" không còn nghĩa gì.
     */
    public function test_hai_mui_ten_doi_thu_tu(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();
        $this->assertStringContainsString('data-move="up"', $html);
        $this->assertStringContainsString('data-move="down"', $html);
        // Không lọc gì -> bấm được.
        $this->assertStringNotContainsString('prd-move is-off', $html);

        // Đang lọc -> khoá lại.
        $locHtml = $this->withSession($this->phienQuanTri())->get('/admin/products?status=active')->getContent();
        $this->assertStringContainsString('prd-move is-off', $locHtml);
    }

    /** Bấm mũi tên gửi ĐÚNG một trường `huong` tới endpoint riêng. */
    public function test_bam_mui_ten_gui_dung_huong(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->put('/admin/products/7/sort', ['huong' => 'down']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/products/7/sort') || $request->method() !== 'PUT') {
                return false;
            }

            return $request->data() === ['huong' => 'down'];
        });
    }

    /**
     * "Nổi bật" đã bỏ hẳn khỏi màn hàng hoá — bản cũ không có.
     *
     * Quan trọng nhất là lượt Lưu KHÔNG gửi is_featured: gửi false ở đó là mỗi
     * lần sửa giá lại gỡ mặt hàng khỏi khối "Xu hướng" ngoài trang chủ.
     */
    public function test_khong_con_noi_bat(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();
        $this->assertStringNotContainsString('Nổi bật', $html);
        $this->assertStringNotContainsString('mFeatured', $html);

        $this->withSession($this->phienQuanTri())->post('/admin/products', [
            'name' => 'Cáp sạc',
            'sku' => 'CS-0001',
            'category_id' => 3,
            'base_price' => 100000,
            'variants_loaded' => 1,
            'variants' => [['id' => 0, 'name' => '', 'sku' => '', 'barcode' => '', 'price' => '', 'cost_price' => '', 'attributes' => []]],
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/products') || $request->method() !== 'POST') {
                return false;
            }

            return ! array_key_exists('is_featured', $request->data());
        });
    }

    /**
     * Khối "Quy đổi đơn vị hàng hóa" ở chân hộp thoại — bản cũ có, bản mình
     * trước đó thiếu hẳn.
     */
    public function test_khoi_quy_doi_don_vi(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        $this->assertStringContainsString('Quy đổi đơn vị hàng hóa', $html);
        $this->assertStringContainsString('id="mConvAdd"', $html);   // nút Thêm
        $this->assertStringContainsString('id="mConvClear"', $html); // nút xoá hết
        $this->assertStringContainsString('id="mConvRows"', $html);
        $this->assertStringContainsString('>ĐV quy đổi<', $html);
    }

    /** Lượt Lưu gửi các dòng quy đổi lên đúng hình dạng API cần. */
    public function test_luu_gui_quy_doi_don_vi(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->post('/admin/products', [
            'name' => 'Nước suối',
            'sku' => 'NS-0001',
            'category_id' => 3,
            'unit_id' => 2,
            'base_price' => 5000,
            'variants_loaded' => 1,
            'variants' => [['id' => 0, 'name' => '', 'sku' => '', 'barcode' => '', 'price' => '', 'cost_price' => '', 'attributes' => []]],
            'conversions_loaded' => 1,
            'unit_conversions' => [
                ['unit_id' => 9, 'quantity' => 24],
            ],
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/products') || $request->method() !== 'POST') {
                return false;
            }
            $d = $request->data();

            return ($d['unit_conversions'] ?? null) === [['unit_id' => 9, 'quantity' => 24.0]];
        });
    }

    /**
     * Nhóm hàng hóa và Trạng thái đều CHỌN ĐƯỢC NHIỀU — bản cũ dùng ô chọn
     * nhiều và một cụm ô tick, bản mình trước đó chỉ chọn được một.
     */
    public function test_loc_chon_duoc_nhieu(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())
            ->get('/admin/products?category_ids=3&statuses=active,hidden')->getContent();

        $this->assertStringContainsString('name="category_ids[]"', $html);
        $this->assertStringContainsString('name="statuses[]"', $html);
        // Nút hiện số điều kiện đang chọn.
        $this->assertStringContainsString('1 nhóm hàng hóa', $html);
        $this->assertStringContainsString('2 trạng thái', $html);

        // Và query đẩy sang API đúng dạng danh sách.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/products?')) {
                return false;
            }

            return str_contains($request->url(), 'category_ids=3')
                && str_contains(urldecode($request->url()), 'statuses=active,hidden');
        });
    }

    /** Bộ số dòng mỗi trang và tên hai nút lấy đúng của bản cũ. */
    public function test_so_dong_moi_trang_va_ten_nut(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        // Bản cũ có mức 40 — bản mình trước đó nhảy từ 30 sang 50. Ô chọn số dòng
        // dựng đường dẫn cho từng mức nên canh trên chính query đó.
        $this->assertStringContainsString('per_page=40', $html);
        $this->assertStringContainsString('Tạo mới', $html);
        $this->assertStringContainsString('Nâng cao', $html);
        $this->assertStringNotContainsString('Tiện ích', $html);
    }

    /** Cột trái hộp thoại có ĐỦ BỐN công tắc, đúng thứ tự bản cũ. */
    public function test_cot_trai_du_bon_cong_tac(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        foreach (['Trạng thái', 'In tem', 'Trừ kho', 'Số seri/IMEI'] as $nhan) {
            $this->assertStringContainsString($nhan, $html);
        }
        foreach (['id="mActive"', 'id="mPrintLabel"', 'id="mStockDeducted"', 'id="mSerial"'] as $id) {
            $this->assertStringContainsString($id, $html);
        }
    }

    /**
     * Lượt Lưu gửi CỜ BẬT/TẮT chứ không gửi `status`.
     *
     * Công tắc chỉ có hai nấc mà trạng thái có ba mức: gửi status thì mặt hàng
     * đang "ngừng kinh doanh" bị hạ xuống "tạm ẩn" chỉ vì ai đó mở hộp thoại ra
     * sửa giá. Gửi cờ thì máy chủ giữ nguyên mức ấy.
     */
    public function test_luu_gui_co_bat_tat_khong_gui_status(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->post('/admin/products', [
            'name' => 'Dịch vụ dán màn hình',
            'sku' => 'DV-0001',
            'category_id' => 3,
            'base_price' => 50000,
            'is_active' => 1,
            'print_label' => 0,
            // Hàng dịch vụ: bán ra không trừ kho.
            'is_stock_deducted' => 0,
            'is_serial' => 0,
            'variants_loaded' => 1,
            'variants' => [['id' => 0, 'name' => '', 'sku' => '', 'barcode' => '', 'price' => '', 'cost_price' => '', 'attributes' => []]],
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/products') || $request->method() !== 'POST') {
                return false;
            }
            $d = $request->data();

            return ! array_key_exists('status', $d)
                && ($d['is_active'] ?? null) === true
                && ($d['print_label'] ?? null) === false
                && ($d['is_stock_deducted'] ?? null) === false
                && ($d['is_serial'] ?? null) === false;
        });
    }

    /** Trạng thái ngoài bảng là CÔNG TẮC: bật là bán, tắt là không. */
    public function test_trang_thai_ngoai_bang_la_cong_tac(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        // Mặt hàng giả lập đang bán nên công tắc phải bật sẵn.
        $this->assertStringContainsString('class="prd-switch on"', $html);
        $this->assertStringContainsString('data-status="7"', $html);
        // Không còn chip ba mức kèm danh sách chọn.
        $this->assertStringNotContainsString('prd-statuspop', $html);
    }

    /**
     * Gạt công tắc gửi CỜ bật/tắt sang API, không tự quy ra "tạm ẩn".
     *
     * Quy ra ở màn quản trị thì mặt hàng đang NGỪNG KINH DOANH bị hạ cấp xuống
     * tạm ẩn chỉ vì ai đó gạt công tắc; để API quyết thì nó còn biết mặt hàng
     * đang ở mức nào mà giữ nguyên.
     */
    public function test_gat_cong_tac_gui_co_khong_gui_status(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())
            ->put('/admin/products/7/toggle-status', ['is_active' => 0]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/products/7/status')) {
                return false;
            }
            $d = $request->data();

            return ! array_key_exists('status', $d) && ($d['is_active'] ?? null) === false;
        });
    }

    /**
     * Ba ô cũ đã đi hẳn khỏi hộp thoại: định mức tồn, giá bán sỉ, giá khuyến mãi.
     *
     * Giá khuyến mãi là ca đáng canh nhất: cột sale_price VẪN CÒN trong database
     * và vẫn là giá bán ra ở luồng đặt đơn. Hộp thoại mà lỡ gửi ô rỗng lên thì
     * mỗi lượt sửa tên hàng là gỡ mất chương trình giảm giá đang chạy.
     */
    public function test_bo_dinh_muc_gia_si_gia_khuyen_mai(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        foreach (['mMinStock', 'mMaxStock', 'mWholesalePrice', 'mSalePrice'] as $id) {
            $this->assertStringNotContainsString($id, $html);
        }
        $this->assertStringNotContainsString('Giá bán sỉ', $html);
        $this->assertStringNotContainsString('Giá khuyến mãi', $html);
    }

    /** Hộp thoại có ô chọn Chi nhánh và ô chọn Thẻ, nạp sẵn nguồn để tick. */
    public function test_hop_thoai_co_o_chi_nhanh_va_the(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        $this->assertStringContainsString('data-pick="shops"', $html);
        $this->assertStringContainsString('data-pick="tags"', $html);
        // Không tick chi nhánh nào = mọi chi nhánh, nói thẳng ra chứ không để trống.
        $this->assertStringContainsString('Mọi chi nhánh', $html);
        // Nguồn để tick: chi nhánh đang hoạt động và thẻ đang có. Hai danh sách
        // này đi vào trang dưới dạng JSON nên tiếng Việt nằm ở dạng thoát unicode
        // — so bằng chính json_encode thay vì gõ tay chuỗi đã thoát.
        $this->assertStringContainsString(json_encode('Chi nhánh Quận 7'), $html);
        $this->assertStringContainsString(json_encode('Món mới'), $html);
    }

    /**
     * Lượt Lưu gửi chi nhánh bằng ID và thẻ bằng TÊN, kèm cờ *_loaded.
     *
     * Cờ ấy là thứ phân biệt "người dùng gỡ hết" với "màn hình không dựng được ô
     * đó" — thiếu nó thì một lượt Lưu từ màn hình lỗi sẽ xoá sạch phần đã gán.
     */
    public function test_luu_gui_chi_nhanh_va_the(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->post('/admin/products', [
            'name' => 'Nồi lẩu điện',
            'sku' => 'NLD-0001',
            'category_id' => 3,
            'base_price' => 850000,
            'shops_loaded' => 1,
            'shop_ids' => [1, 2, 2],
            'tags_loaded' => 1,
            'tags' => ['Bán chạy nhất', ' Món mới ', 'món mới'],
            'variants_loaded' => 1,
            'variants' => [['id' => 0, 'name' => '', 'sku' => '', 'barcode' => '', 'price' => '', 'cost_price' => '', 'attributes' => []]],
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/products') || $request->method() !== 'POST') {
                return false;
            }
            $d = $request->data();

            return ($d['shop_ids'] ?? null) === [1, 2]                       // bỏ trùng
                && ($d['tags'] ?? null) === ['Bán chạy nhất', 'Món mới']     // trim + bỏ trùng hoa thường
                && ! array_key_exists('sale_price', $d)
                && ! array_key_exists('wholesale_price', $d)
                && ! array_key_exists('min_stock', $d);
        });
    }

    /**
     * Không dựng được ô chi nhánh/thẻ thì KHÔNG gửi khoá ấy lên.
     *
     * Gửi mảng rỗng nghĩa là "gỡ hết" — API xoá sạch phần đã gán. Lượt nhập Excel
     * và mọi đường gọi không có hai ô này phải im lặng đi qua.
     */
    public function test_khong_dung_duoc_o_thi_khong_gui_khoa(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->post('/admin/products', [
            'name' => 'Chảo chống dính',
            'sku' => 'CCD-0001',
            'category_id' => 3,
            'base_price' => 250000,
            'variants_loaded' => 1,
            'variants' => [['id' => 0, 'name' => '', 'sku' => '', 'barcode' => '', 'price' => '', 'cost_price' => '', 'attributes' => []]],
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/products') || $request->method() !== 'POST') {
                return false;
            }
            $d = $request->data();

            return ! array_key_exists('shop_ids', $d) && ! array_key_exists('tags', $d);
        });
    }

    /**
     * Mặt hàng MỚI phải có sẵn dòng biến thể mặc định trong hộp thoại.
     *
     * Không có nó thì lượt Lưu tự chặn mình ngay tại chỗ ("Bảng biến thể đang
     * trống") và KHÔNG thêm được mặt hàng nào — kể cả khi mọi ô khác đã điền
     * đúng. Đây là bài kiểm soi thẳng vào mã JS vì lỗi nằm ở trình duyệt, không
     * lượt gọi HTTP nào lộ ra.
     */
    public function test_hop_thoai_dung_san_dong_bien_the_mac_dinh(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        $this->assertStringContainsString('if (!varRowsHtml) regenVariants();', $html);
    }
}
