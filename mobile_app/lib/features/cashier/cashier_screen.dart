import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/ui/app_icon.dart';
import '../auth/auth_controller.dart';
import '../workspace/workspace.dart';

/// Màn chờ của khu Thu ngân — chưa có thiết kế mobile.
///
/// Thay cho khung dựng thử hồi 16/10 (lưới sáu ô "Sắp có" + nút "Kiểm tra kết
/// nối API"). Khung ấy là đồ của lập trình viên: phát hành APK lúc này thì thu
/// ngân mở ra thấy một màn hình có vẻ hỏng, bấm đâu cũng không ra gì.
///
/// Giữ lại ĐƯỜNG VÀO chứ không giấu khu Thu ngân đi: tài khoản chỉ có cửa
/// `thu_ngan` mà giấu mất khu của họ thì đăng nhập xong không còn chỗ nào để
/// tới. Màn này nói thẳng đang thiếu gì và chỉ chỗ làm tạm.
class CashierScreen extends ConsumerWidget {
  const CashierScreen({super.key});

  /// Những màn đã có bên bản web, sẽ lần lượt làm cho điện thoại. Chỉ là chữ,
  /// KHÔNG dựng thành ô bấm được: ô bấm mà không mở ra gì là lời hứa suông.
  static const sapCo = ['Bán hàng', 'Trả hàng', 'Điều phối ca', 'Thu chi', 'Khách hàng'];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(authProvider).value;
    if (s == null) return const SizedBox.shrink();

    // Tên chi nhánh chỉ tra được khi tài khoản có quyền xem danh sách chi nhánh
    // (thu ngân thuần bị API trả 403) — không tra ra thì ghi số hiệu, đừng bỏ
    // trống: người ta cần biết mình đang đứng ở kho nào.
    final branches = ref.watch(workspaceOptionsProvider).value?.branches ?? const <Branch>[];
    final ten = branches.where((b) => b.id == s.chiNhanhId).map((b) => b.name).firstOrNull;
    final cuaHang = s.tenant.name.isNotEmpty ? s.tenant.name : s.tenant.code;
    var chiNhanh = ten ?? (s.chiNhanhId == null ? null : 'Chi nhánh #${s.chiNhanhId}');
    // Tiệm một chi nhánh thường đặt tên chi nhánh trùng tên cửa hàng — in hai
    // dòng giống hệt nhau trông như lỗi.
    if (chiNhanh == cuaHang) chiNhanh = null;

    return Scaffold(
      backgroundColor: DashColors.page,
      appBar: AppBar(
        backgroundColor: DashColors.navy,
        foregroundColor: Colors.white,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(cuaHang, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
            if (chiNhanh != null)
              Text(chiNhanh, style: const TextStyle(fontSize: 12.5, color: Color(0xFFBFD0EA))),
          ],
        ),
        actions: [
          // Cùng chỗ với nút đổi module ở góc phải thanh trên cùng bản PC.
          TextButton.icon(
            onPressed: () => ref.read(currentAreaProvider.notifier).leave(),
            style: TextButton.styleFrom(foregroundColor: Colors.white),
            icon: const Icon(Icons.swap_horiz),
            label: Text(WorkspaceArea.thuNgan.name),
          ),
          IconButton(
            tooltip: 'Đăng xuất',
            icon: const Icon(Icons.logout),
            onPressed: () => ref.read(authProvider.notifier).logout(),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
        children: [
          if (s.cuaHangKhoa)
            Container(
              margin: const EdgeInsets.only(bottom: 16),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: AppColors.dangerSoft, borderRadius: BorderRadius.circular(12)),
              child: const Text(
                'Cửa hàng đã hết hạn hợp đồng. Gia hạn trên web để tiếp tục sử dụng.',
                style: TextStyle(color: AppColors.danger),
              ),
            ),
          Container(
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(14),
              boxShadow: DashColors.cardShadow,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      width: 40,
                      height: 40,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: DashColors.primarySoft,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const AppIcon(AppIcons.cash, size: 20, color: DashColors.primaryDeep),
                    ),
                    const SizedBox(width: 12),
                    const Expanded(
                      child: Text(
                        'Màn Thu ngân cho điện thoại đang được làm',
                        style: TextStyle(color: DashColors.ink, fontSize: 16, fontWeight: FontWeight.w700),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                const Text(
                  'Trong lúc chờ, bán hàng và kết ca vẫn làm trên bản web ở máy tính như thường — '
                  'dữ liệu là một, không phải nhập lại.',
                  style: TextStyle(color: DashColors.text, fontSize: 13.5, height: 1.45),
                ),
                const SizedBox(height: 14),
                const Text(
                  'SẼ CÓ TRÊN ĐIỆN THOẠI',
                  style: TextStyle(
                    color: DashColors.muted,
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    letterSpacing: .7,
                  ),
                ),
                const SizedBox(height: 6),
                for (final m in sapCo)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 3),
                    child: Row(
                      children: [
                        const Icon(Icons.circle, size: 5, color: DashColors.faint),
                        const SizedBox(width: 8),
                        Text(m, style: const TextStyle(color: DashColors.text, fontSize: 13.5)),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          Text(
            'Đang đăng nhập: ${s.user.tenHienThi}${s.user.roleName == null ? '' : ' · ${s.user.roleName}'}',
            textAlign: TextAlign.center,
            style: const TextStyle(color: DashColors.muted, fontSize: 12.5),
          ),
        ],
      ),
    );
  }
}
