<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Gọi Go API rồi trả JSON cho màn hình quầy — một luật cho mọi đường fetch.
 *
 * GIỮ NGUYÊN CÂU LỖI CỦA API. Những câu ấy đã nói rõ việc cần làm ("hết hàng:
 * Áo thun còn 2", "chi nhánh này đang có ca mở"); thay bằng câu chung chung là
 * bắt người đứng quầy đoán giữa lúc khách đang đợi.
 *
 * 500 của API đổi thành 502: với trình duyệt, lỗi nằm ở tầng SAU Laravel.
 */
trait ApiJsonReply
{
    /** @param callable(): Response $goi */
    protected function jsonTuApi(callable $goi, string $cauMacDinh)
    {
        try {
            $res = $goi();
        } catch (\Throwable $e) {
            Log::error('Thu ngan: goi API that bai', ['msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API. Vui lòng thử lại.'], 502);
        }

        if (! $res->successful()) {
            return response()->json(
                ['message' => $res->json('message') ?: $cauMacDinh],
                $res->status() === 500 ? 502 : $res->status()
            );
        }

        return response()->json(['data' => $res->json('data')]);
    }
}
