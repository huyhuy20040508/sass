<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Module thu ngân đã TÁCH khỏi khu quản trị — kiểm phần ranh giới ấy.
 *
 * Ba thứ dễ hỏng khi tách một module ra khỏi chỗ cũ, và đều hỏng lặng lẽ:
 *
 *  1. Người dùng bị đưa nhầm module sau khi đăng nhập.
 *  2. Đường dẫn cũ (máy quầy đặt sẵn trang chủ /admin/ban-tai-quay) thành 404.
 *  3. Trang đơn quầy quên khoá channel=pos và đổ cả đơn giao hàng vào quầy.
 *
 * Dùng Http::fake nên không cần API chạy và KHÔNG đụng dữ liệu của máy đang chạy.
 */
class ModuleThuNganTest extends TestCase
{
    protected function phien(string $vaiTro): array
    {
        return [
            'api.access_token' => 'token-'.$vaiTro,
            'api.refresh_token' => 'refresh-'.$vaiTro,
            'api.user' => ['id' => 7, 'full_name' => 'Người dùng', 'role' => ['name' => $vaiTro]],
        ];
    }

    /** Phản hồi đăng nhập của API cho một vai trò. */
    protected function apiDangNhap(string $vaiTro): array
    {
        return ['data' => [
            'access_token' => 'token-'.$vaiTro,
            'refresh_token' => 'refresh-'.$vaiTro,
            'user' => ['id' => 7, 'full_name' => 'Người dùng', 'role' => ['name' => $vaiTro]],
            'tenant' => ['code' => 'quochuy', 'name' => 'Cửa hàng'],
            'cua_hang_khoa' => false,
        ]];
    }

    // -------------------------------------------------- vào đúng module của mình

    /**
     * Đăng nhập xong là dừng ở màn chọn cửa vào — CẢ nhân viên lẫn chủ tiệm.
     *
     * Nhân viên chỉ có một khu nên ô khu vực với họ chỉ còn một, nhưng màn ấy còn
     * hỏi chi nhánh và in tên tiệm vừa vào; đi thẳng vào quầy là bỏ qua cả hai rồi
     * bán cả ca vào nhầm kho.
     */
    public function test_dang_nhap_xong_dung_o_man_chon_cua(): void
    {
        foreach (['staff', 'admin'] as $vai) {
            Http::fake(['*/auth/shop-login' => Http::response($this->apiDangNhap($vai))]);

            $this->post(route('login.attempt'), [
                'shop_code' => 'quochuy', 'username' => $vai, 'password' => 'x',
            ])->assertRedirect(route('chon-cua'));
        }
    }

    /** Vào thẳng "/" khi đang có phiên: mỗi vai trò về module của mình. */
    public function test_route_goc_dua_ve_dung_module(): void
    {
        $this->withSession($this->phien('staff'))->get('/')
            ->assertRedirect(route('thu-ngan.ban-hang.index'));

        $this->withSession($this->phien('admin'))->get('/')
            ->assertRedirect(route('admin.dashboard'));
    }

    // ------------------------------------------------------------ đường dẫn cũ

    /**
     * Đường cũ vẫn tới nơi.
     *
     * Máy ở quầy hay được đặt sẵn trang chủ trình duyệt là /admin/ban-tai-quay,
     * và đường dẫn ấy nằm trong ghi chú của cửa hàng. Bỏ hẳn thì một sáng nào đó
     * người trực mở máy lên và gặp trang 404 — không có ai ở đó để sửa.
     */
    public function test_duong_dan_cu_van_toi_noi(): void
    {
        $phien = $this->phien('staff');

        $this->withSession($phien)->get('/admin/ban-tai-quay')
            ->assertRedirect(route('thu-ngan.ban-hang.index'));

        $this->withSession($phien)->get('/admin/ca-lam-viec')
            ->assertRedirect(route('thu-ngan.ca-lam-viec.index'));

        $this->withSession($phien)->get('/admin/ca-lam-viec/3')
            ->assertRedirect(route('thu-ngan.ca-lam-viec.show', 3));
    }

    /** Phiếu in giữ luôn khổ giấy đang chọn khi chuyển hướng — in lại từ một
     *  đường cũ mà rơi về khổ mặc định là một tờ giấy tràn mép. */
    public function test_duong_cu_cua_phieu_giu_kho_giay(): void
    {
        $this->withSession($this->phien('staff'))->get('/admin/ban-tai-quay/88/phieu?kho=58')
            ->assertRedirect(route('thu-ngan.ban-hang.phieu', ['id' => 88, 'kho' => '58']));
    }

    // ------------------------------------------------------------ nút đổi module

    /**
     * Nút đổi module có mặt ở CẢ HAI bên — với người được giao CẢ HAI CỬA.
     *
     * Một chiều thôi thì thành cái bẫy: sang được quầy mà không có đường về, hoặc
     * ngược lại. Ở quầy thì nút này còn là lối ra duy nhất — màn hình đó không có
     * thanh điều hướng nào khác.
     */
    public function test_hai_module_deu_co_nut_doi_qua_lai(): void
    {
        Http::fake([
            '*/admin/orders/pos/discount-limit' => Http::response(['data' => ['limit_percent' => 0]]),
            '*' => Http::response(['data' => []]),
        ]);

        $caHai = $this->phien('admin');
        $caHai['api.user']['access_areas'] = 'quan_ly,thu_ngan';

        $quay = $this->withSession($caHai)
            ->get(route('thu-ngan.ban-hang.index'))->assertOk();
        $quay->assertSee(route('admin.dashboard'), false);

        $quanTri = $this->withSession($caHai)
            ->get(route('admin.orders.index'))->assertOk();
        $quanTri->assertSee(route('thu-ngan.ban-hang.index'), false);
    }

    /**
     * Người CHỈ có cửa quầy: thấy NHÃN "Thu ngân", không thấy nút đổi module.
     *
     * Hai vế, và cần cả hai. Bỏ nguyên cả khối đi thì thanh mất thứ duy nhất cho
     * biết mình đang đứng ở đâu. Để nguyên một cái nút có mũi tên thì bấm vào chỉ
     * thấy đúng chỗ mình đang đứng — trông như hỏng. Nên: in ra cái nhãn, và
     * KHÔNG in ra nút.
     */
    public function test_thu_ngan_thay_nhan_chu_khong_thay_nut_doi_module(): void
    {
        Http::fake([
            '*/admin/orders/pos/discount-limit' => Http::response(['data' => ['limit_percent' => 0]]),
            '*' => Http::response(['data' => []]),
        ]);

        $phien = $this->phien('staff');
        $phien['api.user']['access_areas'] = 'thu_ngan';

        $quay = $this->withSession($phien)
            ->get(route('thu-ngan.ban-hang.index'))->assertOk();

        // Vẫn biết mình đang ở đâu.
        $quay->assertSee('mdsw-nhan', false);
        $quay->assertSee('Thu ngân', false);
        // Nhưng không có nút, không có lối sang khu quản trị.
        $quay->assertDontSee('id="mdswBtn"', false);
        $quay->assertDontSee(route('admin.dashboard'), false);
    }

    /**
     * Nút đổi module biến mất với MỌI hình dạng phiên của người chỉ đứng quầy.
     *
     * Ba đường ghi phiên khác nhau nên vai trò nằm ở ba chỗ: `/auth/login` trả
     * entity (`role.name`), `/admin/me` trả DTO (`role_name`), vài chỗ cũ nhét
     * thẳng chuỗi vào `role`. Đọc sót một hình dạng là rơi xuống "không biết vai
     * nào" — và trước đây chỗ đó lại HIỆN CẢ HAI module, tức là đúng lúc không
     * đọc được cửa thì màn hình mời người ta bấm vào khu họ không vào được.
     *
     * Đó chính là cái nút "Thu ngân" bấm không xổ mà người dùng gặp: nó hiện ra
     * nhờ nhánh đoán mò, rồi mục duy nhất bên trong là chỗ họ đang đứng.
     */
    public function test_nut_doi_module_an_voi_moi_hinh_dang_phien(): void
    {
        Http::fake([
            '*/admin/orders/pos/discount-limit' => Http::response(['data' => ['limit_percent' => 0]]),
            '*' => Http::response(['data' => []]),
        ]);

        // Hai hình dạng phiên thật sự tới được trang. Mấy hình dạng khác
        // (`role_name` của DTO, `role` là chuỗi) bị EnsureAdminAuthenticated chặn
        // từ trước vì nó chỉ đọc `role.name` — CuaVao vẫn đọc cả ba cho chắc, nhưng
        // khẳng định chúng ở đây thì chỉ là khẳng định một cảnh không xảy ra.
        $hinhDang = [
            'vai staff' => ['role' => ['name' => 'staff']],
            'vai admin nhưng chỉ tích cửa quầy' => [
                'role' => ['name' => 'admin'], 'access_areas' => 'thu_ngan',
            ],
        ];

        foreach ($hinhDang as $ten => $nguoi) {
            $html = $this->withSession([
                'api.access_token' => 'tok',
                'api.user' => array_merge(['id' => 7, 'full_name' => 'Người trực'], $nguoi),
            ])->get(route('thu-ngan.ban-hang.index'))->assertOk()->getContent();

            $this->assertStringNotContainsString('id="mdswBtn"', $html,
                "Phiên dạng [$ten] vẫn vẽ ra nút đổi module");
            // Nút thì không, nhưng nhãn "đang ở đâu" thì phải còn.
            $this->assertStringContainsString('mdsw-nhan', $html,
                "Phiên dạng [$ten] mất luôn nhãn cho biết đang ở module nào");
        }

        // Phiên không đọc được vai lẫn cửa thì KHÔNG vào tới trang để mà bàn về
        // cái nút: EnsureAdminAuthenticated chặn từ trước. Khẳng định ở đây để lần
        // sau ai đó nới lỗ hổng đó ra thì bài kiểm này lên tiếng.
        $this->withSession([
            'api.access_token' => 'tok',
            'api.user' => ['id' => 7, 'full_name' => 'Không rõ vai'],
        ])->get(route('thu-ngan.ban-hang.index'))->assertRedirect(route('login'));
    }

    /** Người có CẢ HAI cửa thì nút xổ ra đủ hai mục để bấm sang. */
    public function test_ca_hai_cua_thi_nut_xo_ra_hai_muc(): void
    {
        Http::fake([
            '*/admin/orders/pos/discount-limit' => Http::response(['data' => ['limit_percent' => 0]]),
            '*' => Http::response(['data' => []]),
        ]);

        $phien = $this->phien('admin');
        $phien['api.user']['access_areas'] = 'quan_ly,thu_ngan';

        $html = $this->withSession($phien)
            ->get(route('thu-ngan.ban-hang.index'))->assertOk()->getContent();

        $this->assertStringContainsString('id="mdswBtn"', $html);
        // Hai mục, và mục kia phải trỏ sang khu quản trị — một mục thì bấm vào
        // chẳng đi đâu, đúng cái đã gặp.
        $this->assertSame(2, substr_count($html, 'role="menuitem"'));
        $this->assertStringContainsString(route('admin.dashboard'), $html);
    }

    /**
     * Ở quầy vẫn đăng xuất được mà không phải sang module kia.
     *
     * Hết ca là người trực đăng xuất ngay tại quầy. Bắt họ đi qua khu quản trị
     * chỉ để bấm một nút thì kết cục thường gặp là bỏ luôn — và máy quầy nằm đó
     * đăng nhập sẵn qua đêm.
     */
    public function test_quay_van_dang_xuat_duoc_tai_cho(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

        $this->withSession($this->phien('staff'))
            ->get(route('thu-ngan.don-hang.index'))
            ->assertOk()
            ->assertSee(route('logout'), false)
            ->assertSee('Đăng xuất');
    }

    /**
     * Màn hình bán hàng khoá cuộn cả trang, hai trang danh sách thì không.
     *
     * Ở quầy, khối thu tiền phải luôn nằm nguyên chỗ: trôi khỏi tầm mắt giữa lúc
     * đếm tiền là lúc người bán phải cuộn đi tìm lại nút. Còn danh sách ca và đơn
     * quầy thì ngược lại — khoá cuộn ở đó là cắt cụt bảng.
     */
    public function test_man_hinh_ban_hang_khoa_cuon_ca_trang(): void
    {
        Http::fake([
            '*/admin/orders/pos/discount-limit' => Http::response(['data' => ['limit_percent' => 0]]),
            '*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
        ]);

        $phien = $this->phien('staff');

        $this->withSession($phien)->get(route('thu-ngan.ban-hang.index'))
            ->assertOk()->assertSee('<body class="tn-kin">', false);

        // Chỉ soát THẺ BODY: chuỗi "tn-kin" còn nằm trong khối style của vỏ trang
        // (luật `body.tn-kin`), nên tìm nó khắp trang thì trang nào cũng "có".
        $this->withSession($phien)->get(route('thu-ngan.don-hang.index'))
            ->assertOk()->assertDontSee('<body class="tn-kin">', false);
    }

    // -------------------------------------------------------------- đơn quầy

    /** Trang đơn quầy CHỈ hỏi đơn của quầy, không bao giờ hỏi đơn giao hàng. */
    public function test_don_quay_luon_khoa_kenh_pos(): void
    {
        Http::fake(['*/admin/orders*' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

        $this->withSession($this->phien('staff'))
            ->get(route('thu-ngan.don-hang.index'))
            ->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'channel=pos'));
    }

    /** Mở trang ra thì thấy đơn HÔM NAY, không phải cả kho đơn từ đầu năm. */
    public function test_don_quay_mac_dinh_loc_hom_nay(): void
    {
        Http::fake(['*/admin/orders*' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

        $homNay = Carbon::now()->format('Y-m-d');

        $this->withSession($this->phien('staff'))
            ->get(route('thu-ngan.don-hang.index'))
            ->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'from_date='.$homNay)
            && str_contains($req->url(), 'to_date='.$homNay));
    }

    /**
     * Gõ mã đơn vào ô tìm thì BỎ kẹp ngày.
     *
     * Người gõ một mã đơn là đang tìm đúng đơn đó, ở bất kỳ ngày nào — thường là
     * khách cầm phiếu của hôm trước quay lại. Kẹp thêm ngày hôm nay vào là trả về
     * "không tìm thấy" cho một đơn đang nằm ngay trong sổ.
     */
    public function test_tim_theo_ma_don_thi_khong_kep_ngay(): void
    {
        Http::fake(['*/admin/orders*' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

        $this->withSession($this->phien('staff'))
            ->get(route('thu-ngan.don-hang.index', ['keyword' => 'DH202608170088']))
            ->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'keyword=DH202608170088')
            && str_contains($req->url(), 'from_date=&')
            && str_contains($req->url(), 'to_date=&'));
    }

    /** Mỗi dòng có đường in lại phiếu — đó là một trong hai lý do người ta mở trang này. */
    public function test_don_quay_co_duong_in_lai_phieu(): void
    {
        Http::fake(['*/admin/orders*' => Http::response(['data' => [[
            'id' => 88, 'order_code' => 'DH202608170088', 'recipient_name' => 'Chị Lan',
            'payment_method' => 'cash', 'total_amount' => 162000,
            'created_at' => '2026-08-17T10:30:00+07:00',
        ]], 'meta' => ['total' => 1, 'total_pages' => 1, 'page' => 1]])]);

        $this->withSession($this->phien('staff'))
            ->get(route('thu-ngan.don-hang.index'))
            ->assertOk()
            ->assertSee('DH202608170088')
            ->assertSee(route('thu-ngan.ban-hang.phieu', ['id' => 88]), false);
    }
}
