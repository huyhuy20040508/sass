<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * KHÁCH TỰ GIA HẠN — bấm gói, trả tiền, hợp đồng dài thêm.
 *
 * Dùng Http::fake nên không cần API chạy thật. Thứ đang kiểm là phần việc của
 * Shop Admin: đưa người dùng đi đúng đường, và KHÔNG chặn đúng cái nút trả tiền
 * của người đang hết hạn — vì đó là kiểu hỏng tự cắt doanh thu của chính mình.
 */
class GiaHanTest extends TestCase
{
    protected function phien(bool $khoa = false): array
    {
        $s = [
            'api.access_token' => 'token-gia',
            'api.user' => ['id' => 1, 'role' => ['name' => 'super_admin', 'display_name' => 'Super Admin']],
        ];
        if ($khoa) {
            $s['phien.cua_hang_khoa'] = true;
        }

        return $s;
    }

    protected function apiTraDon(array $ghiDe = []): void
    {
        $don = array_merge([
            'id' => 12, 'ma_don' => 12, 'ten_goi' => 'Cửa hàng', 'so_thang' => 3,
            'so_tien' => 1497000, 'trang_thai' => 'cho_thanh_toan', 'da_tra' => false,
            'checkout_url' => 'https://pay.payos.vn/web/abc123',
            // Thông tin trả tiền do cổng trả về — trang tự dựng màn hình chuyển
            // khoản từ đây thay vì đá khách sang trang của cổng.
            'qr_code' => '00020101021238570010A00000072701270006970422...',
            'ngan_hang_bin' => '970422', 'so_tai_khoan' => '99998888',
            'chu_tai_khoan' => 'CONG TY SELLIOTECH', 'noi_dung' => 'GIAHAN ORDER1',
            'het_han_luc' => '2026-08-16T12:00:00Z', 'han_moi' => '2026-11-15T00:00:00Z',
            // Bên mua — trang phải nói rõ đang thu tiền cho cửa hàng nào.
            'ten_app' => 'Sellio Order', 'ma_cua_hang' => 'order1', 'ten_cua_hang' => 'Quốc Huy',
            'nguoi_lien_he' => 'Nguyễn Quốc Huy', 'dien_thoai' => '0901234567',
            'email' => 'huy@quochuy.vn',
        ], $ghiDe);

        Http::fake([
            '*/admin/goi-dich-vu/dat' => Http::response(['success' => true, 'data' => $don], 200),
            '*/admin/goi-dich-vu/don/*' => Http::response(['success' => true, 'data' => $don], 200),
            // Bắt nốt mọi đường còn lại (cấu hình cửa hàng mà layout đọc, lượt làm
            // mới hạn của middleware): không có dòng này thì mỗi lần dựng trang là
            // một lượt gọi ra ngoài thật, và bài kiểm chạy mất mươi giây mỗi cái.
            '*' => Http::response(['success' => true, 'data' => []], 200),
        ]);
    }

    public function test_bam_gia_han_thi_sang_trang_thanh_toan(): void
    {
        $this->apiTraDon();

        $res = $this->withSession($this->phien())->post('/admin/goi-dich-vu/gia-han', [
            'plan_id' => 2, 'so_luong' => 3, 'don_vi' => 'thang',
        ]);

        $res->assertRedirect(route('admin.goi-dich-vu.thanh-toan', 12));
    }

    /**
     * Trang thanh toán phải TỰ ĐỦ: số tiền, mã QR, và thông tin chuyển khoản tay.
     *
     * Nội dung chuyển khoản là thứ được kiểm kỹ nhất — nó là mảnh duy nhất nối
     * một lần tiền vào với một đơn. Thiếu nó trên màn hình thì khách chuyển khoản
     * với nội dung tự nghĩ, tiền vào tài khoản mà không đơn nào được chốt.
     */
    public function test_trang_thanh_toan_du_de_tra_tien_ngay_tai_cho(): void
    {
        $this->apiTraDon();

        $res = $this->withSession($this->phien())->get('/admin/goi-dich-vu/thanh-toan/12');

        $res->assertOk();
        $res->assertSee('1.497.000₫');
        $res->assertSee('3 tháng sử dụng');
        // Mã QR vẽ tại chỗ từ chuỗi VietQR của cổng.
        $res->assertSee('ttQR', false);
        $res->assertSee('00020101021238570010A00000072701270006970422...', false);
        // Bản chữ cho người không quét được.
        $res->assertSee('99998888');
        $res->assertSee('CONG TY SELLIOTECH');
        $res->assertSee('GIAHAN ORDER1');
        // Trang của cổng còn là ĐƯỜNG LUI cho ví / thẻ, không phải đường chính.
        $res->assertSee('https://pay.payos.vn/web/abc123', false);
    }

    /**
     * Trang phải nói rõ ĐANG THU TIỀN CHO AI.
     *
     * Người bấm trả tiền trên một máy dùng chung cần chắc mình đang gia hạn đúng
     * cửa hàng của mình — tiền đã chuyển thì không rút lại được.
     */
    public function test_trang_thanh_toan_hien_thong_tin_cua_hang(): void
    {
        $this->apiTraDon();

        $res = $this->withSession($this->phien())->get('/admin/goi-dich-vu/thanh-toan/12');

        $res->assertOk();
        $res->assertSee('Cửa hàng thanh toán');
        $res->assertSee('Quốc Huy');
        $res->assertSee('order1');
        $res->assertSee('Nguyễn Quốc Huy');
        $res->assertSee('0901234567');
        $res->assertSee('huy@quochuy.vn');
        // Phần mềm nào đang được gia hạn.
        $res->assertSee('Sellio Order');
    }

    /**
     * ĐƠN CŨ không có chuỗi QR (tạo bởi bản máy chủ trước migration 0017): trang
     * phải nói thẳng và đưa nút chính sang cổng.
     *
     * Không có nhánh này thì khách nhìn một cột "chuyển khoản thủ công" trống
     * trơn với đúng một dòng số tiền — trông như trang hỏng, và đường trả tiền
     * duy nhất còn lại là một link nhỏ ở cuối trang.
     */
    public function test_don_khong_co_qr_thi_dua_nut_chinh_sang_cong(): void
    {
        $this->apiTraDon(['qr_code' => '', 'so_tai_khoan' => '', 'chu_tai_khoan' => '', 'noi_dung' => '']);

        $res = $this->withSession($this->phien())->get('/admin/goi-dich-vu/thanh-toan/12');

        $res->assertOk();
        $res->assertSee('Trả tiền qua cổng');
        $res->assertSee('Mở trang thanh toán');
        // Vẫn theo dõi đơn: trả xong ở cổng thì trang này tự đổi trạng thái.
        $res->assertSee('trang này tự cập nhật');
    }

    /**
     * ĐƠN TỪ MÁY CHỦ BẢN CŨ — thiếu hết những trường thêm sau này.
     *
     * Trang đọc JSON của một máy chủ CÓ THỂ CŨ HƠN nó: khai trường mới bên Go rồi
     * triển khai giao diện trước là mọi lượt truy cập nổ "Undefined array key".
     * Đây là trang khách đang giữa chừng trả tiền — một màn hình 500 ở đây tệ hơn
     * hẳn ở bất kỳ trang nào khác, nên nó phải dựng được với đúng bốn trường tối
     * thiểu.
     */
    public function test_don_thieu_truong_moi_van_khong_no_trang(): void
    {
        Http::fake([
            '*/admin/goi-dich-vu/don/*' => Http::response(['success' => true, 'data' => [
                'id' => 6, 'so_thang' => 1, 'so_tien' => 10000, 'trang_thai' => 'cho_thanh_toan',
            ]], 200),
            '*' => Http::response(['success' => true, 'data' => []], 200),
        ]);

        $res = $this->withSession($this->phien())->get('/admin/goi-dich-vu/thanh-toan/6');

        $res->assertOk();
        $res->assertSee('10.000₫');
        // Không có QR lẫn thông tin chuyển khoản: trang phải đưa đường sang cổng.
        $res->assertSee('Trả tiền qua cổng');
    }

    /** Trả xong: trang nói ra HẠN MỚI, thứ khách chờ nghe nhất. */
    public function test_da_tra_thi_hien_han_moi(): void
    {
        $this->apiTraDon(['trang_thai' => 'da_thanh_toan', 'da_tra' => true]);

        $res = $this->withSession($this->phien())->get('/admin/goi-dich-vu/thanh-toan/12');

        $res->assertOk();
        $res->assertSee('Đã gia hạn xong');
        $res->assertSee('15/11/2026');
    }

    /**
     * CỬA HÀNG ĐANG BỊ KHOÁ vẫn phải đi hết được luồng thanh toán.
     *
     * Đây là bài quan trọng nhất của tệp: người hết hạn chính là người cần trả
     * tiền nhất. Chặn họ ở đúng cái nút đó thì cái khoá vừa dựng thành ra chặn
     * luôn doanh thu của chính mình, và khách quay lại cảnh phải gọi điện.
     */
    public function test_cua_hang_khoa_van_dat_don_va_tra_tien_duoc(): void
    {
        $this->apiTraDon();

        $this->withSession($this->phien(khoa: true))
            ->post('/admin/goi-dich-vu/gia-han', ['plan_id' => 2, 'so_luong' => 3, 'don_vi' => 'thang'])
            ->assertRedirect(route('admin.goi-dich-vu.thanh-toan', 12));

        $this->withSession($this->phien(khoa: true))
            ->get('/admin/goi-dich-vu/thanh-toan/12')
            ->assertOk();

        $this->withSession($this->phien(khoa: true))
            ->getJson('/admin/goi-dich-vu/don/12')
            ->assertOk();
    }

    /**
     * Chọn đơn vị NĂM thì API phải nhận số THÁNG đã quy đổi.
     *
     * API chỉ biết một đơn vị — tháng, vì đó là đơn vị của mọi lượt gia hạn dưới
     * database. Quy đổi nằm ở đúng một chỗ (controller này); hai nơi cùng biết
     * cách quy đổi là hai nơi để chúng lệch nhau.
     */
    public function test_don_vi_nam_duoc_quy_ve_thang(): void
    {
        $this->apiTraDon();

        $this->withSession($this->phien())->post('/admin/goi-dich-vu/gia-han', [
            'plan_id' => 2, 'so_luong' => 1, 'don_vi' => 'nam',
        ]);

        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/admin/goi-dich-vu/dat')
                && $req['so_thang'] === 12;
        });
    }

    /** Quá 24 tháng: chặn ngay tại đây, đừng bắt người dùng đi hết một vòng gọi API. */
    public function test_qua_24_thang_bi_chan(): void
    {
        $this->apiTraDon();

        $res = $this->withSession($this->phien())->post('/admin/goi-dich-vu/gia-han', [
            'plan_id' => 2, 'so_luong' => 5, 'don_vi' => 'nam',
        ]);

        $res->assertSessionHas('error');
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/admin/goi-dich-vu/dat'));
    }

    /** Trang gói dịch vụ có nút trả tiền cho gói CÓ GIÁ, và không có cho gói "Liên hệ". */
    public function test_bang_gia_chi_hien_nut_tra_tien_cho_goi_co_gia(): void
    {
        Http::fake(['*/admin/goi-dich-vu' => Http::response(['success' => true, 'data' => [
            'hop_dong' => [
                'ten_app' => 'Sellio Order', 'goi' => 'cua_hang', 'ten_goi' => 'Cửa hàng',
                'trang_thai' => 'active', 'chu_ky' => 'thang', 'gia' => 499000,
                'chi_nhanh' => 1, 'tai_khoan' => 0, 'san_pham' => 0, 'ten_mien_rieng' => false,
                'bat_dau' => '2026-01-15T00:00:00Z', 'het_han' => '2026-09-15T00:00:00Z',
                'con_lai_ngay' => 31, 'da_het_han' => false, 'dung_thu' => false,
            ],
            'bang_gia' => [
                ['id' => 2, 'code' => 'cua_hang', 'name' => 'Cửa hàng', 'tagline' => '',
                    'billing_cycle' => 'thang', 'price' => 499000, 'trial_days' => 0,
                    'status' => 'active', 'features' => []],
                ['id' => 3, 'code' => 'chuoi', 'name' => 'Chuỗi', 'tagline' => '',
                    'billing_cycle' => 'thang', 'price' => null, 'trial_days' => 0,
                    'status' => 'active', 'features' => []],
            ],
            'fields' => [],
        ]], 200)]);

        $res = $this->withSession($this->phien())->get('/admin/goi-dich-vu');

        $res->assertOk();
        $res->assertSee('Gia hạn');
        // Gói "Liên hệ" không có số nào để thu — API cũng từ chối đặt đơn cho nó,
        // nên trang phải mời liên hệ thay vì mời bấm.
        $res->assertSee('Liên hệ báo giá');
    }
}
