import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

import '../../app/theme.dart';
import '../../core/ui/app_icon.dart';
import 'workspace.dart';

/// Đầu navy: logo, giờ + ca, nút đăng xuất, người + tên tiệm — chỗ kịp nhận ra mình vào nhầm tiệm.
class WorkspaceHeader extends StatelessWidget {
  const WorkspaceHeader({
    super.key,
    required this.greeting,
    required this.initials,
    required this.shopName,
    required this.clock,
    required this.onLogout,
    required this.overlap,
  });

  final String greeting;
  final String initials;
  final String shopName;
  final String clock;
  final VoidCallback onLogout;

  /// Phần thẻ chi nhánh đè lên đáy đầu navy (margin-top âm của bản HTML). 0 = không có thẻ.
  final double overlap;

  @override
  Widget build(BuildContext context) {
    final safeTop = MediaQuery.paddingOf(context).top;
    const radius = BorderRadius.vertical(bottom: Radius.circular(28));
    return Stack(
      clipBehavior: Clip.none,
      children: [
        // Nền kéo dài xuống dưới thẻ chi nhánh [overlap] px — thẻ nằm đè lên mép bo.
        Positioned(
          left: 0,
          right: 0,
          top: 0,
          bottom: -overlap,
          child: const RepaintBoundary(
            child: ClipRRect(
              borderRadius: radius,
              child: DecoratedBox(
                decoration: BoxDecoration(gradient: NavyColors.header),
                child: Stack(fit: StackFit.expand, children: [_Glow(), _DotGrid()]),
              ),
            ),
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(20, safeTop + 6, 20, overlap > 0 ? 58 - overlap : 30),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                height: 44,
                child: Row(
                  children: [
                    SvgPicture.asset('assets/images/logo-default-wide-light.svg', height: 21, semanticsLabel: 'Sellio'),
                    const Spacer(),
                    _ClockChip(text: clock),
                    const SizedBox(width: 8),
                    _HeaderButton(icon: AppIcons.logout, tooltip: 'Đăng xuất', onTap: onLogout),
                  ],
                ),
              ),
              const SizedBox(height: 18),
              Row(
                children: [
                  Container(
                    width: 46,
                    height: 46,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(color: AppColors.accent, borderRadius: BorderRadius.circular(14)),
                    child: Text(
                      initials,
                      style: const TextStyle(color: NavyColors.hd1, fontSize: 16, fontWeight: FontWeight.w700),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(greeting, style: const TextStyle(color: Color(0xB3FFFFFF), fontSize: 13)),
                        // Tên tiệm KHÔNG cắt "…" — tên dài thì xuống dòng.
                        Text(
                          shopName,
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 19,
                            fontWeight: FontWeight.w700,
                            height: 1.25,
                            letterSpacing: -.3,
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
      ],
    );
  }
}

class _Glow extends StatelessWidget {
  const _Glow();

  @override
  Widget build(BuildContext context) => const DecoratedBox(
    decoration: BoxDecoration(
      gradient: RadialGradient(
        center: Alignment(1, -1),
        radius: 1.1,
        colors: [NavyColors.glow, Color(0x001890FF)],
        stops: [0, .65],
      ),
    ),
  );
}

class _DotGrid extends StatelessWidget {
  const _DotGrid();

  @override
  Widget build(BuildContext context) => ShaderMask(
    blendMode: BlendMode.dstIn,
    shaderCallback: (r) => const LinearGradient(
      begin: Alignment.topCenter,
      end: Alignment.bottomCenter,
      colors: [Colors.black, Colors.transparent],
      stops: [0, .9],
    ).createShader(r),
    child: const CustomPaint(painter: _DotPainter()),
  );
}

class _DotPainter extends CustomPainter {
  const _DotPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()..color = const Color(0x1CFFFFFF);
    for (double y = 9; y < size.height; y += 18) {
      for (double x = 9; x < size.width; x += 18) {
        canvas.drawCircle(Offset(x, y), 1, p);
      }
    }
  }

  @override
  bool shouldRepaint(_DotPainter old) => false;
}

class _ClockChip extends StatelessWidget {
  const _ClockChip({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) => Container(
    height: 32,
    padding: const EdgeInsets.symmetric(horizontal: 11),
    decoration: BoxDecoration(color: const Color(0x1AFFFFFF), borderRadius: BorderRadius.circular(10)),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        const AppIcon(AppIcons.clock, size: 15, color: AppColors.accent),
        const SizedBox(width: 6),
        Text(
          text,
          style: const TextStyle(
            color: Color(0xE0FFFFFF),
            fontSize: 13,
            fontWeight: FontWeight.w600,
            fontFeatures: [FontFeature.tabularFigures()],
          ),
        ),
      ],
    ),
  );
}

class _HeaderButton extends StatelessWidget {
  const _HeaderButton({required this.icon, required this.tooltip, required this.onTap});

  final AppIcons icon;
  final String tooltip;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Tooltip(
    message: tooltip,
    child: Material(
      color: const Color(0x1AFFFFFF),
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: SizedBox(
          width: 40,
          height: 40,
          child: Center(child: AppIcon(icon, size: 19, color: Colors.white)),
        ),
      ),
    ),
  );
}

/// Thẻ chi nhánh nổi đè mép đầu navy, chạm để đổi. Tên KHÔNG cắt "…":
/// chọn nhầm kho là lỗi đắt, phải đọc được trọn tên.
class BranchCard extends StatelessWidget {
  const BranchCard({super.key, required this.branch, required this.onTap, required this.flash});

  final Branch branch;
  final VoidCallback onTap;

  /// 0→1→0 khi vừa đổi chi nhánh: viền nháy để thấy đã đổi.
  final Animation<double> flash;

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: flash,
      builder: (context, child) {
        final v = flash.value;
        return DecoratedBox(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(18),
            boxShadow: [
              if (v > 0) BoxShadow(color: NavyColors.pick.withValues(alpha: v), spreadRadius: 3),
              const BoxShadow(color: Color(0x471E0A3C), offset: Offset(0, 12), blurRadius: 28, spreadRadius: -10),
              const BoxShadow(color: Color(0x0F1E0A3C), offset: Offset(0, 1), blurRadius: 2),
            ],
          ),
          child: child,
        );
      },
      child: Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        child: InkWell(
          borderRadius: BorderRadius.circular(18),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
            child: Row(
              children: [
                Container(
                  width: 44,
                  height: 44,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: NavyColors.pickSoft, borderRadius: BorderRadius.circular(13)),
                  child: AppIcon(branch.isHq ? AppIcons.hq : AppIcons.store, size: 22, color: NavyColors.pick),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Text('Bạn đang làm ở', style: TextStyle(color: NavyColors.muted, fontSize: 12)),
                          if (branch.isHq) ...[const SizedBox(width: 4), const HqTag()],
                        ],
                      ),
                      Text(
                        branch.name,
                        style: const TextStyle(
                          color: NavyColors.ink,
                          fontSize: 16,
                          fontWeight: FontWeight.w700,
                          height: 1.3,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                Container(
                  height: 36,
                  padding: const EdgeInsets.fromLTRB(12, 0, 10, 0),
                  decoration: BoxDecoration(color: NavyColors.page, borderRadius: BorderRadius.circular(11)),
                  child: const Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        'Đổi',
                        semanticsLabel: 'Đổi chi nhánh',
                        style: TextStyle(color: NavyColors.pick, fontSize: 13.5, fontWeight: FontWeight.w600),
                      ),
                      SizedBox(width: 4),
                      AppIcon(AppIcons.swap, size: 16, color: NavyColors.pick),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class HqTag extends StatelessWidget {
  const HqTag({super.key});

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 6),
    decoration: BoxDecoration(color: NavyColors.pickSoft, borderRadius: BorderRadius.circular(5)),
    child: const Text(
      'Trụ sở',
      style: TextStyle(color: NavyColors.pick, fontSize: 11, fontWeight: FontWeight.w600),
    ),
  );
}

/// Thẻ khu vực màu đặc: tím-navy cho Quản trị, vàng cho Thu ngân — phân biệt từ xa.
class AreaCard extends StatefulWidget {
  const AreaCard({super.key, required this.area, required this.onTap, required this.index});

  final WorkspaceArea area;
  final VoidCallback onTap;

  /// Thứ tự để trễ hiệu ứng trồi lên (100ms + 80ms × index).
  final int index;

  @override
  State<AreaCard> createState() => _AreaCardState();
}

class _AreaCardState extends State<AreaCard> with SingleTickerProviderStateMixin {
  bool _down = false;
  late final _rise = AnimationController(vsync: this, duration: const Duration(milliseconds: 500));

  @override
  void initState() {
    super.initState();
    Future<void>.delayed(Duration(milliseconds: 100 + 80 * widget.index), () {
      if (mounted) _rise.forward();
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.disableAnimationsOf(context)) _rise.value = 1;
  }

  @override
  void dispose() {
    _rise.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final qt = widget.area == WorkspaceArea.quanTri;
    final fg = qt ? Colors.white : const Color(0xFF2A1C00);
    final descColor = qt ? const Color(0xBFFFFFFF) : const Color(0xB82A1C00);
    const r = BorderRadius.all(Radius.circular(24));
    final t = CurvedAnimation(parent: _rise, curve: const Cubic(.2, .8, .2, 1));

    return AnimatedBuilder(
      animation: t,
      builder: (context, child) => Opacity(
        opacity: t.value,
        child: Transform.translate(offset: Offset(0, 20 * (1 - t.value)), child: child),
      ),
      child: Semantics(
        button: true,
        label: '${widget.area.name}. ${widget.area.description}',
        excludeSemantics: true,
        child: GestureDetector(
          onTapDown: (_) => setState(() => _down = true),
          onTapUp: (_) => setState(() => _down = false),
          onTapCancel: () => setState(() => _down = false),
          onTap: widget.onTap,
          child: AnimatedScale(
            scale: _down ? .98 : 1,
            duration: const Duration(milliseconds: 150),
            curve: const Cubic(.2, .8, .2, 1),
            child: DecoratedBox(
              decoration: BoxDecoration(
                borderRadius: r,
                boxShadow: [
                  BoxShadow(
                    color: qt ? NavyColors.hd1 : const Color(0xB3C88C00),
                    offset: const Offset(0, 16),
                    blurRadius: 30,
                    spreadRadius: -14,
                  ),
                ],
              ),
              child: ClipRRect(
                borderRadius: r,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: qt ? const [NavyColors.hd1, NavyColors.hd3] : const [Color(0xFFFFD23F), AppColors.accent],
                    ),
                  ),
                  child: Stack(
                    children: [
                      // Ảnh chỉ nửa phải, mờ dần vào màu thẻ: ảnh Thu ngân gốc nhỏ, trải hết bề ngang sẽ vỡ.
                      Positioned.fill(
                        child: Align(
                          alignment: Alignment.centerRight,
                          child: FractionallySizedBox(
                            widthFactor: .6,
                            heightFactor: 1,
                            child: ShaderMask(
                              blendMode: BlendMode.dstIn,
                              shaderCallback: (b) => const LinearGradient(
                                colors: [Colors.transparent, Colors.black],
                                stops: [0, .55],
                              ).createShader(b),
                              child: Image.asset(widget.area.image, fit: BoxFit.cover, excludeFromSemantics: true),
                            ),
                          ),
                        ),
                      ),
                      Positioned(
                        top: 16,
                        left: 18,
                        child: Container(
                          width: 44,
                          height: 44,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: qt ? const Color(0x24FFFFFF) : const Color(0x1A2A1C00),
                            borderRadius: BorderRadius.circular(14),
                          ),
                          child: AppIcon(widget.area.icon, size: 22, color: fg),
                        ),
                      ),
                      Padding(
                        padding: const EdgeInsets.all(18),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.end,
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              widget.area.name,
                              style: TextStyle(
                                color: fg,
                                fontSize: 22,
                                fontWeight: FontWeight.w700,
                                letterSpacing: -.44,
                              ),
                            ),
                            const SizedBox(height: 2),
                            FractionallySizedBox(
                              widthFactor: .52,
                              alignment: Alignment.centerLeft,
                              child: Text(
                                widget.area.description,
                                style: TextStyle(color: descColor, fontSize: 13, height: 1.4),
                              ),
                            ),
                          ],
                        ),
                      ),
                      Positioned(
                        right: 16,
                        bottom: 16,
                        child: Container(
                          width: 48,
                          height: 48,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: qt ? AppColors.accent : NavyColors.hd1,
                            borderRadius: BorderRadius.circular(16),
                            boxShadow: const [
                              BoxShadow(
                                color: Color(0x66000000),
                                offset: Offset(0, 8),
                                blurRadius: 18,
                                spreadRadius: -6,
                              ),
                            ],
                          ),
                          child: AppIcon(
                            AppIcons.arrow,
                            size: 22,
                            strokeWidth: 2.4,
                            color: qt ? NavyColors.hd1 : AppColors.accent,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Ngăn kéo từ dưới lên: tiêu đề + dòng phụ + nội dung. Dùng cho chọn chi nhánh và hỏi đăng xuất.
Future<T?> showNavySheet<T>(
  BuildContext context, {
  required String title,
  required String subtitle,
  required Widget Function(BuildContext) body,
}) => showModalBottomSheet<T>(
  context: context,
  isScrollControlled: true,
  backgroundColor: Colors.white,
  barrierColor: const Color(0x80140628),
  shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(26))),
  builder: (ctx) {
    final mq = MediaQuery.of(ctx);
    return ConstrainedBox(
      constraints: BoxConstraints(maxHeight: mq.size.height * .82),
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
                decoration: BoxDecoration(color: const Color(0xFFDCDAE3), borderRadius: BorderRadius.circular(3)),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(4, 0, 4, 2),
              child: Semantics(
                header: true,
                child: Text(
                  title,
                  style: const TextStyle(color: NavyColors.ink, fontSize: 19, fontWeight: FontWeight.w700),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(4, 0, 4, 14),
              child: Text(subtitle, style: const TextStyle(color: NavyColors.muted, fontSize: 13.5)),
            ),
            Flexible(child: body(ctx)),
          ],
        ),
      ),
    );
  },
);

class BranchOption extends StatelessWidget {
  const BranchOption({super.key, required this.branch, required this.selected, required this.onTap});

  final Branch branch;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      inMutuallyExclusiveGroup: true,
      checked: selected,
      button: true,
      child: Material(
        color: selected ? NavyColors.pickSoft : Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: BorderSide(color: selected ? NavyColors.pick : NavyColors.line, width: selected ? 2 : 1),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: onTap,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 56),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(10, 8, 12, 8),
              child: Row(
                children: [
                  Container(
                    width: 38,
                    height: 38,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: selected ? NavyColors.pick : NavyColors.page,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: AppIcon(
                      branch.isHq ? AppIcons.hq : AppIcons.store,
                      size: 19,
                      color: selected ? Colors.white : NavyColors.muted,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          branch.name,
                          style: const TextStyle(color: NavyColors.ink, fontSize: 15, fontWeight: FontWeight.w600),
                        ),
                        if (branch.isHq) const Text('Trụ sở', style: TextStyle(color: NavyColors.muted, fontSize: 12)),
                      ],
                    ),
                  ),
                  const SizedBox(width: 12),
                  Container(
                    width: 24,
                    height: 24,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: selected ? NavyColors.pick : null,
                      border: Border.all(color: selected ? NavyColors.pick : const Color(0xFFCFCCD8), width: 1.5),
                    ),
                    child: selected
                        ? const AppIcon(AppIcons.check, size: 14, color: Colors.white, strokeWidth: 3)
                        : null,
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class SheetButton extends StatelessWidget {
  const SheetButton({super.key, required this.label, required this.onTap, this.icon, this.danger = false});

  final String label;
  final VoidCallback onTap;
  final AppIcons? icon;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final fg = danger ? const Color(0xFFB42318) : NavyColors.ink;
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Material(
        color: danger ? AppColors.dangerSoft : Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: danger ? BorderSide.none : const BorderSide(color: NavyColors.line, width: 1.5),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: SizedBox(
            height: 50,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (icon != null) ...[AppIcon(icon!, color: fg), const SizedBox(width: 8)],
                Text(
                  label,
                  style: TextStyle(color: fg, fontSize: 15, fontWeight: FontWeight.w600),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Chữ viết tắt cho ô đại diện: chữ đầu của từ đầu + từ cuối ("Nguyễn Văn An" → "NA").
String initialsOf(String name) {
  final w = name.trim().split(RegExp(r'\s+')).where((x) => x.isNotEmpty).toList();
  if (w.isEmpty) return '?';
  if (w.length == 1) return w.first.substring(0, math.min(2, w.first.length)).toUpperCase();
  return (w.first[0] + w.last[0]).toUpperCase();
}

/// "Chào buổi tối" theo giờ máy — cùng mốc với bản HTML.
String greetingFor(DateTime t) {
  final h = t.hour;
  final buoi = h < 11
      ? 'sáng'
      : h < 13
      ? 'trưa'
      : h < 18
      ? 'chiều'
      : 'tối';
  return 'Chào buổi $buoi';
}

/// "19:42 · Ca tối" — giờ + ca gợi ý theo giờ.
String clockFor(DateTime t) {
  final h = t.hour;
  final ca = h < 5
      ? 'Ca khuya'
      : h < 11
      ? 'Ca sáng'
      : h < 17
      ? 'Ca chiều'
      : h < 22
      ? 'Ca tối'
      : 'Ca khuya';
  String hai(int n) => n.toString().padLeft(2, '0');
  return '${hai(h)}:${hai(t.minute)} · $ca';
}
