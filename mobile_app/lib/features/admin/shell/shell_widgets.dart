import 'package:flutter/material.dart';

import '../../../app/theme.dart';
import '../../../core/ui/app_icon.dart';

/// Các tab của khung khu Quản trị (thanh dưới). "Thêm" không phải tab — nó mở ngăn kéo.
enum AdminTab {
  home('Tổng quan', AppIcons.home),
  orders('Đơn hàng', AppIcons.receipt),
  pos('Bán hàng', AppIcons.cart),
  goods('Hàng hoá', AppIcons.box);

  const AdminTab(this.label, this.icon);

  final String label;
  final AppIcons icon;
}

/// Thanh dưới kiểu Material 3: viên chỉ báo sau icon mục đang mở, Bán hàng nổi giữa.
class AdminTabBar extends StatelessWidget {
  const AdminTabBar({super.key, required this.current, required this.onTab, required this.onMore, this.orderCount = 0});

  final AdminTab current;
  final ValueChanged<AdminTab> onTab;
  final VoidCallback onMore;

  /// Số đỏ trên Đơn hàng = đơn còn đang xử lý.
  final int orderCount;

  static const height = 66.0;

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.viewPaddingOf(context).bottom;
    return Container(
      height: height + bottom,
      padding: EdgeInsets.fromLTRB(6, 0, 6, bottom),
      decoration: const BoxDecoration(
        color: Color(0xF7FFFFFF),
        boxShadow: [
          BoxShadow(color: DashColors.line, offset: Offset(0, -1)),
          BoxShadow(color: Color(0x400F172A), offset: Offset(0, -10), blurRadius: 24, spreadRadius: -16),
        ],
      ),
      child: Row(
        children: [
          _item(AdminTab.home),
          _item(AdminTab.orders, badge: orderCount),
          Expanded(
            child: _PosTab(active: current == AdminTab.pos, onTap: () => onTab(AdminTab.pos)),
          ),
          _item(AdminTab.goods),
          Expanded(
            child: _TabButton(label: 'Thêm', icon: AppIcons.menu, active: false, onTap: onMore),
          ),
        ],
      ),
    );
  }

  Widget _item(AdminTab t, {int badge = 0}) => Expanded(
    child: _TabButton(
      key: Key('tab_${t.name}'),
      label: t.label,
      icon: t.icon,
      active: current == t,
      badge: badge,
      onTap: () => onTab(t),
    ),
  );
}

class _TabButton extends StatelessWidget {
  const _TabButton({
    super.key,
    required this.label,
    required this.icon,
    required this.active,
    required this.onTap,
    this.badge = 0,
  });

  final String label;
  final AppIcons icon;
  final bool active;
  final VoidCallback onTap;
  final int badge;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: active,
      label: badge > 0 ? '$label, $badge đơn đang xử lý' : label,
      excludeSemantics: true,
      child: InkResponse(
        onTap: onTap,
        radius: 32,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Stack(
              clipBehavior: Clip.none,
              children: [
                AnimatedContainer(
                  duration: const Duration(milliseconds: 250),
                  curve: const Cubic(.2, .8, .2, 1),
                  width: 56,
                  height: 30,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: active ? DashColors.primarySoft : Colors.transparent,
                    borderRadius: BorderRadius.circular(15),
                  ),
                  child: AppIcon(
                    icon,
                    size: 22,
                    strokeWidth: active ? 2.2 : 1.8,
                    color: active ? DashColors.primaryDeep : DashColors.muted,
                  ),
                ),
                if (badge > 0)
                  Positioned(
                    top: -3,
                    left: 33,
                    child: Container(
                      constraints: const BoxConstraints(minWidth: 18),
                      height: 18,
                      padding: const EdgeInsets.symmetric(horizontal: 5),
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: DashColors.critical,
                        borderRadius: BorderRadius.circular(9),
                        border: Border.all(color: Colors.white, width: 2),
                      ),
                      child: Text(
                        badge > 99 ? '99+' : '$badge',
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 10,
                          fontWeight: FontWeight.w700,
                          height: 1.1,
                        ),
                      ),
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              label,
              style: TextStyle(
                color: active ? DashColors.navy : DashColors.muted,
                fontSize: 11,
                fontWeight: active ? FontWeight.w700 : FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PosTab extends StatefulWidget {
  const _PosTab({required this.active, required this.onTap});

  final bool active;
  final VoidCallback onTap;

  @override
  State<_PosTab> createState() => _PosTabState();
}

class _PosTabState extends State<_PosTab> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: widget.active,
      label: 'Bán hàng',
      excludeSemantics: true,
      child: GestureDetector(
        key: const Key('tab_pos'),
        behavior: HitTestBehavior.opaque,
        onTapDown: (_) => setState(() => _down = true),
        onTapUp: (_) => setState(() => _down = false),
        onTapCancel: () => setState(() => _down = false),
        onTap: widget.onTap,
        // Stack chứ không Column: nút 54px nổi lên trên mép thanh, không đội chiều cao
        // thanh. Chữ đặt cùng hàng với chữ các tab khác.
        child: SizedBox(
          height: AdminTabBar.height,
          child: Stack(
            clipBehavior: Clip.none,
            alignment: Alignment.bottomCenter,
            children: [
              const Padding(
                padding: EdgeInsets.only(bottom: 9),
                child: Text(
                  'Bán hàng',
                  style: TextStyle(color: DashColors.navy, fontSize: 11, fontWeight: FontWeight.w600),
                ),
              ),
              Positioned(
                top: -16,
                child: AnimatedScale(
                  scale: _down ? .94 : 1,
                  duration: const Duration(milliseconds: 150),
                  child: Container(
                    width: 54,
                    height: 54,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(18),
                      gradient: LinearGradient(
                        begin: const Alignment(-.5, -1),
                        end: const Alignment(.5, 1),
                        colors: widget.active
                            ? const [Color(0xFF2A8CF0), DashColors.primaryDeep]
                            : const [DashColors.navy3, DashColors.navy],
                      ),
                      boxShadow: const [
                        BoxShadow(color: Colors.white, spreadRadius: 4),
                        BoxShadow(color: Color(0xA60B1B3A), offset: Offset(0, 12), blurRadius: 22, spreadRadius: -8),
                      ],
                    ),
                    child: const AppIcon(AppIcons.cart, size: 25, strokeWidth: 2, color: Colors.white),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Thanh navy thu gọn dính trên: hiện khi cuộn qua phần đầu, hoặc ở các tab khác.
class CollapsedAppBar extends StatelessWidget {
  const CollapsedAppBar({
    super.key,
    required this.visible,
    required this.title,
    required this.subtitle,
    this.periodLabel,
    this.onPeriod,
    required this.alertCount,
    required this.onBell,
  });

  final bool visible;
  final String title;
  final String subtitle;

  /// null = ẩn chip kỳ (chỉ màn Tổng quan có kỳ).
  final String? periodLabel;
  final VoidCallback? onPeriod;
  final int alertCount;
  final VoidCallback onBell;

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return AnimatedSlide(
      // Ẩn thì đẩy quá hẳn chiều cao: đuôi mờ của bóng đổ tràn ~30px dưới thanh, chỉ -1 là vẫn lọt một vệt ở mép trên.
      offset: visible ? Offset.zero : const Offset(0, -1.8),
      duration: const Duration(milliseconds: 280),
      curve: const Cubic(.2, .8, .2, 1),
      child: Container(
        height: top + 54,
        padding: EdgeInsets.fromLTRB(16, top, 10, 0),
        decoration: const BoxDecoration(
          color: Color(0xF50B1B3A),
          boxShadow: [BoxShadow(color: Color(0x80000000), offset: Offset(0, 8), blurRadius: 22, spreadRadius: -12)],
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 16.5,
                      fontWeight: FontWeight.w700,
                      letterSpacing: -.16,
                    ),
                  ),
                  Text(
                    subtitle,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: Color(0x99FFFFFF), fontSize: 11.5),
                  ),
                ],
              ),
            ),
            if (periodLabel != null) ...[
              Material(
                color: const Color(0x1FFFFFFF),
                borderRadius: BorderRadius.circular(11),
                child: InkWell(
                  borderRadius: BorderRadius.circular(11),
                  onTap: onPeriod,
                  child: Container(
                    height: 34,
                    padding: const EdgeInsets.fromLTRB(11, 0, 9, 0),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          periodLabel!,
                          style: const TextStyle(color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.w600),
                        ),
                        const SizedBox(width: 3),
                        const AppIcon(AppIcons.down, size: 15, strokeWidth: 2.4, color: Color(0xBFFFFFFF)),
                      ],
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
            ],
            BellButton(count: alertCount, onTap: onBell),
          ],
        ),
      ),
    );
  }
}

/// Nút chuông có số việc cần chú ý.
class BellButton extends StatelessWidget {
  const BellButton({super.key, required this.count, required this.onTap});

  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Tooltip(
    message: 'Cần chú ý',
    child: Material(
      color: const Color(0x1AFFFFFF),
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: SizedBox(
          width: 38,
          height: 38,
          child: Stack(
            children: [
              const Center(child: AppIcon(AppIcons.bell, size: 19, color: Colors.white)),
              if (count > 0)
                Positioned(
                  top: 4,
                  right: 4,
                  child: Container(
                    constraints: const BoxConstraints(minWidth: 16),
                    height: 16,
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: DashColors.critical,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: DashColors.navy, width: 2),
                    ),
                    child: Text(
                      '$count',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 9.5,
                        fontWeight: FontWeight.w700,
                        height: 1.1,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    ),
  );
}

/// Ngăn kéo từ dưới lên của khu Quản trị: tiêu đề, dòng phụ, thân cuộn được.
Future<T?> showDashSheet<T>(
  BuildContext context, {
  required String title,
  String? subtitle,
  required WidgetBuilder body,
}) => showModalBottomSheet<T>(
  context: context,
  isScrollControlled: true,
  useRootNavigator: true,
  backgroundColor: Colors.white,
  barrierColor: const Color(0x800B1B3A),
  shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(22))),
  builder: (ctx) {
    final mq = MediaQuery.of(ctx);
    return ConstrainedBox(
      constraints: BoxConstraints(maxHeight: mq.size.height * .84),
      child: Padding(
        padding: EdgeInsets.fromLTRB(16, 10, 16, mq.viewPadding.bottom + 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: Container(
                width: 40,
                height: 5,
                margin: const EdgeInsets.only(bottom: 14),
                decoration: BoxDecoration(color: const Color(0xFFD5DBE5), borderRadius: BorderRadius.circular(3)),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(4, 0, 4, 4),
              child: Semantics(
                header: true,
                child: Text(
                  title,
                  style: const TextStyle(color: DashColors.ink, fontSize: 18, fontWeight: FontWeight.w700),
                ),
              ),
            ),
            if (subtitle != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(4, 0, 4, 12),
                child: Text(subtitle, style: const TextStyle(color: DashColors.muted, fontSize: 13)),
              )
            else
              const SizedBox(height: 8),
            Flexible(child: SingleChildScrollView(child: body(ctx))),
          ],
        ),
      ),
    );
  },
);

/// Một dòng lựa chọn trong ngăn kéo: đang chọn thì nền xanh nhạt + dấu tích.
class SheetOption extends StatelessWidget {
  const SheetOption({
    super.key,
    required this.label,
    required this.onTap,
    this.selected = false,
    this.trailing,
    this.leading,
    this.danger = false,
  });

  final String label;
  final VoidCallback onTap;
  final bool selected;
  final String? trailing;
  final AppIcons? leading;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final fg = danger
        ? DashColors.critical
        : selected
        ? DashColors.primaryDeep
        : DashColors.ink;
    return Semantics(
      selected: selected,
      button: true,
      child: Material(
        color: selected ? DashColors.primarySoft : Colors.transparent,
        borderRadius: BorderRadius.circular(12),
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: onTap,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 50),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              child: Row(
                children: [
                  if (leading != null) ...[
                    Container(
                      width: 34,
                      height: 34,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: danger ? const Color(0xFFFBE9E9) : DashColors.page,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: AppIcon(leading!, size: 18, color: danger ? DashColors.critical : DashColors.muted),
                    ),
                    const SizedBox(width: 12),
                  ],
                  Expanded(
                    child: Text.rich(
                      TextSpan(
                        text: label,
                        children: [
                          if (trailing != null)
                            TextSpan(
                              text: ' · $trailing',
                              style: const TextStyle(
                                color: DashColors.muted,
                                fontWeight: FontWeight.w400,
                                fontSize: 12.5,
                              ),
                            ),
                        ],
                      ),
                      style: TextStyle(
                        color: fg,
                        fontSize: 14.5,
                        fontWeight: selected ? FontWeight.w600 : FontWeight.w400,
                      ),
                    ),
                  ),
                  if (selected) const AppIcon(AppIcons.check, size: 18, color: DashColors.primaryDeep),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Thông báo ngắn nổi trên thanh dưới.
void showDashToast(BuildContext context, String text) {
  final m = ScaffoldMessenger.of(context);
  m.hideCurrentSnackBar();
  m.showSnackBar(
    SnackBar(
      content: Text(text, style: const TextStyle(fontFamily: appFont, fontSize: 13)),
      behavior: SnackBarBehavior.floating,
      backgroundColor: DashColors.ink,
      duration: const Duration(milliseconds: 2400),
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 14),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
    ),
  );
}

/// Màn của tab chưa thiết kế: khung xám nhấp nháy đúng dáng một danh sách đang tải.
class SkeletonTab extends StatefulWidget {
  const SkeletonTab({super.key, required this.name});

  final String name;

  @override
  State<SkeletonTab> createState() => _SkeletonTabState();
}

class _SkeletonTabState extends State<SkeletonTab> with SingleTickerProviderStateMixin {
  late final _shimmer = AnimationController(vsync: this, duration: const Duration(milliseconds: 1300));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (!MediaQuery.disableAnimationsOf(context) && !_shimmer.isAnimating) _shimmer.repeat();
  }

  @override
  void dispose() {
    _shimmer.dispose();
    super.dispose();
  }

  Widget _sk(double w, double h, {double r = 8, Color? base}) => AnimatedBuilder(
    animation: _shimmer,
    builder: (_, _) {
      final t = _shimmer.value * 4 - 2;
      return Container(
        width: w,
        height: h,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(r),
          gradient: LinearGradient(
            begin: Alignment(t - 1, 0),
            end: Alignment(t + 1, 0),
            colors: [base ?? const Color(0xFFE9EDF3), const Color(0xFFF4F6F9), base ?? const Color(0xFFE9EDF3)],
          ),
        ),
      );
    },
  );

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return ListView(
      padding: EdgeInsets.fromLTRB(12, top + 66, 12, 20),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 4, 4, 14),
          child: Text.rich(
            TextSpan(
              text: 'Màn ',
              children: [
                TextSpan(
                  text: widget.name,
                  style: const TextStyle(color: DashColors.ink, fontWeight: FontWeight.w700),
                ),
                const TextSpan(text: ' chưa thiết kế — đây là trạng thái đang tải của khung app.'),
              ],
            ),
            style: const TextStyle(color: DashColors.muted, fontSize: 12.5),
          ),
        ),
        _sk(double.infinity, 40, r: 12, base: Colors.white),
        const SizedBox(height: 12),
        for (var i = 0; i < 6; i++) ...[
          if (i > 0) const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16)),
            child: Row(
              children: [
                _sk(44, 44, r: 12),
                const SizedBox(width: 12),
                Expanded(
                  child: LayoutBuilder(
                    builder: (_, c) => Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [_sk(c.maxWidth * .7, 12), const SizedBox(height: 8), _sk(c.maxWidth * .45, 10)],
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                _sk(54, 14),
              ],
            ),
          ),
        ],
      ],
    );
  }
}
