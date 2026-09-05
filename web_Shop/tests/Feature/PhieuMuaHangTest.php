<?php

namespace Tests\Feature;

use App\Http\Controllers\PhieuMuaHangController;
use App\Services\ApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trang Phiếu mua hàng dựng bằng API GIẢ.
 *
 * Không cần API thật chạy: bài này canh phần Shop Admin tự làm — đọc envelope
 * của API, tính còn nợ, dựng chip trạng thái, xuất CSV và giữ bộ lọc trên URL.
 * Phần nghiệp vụ (ghi kho, khoá phiếu đã duyệt) do apitest bên Go canh.
 */
class PhieuMuaHangTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'tk',
            'api.refresh_token' => 'rf',
            'api.user' => ['id' => 1, 'full_name' => 'Test', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly,thu_ngan'],
        ];
    }

    protected function fakeApi(): void
    {
        Http::fake([
            '*/admin/phieu-mua-hang/stats*' => Http::response(['data' => [
                'total' => 12, 'draft' => 3, 'approved' => 8, 'cancelled' => 1,
                'purchased_amount' => 42500000, 'debt_amount' => 12400000,
            ]], 200),
            // Chi tiết MỘT phiếu — đặt TRƯỚC khuôn danh sách vì khuôn kia cũng
            // khớp đường này, và Http::fake lấy khuôn khớp đầu tiên.
            '*/admin/phieu-mua-hang/7' => Http::response(['data' => [
                'id' => 7, 'po_code' => 'PMH20260822007', 'supplier_name' => 'Cơ sở Minh Phát',
                'status' => 'approved', 'document_date' => '2026-08-22',
                'items_amount' => 1200000, 'vat_amount' => 96000, 'total_amount' => 1296000,
                'paid_amount' => 400000, 'payment_status' => 'partial',
                'items' => [[
                    'product_name' => 'Sữa tươi', 'variant_sku' => 'SKU7', 'unit_name' => 'Thùng',
                    'quantity' => 2, 'unit_cost' => 600000, 'vat_percent' => 8,
                    'lot_number' => 'L7', 'expire_date' => '2027-08-22',
                ]],
                'payments' => [[
                    'amount' => 400000, 'paid_after' => 400000, 'payment_method' => 'cash',
                    'created_at' => '2026-08-22 09:00:00', 'created_by_name' => 'Trần Thu Hà',
                    'note' => 'dot 1',
                ]],
            ]], 200),
            '*/admin/phieu-mua-hang*' => Http::response([
                'data' => [[
                    'id' => 5, 'po_code' => 'PMH20260822001', 'supplier_id' => 4,
                    'supplier_name' => 'Công ty TNHH An Bình', 'status' => 'draft',
                    'document_date' => '2026-08-20', 'items_amount' => 10000000,
                    'discount_amount' => 0, 'vat_amount' => 800000, 'total_amount' => 10800000,
                    'paid_amount' => 0, 'payment_status' => 'unpaid', 'note' => 'Giao đợt 1',
                ], [
                    'id' => 6, 'po_code' => 'PMH20260822002', 'supplier_id' => 4,
                    'supplier_name' => 'Cơ sở Minh Phát', 'status' => 'approved',
                    'document_date' => '2026-08-21', 'items_amount' => 5000000,
                    'discount_amount' => 0, 'vat_amount' => 400000, 'total_amount' => 5400000,
                    'paid_amount' => 2000000, 'payment_status' => 'partial', 'note' => '',
                ]],
                'meta' => ['page' => 1, 'page_size' => 20, 'total' => 2, 'total_pages' => 1],
            ], 200),
            '*/admin/nha-cung-cap*' => Http::response(['data' => [
                ['id' => 4, 'code' => 'NCC001', 'name' => 'Công ty TNHH An Bình'],
            ]], 200),
            '*/admin/nhan-su*' => Http::response(['data' => [
                ['id' => 7, 'code' => 'NV0007', 'full_name' => 'Trần Thu Hà'],
            ]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);
    }

    public function test_trang_dung_duoc(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/purchase-orders');

        $res->assertOk();

        // Bảng
        $res->assertSee('PMH20260822001');
        $res->assertSee('Công ty TNHH An Bình');
        $res->assertSee('Lưu tạm');
        $res->assertSee('Đã duyệt');
        $res->assertSee('Trả một phần');

        // Con số đầu trang
        $res->assertSee('12.400.000₫');

        // Tab "Phiếu mua hàng" trong hàng tab của module Kho, và nó đang sáng.
        // Quên bật cờ trong header là màn dựng xong mà không có đường nào vào.
        $res->assertSee('class="sub-nav-btn active"', false);
        $this->assertStringContainsString('Phiếu mua hàng',
            $this->tabDangSang($res->getContent()));

        // Nút và tiện ích trên thanh tiêu đề của khuôn v2
        $res->assertSee('Tạo mới');
        $res->assertSee('Xuất Excel');
        $res->assertSee('Nâng cao');

        // Cột riêng của khuôn v2 mà bản cũ không có
        $res->assertSee('Trạng thái kho');
        $res->assertSee('Người tạo');

        // Hộp thoại lập phiếu — bốn cột thông tin của bản v2
        $res->assertSee('Nhà cung cấp');
        $res->assertSee('Sdt người liên hệ');
        $res->assertSee('Ngày chứng từ');
        $res->assertSee('Ngày hết hạn');
        $res->assertSee('Nhân viên mua hàng');
        $res->assertSee('Số phiếu giao hàng từ NCC');
        $res->assertSee('Người lập phiếu');
        $res->assertSee('Loại chứng từ');
        $res->assertSee('Trần Thu Hà');

        // Khối hàng hóa: lọc nhóm, ô tìm, dropdown Nâng cao
        $res->assertSee('Thông tin hàng hóa');
        $res->assertSee('Chọn nhóm danh mục');
        $res->assertSee('Tải file mẫu');

        // Mười ba cột của lưới hàng
        $res->assertSee('Mã hàng hóa');
        $res->assertSee('Thành tiền (chưa VAT)');
        $res->assertSee('Tổng tiền sau VAT');
        $res->assertSee('Số lô');
        $res->assertSee('Ngày hết hạn');

        // Khối tiền ba dòng
        $res->assertSee('Tổng thành tiền (chưa VAT)');
        $res->assertSee('Tổng tiền thuế (VAT)');
    }

    /** Cắt lấy chữ trong tab đang sáng của hàng tab module. */
    protected function tabDangSang(string $html): string
    {
        $dau = strpos($html, 'class="sub-nav-btn active"');
        $this->assertNotFalse($dau, 'Hàng tab không có tab nào đang sáng.');
        $dau = strpos($html, '>', $dau);

        return substr($html, $dau, strpos($html, '</a>', $dau) - $dau);
    }

    public function test_con_no_tinh_dung(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/purchase-orders');

        // 5.400.000 − 2.000.000 = 3.400.000
        $res->assertSee('3.400.000₫');
    }

    /**
     * Xuất danh sách ra .xlsx THẬT, không phải .csv đội tên.
     *
     * Mở tệp bằng ZipArchive rồi đọc thẳng XML của sheet: chỉ so tên tệp thì đổi
     * đuôi là bài xanh mà tệp vẫn là CSV.
     */
    public function test_xuat_xlsx_danh_sach(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/purchase-orders/export');

        $res->assertOk();
        $res->assertHeader('Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('.xlsx', $res->headers->get('Content-Disposition'));

        $this->assertStringContainsString('Mã phiếu', $this->doSheet($res->getContent()));
        $this->assertStringContainsString('PMH20260822002', $this->doSheet($res->getContent()));
        $this->assertStringContainsString('Còn nợ', $this->doSheet($res->getContent()));
    }

    /**
     * Xuất MỘT phiếu: đủ dòng hàng, đủ tổng, và tiền ghi kiểu SỐ.
     *
     * Kiểu số là chỗ CSV không làm được — cột nào cũng là chữ nên Excel không
     * cộng nổi. Ô số trong xlsx là `<v>`, ô chữ là `<is><t>`.
     */
    public function test_xuat_xlsx_mot_phieu(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/purchase-orders/7/export');

        $res->assertOk();
        $xml = $this->doSheet($res->getContent());

        $this->assertStringContainsString('Phiếu mua hàng', $xml);
        $this->assertStringContainsString('Tổng tiền', $xml);
        $this->assertStringContainsString('Số lô', $xml);
        // Tiền phải là ô SỐ, không phải ô chữ.
        $this->assertMatchesRegularExpression('/<c [^>]*><v>1296000<\/v><\/c>/', $xml);
    }

    /**
     * Sổ trả tiền đi kèm tệp xuất của một phiếu.
     *
     * Tệp gửi cho kế toán mà chỉ có mỗi con số luỹ kế thì họ vẫn phải hỏi lại đã
     * trả mấy lượt, bằng hình thức gì.
     */
    public function test_xuat_xlsx_kem_so_tra_tien(): void
    {
        $this->fakeApi();

        $xml = $this->doSheet(
            $this->withSession($this->phien())->get('/admin/purchase-orders/7/export')->getContent());

        $this->assertStringContainsString('Sổ trả tiền', $xml);
        $this->assertStringContainsString('Luỹ kế sau lượt', $xml);
        $this->assertStringContainsString('Tiền mặt', $xml);
        $this->assertStringContainsString('Trần Thu Hà', $xml);
        // Số tiền của lượt trả phải là ô SỐ để Excel cộng được.
        $this->assertMatchesRegularExpression('/<c [^>]*><v>400000<\/v><\/c>/', $xml);
    }

    /** Mở tệp .xlsx trong bộ nhớ rồi trả về XML của sheet đầu. */
    protected function doSheet(string $tep): string
    {
        $tam = tempnam(sys_get_temp_dir(), 'kiem');
        file_put_contents($tam, $tep);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tam) === true, 'Tệp trả về không phải zip — .xlsx hỏng.');
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tam);

        $this->assertNotFalse($xml, 'Tệp .xlsx thiếu sheet.');

        return html_entity_decode((string) $xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Ô "Nhân viên mua hàng" phải hỏi API bằng ĐÚNG giá trị trạng thái.
     *
     * Lỗi đã xảy ra thật: gửi 'dang-lam' gạch ngang trong khi API nhận
     * 'dang_lam' gạch dưới. Repository ghép thẳng vào `WHERE status = ?` nên
     * không dòng nào khớp — cửa hàng có đủ người mà ô chọn vẫn rỗng, lại chẳng
     * có lỗi nào nổi lên để mà lần ra.
     */
    public function test_o_nhan_vien_hoi_dung_trang_thai(): void
    {
        Http::fake([
            '*/admin/nhan-su*' => Http::response(['data' => [
                ['id' => 7, 'code' => 'NV0007', 'full_name' => 'Trần Thu Hà'],
            ]], 200),
            '*' => Http::response(['data' => [], 'meta' => []], 200),
        ]);

        $html = $this->withSession($this->phien())->get('/admin/purchase-orders')->getContent();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/admin/nhan-su')) {
                return false;
            }

            // Gạch DƯỚI, đúng domain.NhanSuDangLam bên API.
            return str_contains($req->url(), 'status=dang_lam');
        });

        // Và người đó phải hiện ra trong ô chọn.
        $dau = strpos($html, 'name="purchaser_id"');
        $this->assertNotFalse($dau, 'Hộp lập phiếu không dựng ô chọn nhân viên mua hàng.');
        $o = substr($html, $dau, strpos($html, '</select>', $dau) - $dau);
        $this->assertStringContainsString('Trần Thu Hà', $o);
        $this->assertStringContainsString('NV0007', $o);
    }

    /**
     * Ô "Chọn nhóm hàng" chỉ bày nhóm CÓ hàng, không bày nhóm rỗng.
     *
     * Trang đọc đường riêng của phiếu mua chứ không lấy cả danh mục nhóm: ô này
     * để lọc ô tìm hàng, nên nó phải bày ra đúng những nhóm ô tìm hàng tra ra
     * được. Bày cả nhóm rỗng thì chọn vào là bảng trắng.
     */
    public function test_o_nhom_hang_chi_bay_nhom_co_hang(): void
    {
        Http::fake([
            '*/admin/phieu-mua-hang/nhom-hang*' => Http::response(['data' => [
                ['id' => 3, 'name' => 'Thiet bi dien tu', 'so_mat_hang' => 2],
            ]], 200),
            // Đường danh mục nhóm chung KHÔNG được dùng ở đây; nếu ai đó đổi
            // lại thì "Nhom rong" sẽ lọt vào ô chọn và bài này đỏ.
            '*/categories*' => Http::response(['data' => [
                ['id' => 3, 'name' => 'Thiet bi dien tu'],
                ['id' => 9, 'name' => 'Nhom rong'],
            ]], 200),
            '*' => Http::response(['data' => [], 'meta' => []], 200),
        ]);

        $html = $this->withSession($this->phien())->get('/admin/purchase-orders')->getContent();
        $o = $this->oNhomHang($html);

        $this->assertStringContainsString('Thiet bi dien tu (2)', $o);
        $this->assertStringNotContainsString('Nhom rong', $o);
        $this->assertStringContainsString('Chọn nhóm danh mục', $o);
        $this->assertStringNotContainsString('disabled', $o);
    }

    /** Hỏi được mà chưa nhóm nào có hàng: nói đúng như vậy, và khoá ô lại. */
    public function test_o_nhom_hang_khi_chua_nhom_nao_co_hang(): void
    {
        Http::fake([
            '*/admin/phieu-mua-hang/nhom-hang*' => Http::response(['data' => []], 200),
            '*' => Http::response(['data' => [], 'meta' => []], 200),
        ]);

        $o = $this->oNhomHang($this->withSession($this->phien())->get('/admin/purchase-orders')->getContent());

        $this->assertStringContainsString('Chưa nhóm nào có hàng', $o);
        $this->assertStringContainsString('disabled', $o);
    }

    /**
     * KHÔNG hỏi được thì phải nói khác hẳn.
     *
     * Gộp chung với "chưa nhóm nào có hàng" là một API cũ hay một lượt mạng chập
     * trông y hệt một kho chưa có mặt hàng nào — người dùng đi tìm lỗi nhầm chỗ.
     */
    public function test_o_nhom_hang_khi_khong_doc_duoc(): void
    {
        Http::fake([
            '*/admin/phieu-mua-hang/nhom-hang*' => Http::response(['message' => 'khong co duong nay'], 404),
            '*' => Http::response(['data' => [], 'meta' => []], 200),
        ]);

        $o = $this->oNhomHang($this->withSession($this->phien())->get('/admin/purchase-orders')->getContent());

        $this->assertStringContainsString('Không đọc được nhóm hàng', $o);
        $this->assertStringNotContainsString('Chưa nhóm nào có hàng', $o);
        $this->assertStringContainsString('disabled', $o);
    }

    /** Cắt lấy riêng ô chọn nhóm hàng trong trang. */
    protected function oNhomHang(string $html): string
    {
        $dau = strpos($html, 'class="form-control select-categories"');
        $this->assertNotFalse($dau, 'Trang không dựng ô chọn nhóm hàng.');
        $cuoi = strpos($html, '</select>', $dau);

        return substr($html, $dau, $cuoi - $dau);
    }

    /**
     * Số lô và hạn dùng gõ trên lưới phải đi được tới API.
     *
     * Lỗi đã xảy ra thật: controller nắn lại mảng items theo một danh sách khoá
     * cứng, và hai khoá này không có trong danh sách — người dùng gõ xong là bay
     * mất, không báo gì, còn hai cột của migration 0042 thì không bao giờ ghi
     * được từ màn hình.
     */
    public function test_so_lo_va_han_dung_di_toi_api(): void
    {
        Http::fake([
            '*/admin/phieu-mua-hang' => Http::response(['data' => ['id' => 9, 'po_code' => 'PMH01']], 201),
            '*' => Http::response(['data' => []], 200),
        ]);

        $this->withSession($this->phien())->post('/admin/purchase-orders', [
            'supplier_name' => 'Cong ty A',
            'items' => json_encode([[
                'variant_id' => 5, 'quantity' => 2, 'unit_cost' => 1000,
                'lot_number' => 'L2026-08', 'expire_date' => '2027-08-22',
            ]]),
        ]);

        Http::assertSent(function ($req) {
            if (! str_ends_with($req->url(), '/admin/phieu-mua-hang')) {
                return false;
            }
            $dong = $req->data()['items'][0] ?? [];

            return ($dong['lot_number'] ?? null) === 'L2026-08'
                && ($dong['expire_date'] ?? null) === '2027-08-22';
        });
    }

    /**
     * Lưu hỏng lúc SỬA thì hộp thoại phải mở lại ở chế độ sửa.
     *
     * Mở lại ở chế độ thêm thì lượt gửi sau đẻ ra một phiếu nháp thứ hai, còn
     * phiếu gốc vẫn nguyên như cũ.
     */
    public function test_luu_hong_luc_sua_thi_mo_lai_dung_phieu(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())
            ->withSession(['_old_input' => [
                'id' => 42,
                'supplier_name' => 'Cong ty A',
                'items' => '[{"variant_id":5,"quantity":1,"unit_cost":1000}]',
                'items_meta' => '[{"variant_id":5,"quantity":1,"unit_cost":1000,"units":[]}]',
            ]])
            ->get('/admin/purchase-orders');

        $res->assertOk();
        $html = $res->getContent();

        // Lưới hàng vừa gõ phải quay lại được trong hộp thoại...
        $this->assertStringContainsString('cu.items_meta', $html);
        // ...và đoạn khôi phục phải rẽ theo id chứ không cứng ở "lập mới".
        $this->assertStringContainsString('moPhieu(id ? { id } : null)', $html);
        $this->assertStringContainsString('const id = Number(cu.id) || 0;', $html);
    }

    /**
     * Bấm "Duyệt" trong hộp lập phiếu thì phải GỬI ĐI ĐƯỢC lượt duyệt.
     *
     * Lỗi đã xảy ra thật: ApiClient gửi mảng PHP rỗng, PHP mã hoá thành `[]` —
     * một MẢNG JSON — và bên Go bind vào struct thì trượt, trả 422. Kết quả:
     * phiếu lưu xong nằm lại ở "lưu tạm", hàng không bao giờ vào kho, mà người
     * dùng chỉ thấy một câu lỗi mơ hồ.
     */
    public function test_duyet_sau_khi_luu_gui_di_object_json(): void
    {
        Http::fake([
            '*/admin/phieu-mua-hang/*/duyet' => Http::response(['data' => ['id' => 9, 'po_code' => 'PMH01']], 200),
            '*/admin/phieu-mua-hang' => Http::response(['data' => ['id' => 9, 'po_code' => 'PMH01']], 201),
            '*' => Http::response(['data' => []], 200),
        ]);

        $res = $this->withSession($this->phien())->post('/admin/purchase-orders', [
            'duyet' => 1,
            'supplier_name' => 'Cong ty A',
            'items' => json_encode([['variant_id' => 5, 'quantity' => 2, 'unit_cost' => 1000]]),
        ]);

        $res->assertRedirect();
        $res->assertSessionHas('success');

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/duyet')) {
                return false;
            }

            // Thân phải là OBJECT `{...}`, không phải mảng `[]`.
            $than = $req->body();

            return str_starts_with(trim($than), '{') && array_key_exists('note', $req->data());
        });
    }

    /**
     * Đường thêm nhanh nhà cung cấp chỉ nói JSON — kể cả khi dữ liệu sai.
     *
     * $request->validate() rẽ theo header Accept: thiếu header là nó trả về một
     * lượt CHUYỂN TRANG, và bên kia res.json() vỡ với câu lỗi chẳng liên quan
     * tới ô nhập nào.
     */
    public function test_them_nhanh_ncc_luon_tra_json(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $res = $this->withSession($this->phien())
            ->call('POST', '/admin/purchase-orders/quick-supplier', [], [], [], [], json_encode(['name' => 'Thieu dia chi']));

        $res->assertStatus(422);
        $this->assertJson($res->getContent());
        $this->assertArrayHasKey('address', json_decode($res->getContent(), true)['errors']);
    }

    /**
     * Hộp "Thêm nhà cung cấp" trên trang Phiếu mua hàng phải là hộp DÙNG CHUNG,
     * không phải một bản chép riêng bị cắt bớt ô.
     *
     * Hai trang giờ cùng đứng trên khuôn v2 nên so thẳng được với nhau: bộ ô của
     * hộp bên Phiếu mua hàng phải PHỦ ĐỦ bộ ô của hộp bên Nhà cung cấp.
     */
    public function test_hop_them_ncc_la_hop_dung_chung_va_du_o(): void
    {
        $this->fakeApi();

        $mua = $this->withSession($this->phien())->get('/admin/purchase-orders');
        $mua->assertOk();
        $a = $this->hopNCC($mua->getContent(), 'id="modalCreateSupplier"');
        $this->assertNotSame('', $a, 'Trang Phiếu mua hàng không dựng hộp thêm nhà cung cấp.');

        $ncc = $this->withSession($this->phien())->get('/admin/suppliers');
        $ncc->assertOk();
        $b = $this->hopNCC($ncc->getContent(), 'id="modalCrUd"');
        $this->assertNotSame('', $b, 'Trang Nhà cung cấp không dựng hộp thêm / sửa.');

        // Ô nào bên kia có thì bên này phải có — không được là bản rút gọn.
        $this->assertNotEmpty($this->oCuaHop($b));
        foreach ($this->oCuaHop($b) as $o) {
            $this->assertContains($o, $this->oCuaHop($a), 'Hộp thêm nhà cung cấp thiếu ô "'.$o.'".');
        }

        // Và các nhãn phải hiện ra thật, không chỉ là class trống.
        foreach (['Mã nhà cung cấp', 'Tên viết tắt', 'Thông tin người đại diện',
            'Số điện thoại người đại diện', 'Hình ảnh', 'Trạng thái', 'Địa chỉ 2', 'Ghi chú'] as $nhan) {
            $this->assertStringContainsString($nhan, $a, 'Hộp thêm nhà cung cấp thiếu ô "'.$nhan.'".');
        }
    }

    /** Cắt lấy phần thân một hộp thoại, tính từ id của nó tới hết thẻ modal. */
    protected function hopNCC(string $html, string $moc): string
    {
        $dau = strpos($html, $moc);
        if ($dau === false) {
            return '';
        }
        $cuoi = strpos($html, 'modal-footer', $dau);

        return $cuoi === false ? '' : substr($html, $dau, $cuoi - $dau);
    }

    /** Tên các ô nhập trong hộp — khuôn v2 đặt chúng bằng class `ip_*`. */
    protected function oCuaHop(string $than): array
    {
        preg_match_all('/\bip_[a-z0-9_]+/', $than, $m);

        // `ip_img` là ô chọn tệp, `ip_image` là ô ẩn giữ đường dẫn — cùng một việc.
        return array_values(array_unique(array_diff($m[0], ['ip_img'])));
    }

    /** Cắt lấy nguyên thẻ <input> của một ô tick theo id. */
    protected function oTick(string $html, string $id): string
    {
        $viTri = strpos($html, 'id="'.$id.'"');
        $this->assertNotFalse($viTri, 'Bộ lọc thiếu ô tick "'.$id.'".');
        $dau = strrpos($html, '<input', $viTri - strlen($html));

        return substr($html, $dau, strpos($html, '>', $dau) - $dau);
    }

    public function test_loc_nhieu_trang_thai_giu_tren_url(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())
            ->get('/admin/purchase-orders?status[]=draft&status[]=approved&payment_status=unpaid');

        $res->assertOk();
        $html = $res->getContent();

        // Ba ô tick phải mở lại đúng như URL nói, không phải chỉ vẽ lại danh sách.
        foreach (['order_status_draft', 'order_status_approved', 'payment_status_unpaid'] as $id) {
            $this->assertStringContainsString('checked', $this->oTick($html, $id),
                'Ô tick "'.$id.'" không mở lại theo URL.');
        }

        // Còn ô không nằm trên URL thì phải để trống.
        $this->assertStringNotContainsString('checked', $this->oTick($html, 'order_status_cancelled'));
    }

    /**
     * Mọi ô ngày của hộp lập phiếu đi qua lịch tiếng Việt, KHÔNG dùng ô ngày của
     * trình duyệt.
     *
     * `input[type=date]` bày sẵn mặt nạ "dd/mm/yyyy" nên ô đang trống nhìn như đã
     * có gì đó, và khuôn của nó lại khác hẳn hai ô ngày bên khung lọc.
     */
    public function test_hop_lap_phieu_khong_dung_o_ngay_cua_trinh_duyet(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/purchase-orders')->getContent();

        $this->assertStringNotContainsString('type="date"', $html,
            'Còn ô ngày của trình duyệt — nó kéo theo mặt nạ dd/mm/yyyy.');
        $this->assertStringContainsString('class="form-control ip-ngay document_date"', $html);
        $this->assertStringContainsString('name="expected_date" class="form-control ip-ngay"', $html);

        // Ngày chứng từ điền sẵn hôm nay khi LẬP MỚI; hẹn giao thì để trống.
        $this->assertStringContainsString('moment().format(KHUON_NGAY)', $html);
    }

    /**
     * Hai ô CHỌN của lưới hàng (Đơn vị, Số lô) phải được trình xử lý `input` bỏ qua.
     *
     * `<select>` bắn cả `input` lẫn `change`, và `input` tới trước. Trình xử lý
     * chung của lưới không nhận ra hai tên đó sẽ rơi xuống nhánh vẽ lại lưới —
     * lưới dựng đè từ dữ liệu CHƯA đổi nên ô chọn bật về giá trị cũ, và người dùng
     * thấy đúng một chuyện: bấm chọn mà không ăn gì.
     */
    public function test_hai_o_chon_cua_luoi_khong_bi_ve_de(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/purchase-orders')->getContent();

        $this->assertStringContainsString("if (f === 'unit_id' || f === 'lot_chon') return;", $html,
            'Thiếu chốt chặn — hai ô chọn của lưới sẽ bị vẽ đè ngay khi vừa bấm.');
    }

    /**
     * Mở màn không kèm tham số: đã lọc sẵn THÁNG NÀY, đúng như bản v2 — và hai ô
     * ngày phải HIỆN đúng khoảng ấy, không để trống.
     *
     * Khoảng mặc định giờ là đường duy nhất: hai đường tắt "Tháng này" / "Mọi thời
     * gian" đã gỡ, người dùng chỉ còn chọn ngày trực tiếp trên hai ô.
     */
    public function test_mo_man_loc_san_thang_nay(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())
            ->get('/admin/purchase-orders')
            ->assertOk()
            ->getContent();

        Http::assertSent(fn ($req) => ! str_contains($req->url(), '/admin/phieu-mua-hang?')
            || (str_contains($req->url(), 'from_date='.date('Y-m-01'))
                && str_contains($req->url(), 'to_date='.date('Y-m-d'))));

        $this->assertStringContainsString('value="'.date('01-m-Y').'"', $html);
        $this->assertStringContainsString('value="'.date('d-m-Y').'"', $html);

        // Không còn đường tắt nào dưới hai ô ngày.
        $this->assertStringNotContainsString('pmh-thang-nay', $html);
        $this->assertStringNotContainsString('pmh-moi-thoi-gian', $html);
    }

    /**
     * Xoá trắng ô ngày = bỏ lọc ngày. Khung lọc gửi hai tham số RỖNG, và đó phải
     * tắt được bộ lọc mặc định.
     *
     * Chỗ dễ hỏng: nếu controller rẽ theo giá trị rỗng thay vì theo `has()`, thì
     * "không gửi gì" và "cố ý gửi rỗng" trông giống hệt nhau — xoá ô xong bảng vẫn
     * chỉ có tháng này và không có cách nào xem phiếu cũ.
     */
    public function test_xoa_trang_o_ngay_tat_duoc_loc_mac_dinh(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->get('/admin/purchase-orders?from_date=&to_date=')
            ->assertOk();

        Http::assertSent(fn ($req) => ! str_contains($req->url(), '/admin/phieu-mua-hang?')
            || ! str_contains($req->url(), 'from_date=2'));
    }

    /**
     * Ô Ngày tạo đơn giữ ba nết của bản v2: không cho chọn ngày tương lai, hai ô
     * kẹp vào nhau, và lịch không mở ra ngoài màn.
     *
     * Cả ba đều là thứ chỉ lộ ra khi bấm thử trên màn hình — quên một cái thì
     * không có gì đỏ, chỉ có người dùng chọn ra một khoảng không bao giờ có dòng
     * nào, hoặc mở lịch ra thì nó nằm ngoài màn.
     */
    public function test_o_ngay_giu_net_cua_v2(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/purchase-orders')->getContent();

        $this->assertStringContainsString('maxDate: homNay', $html, 'Ô ngày phải chặn ngày tương lai.');
        $this->assertStringContainsString('function ketNgay()', $html, 'Hai ô ngày phải kẹp vào nhau.');

        // Hướng mở lịch tính theo CHỖ TRỐNG QUANH Ô, không theo bề ngang màn: hai
        // ô lọc gắn lịch từ lúc tải trang, lúc ấy khung lọc còn nằm trong tấm
        // offcanvas chưa mở nên chưa có toạ độ nào để đo.
        $this->assertStringContainsString('function huongLichCuaO(', $html);
        $this->assertMatchesRegularExpression(
            "/on\('mousedown focusin', '#from_date, #to_date'/",
            $html,
            'Hai ô ngày của bộ lọc phải tính lại hướng mở ngay trước mỗi lượt mở.'
        );
    }

    /**
     * Ô số lô là ô CHỌN các lô đang có, không phải ô gõ tay — và bắt buộc điền.
     *
     * Từ khi lô là một chiều của tồn kho (migration 0047), gõ sai một ký tự là đẻ
     * ra một lô mới trông y hệt lô cũ, và từ đó sổ kho có hai lô mà kho chỉ có một.
     */
    public function test_o_so_lo_la_o_chon_va_bat_buoc(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/purchase-orders')->getContent();

        // Cột Số lô mang dấu bắt buộc, và lượt lưu chặn dòng thiếu lô.
        $this->assertMatchesRegularExpression(
            '/Số lô\s*<span class="required">\*<\/span>/u', $html,
            'Cột Số lô phải mang dấu bắt buộc như bản v2.');
        $this->assertStringContainsString('chưa chọn số lô.', $html);

        // Ô dựng bằng JS: phải là <select> kèm mục "Lô mới", không phải input.
        $this->assertStringContainsString('data-f="lot_chon"', $html);
        $this->assertStringContainsString('+ Lô mới…', $html);
    }

    /**
     * Bộ lọc giữ đúng thứ tự khối của bản v2.
     *
     * Khối "Hàng hoá" của v2 đã bỏ theo yêu cầu (lọc theo mặt hàng nay chỉ còn đi
     * qua URL), nên danh sách dưới đây là v2 trừ khối ấy.
     */
    public function test_bo_loc_du_khoi_cua_v2(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/purchase-orders')->getContent();

        $truoc = -1;
        foreach (['filterSearchKey', 'filterCreateAt',
            'filterOrderStatus', 'filterStockReceiptStatus', 'filterPaymentStatus'] as $khoi) {
            $viTri = strpos($html, 'id="'.$khoi.'"');
            $this->assertNotFalse($viTri, 'Bộ lọc thiếu khối "'.$khoi.'".');
            $this->assertGreaterThan($truoc, $viTri, 'Khối "'.$khoi.'" đứng sai thứ tự so với v2.');
            $truoc = $viTri;
        }
    }

    /**
     * Lọc theo mặt hàng chỉ còn đi qua URL nhưng vẫn phải tới được API.
     *
     * Ô "Hàng hoá" đã gỡ khỏi khung lọc, nhưng đường dẫn cũ ai đó đã lưu lại vẫn
     * phải lọc đúng — bỏ luôn tham số là link ấy im lặng trả về cả sổ.
     */
    public function test_loc_hang_hoa_di_toi_api(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->get('/admin/purchase-orders?variant_id=8,3,8&status[]=xx')
            ->assertOk();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/admin/phieu-mua-hang?')) {
                return false;
            }

            // Trùng id thì gộp, id lạ thì bỏ — chuỗi gửi đi phải sạch.
            return str_contains($req->url(), 'variant_id=8%2C3');
        });
    }

    /**
     * Dòng hàng mới bắt đầu từ số lượng 0 — người lập phiếu tự gõ số thật.
     *
     * Điền sẵn 1 thì lúc nào cũng phải xoá đi rồi mới gõ, và dòng nào quên gõ lại
     * lặng lẽ trôi vào phiếu với số lượng 1. Lượt lưu phải chặn dòng số 0, không
     * thì đổi mặc định thành 0 là mở đường cho phiếu rỗng.
     */
    public function test_dong_hang_moi_bat_dau_tu_so_luong_0(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())
            ->get('/admin/purchase-orders')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('quantity: ghiDe ? Number(ghiDe.quantity || 0) : 0,', $html);
        $this->assertStringContainsString('Phiếu chưa có dòng hàng nào có số lượng.', $html);
    }

    /**
     * Hộp thanh toán gửi đủ phần thoả thuận nợ xuống API.
     *
     * Bên v2 mấy trường này ghi vào bảng `cab_debt`; bên mình nằm thẳng trên
     * phiếu (xem migration 0048). Điều phải giữ là chúng ĐI TỚI NƠI — thiếu một
     * cái thì "còn nợ 3.000.000" là con số chết, không biết hẹn hôm nào.
     */
    public function test_thanh_toan_gui_du_thoa_thuan_no(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->post('/admin/purchase-orders/7/payment', [
                'paid_amount' => 400000,
                'note' => 'chuyen khoan dot 1',
                'payment_method' => 'transfer',
                'payment_attachment' => 'uploads/uy-nhiem-chi.jpg',
                'is_debt' => 1,
                'debt_due_date' => '2026-12-31',
                'debt_contact_name' => 'Anh Ba',
                'debt_contact_phone' => '0900000000',
            ])
            ->assertRedirect();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/7/thanh-toan')) {
                return false;
            }
            $d = $req->data();

            return $d['paid_amount'] === 400000.0
                && $d['payment_method'] === 'transfer'
                && $d['payment_attachment'] === 'uploads/uy-nhiem-chi.jpg'
                && $d['is_debt'] === true
                && $d['debt_due_date'] === '2026-12-31'
                && $d['debt_contact_name'] === 'Anh Ba'
                && $d['debt_contact_phone'] === '0900000000';
        });
    }

    /**
     * KHÔNG ghi nợ thì ba trường đi kèm gửi đi RỖNG, kể cả khi trình duyệt lỡ
     * kèm theo — nếu không, server phải tự đoán xem nên tin cái nào.
     */
    public function test_khong_ghi_no_thi_khong_gui_han_va_nguoi_doi(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->post('/admin/purchase-orders/7/payment', [
                'paid_amount' => 1000000,
                'payment_method' => 'cash',
                'is_debt' => 0,
                'debt_due_date' => '2026-12-31',
                'debt_contact_name' => 'Anh Ba',
                'debt_contact_phone' => '0900000000',
            ])
            ->assertRedirect();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/7/thanh-toan')) {
                return false;
            }
            $d = $req->data();

            return $d['is_debt'] === false
                && $d['debt_due_date'] === ''
                && $d['debt_contact_name'] === ''
                && $d['debt_contact_phone'] === '';
        });
    }

    /** Hộp thanh toán mang đủ ô của v2, và Ghi nợ là công tắc bày ba ô kia ra. */
    public function test_hop_thanh_toan_du_o_cua_v2(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())
            ->get('/admin/purchase-orders')
            ->assertOk()
            ->getContent();

        foreach (['pay_debt', 'pay_method', 'pay_total', 'pay_amount', 'pay_debt_days',
            'pay_debt_date', 'pay_contact_name', 'pay_contact_phone', 'pay_file', 'pay_note'] as $o) {
            $this->assertStringContainsString($o, $html, "Hộp thanh toán thiếu ô $o");
        }

        // Ba ô chỉ có nghĩa khi ghi nợ phải mở màn ở trạng thái GẤP.
        $this->assertSame(3, substr_count($html, 'pay-khoi-no d-none'));

        // Hai hình thức của v2, không hơn.
        $this->assertStringContainsString('<option value="cash">', $html);
        $this->assertStringContainsString('<option value="transfer">', $html);
    }

    /** Mặc định 10 dòng một trang, và đường gọi API cũng phải mang đúng con số ấy. */
    public function test_mac_dinh_muoi_dong_mot_trang(): void
    {
        $this->fakeApi();

        $this->assertSame(10, PhieuMuaHangController::SO_DONG_MOI_TRANG);

        $this->withSession($this->phien())->get('/admin/purchase-orders')->assertOk();

        Http::assertSent(fn ($req) => ! str_contains($req->url(), '/admin/phieu-mua-hang?')
            || str_contains($req->url(), 'page_size=10'));
    }

    /**
     * Dãy nút ngoài bảng: không còn Duyệt, và bốn thứ còn lại phải có khe hở.
     *
     * Duyệt hàng loạt gỡ khỏi màn này — duyệt từng phiếu nằm trong hộp phiếu, ở
     * đó còn nhìn thấy dòng hàng trước khi bấm. Vỏ v2 chỉ đặt
     * `div.btn_top_content { display: flex }` mà không có gap nên dãy nút dính
     * liền thành một dải chữ.
     */
    public function test_day_nut_ngoai_bang(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())
            ->get('/admin/purchase-orders')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('mass-approve', $html);
        $this->assertStringNotContainsString('id="modalApprove"', $html);
        $this->assertMatchesRegularExpression('/\.btn_top_content\s*\{[^}]*gap:/', $html);

        // Ba thứ còn lại vẫn đủ.
        $this->assertStringContainsString('btn_create', $html);
        $this->assertStringContainsString('mass-delete', $html);
        $this->assertStringContainsString('btn_print_list', $html);
    }

    /**
     * Nâng cao → In phải TICK dòng trước rồi mới in.
     *
     * Trước đây nút này gọi thẳng `window.print()`, tức in cả trang quản trị —
     * thanh điều hướng, khung lọc, phân trang. Tờ giấy ấy không mang đi đối
     * chiếu với nhà cung cấp được.
     */
    public function test_in_phai_chon_dong_truoc(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())
            ->get('/admin/purchase-orders')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString("'.btn_print_list', () => window.print()", $html);
        $this->assertStringContainsString('Chọn phiếu muốn in ở cột đầu bảng đã.', $html);
        // Nhiều phiếu thì mỗi tờ một trang giấy.
        $this->assertStringContainsString('page-break-after:always', $html);
    }

    /** Khung lọc không còn ô Số lô, và cũng không còn gửi tham số ấy đi. */
    public function test_khung_loc_khong_con_o_so_lo(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())
            ->get('/admin/purchase-orders?lot_number=L2026')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('filterLotNumber', $html);
        $this->assertStringNotContainsString('name="lot_number"', $html);

        Http::assertSent(fn ($req) => ! str_contains($req->url(), '/admin/phieu-mua-hang?')
            || ! str_contains($req->url(), 'lot_number='));
    }

    /**
     * Ô "Trạng thái phiếu nhập kho" gộp vào trạng thái phiếu trước khi gọi API.
     *
     * API chỉ biết trạng thái phiếu. Tick "Chưa nhập kho" mà gửi đi chuỗi rỗng thì
     * bảng bày ra CẢ phiếu đã nhập kho — đúng những dòng người dùng vừa loại ra.
     */
    public function test_trang_thai_kho_gop_vao_trang_thai_phieu(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->get('/admin/purchase-orders?warehouse_status[]=not_in')
            ->assertOk();

        Http::assertSent(fn ($req) => ! str_contains($req->url(), '/admin/phieu-mua-hang?')
            || str_contains($req->url(), 'status=draft'));
    }

    /**
     * Hộ kinh doanh nộp thuế trực tiếp: chiều mua không còn đường VAT nào.
     *
     * Bản v2 gọi là admin('tax_type')=='direct'; bên mình là công tắc `tax_direct`
     * trong Cài đặt.
     */
    public function test_thue_truc_tiep_giau_moi_thu_ve_vat(): void
    {
        $this->fakeApi();
        $this->batThueTrucTiep(true);

        $html = $this->withSession($this->phien())->get('/admin/purchase-orders')->getContent();

        // Lưới hàng đổi nhãn cột và mang cờ để CSS giấu ba cột VAT.
        $this->assertStringContainsString('class="table-product is-thue-truc-tiep"', $html);
        $this->assertStringContainsString('const THUE_TRUC_TIEP = true;', $html);

        // Hai ô thuế của phiếu và hai dòng thuế ở khối tiền đều gập lại.
        // Khớp lỏng theo class chứ không theo nguyên chuỗi thuộc tính: đổi khung
        // của hộp thoại thì bài này phải vẫn đo đúng thứ nó định đo.
        $this->assertMatchesRegularExpression('/class="[^"]*o-vat-phieu[^"]*d-none[^"]*"/', $html);

        // Tờ in cũng phải bỏ cột VAT, không thì màn hình nói một đằng còn giấy
        // in ra nói một nẻo.
        $this->assertStringContainsString(
            "const cotVat = (noiDung) => (THUE_TRUC_TIEP ? '' : noiDung);", $html);

        // Và tệp .xlsx cũng vậy — nó dựng bên máy chủ nên phải đo trên chính tệp.
        $xml = $this->doSheet(
            $this->withSession($this->phien())->get('/admin/purchase-orders/7/export')->getContent());
        $this->assertStringNotContainsString('VAT %', $xml);
        $this->assertStringNotContainsString('Tổng tiền thuế (VAT)', $xml);
        $this->assertStringContainsString('Thành tiền', $xml);
        // Khối tiền theo khung của v2: hai ô VAT là hai cột `col-lg-*` mang d-none,
        // còn ô Tổng tiền thì luôn hiện.
        $this->assertMatchesRegularExpression('/col-lg-5 d-none"[\s\S]{0,800}tong-tien-hang/', $html);
        $this->assertMatchesRegularExpression('/col-lg-4 d-none"[\s\S]{0,800}tong-thue/', $html);
    }

    /** Tắt công tắc thì màn hình phải y như cũ — đây là mặc định của mọi cửa hàng. */
    public function test_khong_bat_thue_truc_tiep_thi_giu_nguyen_cot_vat(): void
    {
        $this->fakeApi();
        $this->batThueTrucTiep(false);

        $html = $this->withSession($this->phien())->get('/admin/purchase-orders')->getContent();

        // Đo trên THẺ BẢNG, không phải cả trang: luật CSS `.is-thue-truc-tiep`
        // lúc nào cũng nằm trong <style>, có tìm thấy chuỗi đó cũng không nói gì.
        $this->assertStringContainsString('class="table-product "', $html);
        $this->assertStringContainsString('const THUE_TRUC_TIEP = false;', $html);
        $this->assertStringContainsString('Thành tiền (chưa VAT)', $html);
    }

    /**
     * Giấu ô thôi thì chưa đủ: payload gửi API phải ghi thuế 0.
     *
     * Cửa hàng bật công tắc giữa chừng, hay một lượt gửi lại sau khi lưu hỏng, đều
     * mang theo con số thuế cũ — không ghi đè thì phiếu có thuế mà trên màn hình
     * không chỗ nào nói ra.
     */
    public function test_thue_truc_tiep_ep_thue_ve_khong_khi_luu(): void
    {
        Http::fake([
            '*/admin/phieu-mua-hang' => Http::response(['data' => ['id' => 9, 'po_code' => 'PMH01']], 201),
            '*' => Http::response(['data' => []], 200),
        ]);
        $this->batThueTrucTiep(true);

        $this->withSession($this->phien())->post('/admin/purchase-orders', [
            'supplier_name' => 'Cong ty A',
            'vat_mode' => 'goods',
            'vat_percent' => 10,
            'items' => json_encode([['variant_id' => 5, 'quantity' => 2, 'unit_cost' => 1000, 'vat_percent' => 8]]),
        ])->assertRedirect();

        Http::assertSent(function ($req) {
            if ($req->method() !== 'POST' || ! str_ends_with($req->url(), '/admin/phieu-mua-hang')) {
                return false;
            }
            $o = $req->data();

            return $o['vat_percent'] === 0
                && $o['vat_mode'] === 'order'
                && $o['items'][0]['vat_percent'] === 0;
        });
    }

    /**
     * Bật / tắt công tắc thuế trực tiếp cho một bài kiểm.
     *
     * Ghi thẳng vào cache của ApiClient thay vì giả thêm một lượt gọi API: khoá này
     * đi qua settingValues() vốn đã cache, giả API thì bài nào chạy sau vẫn đọc
     * phải giá trị của bài trước.
     */
    protected function batThueTrucTiep(bool $bat): void
    {
        // Khoá kèm mã cửa hàng — phải hỏi đúng hàm dựng khoá, không ghép tay.
        Cache::put(ApiClient::khoaCacheSettings(), ['tax_direct' => $bat ? '1' : '0'], 300);
    }

    /** Tick hai ô đá nhau thì phải ra bảng RỖNG, không phải ra mọi phiếu. */
    public function test_trang_thai_kho_da_nhau_thi_khong_ra_dong_nao(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->get('/admin/purchase-orders?status[]=approved&warehouse_status[]=not_in')
            ->assertOk();

        Http::assertSent(fn ($req) => ! str_contains($req->url(), '/admin/phieu-mua-hang?')
            || str_contains($req->url(), 'status=none'));
    }

    /**
     * Khối tiền cuối hộp lập phiếu phải giữ đúng hình của v2 cũ: khung
     * `wrapper-money-into`, ba ô CHỈ ĐỌC (chưa VAT / tiền thuế / tổng tiền), chứ
     * không phải một thẻ tổng kết tự chế. Bài này khoá lại để lần sau ai đó thấy
     * "cho đẹp hơn" thì cũng biết là đang đi lệch bản gốc.
     */
    public function test_khoi_tien_giu_dung_hinh_cua_v2(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())
            ->get('/admin/purchase-orders')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('wrapper-money-into', $html);

        foreach (['tong-tien-hang', 'tong-thue', 'tong-cong'] as $o) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*class="form-control '.$o.'"[^>]*readonly/',
                $html,
                "Ô $o phải là <input readonly> như v2"
            );
        }

        // v2 không đặt tiêu đề ở thanh nút trên cùng — chỉ có Lưu tạm và Duyệt.
        $this->assertStringNotContainsString('pmh-thanh-ten">Phiếu mua hàng', $html);
    }

    /**
     * MỘT hộp thoại cho cả ba cảnh — lập mới, sửa phiếu lưu tạm, xem phiếu đã
     * duyệt — đúng như v2 (bên đó cả con mắt lẫn mã phiếu đều nạp `edit.blade.php`
     * vào `#modalCreate`, rồi chính form ấy khoá từng ô khi phiếu đã duyệt).
     * Không còn hộp chi tiết riêng, nên không còn nhịp "xem rồi bấm Sửa".
     */
    public function test_chi_mot_hop_thoai_cho_ca_sua_lan_xem(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())
            ->get('/admin/purchase-orders')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('id="modalDetail"', $html);
        $this->assertStringNotContainsString('id="detail_foot"', $html);

        // Con mắt và mã phiếu cùng trỏ về một đường mở phiếu.
        $this->assertStringContainsString('class="detail-item"', $html);
        $this->assertStringContainsString('edit_bt detail-item', $html);

        // Cụm nút nằm trên thanh đầu, không phải chân hộp. HAI bộ như v2: bộ lập
        // mới (Lưu tạm xám · Duyệt xanh) và bộ sửa (Duyệt vàng · Lưu · Thanh toán ·
        // In · Xuất Excel); moPhieu() bật đúng một bộ.
        foreach (['btn_gray save-order pmh-nut-moi', 'btn_green save-order pmh-nut-moi',
            'btn-warning save-order pmh-nut-sua', 'btn_green save-order pmh-nut-sua',
            'pmh-tra', 'pmh-in', 'pmh-excel'] as $nut) {
            $this->assertMatchesRegularExpression(
                '/pmh-thanh-nut"[\s\S]{0,2400}'.preg_quote($nut, '/').'/',
                $html,
                "Nút $nut phải nằm trong thanh đầu hộp thoại"
            );
        }

        // Xoá và Huỷ KHÔNG ở trong hộp thoại — chúng là icon của cột Hành động.
        $this->assertStringNotContainsString('pmh-xoa', $html);
        $this->assertStringNotContainsString('pmh-huy', $html);
    }

    /**
     * Cột Hành động: con mắt cho mọi phiếu, thêm hai icon Huỷ và Xoá cho phiếu
     * LƯU TẠM. v2 cũng chỉ vẽ nút xoá khi `$item->status == 0` — phiếu đã duyệt
     * thì hai việc ấy không còn hợp lệ, bày ra chỉ để bấm vào rồi bị từ chối.
     */
    public function test_icon_huy_va_xoa_chi_co_o_dong_luu_tam(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())
            ->get('/admin/purchase-orders')
            ->assertOk()
            ->getContent();

        // Đo trên từng <tr>, không phải cả trang: tìm chuỗi trên cả trang thì chỉ
        // cần MỘT dòng lưu tạm là bài này xanh, kể cả khi dòng đã duyệt cũng vẽ.
        preg_match_all('/<tr class="item"[\s\S]*?<\/tr>/', $html, $m);
        $this->assertNotEmpty($m[0], 'Dữ liệu giả phải có ít nhất một dòng phiếu');

        $luuTam = 0;
        foreach ($m[0] as $tr) {
            $this->assertStringContainsString('class="detail-item"', $tr);

            if (str_contains($tr, 'data-status="draft"')) {
                $luuTam++;
                $this->assertStringContainsString('dele_bt delete-item', $tr);
                $this->assertStringContainsString('huy_bt cancel-item', $tr);
            } else {
                $this->assertStringNotContainsString('delete-item', $tr);
                $this->assertStringNotContainsString('cancel-item', $tr);
            }
        }

        $this->assertGreaterThan(0, $luuTam, 'Dữ liệu giả phải có ít nhất một phiếu lưu tạm');
    }
}
