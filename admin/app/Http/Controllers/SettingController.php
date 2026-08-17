<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cấu hình hệ thống — các khoá key-value do API giữ (GET/PUT /admin/settings).
 *
 * Trang này KHÔNG tự khai danh sách khoá: API trả kèm `fields` (nhóm, kiểu, nhãn,
 * giới hạn) lấy từ registry trong setting_service.go, view dựng ô nhập từ đó. Thêm
 * một khoá mới bên Go là trang này hiện ngay, không phải sửa Blade.
 *
 * Ảnh logo KHÔNG đi qua API: file lưu vào public disk của chính trang quản trị rồi
 * chỉ URL được gửi sang API dưới dạng chuỗi — đúng cách logo thương hiệu và ảnh sản
 * phẩm đang làm, nên storefront đọc ảnh từ một chỗ duy nhất.
 */
class SettingController extends Controller
{
    /**
     * MỖI nhóm cấu hình là MỘT trang riêng.
     *
     * Gom cả ba vào một form thì trang "Cấu hình cửa hàng" lại chứa cả phí vận
     * chuyển lẫn ngưỡng kho — ba việc khác nhau, người sửa phải lướt qua những
     * thứ mình không đụng tới. Tách ra, mỗi trang trả lời đúng một câu hỏi.
     *
     * `title` là tiêu đề trang và cũng là nhãn trên sidebar; khoá mảng là mã nhóm
     * do API quy định (registry trong setting_service.go) và cũng là phần đuôi URL.
     *
     * `sub` là dòng duy nhất dưới tiêu đề, nên nói điều người sửa CHƯA biết (giá
     * trị này chạy vào đâu, có hiệu lực từ lúc nào) chứ không diễn giải lại tiêu đề
     * — "Cấu hình cửa hàng / Tên, liên hệ và địa chỉ của cửa hàng" là hai lần nói
     * cùng một chuyện.
     */
    public const GROUPS = [
        'general' => [
            'title' => 'Cấu hình cửa hàng',
            'sub' => 'Tên, liên hệ và địa chỉ — hiển thị trong email gửi khách, trang liên hệ và chân trang website.',
        ],
        'shipping' => [
            'title' => 'Vận chuyển',
            'sub' => 'Áp cho đơn đặt MỚI. Đơn đã tạo giữ nguyên phí ship đã chốt lúc đặt.',
        ],
        'payment' => [
            'title' => 'Thanh toán',
            'sub' => 'Hình thức khách chọn được khi đặt hàng, và tài khoản nhận chuyển khoản in ra cho khách.',
        ],
        'inventory' => [
            'title' => 'Kho',
            'sub' => 'Quy tắc theo dõi tồn kho, chỉ dùng trong trang quản trị — khách không thấy.',
        ],
        'pos' => [
            'title' => 'Quầy bán hàng',
            'sub' => 'Người đứng quầy được phép làm gì. Khác nhóm Thanh toán — nhóm đó nói cửa hàng nhận tiền bằng cách nào.',
        ],
    ];

    /**
     * Gợi ý dưới từng ô — nói rõ giá trị này chạy vào đâu.
     *
     * Không có dòng này thì người sửa không đoán được đổi một con số sẽ ảnh hưởng
     * chỗ nào, và đó là kiểu cấu hình không ai dám đụng vào.
     */
    public const HINTS = [
        'site_name' => 'Hiện ở tiêu đề tab của cả website lẫn trang quản trị, chân trang website và mọi email gửi khách.',
        'store_slogan' => 'Câu ngắn dưới dòng bản quyền ở chân trang website.',
        'store_logo' => 'Dùng chung cho website và sidebar trang quản trị. Tối đa 2MB; nền sidebar màu xanh đậm nên chọn ảnh nền trong suốt, chữ sáng. Gỡ ảnh đi thì mỗi bên quay về logo mặc định của mình.',
        'store_favicon' => 'Ảnh nhỏ trên tab trình duyệt, dùng cho cả website và trang quản trị. Nên là ảnh vuông, tối thiểu 64×64. Gỡ ảnh đi thì quay về favicon mặc định.',
        'contact_email' => 'Địa chỉ khách bấm vào để phản hồi, hiện ở trang liên hệ và chân trang.',
        'contact_phone' => 'Hotline in trong email, nút gọi, nút Zalo và trang liên hệ.',
        'store_address' => 'Hiện ở trang liên hệ và chân trang, kèm link mở Google Maps. Bỏ trống thì hai chỗ đó không hiện địa chỉ.',
        'business_hours' => 'Hiện ở trang liên hệ và chân trang thanh toán. Gõ sao hiện vậy.',
        'store_website' => 'Hiện ở phần liên hệ dưới chân trang. Bỏ trống thì không hiện dòng đó.',
        'social_facebook' => 'Bỏ trống thì biểu tượng Facebook biến mất khỏi website — không để nút bấm vào không đi đâu.',
        'social_instagram' => 'Bỏ trống thì ẩn biểu tượng Instagram ở chân trang, nút nổi và trang liên hệ.',
        'social_tiktok' => 'Bỏ trống thì ẩn biểu tượng TikTok ở chân trang.',
        'social_messenger' => 'Link m.me/… của trang Facebook. Bỏ trống thì ẩn nút nhắn tin nổi bên phải.',
        'default_shipping_fee' => 'Phí thu khi đơn chưa đạt ngưỡng miễn phí bên dưới.',
        'free_shipping_threshold' => 'Đơn từ mức này trở lên được miễn phí vận chuyển. Đặt 0 = miễn phí mọi đơn.',
        'low_stock_threshold' => 'Biến thể còn tồn từ mức này trở xuống bị xếp vào nhóm "sắp hết" ở trang Tồn kho.',
        'pos_staff_discount_limit' => 'Nhân viên bán tại quầy được tự bớt giá từng món tới mức này. Bấm cao hơn thì hệ thống từ chối bán và bảo họ gọi quản lý. Bạn và các quản trị viên KHÔNG bị con số này chặn. Đặt 0 = nhân viên không được tự bớt đồng nào.',
        'payment_cod_enabled' => 'Khách trả tiền cho shipper lúc nhận hàng. Tắt đi thì lựa chọn này biến mất khỏi trang thanh toán.',
        'payment_bank_enabled' => 'Khách chuyển khoản trước rồi cửa hàng mới giao. Chỉ hiện ra cho khách khi đã khai đủ ngân hàng, số tài khoản và chủ tài khoản bên dưới.',
        'payment_payos_enabled' => 'Khách quét mã QR trả tiền ngay trên website, đơn tự chuyển sang "đã thanh toán" khi tiền về — không phải soi sao kê. Khác chuyển khoản bên dưới ở chỗ đó. Cần người quản trị máy chủ khai bộ khoá PayOS trong file .env của API trước; chưa có khoá thì bật lên cũng chưa hiện ra cho khách.',
        'payment_sepay_enabled' => 'Cũng là QR ngân hàng nhưng tiền vào THẲNG tài khoản của cửa hàng, không qua trung gian giữ tiền — SePay chỉ đọc biến động số dư rồi báo về, đối soát theo mã đơn ghi trong nội dung chuyển khoản. Cần khai số tài khoản và khoá webhook SePay trong file .env của API trước; chưa khai thì bật lên cũng chưa hiện ra cho khách.',
        'bank_name' => 'Tên ngân hàng như khách thấy khi chuyển tiền, VD "Vietcombank — CN Tân Bình".',
        'bank_account_number' => 'Kiểm lại từng chữ số trước khi lưu: sai một số là tiền của khách đi vào tài khoản người lạ.',
        'bank_account_name' => 'Gõ đúng như trên app ngân hàng (thường là chữ IN HOA không dấu) để khách đối chiếu trước khi bấm chuyển.',
        'bank_qr_image' => 'Ảnh QR chụp từ app ngân hàng, hiện ngay cạnh số tài khoản ở trang đặt hàng thành công. Bỏ trống thì khách nhập tay số tài khoản.',
        'bank_transfer_note' => 'Một câu dặn thêm hiện dưới khối chuyển khoản, VD "Chuyển xong nhắn Zalo giúp shop để xác nhận nhanh". Nội dung chuyển khoản thì hệ thống tự điền mã đơn, không cần dặn.',
    ];

    /**
     * Tên các khối trong một trang (mã khối do registry bên API quy định).
     *
     * Trang thông tin cửa hàng có 13 ô — dàn phẳng thì phải đọc hết mới tìm ra ô
     * cần sửa. Trang chỉ có một khối (Vận chuyển, Kho) không hiện tiêu đề khối,
     * vì khi đó nó chỉ lặp lại tiêu đề trang.
     */
    public const SECTIONS = [
        'brand' => 'Nhận diện',
        'contact' => 'Liên hệ',
        'social' => 'Mạng xã hội',
        'methods' => 'Hình thức nhận tiền',
        'bank' => 'Tài khoản nhận chuyển khoản',
    ];

    /**
     * Khối chỉ dùng tới khi một công tắc đang bật: mã khối => khoá công tắc.
     *
     * Khối như vậy được gập lại và chỉ mở ra khi công tắc bật. Tắt chuyển khoản mà
     * vẫn bày ra bốn ô tài khoản là mời người dùng điền một thứ không chạy vào đâu.
     */
    public const SECTION_TOGGLES = [
        'bank' => 'payment_bank_enabled',
    ];

    /** Đơn vị hiển thị bên phải ô nhập số. */
    public const UNITS = [
        'default_shipping_fee' => '₫',
        'free_shipping_threshold' => '₫',
        'low_stock_threshold' => 'sản phẩm',
        'pos_staff_discount_limit' => '%',
    ];

    /** Khoá dạng số nhưng là TIỀN — ô nhập tự chấm phân cách hàng nghìn. */
    public const MONEY_KEYS = ['default_shipping_fee', 'free_shipping_threshold'];

    /**
     * Ngân hàng Việt Nam — danh sách chọn cho ô "Ngân hàng".
     *
     * Mỗi dòng: [tên ngắn, tên đầy đủ, mã tra cứu]. Tên NGẮN là thứ được lưu xuống
     * và in ra cho khách, vì đó là cái khách thấy trong app ngân hàng của mình;
     * tên đầy đủ và mã chỉ để tìm cho ra (gõ "ngoai thuong" hay "VCB" đều tới
     * Vietcombank).
     *
     * Ô này vẫn gõ tay được: quỹ tín dụng, ngân hàng nước ngoài hay ví điện tử nằm
     * ngoài danh sách thì người dùng tự nhập, không bị danh sách chặn lại.
     */
    public const BANKS = [
        ['Vietcombank', 'NH TMCP Ngoại thương Việt Nam', 'VCB'],
        ['VietinBank', 'NH TMCP Công thương Việt Nam', 'CTG'],
        ['BIDV', 'NH TMCP Đầu tư và Phát triển Việt Nam', 'BIDV'],
        ['Agribank', 'NH NN&PTNT Việt Nam', 'VBA'],
        ['Techcombank', 'NH TMCP Kỹ thương Việt Nam', 'TCB'],
        ['MB Bank', 'NH TMCP Quân đội', 'MB'],
        ['ACB', 'NH TMCP Á Châu', 'ACB'],
        ['VPBank', 'NH TMCP Việt Nam Thịnh Vượng', 'VPB'],
        ['TPBank', 'NH TMCP Tiên Phong', 'TPB'],
        ['Sacombank', 'NH TMCP Sài Gòn Thương Tín', 'STB'],
        ['HDBank', 'NH TMCP Phát triển TP.HCM', 'HDB'],
        ['VIB', 'NH TMCP Quốc tế Việt Nam', 'VIB'],
        ['SHB', 'NH TMCP Sài Gòn – Hà Nội', 'SHB'],
        ['Eximbank', 'NH TMCP Xuất Nhập khẩu Việt Nam', 'EIB'],
        ['MSB', 'NH TMCP Hàng Hải Việt Nam', 'MSB'],
        ['OCB', 'NH TMCP Phương Đông', 'OCB'],
        ['SeABank', 'NH TMCP Đông Nam Á', 'SEAB'],
        ['LPBank', 'NH TMCP Lộc Phát Việt Nam', 'LPB'],
        ['Nam A Bank', 'NH TMCP Nam Á', 'NAB'],
        ['ABBANK', 'NH TMCP An Bình', 'ABB'],
        ['Bac A Bank', 'NH TMCP Bắc Á', 'BAB'],
        ['PVcomBank', 'NH TMCP Đại Chúng Việt Nam', 'PVCB'],
        ['VietABank', 'NH TMCP Việt Á', 'VAB'],
        ['SaigonBank', 'NH TMCP Sài Gòn Công Thương', 'SGICB'],
        ['BaoViet Bank', 'NH TMCP Bảo Việt', 'BVB'],
        ['BVBank', 'NH TMCP Bản Việt', 'VCCB'],
        ['KienlongBank', 'NH TMCP Kiên Long', 'KLB'],
        ['NCB', 'NH TMCP Quốc Dân', 'NCB'],
        ['VietBank', 'NH TMCP Việt Nam Thương Tín', 'VIETBANK'],
        ['PGBank', 'NH TMCP Thịnh vượng và Phát triển', 'PGB'],
        ['SCB', 'NH TMCP Sài Gòn', 'SCB'],
        ['DongA Bank', 'NH TMCP Đông Á', 'DOB'],
        ['OceanBank', 'NH TM TNHH MTV Đại Dương', 'OCEANBANK'],
        ['GPBank', 'NH TM TNHH MTV Dầu Khí Toàn Cầu', 'GPB'],
        ['CBBank', 'NH TM TNHH MTV Xây dựng Việt Nam', 'CBB'],
        ['VRB', 'NH Liên doanh Việt – Nga', 'VRB'],
        ['Indovina Bank', 'NH TNHH Indovina', 'IVB'],
        ['Woori Bank', 'NH TNHH MTV Woori Việt Nam', 'WVN'],
        ['Shinhan Bank', 'NH TNHH MTV Shinhan Việt Nam', 'SHBVN'],
        ['HSBC', 'NH TNHH MTV HSBC Việt Nam', 'HSBC'],
        ['Standard Chartered', 'NH TNHH MTV Standard Chartered Việt Nam', 'SCVN'],
        ['Public Bank', 'NH TNHH MTV Public Việt Nam', 'PBVN'],
        ['Hong Leong Bank', 'NH TNHH MTV Hong Leong Việt Nam', 'HLBVN'],
        ['UOB', 'NH TNHH MTV UOB Việt Nam', 'UOB'],
        ['CIMB', 'NH TNHH MTV CIMB Việt Nam', 'CIMB'],
        ['Co-opBank', 'NH Hợp tác xã Việt Nam', 'COOPBANK'],
    ];

    public function __construct(protected ApiClient $api) {}

    /**
     * Trang cấu hình của MỘT nhóm.
     *
     * Ba route (cửa hàng / vận chuyển / kho) dùng chung phương thức này, chỉ khác
     * mã nhóm — nội dung form dựng từ `fields` API trả về nên không có gì phải
     * viết riêng cho từng trang.
     */
    public function page(string $group)
    {
        abort_unless(isset(self::GROUPS[$group]), 404);

        $error = null;
        $values = [];
        $fields = [];

        try {
            $res = $this->api->settings($group);
            if ($res->successful()) {
                $values = $res->json('data.values') ?? [];
                $fields = $res->json('data.fields') ?? [];
            } else {
                Log::warning('Load settings failed', ['group' => $group, 'status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được cấu hình hệ thống.';
            }
        } catch (\Throwable $e) {
            Log::error('Load settings failed', ['group' => $group, 'msg' => $e->getMessage()]);
            $error = 'Không tải được cấu hình hệ thống. Kiểm tra kết nối API.';
        }

        $view = view('settings.form', [
            'group' => $group,
            'meta' => self::GROUPS[$group],
            'values' => $values,
            'sections' => $this->decorate($fields),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Ghi cấu hình của một nhóm — gửi thẳng những khoá form gửi lên, API tự validate.
     *
     * $group chỉ dùng để quay lại đúng trang vừa sửa; khoá nào thuộc nhóm nào là
     * việc của API.
     */
    public function update(Request $request, string $group)
    {
        abort_unless(isset(self::GROUPS[$group]), 404);

        $items = $request->input('items');
        if (! is_array($items) || $items === []) {
            return redirect()->route('admin.settings.page', $group)
                ->with('error', 'Không có cấu hình nào được gửi lên.');
        }

        $items = $this->normalize($items);

        try {
            $res = $this->api->updateSettings($items);
        } catch (\Throwable $e) {
            Log::error('Update settings failed', ['group' => $group, 'msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            // Các trang khác (Tồn kho, Tổng quan) đọc cấu hình qua bản cache 5 phút.
            // Xoá ngay để người vừa bấm Lưu không phải chờ mới thấy hiệu lực.
            Cache::forget(ApiClient::SETTINGS_CACHE_KEY);

            return redirect()->route('admin.settings.page', $group)
                ->with('success', 'Đã lưu '.mb_strtolower(self::GROUPS[$group]['title']).'.');
        }

        // 422: API trả lỗi theo từng khoá — gắn về đúng ô nhập thay vì đổ một câu
        // chung chung, người sửa mới biết trường nào sai.
        $errors = $res->json('errors');
        if ($res->status() === 422 && is_array($errors) && $errors !== []) {
            $bag = [];
            foreach ($errors as $key => $message) {
                $bag['items.'.$key] = [is_array($message) ? reset($message) : (string) $message];
            }

            return back()->withInput()->withErrors($bag)
                ->with('error', 'Cấu hình chưa hợp lệ nên chưa lưu được — xem lại các ô báo đỏ.');
        }

        return back()->withInput()
            ->with('error', $res->json('message') ?: 'Lưu cấu hình không thành công.');
    }

    /** Nhận logo cửa hàng từ form, lưu vào public disk, trả URL tuyệt đối. */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'image' => ImageStore::rules(allowSvg: true),
        ], ImageStore::messages(allowSvg: true));

        return response()->json(['url' => ImageStore::put($request->file('image'), 'settings')]);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Gom field theo khối và bồi thêm phần chỉ giao diện cần: gợi ý, đơn vị, có
     * phải ô tiền không.
     *
     * Thứ tự khối và thứ tự ô trong khối đều giữ nguyên như API trả về (chính là
     * thứ tự khai trong registry) — sắp lại ở đây thì hai bên sẽ trôi khỏi nhau.
     * Trang chỉ có một khối trả về `title` rỗng để view không hiện tiêu đề khối.
     */
    protected function decorate(array $fields): array
    {
        $sections = [];
        foreach ($fields as $f) {
            $key = (string) ($f['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $f['hint'] = self::HINTS[$key] ?? '';
            $f['unit'] = self::UNITS[$key] ?? '';
            $f['is_money'] = in_array($key, self::MONEY_KEYS, true);
            $f['options'] = $this->options($key);

            $code = (string) ($f['section'] ?? '');
            $sections[$code]['fields'][] = $f;
        }

        $single = count($sections) <= 1;
        $out = [];
        foreach ($sections as $code => $section) {
            $out[] = [
                'code' => $code,
                'title' => $single ? '' : (self::SECTIONS[$code] ?? $code),
                'fields' => $section['fields'],
                // Khối gập được cần có tiêu đề để bấm vào; trang một khối không có
                // tiêu đề nên cũng không gập.
                'controlled_by' => $single ? '' : (self::SECTION_TOGGLES[$code] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Danh sách chọn của một khoá — rỗng nghĩa là ô nhập chữ bình thường.
     *
     * Đây là dữ liệu CHỈ giao diện cần: API vẫn nhận chuỗi tự do, danh sách này chỉ
     * để người dùng khỏi phải nhớ tên ngân hàng viết thế nào cho đúng.
     */
    protected function options(string $key): array
    {
        if ($key !== 'bank_name') {
            return [];
        }

        return array_map(fn (array $b) => [
            'value' => $b[0],
            'label' => $b[1],
            'code' => $b[2],
        ], self::BANKS);
    }

    /**
     * Chuẩn hoá giá trị trước khi gửi API.
     *
     * Ô tiền hiển thị "1.000.000" cho dễ đọc nên phải bóc dấu chấm ra trước, nếu
     * không API nhận chuỗi không phải số và từ chối cả lần lưu. Bóc theo danh sách
     * khoá tiền chứ không bóc đại trà: `store_address` cũng có dấu chấm.
     */
    protected function normalize(array $items): array
    {
        $out = [];
        foreach ($items as $key => $value) {
            $value = is_array($value) ? '' : trim((string) $value);
            if (in_array($key, self::MONEY_KEYS, true)) {
                $value = preg_replace('/[^0-9]/', '', $value) ?? '';
            }
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
