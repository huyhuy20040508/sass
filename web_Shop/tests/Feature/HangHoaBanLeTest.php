<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use Illuminate\Http\UploadedFile;
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
                    'id' => 1, 'code' => 'DUNGLUONG', 'name' => 'Dung lượng', 'is_active' => true,
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

        // Có đủ. Nhãn lấy từ tệp ngôn ngữ của v2 nên phải khớp đúng chữ bên đó
        // ("Mã hàng hóa", không phải "Mã hàng" như bản khu cũ tự đặt).
        $res->assertSee('>STT</th>', false);
        $res->assertSee('Mã hàng hóa', false);
        $res->assertSee('Tên hàng hóa', false);
        $res->assertSee('Nhóm hàng hóa', false);
        $res->assertSee('VAT', false);
        $res->assertSee('ĐVT', false);
        $res->assertSee('Giá bán', false);
        $res->assertSee('Chi nhánh', false);
        $res->assertSee('Trạng thái', false);

        // Bảng dựng theo khuôn v2: mỗi cột một lớp show_* để nút chọn cột tắt
        // được cả cột.
        $res->assertSee('id="sortableTable"', false);
        $res->assertSee('show_sale_price', false);

        // Không thừa: các cột v2 không có đã bỏ hẳn khỏi bảng.
        $res->assertDontSee('prd-c-img', false);
        $res->assertDontSee('prd-c-loc', false);
        $res->assertDontSee('prd-c-wsale', false);
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
        // v2 KHÔNG bày số biến thể dưới tên hàng; muốn xem thì mở tab Thuộc tính
        // trong hộp sửa.
        $res->assertDontSee('2 biến thể', false);
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

        // Neo đúng ô của HỘP THOẠI: thanh lọc cũng có một ô nhóm hàng
        // (`select_menu_group_search`) và nó đứng trước trong trang.
        preg_match('/select_menu_group select2.*?<\/select>/s', $html, $m);
        $oChon = $m[0] ?? '';
        $this->assertNotSame('', $oChon, 'Trang không dựng ô chọn nhóm hàng trong hộp thoại.');

        // Chỉ nhóm NHỎ NHẤT (không có nhóm con) mới gắn hàng được. Nhóm nào có
        // nhóm con thì chính các nhóm con hiện lên thay, còn nó lui về làm khung
        // phân loại — gắn hàng vào đó thì báo cáo theo nhóm đếm hai lần.
        $this->assertStringNotContainsString('>Hàng bán', $oChon);
        $this->assertStringNotContainsString('>Hàng hóa khác', $oChon);

        // Và chỉ in TÊN nhóm, không chen đường dẫn vào dòng chọn.
        $this->assertMatchesRegularExpression('/<option[^>]*>\s*Điện thoại\s*<\/option>/u', $oChon);
        $this->assertMatchesRegularExpression('/<option[^>]*>\s*Vật tư\s*<\/option>/u', $oChon);
        // Đường dẫn lui về `title` cho ai cần rà lại nhánh.
        $this->assertStringContainsString('title="Hàng bán › Điện thoại"', $oChon);

        // Mức thuế của nhóm đi kèm để chọn nhóm là ô % VAT tự điền theo.
        $this->assertStringContainsString('data-vat="10"', $oChon);
    }

    /** Hộp thoại chỉ còn MỘT ảnh đại diện — bản cũ v2 không có thư viện ảnh. */
    public function test_hop_thoai_chi_co_mot_anh(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        // Đúng MỘT ô ảnh, dựng theo khối uploadPhoto/pic_add của v2.
        $this->assertStringContainsString('class="uploadPhoto"', $html);
        $this->assertStringContainsString('id="img-preview"', $html);
        $this->assertSame(1, substr_count($html, 'class="ip_img"'));
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

        // Hộp thoại chép từ #modalCreate của v2: đúng hai tab, hàng tab là
        // ul.nav-tabs.nav-detail chứ không phải nút tự vẽ.
        $this->assertStringContainsString('id="modalCreate"', $html);
        $this->assertStringContainsString('nav nav-tabs nav-detail', $html);
        $this->assertStringContainsString('data-bs-target="#add-menu-normal"', $html);
        $this->assertStringContainsString('data-bs-target="#attribute"', $html);
        // Tab của v2 mà bán lẻ không có: topping, combo.
        $this->assertStringNotContainsString('data-bs-target="#extra_dish"', $html);
        $this->assertStringNotContainsString('data-bs-target="#menu-combo"', $html);

        // Thân hộp: cột ảnh + công tắc bên trái, lưới ô bên phải — khuôn v2.
        $this->assertStringContainsString('nav-normal-info-container', $html);
        // KHÔNG canh .type-product: v2 dùng class ấy để kẻ khung quanh cụm
        // "chọn loại hàng + công tắc"; bán lẻ không có nút chọn loại nên khung
        // do .cong-tac kẻ. Canh cụm công tắc thay cho canh tên class của v2.
        $this->assertStringContainsString('class="cong-tac', $html);
        $this->assertStringContainsString('id="data-body"', $html);
        $this->assertStringContainsString('data-body-container', $html);

        // Ba ô của v2: mã vạch, giá sau thuế, và accordion quy đổi đơn vị.
        $this->assertStringContainsString('ip_barcode', $html);
        $this->assertStringContainsString('ip_sale_price_after_tax', $html);
        $this->assertStringContainsString('id="collapseMenuUnit"', $html);
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
        // Không lọc gì -> bấm được (khuôn .sort-item của v2, chưa có lớp khoá).
        $this->assertStringContainsString('class="sort-item d-inline-block "', $html);

        // Đang lọc -> khoá lại.
        $locHtml = $this->withSession($this->phienQuanTri())->get('/admin/products?status=active')->getContent();
        $this->assertStringContainsString('sort-item d-inline-block khoa', $locHtml);
    }

    /**
     * Kéo thả đổi thứ tự: dòng nhấc lên được, và CHỈ khi thứ tự đang là tự xếp.
     *
     * Cùng điều kiện với hai mũi tên. Đang lọc hay đang sắp theo cột mà thả một
     * dòng vào giữa thì cái thứ tự vừa dựng ra không sống nổi qua lượt tải lại —
     * bảng sẽ hiện lại theo đúng cột đang sắp.
     */
    public function test_keo_tha_doi_thu_tu(): void
    {
        $this->fakeApi();

        // Soi ĐÚNG hàng của bảng, không soi cả trang: bảng thuộc tính trong hộp
        // sửa cũng có dòng kéo thả mang draggable="true", nên một khẳng định
        // chung chung sẽ xanh cả khi hàng của bảng không kéo được.
        $hang = fn (string $html) => preg_match('/<tr class="item[^"]*"[^>]*>/', $html, $m) ? $m[0] : '(không thấy hàng nào)';

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();
        $this->assertStringContainsString('keo-duoc', $hang($html));
        $this->assertStringContainsString('draggable="true"', $hang($html));
        // Mốc để đánh lại cột STT: thiếu nó thì trang 2 đánh số từ 1.
        $this->assertStringContainsString('data-stt-dau=', $html);

        $locHtml = $this->withSession($this->phienQuanTri())->get('/admin/products?status=active')->getContent();
        $this->assertStringNotContainsString('keo-duoc', $hang($locHtml));
        $this->assertStringContainsString('draggable="false"', $hang($locHtml));
    }

    /**
     * Thả xong gửi NGUYÊN trình tự của trang, không gửi "dòng X về chỗ thứ n".
     *
     * Số thứ tự trên màn hình chỉ có nghĩa trong đúng cái trang người dùng đang
     * nhìn, mà máy chủ thì không biết trang ấy đang hiện những ai.
     */
    public function test_keo_tha_gui_nguyen_trinh_tu(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())
            ->put('/admin/products/sap-xep', ['ids' => [9, 7, 8]]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/products/sap-xep') || $request->method() !== 'PUT') {
                return false;
            }

            return $request->data() === ['ids' => [9, 7, 8]];
        });
    }

    /**
     * Lưu HỎNG phải trả JSON lỗi, không phải một lượt chuyển hướng.
     *
     * Chuyển hướng thì lượt AJAX đọc y hệt thành công: trang im lặng, bảng vẫn
     * bày trình tự vừa kéo, và người dùng đinh ninh thứ tự đã vào sổ trong khi
     * máy chủ chưa ghi gì.
     */
    public function test_keo_tha_loi_thi_bao_ro_chu_khong_chuyen_huong(): void
    {
        // Khai TRƯỚC fakeApi(): trong fakeApi có một mẫu '*' bắt hết, mà mẫu
        // đăng ký trước là mẫu thắng — để sau thì lượt gọi này rơi vào '*' và
        // luôn "thành công".
        Http::fake([
            '*/admin/products/sap-xep' => Http::response(['message' => 'Không tìm thấy dữ liệu'], 404),
        ]);
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->putJson('/admin/products/sap-xep', ['ids' => [9, 7, 8]]);

        $res->assertStatus(422)->assertJson(['success' => false]);
        $this->assertStringContainsString('Không tìm thấy dữ liệu', (string) $res->json('message'));
    }

    /** Một mình một dòng thì không có gì để xếp — chặn tại chỗ, khỏi gọi API. */
    public function test_keo_tha_mot_dong_thi_khong_goi_api(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())
            ->putJson('/admin/products/sap-xep', ['ids' => [7]])
            ->assertStatus(422);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/products/sap-xep'));
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

        $this->assertStringContainsString(__('message.unit_conversion'), $html);
        $this->assertStringContainsString('class="btn btn-sm btn-primary add-unit"', $html); // nút Thêm
        $this->assertStringContainsString('close-all-unit', $html);                          // nút xoá hết
        $this->assertStringContainsString('id="list_unit"', $html);
        $this->assertStringContainsString(__('message.converted_unit'), $html);
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
        // v2 bày thẳng ô tick ra thanh lọc nên không có nút đếm điều kiện; canh
        // các ô đang tick sẵn thay cho con số ấy.
        $this->assertStringContainsString('id="menu_status_active"', $html);
        $this->assertStringContainsString('id="menu_status_inactive"', $html);

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
        // giờ là <select> đổi bằng AJAX (data-param) chứ không dựng sẵn đường dẫn.
        $this->assertStringContainsString('data-param="per_page"', $html);
        $this->assertStringContainsString('<option value="40"', $html);
        $this->assertStringContainsString(__('message.create_new'), $html);
        $this->assertStringContainsString('Nâng cao', $html);
        $this->assertStringNotContainsString('Tiện ích', $html);
    }

    /** Cột trái hộp thoại có ĐỦ BỐN công tắc, đúng thứ tự bản cũ. */
    public function test_cot_trai_du_bon_cong_tac(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        foreach ([__('message.status'), __('message.print_label'), __('message.deduct_inventory'), 'Số seri / IMEI'] as $nhan) {
            $this->assertStringContainsString($nhan, $html);
        }
        foreach (['id="ip_status"', 'id="print_label"', 'id="is_stock_deducted"', 'id="is_serial"'] as $id) {
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
        $this->assertStringContainsString('class="switch_customer item-status"', $html);
        $this->assertMatchesRegularExpression('/item-status" data-id="7"\s+checked/', $html);
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

        $this->assertStringContainsString('select2 select_branch', $html);
        $this->assertStringContainsString('select2 select_tag', $html);
        // Nguồn để chọn: chi nhánh đang hoạt động và thẻ đang có. v2 dựng sẵn
        // <option> trong trang chứ không nhét JSON rồi vẽ bằng JS.
        $this->assertStringContainsString('Chi nhánh Quận 7', $html);
        $this->assertStringContainsString('Món mới', $html);
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

        // Không dựng sẵn dòng lúc mở hộp thoại nữa: lúc LƯU mà chưa có dòng biến
        // thể nào thì tự đắp một dòng mặc định, tên để trống cho máy chủ hiểu là
        // hàng đơn.
        $this->assertStringContainsString("f['variants[0][name]'] = '';", $html);
    }

    // =================================================================
    //  Vòng CRUD còn lại: đọc một dòng, sửa, nhân bản, xoá, nhập tệp.
    //  Mỗi bài canh ĐÚNG một điều: nút trên màn đi tới đường API nào.
    // =================================================================

    /**
     * Bấm Sửa nạp lại mặt hàng TỪ MÁY CHỦ chứ không dùng bản nhúng trong trang.
     * Bản nhúng là ảnh chụp lúc mở danh sách; người khác vừa sửa là nó đã cũ.
     */
    public function test_doc_mot_mat_hang_tra_json(): void
    {
        Http::fake([
            '*/admin/products/7' => Http::response(['data' => ['id' => 7, 'name' => 'iPhone 15']]),
            '*' => Http::response(['data' => []]),
        ]);

        $res = $this->withSession($this->phienQuanTri())->getJson('/admin/products/7');

        $res->assertOk();
        $res->assertJsonPath('data.name', 'iPhone 15');
    }

    /** API không có dòng ấy thì trả đúng 404, không nuốt thành 200 rỗng. */
    public function test_doc_mat_hang_khong_co_tra_404(): void
    {
        Http::fake([
            '*/admin/products/99' => Http::response(['message' => 'Không tìm thấy'], 404),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phienQuanTri())
            ->getJson('/admin/products/99')
            ->assertStatus(404);
    }

    /** Sửa: đi bằng PUT tới đúng dòng, mang theo trọn payload của hộp thoại. */
    public function test_sua_mat_hang(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->put('/admin/products/7', [
            'name' => 'iPhone 15 Pro',
            'sku' => 'IP15-0001',
            'category_id' => 3,
            'unit_id' => 2,
            'base_price' => 25000000,
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/products/7') || $request->method() !== 'PUT') {
                return false;
            }
            $d = $request->data();

            return $d['name'] === 'iPhone 15 Pro' && $d['base_price'] === 25000000.0;
        });
    }

    /** Nhân bản: một lượt POST, máy chủ tự lo tên và mã của bản sao. */
    public function test_nhan_ban_mat_hang(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->post('/admin/products/7/duplicate');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/products/7/duplicate')
            && $request->method() === 'POST');
    }

    /** Xoá một dòng. */
    public function test_xoa_mot_mat_hang(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->delete('/admin/products/7');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/products/7')
            && $request->method() === 'DELETE');
    }

    /**
     * Xoá nhiều đi MỘT lượt gọi (API chạy trong một giao dịch), không lặp từng
     * id: 50 dòng là 50 lượt HTTP nối đuôi, hỏng giữa chừng thì mất một nửa.
     */
    public function test_xoa_nhieu_mat_hang_mot_luot(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())
            ->post('/admin/products/bulk-destroy', ['ids' => [7, 8, 8]]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/products/bulk-delete') || $request->method() !== 'POST') {
                return false;
            }

            // Trùng id bị lọc trước khi gửi.
            return $request->data() === ['ids' => [7, 8]];
        });
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    /** Chưa tick dòng nào thì nói ra, không gọi API. */
    public function test_xoa_nhieu_khi_chua_tick_gi(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->from('/admin/products')
            ->post('/admin/products/bulk-destroy', ['ids' => []]);

        $res->assertSessionHas('error');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'bulk-delete'));
    }

    /** Nhập tệp: mỗi dòng CSV thành một lượt tạo mặt hàng. */
    public function test_nhap_tep_csv(): void
    {
        $this->fakeApi();

        $csv = "name,category_id,unit_code,location_code,vat,base_price,cost_price,the,bien_the\n"
            ."Áo thun,3,CAI,VT001,10,150000,90000,Bán chạy nhất,\n";

        $this->withSession($this->phienQuanTri())->post('/admin/products/import', [
            'file' => UploadedFile::fake()->createWithContent('hang-hoa.csv', $csv),
        ]);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/admin/products') || $request->method() !== 'POST') {
                return false;
            }
            $d = $request->data();

            return $d['name'] === 'Áo thun'
                && $d['base_price'] === 150000.0
                && $d['tags'] === ['Bán chạy nhất'];
        });
    }

    /** Tệp không phải CSV thì chặn tại trang. */
    public function test_nhap_tep_sai_dinh_dang(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->from('/admin/products')
            ->post('/admin/products/import', [
                'file' => UploadedFile::fake()->create('hang-hoa.pdf', 10, 'application/pdf'),
            ]);

        $res->assertSessionHasErrors('file');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/admin/products'));
    }

    /**
     * Ô thuế chia làm hai: chọn LOẠI thuế trước, ô "% VAT" bên cạnh chỉ bày mức
     * của loại ấy — đúng hàng 4-2-2-4 của bản v2 cũ (ĐVT | Thuế | %VAT | Giá sau
     * thuế). Loại thuế KHÔNG lưu vào mặt hàng: mặt hàng chỉ giữ một số `vat`,
     * ô loại là cái phễu lọc cho ô bên cạnh.
     */
    public function test_o_thue_chia_lam_hai(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        // Ô chọn loại, mang theo bộ mức của loại đó để JS dựng ô "% VAT".
        $this->assertStringContainsString('select_tax_type', $html);
        $this->assertStringContainsString('value="mac-dinh"', $html);
        $this->assertStringContainsString('data-muc="0,8,10"', $html);
        // Ô "% VAT" vẫn còn, và vẫn là ô người dùng chọn mức.
        $this->assertStringContainsString('select_vat_value', $html);
    }

    /** Loại thuế đã TẮT thì thôi bày ra: tắt nghĩa là màn nghiệp vụ không dùng nữa. */
    public function test_loai_thue_da_tat_thi_khong_bay(): void
    {
        Http::fake([
            '*/admin/thue*' => Http::response(['data' => [
                ['id' => 1, 'loai' => 'mac-dinh', 'ten' => 'Thuế mặc định', 'muc' => [0, 10], 'is_active' => true],
                ['id' => 2, 'loai' => 'ban-hang', 'ten' => 'Thuế đơn bán hàng', 'muc' => [8], 'is_active' => false],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        $this->assertStringContainsString('value="mac-dinh"', $html);
        $this->assertStringNotContainsString('value="ban-hang"', $html);
    }

    /**
     * Ô chọn rỗng phải NÓI RA vì sao.
     *
     * Trước đây mọi hàm nạp đều nuốt lỗi rồi trả mảng rỗng, nên "cửa hàng chưa
     * khai gì" và "máy chủ từ chối vì thiếu quyền" hiện ra y như nhau: một ô
     * trắng trơn. Hai ca ấy phải làm hai việc khác hẳn nhau.
     */
    public function test_o_chon_rong_thi_noi_ro_vi_sao(): void
    {
        Http::fake([
            '*/admin/don-vi-tinh*' => Http::response(['message' => 'Bạn không được giao việc này'], 403),
            '*/admin/chi-nhanh*' => Http::response(['message' => 'Bạn không được giao việc này'], 403),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        // Câu của API được chép nguyên ra màn hình: "thiếu quyền" và "sai chi
        // nhánh" là hai việc phải sửa khác hẳn nhau, gộp thành một câu tự đặt là
        // mất đúng phần cần biết.
        $this->assertStringContainsString('Không đọc được đơn vị tính: Bạn không được giao việc này (403).', $html);
        $this->assertStringContainsString('Không đọc được chi nhánh: Bạn không được giao việc này (403).', $html);
    }

    /** API chạy được nhưng cửa hàng chưa khai gì thì nói đúng như vậy. */
    public function test_chua_khai_gi_thi_chi_dan_di_khai(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        $this->assertStringContainsString('Chưa có đơn vị tính nào đang bật', $html);
        $this->assertStringContainsString('Chưa có nhóm hàng hoá nào', $html);
    }

    /**
     * Lượt khai select2 CHUNG trong v2/js/script.js phải CHỪA ô trong modal.
     *
     * script.js chạy ở $(document).ready và vơ hết `select.select2`. Nó chiếm
     * mất các ô trong hộp thoại trước, nên lượt khai riêng của màn (chạy lúc
     * `shown.bs.modal`) thấy chúng đã có select2 rồi và bỏ qua — mất luôn
     * `dropdownParent`. Thiếu tham số ấy thì bảng xổ ra bám vào <body>, tức là
     * nằm DƯỚI lớp phủ của modal: bấm vào chỉ thấy trống trơn, trông hệt như
     * không đọc được dữ liệu.
     *
     * Đây là bài canh tệp asset chứ không canh trang, vì gốc lỗi nằm ở đó.
     */
    public function test_khai_select2_chung_chua_o_trong_modal(): void
    {
        $js = file_get_contents(public_path('v2/js/script.js'));

        $this->assertStringContainsString('.not(".modal select, .fillter-box select")', $js,
            'v2/js/script.js lại vơ hết select.select2 — ô chọn trong hộp thoại sẽ mất dropdownParent.');
        $this->assertStringNotContainsString('$("select.select2").select2();', $js);
    }

    /** Hộp thoại tự khai select2 kèm dropdownParent và bộ chữ tiếng Việt. */
    public function test_hop_thoai_tu_khai_select2(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        $this->assertStringContainsString('dropdownParent: $hop', $html);
        // Ô chọn NHIỀU: select2 giấu dòng đã tick, tick hết là danh sách rỗng và
        // nó bung ra câu tiếng Anh trông như lỗi. Nói rõ ra bằng tiếng Việt.
        $this->assertStringContainsString('Đã chọn hết — không còn mục nào để thêm', $html);
    }

    /**
     * Nhóm gốc CHƯA có nhánh con thì chính nó là nhóm nhỏ nhất — vẫn chọn được.
     *
     * Luật chỉ xét "có nhóm con hay không", KHÔNG xét cấp. Thêm điều kiện "phải
     * nằm dưới một nhóm gốc" là nhánh nào chưa kịp chia nhỏ sẽ mất chỗ để hàng,
     * và người dùng không hiểu vì sao nhóm mình vừa tạo lại không chọn được.
     */
    public function test_nhom_goc_chua_co_nhanh_con_van_chon_duoc(): void
    {
        Http::fake([
            '*/categories*' => Http::response(['data' => [
                // "Hàng bán" có nhánh con -> lui về làm khung phân loại.
                ['id' => 1, 'parent_id' => null, 'slug' => 'hang-ban', 'name' => 'Hàng bán', 'vat' => 8, 'is_active' => true],
                ['id' => 3, 'parent_id' => 1, 'slug' => 'NH0003', 'name' => 'Điện thoại', 'vat' => 10, 'is_active' => true],
                // "Hàng hóa khác" chưa có nhánh con -> tự nó là nhóm nhỏ nhất.
                ['id' => 2, 'parent_id' => null, 'slug' => 'hang-hoa-khac', 'name' => 'Hàng hóa khác', 'vat' => 0, 'is_active' => true],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        preg_match('/select_menu_group select2.*?<\/select>/s', $html, $m);
        $oChon = $m[0] ?? '';

        $this->assertMatchesRegularExpression('/<option[^>]*>\s*Điện thoại\s*<\/option>/u', $oChon);
        $this->assertMatchesRegularExpression('/<option[^>]*>\s*Hàng hóa khác\s*<\/option>/u', $oChon);
        // "Hàng bán" có nhánh con nên không bày ra.
        $this->assertStringNotContainsString('>Hàng bán', $oChon);
        // Hai nhóm chọn được, cộng dòng nhắc chọn.
        $this->assertSame(3, substr_count($oChon, '<option'));
    }

    /**
     * Ô lọc ngoài bảng theo CÙNG luật nhóm nhỏ nhất, cộng ràng buộc riêng của
     * nó: nhóm phải đang có hàng.
     *
     * Nhóm cha lọt được vào danh sách "đang có hàng" khi hàng cũ còn gắn thẳng
     * vào nó. Bày nó ra thì ô lọc lệch hẳn luật của ô trong hộp thoại — hai chỗ
     * cùng nói về nhóm hàng mà cho hai câu trả lời khác nhau.
     */
    public function test_o_loc_chi_bay_nhom_nho_nhat_va_dang_co_hang(): void
    {
        Http::fake([
            '*/categories*' => Http::response(['data' => [
                ['id' => 1, 'parent_id' => null, 'slug' => 'hang-ban', 'name' => 'Hang ban', 'vat' => 8, 'is_active' => true],
                ['id' => 3, 'parent_id' => 1, 'slug' => 'NH0003', 'name' => 'Dien thoai', 'vat' => 10, 'is_active' => true],
                ['id' => 5, 'parent_id' => 1, 'slug' => 'NH0005', 'name' => 'Phu kien', 'vat' => 10, 'is_active' => true],
            ]]),
            // API trả cả nhóm cha (1) vì còn hàng cũ gắn thẳng vào đó; nhóm nhỏ
            // nhất chưa có hàng (5) thì không nằm trong danh sách này.
            '*/admin/phieu-mua-hang/nhom-hang*' => Http::response(['data' => [
                ['id' => 1, 'name' => 'Hang ban', 'so_mat_hang' => 4],
                ['id' => 3, 'name' => 'Dien thoai', 'so_mat_hang' => 2],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        preg_match('/select_menu_group_search.*?<\/select>/s', $html, $m);
        $oLoc = $m[0] ?? '';
        $this->assertNotSame('', $oLoc, 'Trang khong dung o loc nhom hang.');

        // Có hàng VÀ là nhóm nhỏ nhất -> bày ra, kèm số mặt hàng.
        $this->assertStringContainsString('Dien thoai (2)', $oLoc);
        // Có hàng nhưng là nhóm cha -> không bày.
        $this->assertStringNotContainsString('Hang ban (4)', $oLoc);
        // Là nhóm nhỏ nhất nhưng chưa có hàng -> không bày.
        $this->assertStringNotContainsString('Phu kien', $oLoc);
        $this->assertSame(1, substr_count($oLoc, '<option'));
    }

    // =================================================================
    //  Lượt Lưu của hộp thoại đi bằng AJAX: hỏng thì GIỮ HỘP LẠI.
    // =================================================================

    /** Lưu được thì trả 200 kèm câu để bắn toast xanh. */
    public function test_luu_xong_tra_json_200(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->postJson('/admin/products', [
                'name' => 'Nuoc suoi',
                'sku' => 'NS-0001',
                'category_id' => 3,
                'base_price' => 5000,
            ]);

        $res->assertOk();
        $res->assertJson(['success' => true]);
        $res->assertJsonPath('message', 'Đã thêm mặt hàng.');
    }

    /**
     * API từ chối (trùng tên) thì trả 422 kèm ĐÚNG câu của API.
     *
     * Hộp thoại đọc câu này để bắn toast rồi GIỮ NGUYÊN hộp cho người dùng sửa.
     * Trước đây lượt Lưu đi bằng form ẩn nên trang tải lại, hộp biến mất rồi mới
     * thấy toast — mất trắng mọi thứ vừa gõ, kể cả bảng biến thể.
     */
    public function test_trung_ten_tra_422_kem_cau_cua_api(): void
    {
        Http::fake([
            '*/admin/products' => Http::response([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => ['name' => 'Tên mặt hàng này đã có trong cửa hàng'],
            ], 422),
            '*' => Http::response(['data' => []]),
        ]);

        $res = $this->withSession($this->phienQuanTri())
            ->postJson('/admin/products', [
                'name' => 'Iphone 16 pro max',
                'sku' => 'IP-0002',
                'category_id' => 3,
                'base_price' => 5000,
            ]);

        $res->assertStatus(422);
        $res->assertJson(['success' => false]);
        // Lấy câu theo TỪNG Ô chứ không lấy `message` chung chung: "Dữ liệu
        // không hợp lệ" thì người dùng không biết phải sửa ô nào.
        $res->assertJsonPath('message', 'Tên mặt hàng này đã có trong cửa hàng');
    }

    /** Sửa cũng vậy: hỏng thì 422, không chuyển hướng. */
    public function test_sua_hong_tra_422_khong_chuyen_huong(): void
    {
        Http::fake([
            '*/admin/products/7' => Http::response([
                'errors' => ['name' => 'Tên mặt hàng này đã có trong cửa hàng'],
            ], 422),
            '*' => Http::response(['data' => []]),
        ]);

        $res = $this->withSession($this->phienQuanTri())
            ->putJson('/admin/products/7', [
                'name' => 'Trung ten',
                'sku' => 'IP-0003',
                'category_id' => 3,
                'base_price' => 5000,
            ]);

        $res->assertStatus(422);
        $res->assertJsonPath('message', 'Tên mặt hàng này đã có trong cửa hàng');
    }

    /**
     * Lượt gửi THƯỜNG (không phải AJAX) vẫn đi đường chuyển hướng cũ.
     *
     * Nhập tệp Excel và mọi form gửi thẳng đều đi lối này; đổi hết sang JSON là
     * chúng nhận về một cục JSON giữa màn hình.
     */
    public function test_gui_thuong_van_chuyen_huong_nhu_cu(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->post('/admin/products', [
                'name' => 'Nuoc ngot',
                'sku' => 'NN-0001',
                'category_id' => 3,
                'base_price' => 5000,
            ]);

        $res->assertRedirect();
        $res->assertSessionHas('success');
    }

    /**
     * Select2 của hộp thoại dựng NGAY lúc nạp trang, không đợi modal mở.
     *
     * Dựng ở `shown.bs.modal` là dựng SAU khi moHopHangHoa đã điền xong: lượt mở
     * đầu còn may (select2 đọc giá trị sẵn có lúc khởi tạo), những lượt sau thì ô
     * hiển thị một đằng còn giá trị thật một nẻo — bấm Sửa thấy ô trống, mở danh
     * sách ra thì không còn mục nào, phải F5 mới chọn lại được.
     */
    public function test_select2_cua_hop_thoai_dung_ngay_luc_nap_trang(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        // Gọi thẳng, không nằm trong nhánh chờ modal mở.
        $this->assertMatchesRegularExpression('/
\s*dungSelect2\(\);/', $html);
        $this->assertStringContainsString('dropdownParent: $hop', $html);
    }

    /**
     * Mở Sửa thì ô "% VAT" phải nhảy sang LOẠI thuế chứa được mức của mặt hàng.
     *
     * Mỗi loại chỉ bày mức của riêng nó. Đặt thẳng `val` là hỏng khi mức ấy không
     * thuộc loại đang đứng: mặt hàng khai 12% (thuế tiêu thụ đặc biệt) mà ô đang
     * ở "Thuế mặc định" (0/8/10) thì 12 không có trong danh sách, select rơi về
     * mức đầu và người dùng thấy 0%.
     */
    public function test_mo_sua_thi_o_vat_chon_dung_loai_thue(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        $this->assertStringContainsString('function datMucThue(vat)', $html);
        // Lượt điền dùng datMucThue chứ không đặt thẳng vào ô % VAT.
        $this->assertStringContainsString('datMucThue(p.vat == null ? 0 : p.vat)', $html);
    }

    /**
     * Lượt khai select2 CHUNG không được dựng đè lên ô màn hình đã tự khai.
     *
     * Dựng đè để lại HAI lớp vỏ select2 trên cùng một ô: lượt
     * `.val().trigger('change')` cập nhật lớp này, còn mắt người nhìn vào lớp
     * kia — ô hiện ra TRẮNG TRƠN dù giá trị bên dưới vẫn đúng. Đúng cái ảnh chụp
     * màn hình: mã, tên, giá, thuế đều điền, riêng năm ô select2 trắng.
     */
    public function test_khai_select2_chung_khong_dung_de(): void
    {
        $js = file_get_contents(public_path('v2/js/script.js'));

        $this->assertStringContainsString('.not(".select2-hidden-accessible")', $js);
        $this->assertStringContainsString('.not(".modal select, .fillter-box select")', $js);
    }

    /**
     * Tệp JS tĩnh phải mang dấu phiên bản.
     *
     * Không có `?v=` thì trình duyệt giữ bản cũ rất dai — sửa script.js xong,
     * người dùng bấm F5 mấy lượt vẫn chạy mã cũ mà không ai hiểu vì sao.
     */
    public function test_js_tinh_mang_dau_phien_ban(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        $this->assertMatchesRegularExpression('#v2/js/script\.js\?v=\d+#', $html);
    }

    /** Ô chỗ để hàng phải là "Vị trí", không phải "Chức vụ" của nhân sự. */
    public function test_o_vi_tri_dung_nhan(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        preg_match('/<label[^>]*>([^<]*)<\/label>\s*(?:\{\{--.*?--\}\}\s*)?(?:<[^>]*>\s*)*?<select[^>]*select_location/s', $html, $m);
        $truoc = trim($m[1] ?? '');
        $this->assertSame('Vị trí', $truoc, 'Ô chỗ để hàng đang mang nhãn khác.');
    }

    /**
     * Mọi lượt khai select2 phải neo vào THẺ `select`, không neo vào lớp `.select2`.
     *
     * Chính select2 dựng thêm một <span class="select2 select2-container"> ngay
     * cạnh ô gốc, nên `.select2` khớp cả cái span ấy — và `.not('.select2-hidden-
     * accessible')` không lọc được nó vì cờ đó nằm trên ô gốc. Gọi select2() lên
     * một span thì ô có HAI vỏ: lượt .val().trigger('change') cập nhật vỏ đầu,
     * còn mắt người nhìn vỏ sau — ô hiện ra TRẮNG dù giá trị bên dưới vẫn đúng.
     *
     * Đo được bằng jsdom: neo '.select2' cho 2 vỏ ["laptop", ""], neo
     * 'select.select2' cho 1 vỏ ["laptop"].
     */
    public function test_moi_luot_khai_select2_neo_vao_the_select(): void
    {
        $tep = [
            'v2/hang-hoa/_modal_js.blade.php',
            'v2/hang-hoa/index.blade.php',
            'v2/layouts/master.blade.php',
            'v2/thue/index.blade.php',
        ];

        foreach ($tep as $t) {
            $ma = file_get_contents(base_path($t));
            // Bắt mọi lượt gọi .select2({...}) rồi soi bộ chọn đứng trước nó.
            preg_match_all('/\$\(([^)]*)\)[^;]{0,400}?\.select2\(\{/s', $ma, $m);
            foreach ($m[1] as $bo) {
                $this->assertStringNotContainsString(
                    "'.select2'", $bo,
                    $t.': khai select2 theo lớp .select2 sẽ dựng đè lên cái span của chính select2.'
                );
            }
            // Và không còn lượt nào dùng find('.select2') trần.
            $this->assertStringNotContainsString("find('.select2')", $ma, $t.': còn find(\'.select2\') trần.');
        }
    }

    /**
     * Bấm MÃ hàng hoá là đi XEM, bấm nút bút chì mới là đi SỬA.
     *
     * Hai việc khác nhau nên không mở chung một hộp toang: đang tra cứu mà lỡ
     * tay đổi một ô rồi bấm Lưu thì không ai biết vừa sửa gì.
     */
    public function test_bam_ma_hang_hoa_chi_mo_hop_xem(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        // Ô mã mang class .show-item, nút bút chì mang .btn-edit.
        $this->assertStringContainsString('class="show-item', $html);
        $this->assertStringContainsString('btn-edit', $html);
        // Lượt bấm phân biệt hai chế độ rồi mới mở hộp.
        $this->assertStringContainsString("hasClass('show-item')", $html);
        $this->assertStringContainsString('window.moHopHangHoa(r.data, chiXem)', $html);
    }

    /**
     * Chế độ chỉ xem phải khoá ô VÀ giấu nút Xác nhận.
     *
     * Khoá ô thôi chưa đủ: mấy nút thêm dòng quy đổi / thêm thuộc tính / xoá
     * dòng không phải ô nhập nên `disabled` không giấu được chúng.
     */
    public function test_che_do_chi_xem_khoa_o_va_an_nut_luu(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        $this->assertStringContainsString('function datCheDo(chiXem)', $html);
        // CHỈ ô nhập liệu. Dùng `:input` là khoá luôn nút X và nút Đóng — vào
        // chế độ xem rồi không có đường thoát ra.
        $this->assertStringContainsString("\$hop.find('input, select, textarea').prop('disabled', !!chiXem)", $html);
        $this->assertStringNotContainsString("\$hop.find(':input').prop('disabled'", $html);
        $this->assertStringContainsString("\$hop.find('#confirm_create_menu').toggleClass('d-none', !!chiXem)", $html);
        // Nút làm đổi nội dung bị giấu bằng CSS.
        $this->assertStringContainsString('#modalCreate.dang-xem .add-unit', $html);

        // Ô vốn đã khoá phải mang dấu, để lượt trả về chế độ sửa không mở toang.
        $this->assertStringContainsString('data-khoa-san', $html);
        $this->assertStringContainsString("\$hop.find('[data-khoa-san]').prop('disabled', true)", $html);
    }

    /**
     * Hộp thoại co theo nội dung, chỉ cuộn khi thật sự dài quá màn.
     *
     * `h-100` trên .modal-content ép thân hộp cao bằng cả màn hình: hộp ít ô vẫn
     * hiện thanh cuộn và chừa một khoảng trống lớn phía dưới. Bỏ nó đi thì
     * `modal-dialog-scrollable` vẫn lo phần cuộn cho ca nội dung dài.
     */
    public function test_hop_thoai_co_theo_noi_dung_khong_ep_cao_het_man(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        $this->assertStringContainsString('<div class="modal-content">', $html);
        $this->assertStringNotContainsString('modal-content h-100', $html);

        // KHÔNG dùng `modal-dialog-scrollable`: Bootstrap đặt cho nó
        // `height: calc(100% - ...)` — chiều cao CỐ ĐỊNH, nên hộp ít ô vẫn cao
        // hết màn và luôn có thanh cuộn.
        // Neo từ chính #modalCreate: trang còn vài hộp khác đứng trước nó.
        $vt = strpos($html, 'id="modalCreate"');
        $this->assertNotFalse($vt, 'Không tìm thấy hộp thoại hàng hoá.');
        preg_match('/<div class="modal-dialog[^"]*"/', substr($html, $vt, 600), $m);
        $this->assertNotEmpty($m, 'Không tìm thấy khung hộp thoại hàng hoá.');
        // `modal-dialog-centered` cũng bị bỏ: nó đặt min-height: calc(100% - ...)
        // nên khung vẫn cao hết màn dù ruột ngắn.
        $this->assertStringNotContainsString('modal-dialog-scrollable', $m[0]);
        $this->assertStringNotContainsString('modal-dialog-centered', $m[0]);

        // Đường cuộn nằm ở THÂN hộp, cắt trần bằng max-height.
        $this->assertMatchesRegularExpression(
            '/#modalCreate \.modal-body \{[^}]*max-height:[^}]*overflow-y: auto/s',
            $html
        );
    }

    /**
     * Tab Thuộc tính theo khuôn v2: mỗi thuộc tính một hàng, ruột hàng là nhiều
     * DÒNG CHI TIẾT — mỗi dòng một giá trị, có ô "Mặc định" và "Giá trị cộng
     * thêm", kéo thả đổi thứ tự, nút [+] thêm dòng và [×] bỏ cả hàng.
     */
    public function test_tab_thuoc_tinh_theo_khuon_v2(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        // Ba cột của v2.
        $this->assertStringContainsString(__('message.attribute_details'), $html);
        // Dòng tiêu đề trong ruột hàng.
        $this->assertStringContainsString(__('message.additional_value'), $html);
        $this->assertStringContainsString('tt-mac-dinh', $html);
        $this->assertStringContainsString('tt-cong-them', $html);
        // Cột Hành động: dấu CỘNG thêm một dòng, dấu TRỪ bớt một dòng.
        $this->assertStringContainsString('fa-plus tt-them-dong', $html);
        $this->assertStringContainsString('fa-minus tt-bot-dong', $html);

        // Ba nhãn con nằm MỘT LẦN trên <thead>, không lặp ở từng hàng thuộc tính.
        $this->assertSame(1, substr_count($html, 'd-flex tt-dau'));
        // Kéo thả đổi thứ tự, viết bằng drag native chứ không kéo thêm jQuery UI.
        $this->assertStringContainsString('draggable="true"', $html);
        $this->assertStringNotContainsString('jquery-ui', $html);

        // Không còn ô chọn nhiều giá trị của bản cũ.
        $this->assertStringNotContainsString('tt-gia-tri" multiple', $html);

        // KHÔNG có bảng liệt kê từng tổ hợp: tab này để KHAI THUỘC TÍNH chứ
        // không phải khai từng mặt hàng con. Tổ hợp dựng ngay lúc Lưu.
        $this->assertStringNotContainsString('id="list_variant"', $html);
        $this->assertStringNotContainsString('Chưa chọn thuộc tính nào', $html);
        $this->assertStringContainsString('function dungBienThe(giaGoc, maVach)', $html);
    }

    /**
     * "Giá trị cộng thêm" cộng vào GIÁ của biến thể, và tổ hợp toàn giá trị mặc
     * định thành biến thể mặc định.
     *
     * Bên v2 phụ phí lưu rời theo từng giá trị; bên này mỗi biến thể là một mã
     * hàng bán được có giá riêng, nên số ấy cộng thẳng vào giá biến thể. Đo được
     * bằng jsdom: giá bán 100.000 + phụ phí 20.000 -> dòng biến thể ra 120.000.
     */
    public function test_phu_phi_cong_vao_gia_bien_the_va_co_bien_the_mac_dinh(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        $this->assertStringContainsString('const cong = t.reduce((s, x) => s + (x.cong || 0), 0)', $html);
        $this->assertStringContainsString('const macDinh = t.every((x) => x.md)', $html);
        // Cờ mặc định phải được GỬI lên, không thì tick xong không lưu được.
        $this->assertStringContainsString("f['variants[' + vi + '][is_default]'] = v.is_default", $html);

        // Mã vạch chung chỉ dán cho biến thể MẶC ĐỊNH: cột UNIQUE, hai biến thể
        // cùng một mã vạch là máy chủ từ chối cả lượt lưu.
        $this->assertStringContainsString("barcode: c.barcode || (macDinh ? maVach : '')", $html);
    }

    /**
     * Mở trang ra là CẮT THEO CHI NHÁNH ĐANG LÀM VIỆC, không phải "tất cả".
     *
     * Cột Tồn kho và tập mặt hàng bày ra đều đổi nghĩa theo chi nhánh, nên bày
     * hàng của mọi kho cho người đang đứng ở một quầy là nói dối.
     */
    public function test_mac_dinh_cat_theo_chi_nhanh_dang_lam(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri() + [ApiClient::KHOA_CHI_NHANH => 2])
            ->get('/admin/products')->assertOk();

        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/products?')
                && ($req->data()['shop_id'] ?? null) === 2;
        });
    }

    /**
     * Chọn "Tất cả chi nhánh" (shop_id=0) thì gửi ĐÚNG số 0 lên API.
     *
     * Bỏ khoá đi thì API hiểu là "chưa nói gì" và tự cắt theo chi nhánh đang làm
     * việc — ngược hẳn ý người vừa bấm.
     */
    public function test_chon_tat_ca_chi_nhanh_thi_gui_so_khong(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri() + [ApiClient::KHOA_CHI_NHANH => 2])
            ->get('/admin/products?shop_id=0')->assertOk();

        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/products?')
                && ($req->data()['shop_id'] ?? null) === 0;
        });
    }

    /**
     * HAI MŨI TÊN ĐỔI THỨ TỰ: cửa hàng MỘT chi nhánh vẫn dùng được.
     *
     * Từ lúc phiên nào cũng ghim sẵn một chi nhánh, bộ lọc chi nhánh luôn bật —
     * coi nó là "đang lọc" thì gần như mọi cửa hàng hôm nay mất luôn tính năng
     * sắp xếp. Một chi nhánh thì bộ lọc ấy không loại ra mặt hàng nào, danh sách
     * vẫn đầy đủ, nên hai mũi tên vẫn đúng.
     */
    public function test_mot_chi_nhanh_thi_van_doi_duoc_thu_tu(): void
    {
        // Khai ô chi nhánh TRƯỚC fakeApi: stub khớp đầu tiên thắng, nên dòng này
        // đè bộ hai chi nhánh mặc định mà vẫn giữ nguyên mọi dữ liệu giả khác.
        Http::fake(['*/admin/chi-nhanh*' => Http::response(['data' => [
            ['id' => 1, 'code' => 'CN01', 'name' => 'Chi nhánh trung tâm', 'is_active' => true],
        ]])]);
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri() + [ApiClient::KHOA_CHI_NHANH => 1])
            ->get('/admin/products')->getContent();

        $this->assertStringContainsString('title="Đưa lên trên"', $html,
            'cửa hàng một chi nhánh phải còn dùng được hai mũi tên đổi thứ tự');
    }

    /**
     * Nhiều chi nhánh mà đang đứng ở MỘT chi nhánh: khoá hai mũi tên.
     *
     * Chúng đổi chỗ với hàng xóm trong danh sách ĐẦY ĐỦ chứ không phải với dòng
     * ngay trên màn hình, nên bấm lúc đang lọc là đổi chỗ với một mặt hàng đang
     * bị giấu: dòng đứng im, người dùng bấm tiếp, thứ tự thật loạn dần.
     */
    public function test_nhieu_chi_nhanh_thi_khoa_hai_mui_ten(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri() + [ApiClient::KHOA_CHI_NHANH => 2])
            ->get('/admin/products')->getContent();

        $this->assertStringNotContainsString('title="Đưa lên trên"', $html,
            'đang cắt theo một chi nhánh thì hai mũi tên phải bị khoá');
        $this->assertStringContainsString('sort-item d-inline-block khoa', $html);

        // Chọn "Tất cả chi nhánh" là mở lại được.
        $htmlTatCa = $this->withSession($this->phienQuanTri() + [ApiClient::KHOA_CHI_NHANH => 2])
            ->get('/admin/products?shop_id=0')->getContent();

        $this->assertStringContainsString('title="Đưa lên trên"', $htmlTatCa,
            'chọn Tất cả chi nhánh thì hai mũi tên phải dùng được lại');
    }
}
