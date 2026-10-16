import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_exception.dart';
import '../auth/auth_controller.dart';

/// Màn chính tạm thời: chưa có thiết kế, chỉ để kiểm tra phiên và API chạy được.
class HomeScreen extends ConsumerStatefulWidget {
  const HomeScreen({super.key});

  @override
  ConsumerState<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends ConsumerState<HomeScreen> {
  String? _ketQua;
  bool _dangKiemTra = false;

  static const _modules = [
    (Icons.point_of_sale, 'Bán hàng'),
    (Icons.receipt_long, 'Đơn hàng'),
    (Icons.inventory_2_outlined, 'Sản phẩm'),
    (Icons.people_outline, 'Khách hàng'),
    (Icons.schedule, 'Ca làm việc'),
    (Icons.bar_chart, 'Báo cáo'),
  ];

  Future<void> _kiemTraPhien() async {
    setState(() {
      _dangKiemTra = true;
      _ketQua = null;
    });
    try {
      final me = await ref.read(apiClientProvider).get<Map<String, dynamic>>('/auth/me');
      _ketQua = 'Kết nối tốt — máy chủ nhận ra ${me['username'] ?? 'tài khoản'}.';
    } on ApiException catch (e) {
      _ketQua = e.message;
    } finally {
      if (mounted) setState(() => _dangKiemTra = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = ref.watch(authProvider).value;
    if (s == null) return const SizedBox.shrink();
    return Scaffold(
      appBar: AppBar(
        title: Text(s.tenant.name.isNotEmpty ? s.tenant.name : s.tenant.code),
        actions: [
          IconButton(
            tooltip: 'Đăng xuất',
            icon: const Icon(Icons.logout),
            onPressed: () => ref.read(authProvider.notifier).logout(),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
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
          Card(
            color: AppColors.brandSoft,
            elevation: 0,
            child: ListTile(
              leading: const CircleAvatar(
                backgroundColor: AppColors.brand,
                foregroundColor: Colors.white,
                child: Icon(Icons.person),
              ),
              title: Text(s.user.tenHienThi),
              subtitle: Text(
                [
                  if (s.user.roleName != null) s.user.roleName!,
                  if (s.chiNhanhId != null) 'Chi nhánh #${s.chiNhanhId}',
                ].join(' · '),
              ),
            ),
          ),
          const SizedBox(height: 16),
          GridView.count(
            crossAxisCount: 3,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 12,
            crossAxisSpacing: 12,
            children: [
              for (final (icon, ten) in _modules)
                Card(
                  elevation: 0,
                  shape: RoundedRectangleBorder(
                    side: const BorderSide(color: AppColors.line),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(icon, color: AppColors.brand, size: 28),
                      const SizedBox(height: 8),
                      Text(ten, textAlign: TextAlign.center),
                      const Text('Sắp có', style: TextStyle(fontSize: 11, color: AppColors.muted)),
                    ],
                  ),
                ),
            ],
          ),
          const SizedBox(height: 24),
          OutlinedButton.icon(
            onPressed: _dangKiemTra ? null : _kiemTraPhien,
            icon: const Icon(Icons.wifi_tethering),
            label: const Text('Kiểm tra kết nối API'),
          ),
          if (_ketQua != null) ...[const SizedBox(height: 8), Text(_ketQua!, textAlign: TextAlign.center)],
        ],
      ),
    );
  }
}
