<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tạm: dựng thử trang Nhà cung cấp + lượt nhập file với API giả. */
class TmpNhaCungCapRenderTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'tk',
            'api.refresh_token' => 'rf',
            'api.user' => ['id' => 1, 'full_name' => 'Test', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly,thu_ngan'],
        ];
    }

    public function test_trang_dung_duoc(): void
    {
        Http::fake([
            '*/admin/nha-cung-cap*' => Http::response(['data' => [[
                'id' => 7, 'code' => 'NCC001', 'name' => 'Công ty TNHH An Bình', 'short_name' => 'An Bình',
                'tax_code' => '0101234567', 'phone' => '0912345678', 'email' => 'kd@anbinh.vn',
                'address' => '12 Lê Lợi, Q1', 'address_line2' => 'Kho Bình Tân', 'status' => 1,
                'total_purchases' => 12500000, 'paid' => 9500000, 'debt' => 3000000,
            ]]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        $res = $this->withSession($this->phien())->get('/admin/suppliers');

        $res->assertOk();
        $res->assertSee('Công ty TNHH An Bình');
        $res->assertSee('Kho Bình Tân');
        $res->assertSee('Nhập file');
        $res->assertSee('Tải file mẫu');
        $res->assertSee('Xem cột');
    }

    public function test_tai_file_mau(): void
    {
        $res = $this->withSession($this->phien())->get('/admin/suppliers/import-template');

        $res->assertOk();
        $this->assertStringContainsString('Tên nhà cung cấp', $res->streamedContent());
    }

    public function test_nhap_file_ghi_tung_dong(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 9]], 201)]);

        $csv = "STT,Mã nhà cung cấp,Tên nhà cung cấp,Mã số thuế,Điện thoại,Email,Địa chỉ,Địa chỉ 2,Trạng thái\n"
            ."1,NCC009,Cơ sở Minh Phát,,0908765432,,55 Trần Hưng Đạo,,1\n";

        $res = $this->withSession($this->phien())->post('/admin/suppliers/import', [
            'file' => UploadedFile::fake()->createWithContent('ncc.csv', $csv),
        ]);

        $res->assertRedirect();
        $res->assertSessionHas('success');
    }

    public function test_nhap_file_thieu_ten_thi_dung_ca_luot(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $csv = "STT,Mã nhà cung cấp,Tên nhà cung cấp,Mã số thuế,Điện thoại,Email,Địa chỉ,Địa chỉ 2,Trạng thái\n"
            ."1,NCC009,,,0908765432,,55 Trần Hưng Đạo,,1\n";

        $res = $this->withSession($this->phien())->post('/admin/suppliers/import', [
            'file' => UploadedFile::fake()->createWithContent('ncc.csv', $csv),
        ]);

        $res->assertRedirect();
        $res->assertSessionHas('error');
        Http::assertNotSent(fn ($req) => $req->method() === 'POST' && str_contains($req->url(), 'nha-cung-cap'));
    }
}
