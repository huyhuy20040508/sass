import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_exception.dart';
import '../../core/ui/app_icon.dart';
import '../auth/auth_controller.dart';
import '../auth/session.dart';

/// Hai khu vực làm việc — khớp WorkspaceModule của web_Shop.
enum WorkspaceArea {
  quanTri(
    cua: 'quan_ly',
    route: '/quan-tri',
    name: 'Quản trị',
    description: 'Hàng hoá, kho, khách hàng, báo cáo',
    image: 'assets/images/admin-workspace.jpg',
    icon: AppIcons.grid,
  ),
  thuNgan(
    cua: 'thu_ngan',
    route: '/thu-ngan',
    name: 'Thu ngân',
    description: 'Bán hàng, điều phối ca, sổ quỹ',
    image: 'assets/images/cashier-workspace.jpg',
    icon: AppIcons.cash,
  );

  const WorkspaceArea({
    required this.cua,
    required this.route,
    required this.name,
    required this.description,
    required this.image,
    required this.icon,
  });

  /// Giá trị trong `users.access_areas`.
  final String cua;
  final String route;
  final String name;
  final String description;
  final String image;
  final AppIcons icon;

  /// Cửa vào của tài khoản, cùng luật Workspace::tuPhien bên web_Shop: đọc
  /// `access_areas`; tài khoản cũ chưa có cột này thì suy từ vai trò.
  static List<WorkspaceArea> forUser({String? accessAreas, String? roleCode}) {
    final cua = (accessAreas ?? '').split(',').map((x) => x.trim()).where((x) => x.isNotEmpty).toSet();
    if (cua.isEmpty) {
      return switch (roleCode) {
        'super_admin' || 'admin' => WorkspaceArea.values,
        'staff' => const [WorkspaceArea.thuNgan],
        _ => const [],
      };
    }
    return [
      for (final a in WorkspaceArea.values)
        if (cua.contains(a.cua)) a,
    ];
  }
}

class Branch {
  const Branch({required this.id, required this.name, this.isHq = false});

  final int id;
  final String name;

  /// Trụ sở — đứng đầu danh sách và có nhãn riêng.
  final bool isHq;
}

/// Chi nhánh loại "Công ty" (BranchController::LOAI_CONG_TY bên web_Shop).
const _loaiCongTy = 2;

/// Sắp chi nhánh như CurrentBranch::danhSach của web_Shop: trụ sở (id nhỏ nhất
/// trong các chi nhánh loại Công ty, không có thì id nhỏ nhất) lên đầu.
List<Branch> parseBranches(List<dynamic> raw, {List<int> assigned = const []}) {
  final rows = [
    for (final r in raw.whereType<Map>())
      if (assigned.isEmpty || assigned.contains((r['id'] as num).toInt())) r,
  ];
  if (rows.isEmpty) return const [];
  int idOf(Map r) => (r['id'] as num).toInt();
  final congTy = rows.where((r) => (r['branch_type'] as num?)?.toInt() == _loaiCongTy).map(idOf).toList();
  final pool = congTy.isNotEmpty ? congTy : rows.map(idOf).toList();
  final hq = pool.reduce((a, b) => a < b ? a : b);
  final list = [for (final r in rows) Branch(id: idOf(r), name: (r['name'] ?? '') as String, isHq: idOf(r) == hq)];
  // Sắp ổn định: trụ sở lên đầu, còn lại giữ thứ tự API trả.
  return [...list.where((b) => b.isHq), ...list.where((b) => !b.isHq)];
}

class WorkspaceOptions {
  const WorkspaceOptions({required this.areas, required this.branches, this.locked = false, this.lockedMessage});

  final List<WorkspaceArea> areas;
  final List<Branch> branches;

  /// Cửa hàng hết hạn hợp đồng (CUA_HANG_KHOA): chỉ còn đường gia hạn trên web.
  final bool locked;
  final String? lockedMessage;

  /// Còn gì để chọn không — thiếu cả hai thì vào thẳng như web_Shop.
  bool get needsChoice => areas.length > 1 || branches.length > 1;
}

/// Khu vực + chi nhánh của người đang đăng nhập. Đọc lại `access_areas` qua
/// /auth/me vì quản lý có thể đã đổi cửa vào sau lúc đăng nhập.
final workspaceOptionsProvider = FutureProvider.autoDispose<WorkspaceOptions>(
  _loadWorkspaceOptions,
  // Không tự thử lại: màn có nút "Thử lại", tự thử ngầm thì người dùng chỉ thấy vòng xoay mãi.
  retry: _noRetry,
);

Duration? _noRetry(int _, Object _) => null;

Future<WorkspaceOptions> _loadWorkspaceOptions(Ref ref) async {
  final session = ref.read(authProvider).value;
  if (session == null) return const WorkspaceOptions(areas: [], branches: []);
  final api = ref.read(apiClientProvider);

  Future<SessionUser> me() async {
    final j = await api.get<Map<String, dynamic>>('/auth/me');
    return SessionUser.fromJson(j);
  }

  // Thu ngân không có quyền xem danh sách chi nhánh (403) — coi như không có gì để chọn.
  Future<List<Branch>> branches() async {
    try {
      final raw = await api.get<List<dynamic>>('/admin/chi-nhanh', query: {'active': 'true'});
      return parseBranches(raw, assigned: session.chiNhanhIds);
    } on ApiException catch (e) {
      if (e.statusCode == 403 && e.ma != MaLoi.cuaHangKhoa) return const [];
      rethrow;
    }
  }

  try {
    final kq = await Future.wait<Object>([me(), branches()]);
    final user = kq[0] as SessionUser;
    return WorkspaceOptions(
      areas: WorkspaceArea.forUser(accessAreas: user.accessAreas, roleCode: user.roleCode ?? session.user.roleCode),
      branches: kq[1] as List<Branch>,
    );
  } on ApiException catch (e) {
    if (e.ma != MaLoi.cuaHangKhoa) rethrow;
    return WorkspaceOptions(areas: const [], branches: const [], locked: true, lockedMessage: e.message);
  }
}

/// Khu vực đang làm. null = chưa chọn → router đưa về màn chọn nơi làm việc.
final currentAreaProvider = NotifierProvider<CurrentArea, WorkspaceArea?>(CurrentArea.new);

class CurrentArea extends Notifier<WorkspaceArea?> {
  @override
  WorkspaceArea? build() {
    // Đăng xuất / đổi tài khoản thì phải chọn lại.
    ref.watch(authProvider.select((s) => s.value?.user.id));
    return null;
  }

  void enter(WorkspaceArea a) => state = a;

  void leave() => state = null;
}
