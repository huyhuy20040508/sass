<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ca làm việc & sổ quỹ — phần giáp ranh giữa khu quản trị và Go API.
 *
 * Nghiệp vụ (cộng sổ, chênh lệch, một chi nhánh một ca) đã có bài kiểm chạy trên
 * API thật + MySQL thật ở api/internal/apitest. Ở đây kiểm những gì chỉ tầng này
 * quyết định: payload gửi đi, câu lỗi giữ nguyên văn, và MỘT điểm thiết kế quan
 * trọng — cụm ca làm việc hỏng thì màn hình quầy vẫn phải bán được.
 */
class CaLamViecTest extends TestCase
{
    protected function phienNhanVien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff']],
        ];
    }

    protected function caDangMo(): array
    {
        return ['data' => [
            'id' => 3, 'shop_id' => 1, 'opening_cash' => 500000,
            'opened_at' => '2026-08-17T08:00:00+07:00',
            'opened_by_name' => 'Nhân viên quầy',
            'closed_at' => null, 'counted_cash' => null,
            'expected_cash' => null, 'difference' => null,
            'tong_thu' => 180000, 'tong_chi' => 50000, 'so_don_tien_mat' => 2,
        ]];
    }

    /** Mở ca gửi đúng số tiền người trực vừa đếm. */
    public function test_mo_ca_gui_tien_dau_ca(): void
    {
        Http::fake(['*/admin/ca-lam-viec/mo' => Http::response($this->caDangMo(), 201)]);

        $this->withSession($this->phienNhanVien())
            ->postJson(route('thu-ngan.ca-lam-viec.mo'), ['opening_cash' => 500000])
            ->assertOk();

        Http::assertSent(fn ($req) => $req->data()['opening_cash'] === 500000.0);
    }

    /**
     * Mở ca khi đang còn ca: giữ NGUYÊN VĂN câu của API.
     *
     * Câu đó đã nói rõ việc cần làm ("đóng ca đó trước khi mở ca mới"); thay bằng
     * một câu chung chung là bắt người trực đoán.
     */
    public function test_giu_nguyen_van_loi_dang_co_ca_mo(): void
    {
        Http::fake(['*/admin/ca-lam-viec/mo' => Http::response(
            ['message' => 'Chi nhánh này đang có ca mở. Đóng ca đó trước khi mở ca mới.'], 409
        )]);

        $this->withSession($this->phienNhanVien())
            ->postJson(route('thu-ngan.ca-lam-viec.mo'), ['opening_cash' => 0])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Chi nhánh này đang có ca mở. Đóng ca đó trước khi mở ca mới.');
    }

    /** Đóng ca gửi số tiền đếm được. */
    public function test_dong_ca_gui_tien_dem_duoc(): void
    {
        Http::fake(['*/admin/ca-lam-viec/dong' => Http::response(['data' => ['ca' => [], 'ngoai_ca' => []]])]);

        $this->withSession($this->phienNhanVien())
            ->postJson(route('thu-ngan.ca-lam-viec.dong'), ['counted_cash' => 625000])
            ->assertOk();

        Http::assertSent(fn ($req) => $req->data()['counted_cash'] === 625000.0);
    }

    /**
     * Ghi sổ quỹ BẮT BUỘC có lý do.
     *
     * Một khoản ra khỏi két mà không có lý do thì đúng bằng mất tiền, chỉ khác là
     * có ghi lại. Chặn ngay tại đây, không làm phiền API.
     */
    public function test_ghi_so_quy_bat_buoc_co_ly_do(): void
    {
        Http::fake();

        $this->withSession($this->phienNhanVien())
            ->postJson(route('thu-ngan.ca-lam-viec.soQuy'), ['direction' => 'out', 'amount' => 50000])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /** Số tiền phải lớn hơn 0 — dòng 0 đồng chỉ làm dài sổ. */
    public function test_ghi_so_quy_chan_so_tien_khong(): void
    {
        Http::fake();

        $this->withSession($this->phienNhanVien())
            ->postJson(route('thu-ngan.ca-lam-viec.soQuy'), [
                'direction' => 'out', 'amount' => 0, 'reason' => 'Mua nước',
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /** Ghi sổ quỹ hợp lệ thì chuyển tiếp đủ ba trường. */
    public function test_ghi_so_quy_chuyen_tiep_du_truong(): void
    {
        Http::fake(['*/admin/so-quy' => Http::response(['data' => ['id' => 9]], 201)]);

        $this->withSession($this->phienNhanVien())
            ->postJson(route('thu-ngan.ca-lam-viec.soQuy'), [
                'direction' => 'out', 'amount' => 50000, 'reason' => '  Mua nước cho quầy  ',
            ])
            ->assertOk();

        Http::assertSent(function ($req) {
            $d = $req->data();

            return $d['direction'] === 'out'
                && $d['amount'] === 50000.0
                // Lý do đã bỏ khoảng trắng thừa: sổ quỹ là thứ người ta đọc lại.
                && $d['reason'] === 'Mua nước cho quầy';
        });
    }

    /**
     * ĐIỂM THIẾT KẾ: cụm ca làm việc hỏng thì màn hình quầy VẪN BÁN ĐƯỢC.
     *
     * Đường /hien-tai trả `data: null` thay vì ném lỗi, vì nó là thứ GHI CHÉP chứ
     * không phải thứ gác cửa. Trả lỗi ở đây là để một trục trặc của sổ sách làm
     * cả tiệm không bán được hàng.
     */
    public function test_api_hong_thi_van_tra_null_chu_khong_loi(): void
    {
        Http::fake(['*/admin/ca-lam-viec/hien-tai' => Http::response([], 500)]);

        $this->withSession($this->phienNhanVien())
            ->getJson(route('thu-ngan.ca-lam-viec.hienTai'))
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /** Chưa mở ca cũng là `null`, và đó là trạng thái bình thường. */
    public function test_chua_mo_ca_tra_null(): void
    {
        Http::fake(['*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => null])]);

        $this->withSession($this->phienNhanVien())
            ->getJson(route('thu-ngan.ca-lam-viec.hienTai'))
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /** Trang lịch sử ca mở được và hiện con số chênh lệch. */
    public function test_mo_duoc_trang_lich_su_ca(): void
    {
        Http::fake(['*/admin/ca-lam-viec*' => Http::response([
            'data' => [[
                'id' => 3, 'opened_at' => '2026-08-17T08:00:00+07:00',
                'closed_at' => '2026-08-17T20:00:00+07:00',
                'opened_by_name' => 'Nhân viên quầy',
                'opening_cash' => 500000, 'tong_thu' => 180000, 'tong_chi' => 50000,
                'expected_cash' => 630000, 'counted_cash' => 625000, 'difference' => -5000,
            ]],
            'meta' => ['page' => 1, 'page_size' => 20, 'total' => 1, 'total_pages' => 1],
        ])]);

        $this->withSession($this->phienNhanVien())
            ->get(route('thu-ngan.ca-lam-viec.index'))
            ->assertOk()
            ->assertSee('630.000₫', false)
            ->assertSee('625.000₫', false)
            // Chênh lệch âm hiện kèm dấu — đây là con số duy nhất đáng nhìn trên bảng.
            ->assertSee('-5.000₫', false);
    }

    /** Trang chi tiết ca liệt kê từng dòng sổ quỹ theo đúng thứ tự đã xảy ra. */
    public function test_mo_duoc_trang_chi_tiet_ca(): void
    {
        Http::fake(['*/admin/ca-lam-viec/3' => Http::response(['data' => [
            'ca' => [
                'id' => 3, 'opened_at' => '2026-08-17T08:00:00+07:00',
                'closed_at' => '2026-08-17T20:00:00+07:00',
                'opening_cash' => 500000, 'tong_thu' => 180000, 'tong_chi' => 50000,
                'expected_cash' => 630000, 'counted_cash' => 625000, 'difference' => -5000,
                'so_don_tien_mat' => 2,
            ],
            'so_quy' => [
                ['created_at' => '2026-08-17T09:00:00+07:00', 'direction' => 'in',
                    'amount' => 180000, 'reason' => 'Bán hàng DH001', 'reference_type' => 'order'],
                ['created_at' => '2026-08-17T15:00:00+07:00', 'direction' => 'out',
                    'amount' => 50000, 'reason' => 'Mua nước cho quầy', 'reference_type' => 'manual'],
            ],
        ]])]);

        $this->withSession($this->phienNhanVien())
            ->get(route('thu-ngan.ca-lam-viec.show', ['id' => 3]))
            ->assertOk()
            ->assertSee('Bán hàng DH001', false)
            ->assertSee('Mua nước cho quầy', false)
            ->assertSee('Thiếu két', false);
    }
}
