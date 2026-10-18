import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_number.dart';
import '../../../core/ui/app_icon.dart';
import '../../auth/auth_controller.dart';
import '../../workspace/workspace.dart' show Branch;
import '../../workspace/workspace_widgets.dart' show greetingFor, initialsOf;
import '../shell/shell_widgets.dart';
import 'dashboard_cards.dart';
import 'dashboard_data.dart';

/// Màn Tổng quan khu Quản trị theo design/html/tong-quan-mobile.html — cùng
/// nguồn số và công thức với Tổng quan PC (DashboardController).
class DashboardScreen extends ConsumerStatefulWidget {
  const DashboardScreen({super.key, required this.onAccount});

  /// Chạm ảnh đại diện — khung app mở ngăn tài khoản.
  final VoidCallback onAccount;

  /// Phần thẻ đè lên đáy đầu navy (margin-top: -60px của bản HTML).
  static const overlap = 60.0;

  @override
  ConsumerState<DashboardScreen> createState() => DashboardScreenState();
}

class DashboardScreenState extends ConsumerState<DashboardScreen> {
  final _alertsKey = GlobalKey();
  final _scroll = ScrollController();
  DashboardData? _last;

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  void scrollToAlerts() {
    final ctx = _alertsKey.currentContext;
    if (ctx != null) {
      Scrollable.ensureVisible(
        ctx,
        alignment: .5,
        duration: const Duration(milliseconds: 400),
        curve: Curves.easeInOut,
      );
    }
  }

  Future<void> openPeriodSheet() async {
    final f = ref.read(dashFilterProvider);
    final now = ref.read(clockProvider)();
    final p = await showDashSheet<DashPeriod>(
      context,
      title: 'Chọn kỳ',
      body: (ctx) => Column(
        children: [
          for (final k in DashPeriod.values)
            SheetOption(
              key: Key('period_${k.name}'),
              label: k.label,
              trailing: _rangeText(k.range(now)),
              selected: f.period == k,
              onTap: () => Navigator.of(ctx).pop(k),
            ),
        ],
      ),
    );
    if (p != null) _setPeriod(p);
  }

  Future<void> openBranchSheet() async {
    final cur = ref.read(dashQueryProvider)?.shopId;
    final branches = await ref.read(dashBranchesProvider.future);
    if (!mounted) return;
    final id = await showDashSheet<int>(
      context,
      title: 'Xem chi nhánh',
      subtitle: 'Mọi số lọc theo chi nhánh này — trừ Tồn kho.',
      body: (ctx) => Column(
        children: [
          for (final b in [...branches, const Branch(id: 0, name: 'Tất cả chi nhánh')])
            SheetOption(
              key: Key('dash_branch_${b.id}'),
              label: b.name,
              trailing: b.isHq ? 'Trụ sở' : null,
              selected: cur == b.id,
              onTap: () => Navigator.of(ctx).pop(b.id),
            ),
        ],
      ),
    );
    if (id != null) ref.read(dashFilterProvider.notifier).setShop(id);
  }

  void _setPeriod(DashPeriod p) {
    ref.read(dashFilterProvider.notifier).setPeriod(p);
    if (_scroll.hasClients) _scroll.jumpTo(0);
  }

  Future<void> _refresh() async {
    final f = ref.read(dashQueryProvider);
    if (f == null) return;
    ref.invalidate(dashboardProvider(f));
    ref.invalidate(pendingOrdersProvider);
    try {
      await ref.read(dashboardProvider(f).future);
      if (mounted) showDashToast(context, 'Đã cập nhật số liệu');
    } on ApiException catch (e) {
      if (mounted) showDashToast(context, e.message);
    }
  }

  Future<void> _setGoal(DashboardData d) async {
    final so = await showDashSheet<int>(
      context,
      title: 'Mục tiêu tháng ${d.now.month}',
      subtitle: 'Doanh thu thuần muốn đạt trong tháng (đồng).',
      body: (_) => const _GoalForm(),
    );
    if (so == null || !mounted) return;
    try {
      await ref
          .read(apiClientProvider)
          .put<Object?>(
            '/admin/settings',
            body: {
              'items': {'monthly_revenue_goal': '$so'},
            },
          );
      final q = ref.read(dashQueryProvider);
      if (q != null) ref.invalidate(dashboardProvider(q));
      if (mounted) showDashToast(context, 'Đã lưu mục tiêu ${compact(so)}');
    } on ApiException catch (e) {
      if (mounted) showDashToast(context, e.message);
    }
  }

  static String _rangeText((DateTime, DateTime) r) {
    final a = ymd(r.$1), b = ymd(r.$2);
    return a == b ? dm(a) : '${dm(a)} – ${dm(b)}';
  }

  @override
  Widget build(BuildContext context) {
    final f = ref.watch(dashFilterProvider);
    final q = ref.watch(dashQueryProvider);
    final AsyncValue<DashboardData> async = q == null ? const AsyncLoading() : ref.watch(dashboardProvider(q));
    if (async.hasValue) _last = async.value;
    final data = async.value ?? _last;
    final session = ref.watch(authProvider).value;
    final now = ref.read(clockProvider)();
    final branches = ref.watch(dashBranchesProvider).value ?? const [];
    final branchName = q == null ? '…' : branchLabel(q.shopId, branches);
    final (a, b) = f.period.range(now);
    final (pa, pb) = prevRange(a, b);
    final name = session?.user.tenHienThi ?? '';

    return RefreshIndicator(
      color: DashColors.primary,
      edgeOffset: MediaQuery.paddingOf(context).top,
      onRefresh: _refresh,
      child: SingleChildScrollView(
        controller: _scroll,
        physics: const AlwaysScrollableScrollPhysics(),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _Header(
              initials: initialsOf(name),
              greeting: '${greetingFor(now)}, $name',
              branch: branchName,
              alertCount: data?.alerts.length ?? 0,
              period: f.period,
              range: a == b ? '${dm(ymd(a))}/${a.year}' : '${dm(ymd(a))} – ${dm(ymd(b))}',
              prevRange: 'so với ${_rangeText((pa, pb))}',
              onAccount: widget.onAccount,
              onBranch: openBranchSheet,
              onBell: scrollToAlerts,
              onPeriod: _setPeriod,
              onMorePeriods: openPeriodSheet,
            ),
            Padding(padding: const EdgeInsets.fromLTRB(12, 0, 12, 18), child: _body(async, data)),
          ],
        ),
      ),
    );
  }

  Widget _body(AsyncValue<DashboardData> async, DashboardData? d) {
    if (d == null) {
      if (async.hasError) {
        final e = async.error;
        return DashCard(
          child: Column(
            children: [
              const AppIcon(AppIcons.alert, size: 26, color: DashColors.critical),
              const SizedBox(height: 10),
              Text(
                e is ApiException ? e.message : 'Không tải được số liệu.',
                textAlign: TextAlign.center,
                style: const TextStyle(color: DashColors.text, fontSize: 14),
              ),
              TextButton(
                onPressed: () {
                  final q = ref.read(dashQueryProvider);
                  if (q != null) ref.invalidate(dashboardProvider(q));
                },
                child: const Text('Thử lại'),
              ),
            ],
          ),
        );
      }
      return const DashCard(
        child: SizedBox(
          height: 240,
          child: Center(child: CircularProgressIndicator(color: DashColors.primary)),
        ),
      );
    }

    final gap = const SizedBox(height: 12);
    return Stack(
      children: [
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            RevenueCard(
              data: d,
              onTable: () => showDashSheet<void>(
                context,
                title: 'Doanh thu thuần · ${d.period.label}',
                body: (_) => RevenueTable(data: d),
              ),
            ),
            gap,
            MetricTiles(data: d),
            gap,
            GoalCard(data: d, onSetGoal: () => _setGoal(d)),
            gap,
            const SectionLabel('Vận hành'),
            gap,
            AlertsCard(
              key: _alertsKey,
              alerts: d.alerts,
              onTap: (a) => showDashToast(context, 'Mở danh sách ở màn tương ứng — màn chưa thiết kế'),
            ),
            gap,
            StatusCard(data: d),
            gap,
            const SectionLabel('Bán hàng'),
            gap,
            MixCard(data: d),
            gap,
            HoursCard(data: d),
            gap,
            TopProductsCard(data: d),
            gap,
            const SectionLabel('Hàng hoá'),
            gap,
            StockCard(data: d),
            gap,
            Text(
              'Cập nhật ${d.now.hour.toString().padLeft(2, '0')}:${d.now.minute.toString().padLeft(2, '0')} · kéo xuống để làm mới',
              textAlign: TextAlign.center,
              style: const TextStyle(color: DashColors.faint, fontSize: 11.5),
            ),
          ],
        ),
        // Đổi kỳ / chi nhánh: giữ số cũ, chỉ hiện vạch tải mảnh — không nháy trắng cả màn.
        if (async.isLoading)
          const Positioned(
            left: 8,
            right: 8,
            top: 0,
            child: LinearProgressIndicator(
              minHeight: 2,
              color: DashColors.primary,
              backgroundColor: Colors.transparent,
            ),
          ),
      ],
    );
  }
}

/// Ô nhập mục tiêu. Tự giữ controller: ngăn kéo còn chạy hiệu ứng đóng sau khi
/// trả kết quả, huỷ controller ở chỗ gọi lúc đó thì ô nhập dùng đồ đã huỷ.
class _GoalForm extends StatefulWidget {
  const _GoalForm();

  @override
  State<_GoalForm> createState() => _GoalFormState();
}

class _GoalFormState extends State<_GoalForm> {
  final _ctl = TextEditingController();

  @override
  void dispose() {
    _ctl.dispose();
    super.dispose();
  }

  void _save() {
    final v = int.tryParse(_ctl.text);
    if (v != null && v > 0) Navigator.of(context).pop(v);
  }

  @override
  Widget build(BuildContext context) => Padding(
    padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const Key('goal_input'),
          controller: _ctl,
          autofocus: true,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(15)],
          decoration: const InputDecoration(hintText: 'Ví dụ 500000000', suffixText: 'đ'),
          onSubmitted: (_) => _save(),
        ),
        const SizedBox(height: 12),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: DashColors.primary),
          onPressed: _save,
          child: const Text('Lưu mục tiêu'),
        ),
      ],
    ),
  );
}

class _Header extends StatelessWidget {
  const _Header({
    required this.initials,
    required this.greeting,
    required this.branch,
    required this.alertCount,
    required this.period,
    required this.range,
    required this.prevRange,
    required this.onAccount,
    required this.onBranch,
    required this.onBell,
    required this.onPeriod,
    required this.onMorePeriods,
  });

  final String initials;
  final String greeting;
  final String branch;
  final int alertCount;
  final DashPeriod period;
  final String range;
  final String prevRange;
  final VoidCallback onAccount;
  final VoidCallback onBranch;
  final VoidCallback onBell;
  final ValueChanged<DashPeriod> onPeriod;
  final VoidCallback onMorePeriods;

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    final idx = switch (period) {
      DashPeriod.today => 0,
      DashPeriod.days7 => 1,
      DashPeriod.thisMonth => 2,
      _ => 3,
    };
    return Stack(
      clipBehavior: Clip.none,
      children: [
        // Nền kéo dài xuống dưới các thẻ [overlap] px — thẻ đầu nằm đè lên đầu navy.
        const Positioned(
          left: 0,
          right: 0,
          top: 0,
          bottom: -DashboardScreen.overlap,
          child: RepaintBoundary(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment(-.17, -1),
                  end: Alignment(.17, 1),
                  colors: [DashColors.navy2, DashColors.navy],
                ),
              ),
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: RadialGradient(
                    center: Alignment.topLeft,
                    radius: 1.2,
                    colors: [Color(0x421890FF), Color(0x001890FF)],
                    stops: [0, .6],
                  ),
                ),
              ),
            ),
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(16, top + 4, 16, 74 - DashboardScreen.overlap),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(
                height: 48,
                child: Row(
                  children: [
                    Semantics(
                      button: true,
                      label: 'Tài khoản',
                      child: GestureDetector(
                        key: const Key('avatar'),
                        onTap: onAccount,
                        child: Container(
                          width: 38,
                          height: 38,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: const Color(0x1FFFFFFF),
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(color: const Color(0x2EFFFFFF)),
                          ),
                          child: Text(
                            initials,
                            style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w700),
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            greeting,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(color: Color(0x99FFFFFF), fontSize: 12),
                          ),
                          GestureDetector(
                            key: const Key('branch_filter'),
                            onTap: onBranch,
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Flexible(
                                  child: Text(
                                    branch,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(
                                      color: Colors.white,
                                      fontSize: 15,
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 4),
                                const AppIcon(AppIcons.down, size: 16, strokeWidth: 2.4, color: Color(0xB3FFFFFF)),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 10),
                    BellButton(count: alertCount, onTap: onBell),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              Container(
                height: 38,
                padding: const EdgeInsets.all(3),
                decoration: BoxDecoration(color: const Color(0x14FFFFFF), borderRadius: BorderRadius.circular(12)),
                child: Stack(
                  children: [
                    AnimatedAlign(
                      duration: const Duration(milliseconds: 300),
                      curve: const Cubic(.2, .8, .2, 1),
                      alignment: Alignment(-1 + idx * 2 / 3, 0),
                      child: FractionallySizedBox(
                        widthFactor: .25,
                        heightFactor: 1,
                        child: DecoratedBox(
                          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(9)),
                        ),
                      ),
                    ),
                    Row(
                      children: [
                        _seg('Hôm nay', idx == 0, () => onPeriod(DashPeriod.today)),
                        _seg('7 ngày', idx == 1, () => onPeriod(DashPeriod.days7)),
                        _seg('Tháng', idx == 2, () => onPeriod(DashPeriod.thisMonth)),
                        _seg(idx == 3 ? period.label : 'Khác', idx == 3, onMorePeriods, key: const Key('period_more')),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      range,
                      style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600),
                    ),
                  ),
                  Text(prevRange, style: const TextStyle(color: Color(0x99FFFFFF), fontSize: 12)),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _seg(String label, bool on, VoidCallback onTap, {Key? key}) => Expanded(
    child: Semantics(
      button: true,
      selected: on,
      child: GestureDetector(
        key: key,
        behavior: HitTestBehavior.opaque,
        onTap: onTap,
        child: Center(
          child: AnimatedDefaultTextStyle(
            duration: const Duration(milliseconds: 200),
            style: TextStyle(
              fontFamily: appFont,
              color: on ? DashColors.navy : const Color(0xB8FFFFFF),
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
            child: Text(label, maxLines: 1),
          ),
        ),
      ),
    ),
  );
}
