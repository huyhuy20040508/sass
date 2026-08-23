<?php

namespace Tests\Feature;

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

        // Nút và tiện ích của màn v2
        $res->assertSee('Lập phiếu mua');
        $res->assertSee('Xuất file (CSV)');
        $res->assertSee('Xem cột');
        $res->assertSee('Nâng cao');

        // Hộp thoại lập phiếu — bốn cột thông tin của bản v2
        $res->assertSee('Nhà cung cấp');
        $res->assertSee('SĐT người liên hệ');
        $res->assertSee('Ngày chứng từ');
        $res->assertSee('Ngày hết hạn');
        $res->assertSee('Nhân viên mua hàng');
        $res->assertSee('Số phiếu giao của NCC');
        $res->assertSee('Người tạo phiếu');
        $res->assertSee('Loại chứng từ');
        $res->assertSee('Trần Thu Hà');

        // Khối hàng hóa: lọc nhóm, ô tìm, dropdown Nâng cao
        $res->assertSee('Thông tin hàng hóa');
        $res->assertSee('Chọn nhóm hàng');
        $res->assertSee('Tìm hàng hóa');
        $res->assertSee('Tải file mẫu');

        // Mười ba cột của lưới hàng
        $res->assertSee('Mã hàng hóa');
        $res->assertSee('Đơn vị tính');
        $res->assertSee('Thành tiền trước thuế');
        $res->assertSee('Tiền VAT');
        $res->assertSee('Tổng tiền sau VAT');
        $res->assertSee('Số lô');
        $res->assertSee('Hạn dùng');

        // Khối tiền ba dòng
        $res->assertSee('Tổng tiền trước thuế');
        $res->assertSee('Tổng tiền thuế');
    }

    public function test_con_no_tinh_dung(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/purchase-orders');

        // 5.400.000 − 2.000.000 = 3.400.000
        $res->assertSee('3.400.000₫');
    }

    public function test_xuat_csv(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/purchase-orders/export');

        $res->assertOk();
        $noi = $res->streamedContent();
        $this->assertStringContainsString('Mã phiếu', $noi);
        $this->assertStringContainsString('PMH20260822002', $noi);
        $this->assertStringContainsString('Còn nợ', $noi);
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
        $dau = strpos($html, 'id="pmhNguoiMua"');
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
        $this->assertStringContainsString('Chọn nhóm hàng', $o);
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
        $dau = strpos($html, 'id="pmhNhomHang"');
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

        // Ô ẩn mang id phải có mặt để lượt mở lại biết đang sửa ai...
        $this->assertStringContainsString('id="pmhId"', $html);
        // ...và đoạn khôi phục phải rẽ theo id chứ không cứng ở 'add'.
        $this->assertStringContainsString("moForm(id ? 'edit' : 'add'", $html);
        $this->assertStringNotContainsString("moForm('add', null);

                    O.supplier_id", $html);
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
     * Hộp "Thêm nhà cung cấp" của hai trang phải là MỘT.
     *
     * Cả hai cùng gọi partials/modal-nha-cung-cap, nên bài này chỉ còn việc
     * canh: ai đó chép nó ra làm bản riêng cho một trang là đỏ ngay.
     */
    public function test_hop_them_ncc_giong_het_trang_nha_cung_cap(): void
    {
        $this->fakeApi();

        $mua = $this->withSession($this->phien())->get('/admin/purchase-orders');
        $ncc = $this->withSession($this->phien())->get('/admin/suppliers');

        $mua->assertOk();
        $ncc->assertOk();

        $a = $this->hopNCC($mua->getContent());
        $b = $this->hopNCC($ncc->getContent());

        $this->assertNotSame('', $a, 'Trang Phiếu mua hàng không dựng hộp thêm nhà cung cấp.');
        $this->assertSame($b, $a, 'Hộp thêm nhà cung cấp ở hai trang đã lệch nhau.');

        // Và nó phải là hộp ĐẦY ĐỦ, không phải bản rút gọn vài ô.
        foreach (['Mã nhà cung cấp', 'Tên viết tắt', 'Người đại diện', 'SĐT người đại diện',
            'Hình ảnh', 'Trạng thái', 'Địa chỉ 2', 'Ghi chú'] as $o) {
            $this->assertStringContainsString($o, $a, 'Hộp thêm nhà cung cấp thiếu ô "'.$o.'".');
        }
    }

    /**
     * Cắt lấy phần thân hộp thêm nhà cung cấp.
     *
     * Ô ẩn `return` mang đường dẫn của chính trang đang mở nên hai bên khác
     * nhau là đúng — chuẩn hoá nó đi rồi mới so.
     */
    protected function hopNCC(string $html): string
    {
        $dau = strpos($html, 'id="nccFormOverlay"');
        $cuoi = strpos($html, 'id="nccFormSubmit"');
        if ($dau === false || $cuoi === false || $cuoi < $dau) {
            return '';
        }

        $than = substr($html, $dau, $cuoi - $dau);

        return preg_replace('/name="return" value="[^"]*"/', 'name="return" value="*"', $than);
    }

    public function test_loc_nhieu_trang_thai_giu_tren_url(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())
            ->get('/admin/purchase-orders?status[]=draft&status[]=approved&payment_status=unpaid');

        $res->assertOk();
        // Hai ô tick phải mở lại đúng như URL nói.
        $res->assertSee('Xoá lọc');
    }
}
