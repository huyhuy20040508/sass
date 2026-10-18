import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/ui/app_icon.dart';
import '../auth/auth_controller.dart';
import '../workspace/workspace.dart';
import '../workspace/workspace_widgets.dart' show SheetButton, initialsOf;
import 'dashboard/dashboard_data.dart';
import 'dashboard/dashboard_screen.dart';
import 'shell/shell_widgets.dart';

/// Khung app của khu Quản trị: thanh dưới 5 mục, thanh navy thu gọn, ngăn tài khoản và "Thêm".
class AdminShell extends ConsumerStatefulWidget {
  const AdminShell({super.key});

  /// Cuộn quá chừng này thì phần đầu navy đã khuất — hiện thanh thu gọn.
  static const collapseAt = 150.0;

  @override
  ConsumerState<AdminShell> createState() => _AdminShellState();
}

class _AdminShellState extends ConsumerState<AdminShell> {
  final _dash = GlobalKey<DashboardScreenState>();
  AdminTab _tab = AdminTab.home;
  bool _scrolled = false;

  static const _functions = [
    (
      'Bán hàng',
      [
        ('Đơn hàng', AppIcons.receipt),
        ('Trả hàng', AppIcons.returns),
        ('Khách hàng', AppIcons.users),
        ('Ca làm việc', AppIcons.clock),
      ],
    ),
    (
      'Hàng hoá & kho',
      [
        ('Hàng hoá', AppIcons.box),
        ('Tồn kho', AppIcons.store),
        ('Nhập hàng', AppIcons.truck),
        ('Kiểm kho', AppIcons.clip),
        ('Nhà cung cấp', AppIcons.factory),
        ('Trả hàng NCC', AppIcons.returns),
      ],
    ),
    ('Marketing', [('Khuyến mại', AppIcons.tag), ('Voucher', AppIcons.ticket), ('Bài viết', AppIcons.news)]),
    (
      'Quản lý',
      [
        ('Báo cáo', AppIcons.chart),
        ('Nhân viên', AppIcons.user),
        ('Chi nhánh', AppIcons.store),
        ('Phân quyền', AppIcons.shield),
        ('Cài đặt', AppIcons.gear),
      ],
    ),
  ];

  bool get _canCashier {
    final u = ref.read(authProvider).value?.user;
    return u != null &&
        WorkspaceArea.forUser(accessAreas: u.accessAreas, roleCode: u.roleCode).contains(WorkspaceArea.thuNgan);
  }

  void _toCashier() => ref.read(currentAreaProvider.notifier).enter(WorkspaceArea.thuNgan);

  void _soon(String name) => showDashToast(context, '$name — màn chưa thiết kế');

  void _goHome(VoidCallback then) {
    if (_tab == AdminTab.home) return then();
    setState(() => _tab = AdminTab.home);
    WidgetsBinding.instance.addPostFrameCallback((_) => then());
  }

  Future<void> _openAccount() async {
    final s = ref.read(authProvider).value;
    if (s == null) return;
    final pick = await showDashSheet<String>(
      context,
      title: 'Tài khoản',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            margin: const EdgeInsets.fromLTRB(4, 0, 4, 14),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(color: DashColors.page, borderRadius: BorderRadius.circular(16)),
            child: Row(
              children: [
                Container(
                  width: 48,
                  height: 48,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(15),
                    gradient: const LinearGradient(
                      begin: Alignment(-.5, -1),
                      end: Alignment(.5, 1),
                      colors: [DashColors.navy3, DashColors.navy],
                    ),
                  ),
                  child: Text(
                    initialsOf(s.user.tenHienThi),
                    style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w700),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        s.user.tenHienThi,
                        style: const TextStyle(color: DashColors.ink, fontSize: 15.5, fontWeight: FontWeight.w700),
                      ),
                      Text(
                        [s.user.username, if (s.user.roleName != null) s.user.roleName!].join(' · '),
                        style: const TextStyle(color: DashColors.muted, fontSize: 12.5),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          SheetOption(label: 'Đổi chi nhánh', leading: AppIcons.store, onTap: () => Navigator.of(ctx).pop('branch')),
          if (_canCashier)
            SheetOption(
              label: 'Chuyển sang khu Thu ngân',
              leading: AppIcons.swapH,
              onTap: () => Navigator.of(ctx).pop('cashier'),
            ),
          SheetOption(
            label: 'Thông tin tài khoản',
            leading: AppIcons.user,
            onTap: () => Navigator.of(ctx).pop('profile'),
          ),
          SheetOption(
            key: const Key('account_logout'),
            label: 'Đăng xuất',
            leading: AppIcons.logout,
            danger: true,
            onTap: () => Navigator.of(ctx).pop('logout'),
          ),
        ],
      ),
    );
    if (!mounted) return;
    switch (pick) {
      case 'branch':
        _goHome(() => _dash.currentState?.openBranchSheet());
      case 'cashier':
        _toCashier();
      case 'profile':
        _soon('Thông tin tài khoản');
      case 'logout':
        await _confirmLogout();
    }
  }

  Future<void> _openMore() async {
    final pick = await showDashSheet<String>(
      context,
      title: 'Tất cả chức năng',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final (group, items) in _functions)
            Padding(
              padding: const EdgeInsets.fromLTRB(4, 4, 4, 16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    group.toUpperCase(),
                    style: const TextStyle(
                      color: DashColors.muted,
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      letterSpacing: .7,
                    ),
                  ),
                  const SizedBox(height: 8),
                  GridView.count(
                    crossAxisCount: 4,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    mainAxisSpacing: 6,
                    crossAxisSpacing: 6,
                    childAspectRatio: .9,
                    children: [
                      for (final (name, icon) in items)
                        InkWell(
                          borderRadius: BorderRadius.circular(14),
                          onTap: () => Navigator.of(ctx).pop(name),
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Container(
                                width: 44,
                                height: 44,
                                alignment: Alignment.center,
                                decoration: BoxDecoration(
                                  color: DashColors.primarySoft,
                                  borderRadius: BorderRadius.circular(14),
                                ),
                                child: AppIcon(icon, size: 21, color: DashColors.primaryDeep),
                              ),
                              const SizedBox(height: 6),
                              Text(
                                name,
                                textAlign: TextAlign.center,
                                maxLines: 2,
                                style: const TextStyle(
                                  color: DashColors.text,
                                  fontSize: 11.5,
                                  fontWeight: FontWeight.w500,
                                  height: 1.25,
                                ),
                              ),
                            ],
                          ),
                        ),
                    ],
                  ),
                ],
              ),
            ),
          if (_canCashier)
            Material(
              borderRadius: BorderRadius.circular(14),
              clipBehavior: Clip.antiAlias,
              child: Ink(
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment(-.5, -1),
                    end: Alignment(.5, 1),
                    colors: [DashColors.navy2, DashColors.navy],
                  ),
                ),
                child: InkWell(
                  onTap: () => Navigator.of(ctx).pop('__cashier'),
                  child: const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    child: Row(
                      children: [
                        AppIcon(AppIcons.swapH, size: 22, color: Colors.white),
                        SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                'Chuyển sang khu Thu ngân',
                                style: TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.w700),
                              ),
                              Text(
                                'Bán hàng, điều phối ca, sổ quỹ',
                                style: TextStyle(color: Color(0xA6FFFFFF), fontSize: 12),
                              ),
                            ],
                          ),
                        ),
                        AppIcon(AppIcons.right, size: 18, color: Colors.white),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          // Đăng xuất để ở cuối "Thêm" — chỗ người dùng tìm đầu tiên; ngăn tài khoản (chạm ảnh) cũng có.
          const SizedBox(height: 10),
          SheetOption(
            key: const Key('more_logout'),
            label: 'Đăng xuất',
            leading: AppIcons.logout,
            danger: true,
            onTap: () => Navigator.of(ctx).pop('__logout'),
          ),
        ],
      ),
    );
    if (pick == null || !mounted) return;
    switch (pick) {
      case '__cashier':
        _toCashier();
      case '__logout':
        await _confirmLogout();
      default:
        _soon(pick);
    }
  }

  Future<void> _confirmLogout() async {
    final yes = await showDashSheet<bool>(
      context,
      title: 'Đăng xuất?',
      subtitle: 'Sai tài khoản hay sai tiệm thì đăng xuất rồi vào lại.',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SheetButton(
            key: const Key('logout_yes'),
            label: 'Đăng xuất',
            icon: AppIcons.logout,
            danger: true,
            onTap: () => Navigator.of(ctx).pop(true),
          ),
          SheetButton(label: 'Ở lại', onTap: () => Navigator.of(ctx).pop(false)),
        ],
      ),
    );
    if (yes == true) await ref.read(authProvider.notifier).logout();
  }

  @override
  Widget build(BuildContext context) {
    final f = ref.watch(dashFilterProvider);
    final q = ref.watch(dashQueryProvider);
    final data = q == null ? null : ref.watch(dashboardProvider(q)).value;
    final branches = ref.watch(dashBranchesProvider).value ?? const [];
    final branchName = q == null ? '…' : branchLabel(q.shopId, branches);
    final pending = ref.watch(pendingOrdersProvider).value ?? 0;
    final home = _tab == AdminTab.home;

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        backgroundColor: DashColors.page,
        body: Column(
          children: [
            Expanded(
              child: Stack(
                children: [
                  Positioned.fill(
                    child: IndexedStack(
                      index: _tab.index,
                      children: [
                        NotificationListener<ScrollUpdateNotification>(
                          onNotification: (n) {
                            if (n.depth == 0) {
                              final s = n.metrics.pixels > AdminShell.collapseAt;
                              if (s != _scrolled) setState(() => _scrolled = s);
                            }
                            return false;
                          },
                          child: DashboardScreen(key: _dash, onAccount: _openAccount),
                        ),
                        for (final t in AdminTab.values.skip(1))
                          SkeletonTab(key: Key('skeleton_${t.name}'), name: t.label),
                      ],
                    ),
                  ),
                  Positioned(
                    left: 0,
                    right: 0,
                    top: 0,
                    child: CollapsedAppBar(
                      visible: !home || _scrolled,
                      title: _tab.label,
                      subtitle: branchName,
                      periodLabel: home ? f.period.label : null,
                      onPeriod: () => _dash.currentState?.openPeriodSheet(),
                      alertCount: data?.alerts.length ?? 0,
                      onBell: () => _goHome(() => _dash.currentState?.scrollToAlerts()),
                    ),
                  ),
                ],
              ),
            ),
            AdminTabBar(current: _tab, orderCount: pending, onTab: (t) => setState(() => _tab = t), onMore: _openMore),
          ],
        ),
      ),
    );
  }
}
