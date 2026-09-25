<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hộp Thanh toán của màn Công nợ — phần THOẢ THUẬN NỢ.
 *
 * Vì sao cần bài này: cờ `is_debt` chỉ bật được ở hộp Thanh toán của màn Phiếu
 * mua hàng, mà hộp ấy đóng lại ngay khi phiếu có lượt trả đầu tiên. Trước đó
 * `traNo()` lại ép cứng `is_debt = còn nợ` và luôn chép lại hạn cũ, nên bật nhầm
 * một cái là hạn nợ đóng băng vĩnh viễn — không sửa được ngày, không gỡ được
 * thoả thuận, chỉ còn cách vào thẳng database.
 *
 * Payload gửi sang API là thứ duy nhất quyết định điều đó, nên bài này soi thẳng
 * payload chứ không soi màn hình.
 */
class DebtRepaymentTest extends TestCase
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

    /** Phiếu đang nợ 60.000/100.000, đã hẹn hạn và có người đại diện. */
    protected function fakeApi(): void
    {
        Http::fake([
            '*/admin/phieu-mua-hang/9/thanh-toan' => Http::response(['data' => ['id' => 9]]),
            '*/admin/phieu-mua-hang/9*' => Http::response(['data' => [
                'id' => 9,
                'total_amount' => 100000,
                'paid_amount' => 40000,
                'is_debt' => true,
                'debt_due_date' => '2026-10-15T00:00:00Z',
                'debt_contact_name' => 'TEST Nguoi Dai Dien',
                'debt_contact_phone' => '0909000111',
                'payment_attachment' => '',
            ]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /** Nhặt payload của lượt POST sang đường thanh toán. */
    protected function payload(): array
    {
        $than = [];
        Http::recorded(function ($req) use (&$than) {
            if ($req->method() === 'POST' && str_contains($req->url(), '/thanh-toan')) {
                $than = $req->data();
            }

            return true;
        });

        return $than;
    }

    /** @param  array<string, mixed>  $them */
    protected function traNo(array $them = [])
    {
        // postJson chứ không post: hộp thoại gọi bằng AJAX, và `traLoiHopThoai`
        // chỉ trả JSON cho lượt AJAX — lượt thường thì nó chuyển hướng về danh sách.
        return $this->withSession($this->phien())
            ->postJson(route('admin.cong-no.traNo', 9), $them + [
                'amount' => 10000,
                'payment_method' => 'cash',
            ]);
    }

    /**
     * BỎ TICK "Hẹn hạn trả" → API nhận is_debt = false và ba trường đi kèm RỖNG.
     *
     * Gửi rỗng là cố ý: API dọn sạch hạn và người đại diện khi `is_debt` tắt.
     * Đây chính là đường hoàn nguyên mà giao diện trước đây không có.
     */
    public function test_bo_tick_thi_go_han_va_nguoi_dai_dien(): void
    {
        $this->fakeApi();

        $this->traNo(['co_han' => 0])->assertOk();

        $than = $this->payload();
        $this->assertFalse($than['is_debt'], 'bỏ tick mà vẫn gửi is_debt = true');
        $this->assertSame('', $than['debt_due_date'], 'bỏ tick mà vẫn giữ hạn cũ');
        $this->assertSame('', $than['debt_contact_name']);
        $this->assertSame('', $than['debt_contact_phone']);
    }

    /** Còn tick thì hạn lấy ĐÚNG ngày vừa chọn, không phải ngày cũ chép lại. */
    public function test_con_tick_thi_lay_han_vua_chon(): void
    {
        $this->fakeApi();

        $this->traNo(['co_han' => 1, 'due_date' => '20-11-2026'])->assertOk();

        $than = $this->payload();
        $this->assertTrue($than['is_debt']);
        $this->assertSame('2026-11-20', $than['debt_due_date'], 'hạn mới không tới được API');
        // Bỏ trống người đại diện thì GIỮ cái đang có, không ghi đè bằng rỗng.
        $this->assertSame('TEST Nguoi Dai Dien', $than['debt_contact_name']);
        $this->assertSame('0909000111', $than['debt_contact_phone']);
    }

    /** Còn tick mà không chọn ngày → 422, nói rõ phải làm gì. */
    public function test_tick_ma_khong_chon_ngay_thi_bao_loi(): void
    {
        $this->fakeApi();

        $this->traNo(['co_han' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_date');
    }

    /**
     * SỬA THOẢ THUẬN MÀ KHÔNG GHI TIỀN — bỏ trống ô số tiền.
     *
     * Trước tệp này ô ấy bắt buộc, nên muốn sửa mỗi cái hạn thì phải ghi một lượt
     * trả giả rồi ghi tiếp một lượt âm để bù. Sổ nợ lãnh hai lượt trả bù nhau
     * (+10.000 rồi -10.000), và sổ thu chi lãnh hai phiếu vô nghĩa: một phiếu chi
     * "Trả tiền phiếu mua" và một phiếu thu "Chữa lại lượt trả".
     *
     * `paid_amount` gửi lên phải đúng bằng số ĐÃ TRẢ: API so chênh lệch, chênh
     * bằng 0 thì không đẻ dòng trả tiền nào và cũng không sinh phiếu thu chi.
     */
    public function test_sua_han_ma_khong_ghi_tien(): void
    {
        $this->fakeApi();

        $this->traNo(['amount' => null, 'co_han' => 1, 'due_date' => '20-11-2026'])->assertOk();

        $than = $this->payload();
        $this->assertSame(40000.0, (float) $than['paid_amount'],
            'không ghi tiền mà paid_amount đổi — API sẽ đẻ một lượt trả và một phiếu thu chi');
        $this->assertSame('2026-11-20', $than['debt_due_date'], 'hạn mới không tới được API');
    }

    /** Bỏ trống ô tiền vẫn phải lưu được — trước đây trả 422 "Nhập số tiền trả". */
    public function test_bo_trong_o_tien_van_luu_duoc(): void
    {
        $this->fakeApi();

        $this->traNo(['amount' => null, 'co_han' => 0])
            ->assertOk()
            ->assertJsonPath('message', 'Đã lưu thoả thuận nợ.');
    }

    /**
     * Trả NỐT thì không còn gì để hẹn: dù ô tick còn bật, payload phải tắt cờ.
     *
     * API từ chối thẳng lượt ghi nợ trên phiếu đã trả đủ (422), nên để cờ bật là
     * người dùng bấm Lưu rồi nhận một câu lỗi không giải thích được.
     */
    public function test_tra_not_thi_tat_co_du_van_con_tick(): void
    {
        $this->fakeApi();

        $this->traNo(['co_han' => 1, 'due_date' => '20-11-2026', 'amount' => 60000])->assertOk();

        $than = $this->payload();
        $this->assertFalse($than['is_debt'], 'trả đủ rồi mà vẫn gửi ghi nợ — API sẽ trả 422');
        $this->assertSame('', $than['debt_due_date']);
    }
}
