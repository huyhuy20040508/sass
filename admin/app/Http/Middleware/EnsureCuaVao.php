<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn theo CỬA VÀO đã giao cho tài khoản (`users.access_areas` bên API).
 *
 * Khác EnsureManagerRole ở chỗ nó hỏi câu khác: middleware kia hỏi "anh là LOẠI
 * tài khoản nào" (vai trò), còn đây hỏi "chủ tiệm có tích cho anh khu này
 * không". Từ migration 0015 thì tích gì vào được nấy — một người mang vai
 * `admin` nhưng chỉ được tích "Quản lý" thì KHÔNG mở được module quầy, dù vai
 * trò của họ trước đây vẫn đi qua cả hai khu.
 *
 * Dùng:  ->middleware('admin.cua:thu_ngan')
 *
 * Đây là lượt chặn ở GIAO DIỆN. Go API chặn lại lần nữa bằng KiemQuyen.Cua trên
 * đúng những đường của quầy (thu tiền, quét mã, mở/đóng ca, ghi sổ quỹ), nên bỏ
 * middleware này ra thì người dùng chỉ nhận 403 muộn hơn chứ không lọt dữ liệu.
 *
 * Chạy SAU EnsureAdminAuthenticated, nên tới đây chắc chắn đã đăng nhập.
 */
class EnsureCuaVao
{
    /** Nhãn để viết câu từ chối cho người đọc, không phải cho lập trình viên. */
    protected const NHAN = [
        'quan_ly' => 'khu quản trị',
        'thu_ngan' => 'quầy bán hàng',
    ];

    public function handle(Request $request, Closure $next, string $cua): Response
    {
        if (in_array($cua, self::cuaCuaPhien(), true)) {
            return $next($request);
        }

        $message = 'Tài khoản của bạn không được giao '.(self::NHAN[$cua] ?? $cua)
            .'. Nhờ chủ cửa hàng tích thêm quyền này trong mục Nhân sự.';

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        // Đưa về khu người này ĐỨNG ĐƯỢC, chứ không đá ra trang đăng nhập: họ đã
        // đăng nhập hợp lệ, chỉ là gõ nhầm cửa.
        //
        // Điều kiện phải là "CÓ cửa kia", không phải "không phải cửa này". Chốt bên
        // kia cũng kiểm y hệt, nên đưa họ tới một khu họ không có cửa là hai chốt
        // chỉ vào nhau — trình duyệt quay vòng tới khi tự bỏ cuộc, và trên màn hình
        // thì trông như trang bị treo chứ không phải bị từ chối.
        //
        // Chỗ này dễ viết hụt: chỉ chặn lúc KHÔNG CÓ CỬA NÀO thì vẫn lọt vòng lặp
        // với người mang một cửa lạ — hôm nào thêm cửa thứ ba (kho chẳng hạn) là
        // người chỉ có cửa ấy quay vòng giữa hai khu này.
        $co = self::cuaCuaPhien();

        if ($cua === 'quan_ly' && in_array('thu_ngan', $co, true)) {
            return redirect()->route('thu-ngan.ban-hang.index')->with('error', $message);
        }

        if ($cua === 'thu_ngan' && in_array('quan_ly', $co, true)) {
            return redirect()->route('admin.dashboard')->with('error', $message);
        }

        // Không đứng được ở đâu cả: về trang đăng nhập, đó là chỗ duy nhất chắc
        // chắn mở và có chỗ in ra câu giải thích.
        return redirect()->route('login')->with('error', $message);
    }

    /**
     * Cửa của người đang đăng nhập.
     *
     * Chuyển tiếp sang App\Services\CuaVao — chỗ DUY NHẤT biết cách tra, gồm cả
     * lượt làm mới định kỳ từ API. Giữ tên hàm ở đây vì middleware kia và mấy
     * blade đã gọi nó, và một cái tên là đủ cho một câu hỏi.
     */
    public static function cuaCuaPhien(): array
    {
        return \App\Services\CuaVao::cua();
    }
}
