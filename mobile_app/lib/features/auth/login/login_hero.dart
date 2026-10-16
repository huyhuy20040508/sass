import 'dart:math' as math;
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_svg/flutter_svg.dart';

import '../../../app/theme.dart';
import '../../../core/ui/app_icon.dart';

/// Nền tím của đầu màn đăng nhập (và màn "Đăng nhập thành công").
const heroGradient = LinearGradient(
  begin: Alignment(-0.26, -1),
  end: Alignment(0.26, 1),
  colors: [Color(0xFF1C0733), AppColors.brand, Color(0xFF4F2290)],
  stops: [0, .6, 1],
);

class LoginHero extends StatelessWidget {
  const LoginHero({
    super.key,
    required this.compact,
    required this.showDeco,
    required this.showSubtitle,
    required this.title,
    required this.subtitle,
  });

  /// Đang gõ: thu về một dải logo để bàn phím không che nút Đăng nhập.
  final bool compact;
  final bool showDeco;
  final bool showSubtitle;
  final String title;
  final String subtitle;

  /// Phần khung trắng đè lên đáy đầu tím (margin-top: -28px trong bản HTML).
  /// Chiều cao đầu tím tính TỪ ĐỈNH tới mép khung trắng, chưa gồm phần này.
  static const overlap = 28.0;

  @override
  Widget build(BuildContext context) {
    final safeTop = MediaQuery.paddingOf(context).top;
    return Stack(
      clipBehavior: Clip.none,
      children: [
        // Nền kéo dài xuống dưới khung trắng [overlap] px để hai góc bo của khung
        // trắng lộ nền tím — thay cho margin âm của bản HTML.
        const Positioned(
          left: 0,
          right: 0,
          top: 0,
          bottom: -overlap,
          child: DecoratedBox(
            decoration: BoxDecoration(gradient: heroGradient),
            child: Stack(fit: StackFit.expand, children: [_Glow(), _DotGrid()]),
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(24, safeTop + 14, 24, (compact ? 40 : 52) - overlap),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const _Brand(),
              if (!compact) ...[
                if (showDeco) const Expanded(child: _NoIntrinsicHeight(child: _Deco())) else const SizedBox(height: 24),
                _Greet(title: title, subtitle: subtitle, showSubtitle: showSubtitle),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

/// Như `min-height: 0` của vùng minh hoạ trong bản HTML: không đòi chỗ khi màn
/// đo chiều cao, chỉ lấp phần còn dư — thiếu chỗ thì minh hoạ co lại chứ màn
/// không phải cuộn.
class _NoIntrinsicHeight extends SingleChildRenderObjectWidget {
  const _NoIntrinsicHeight({required super.child});

  @override
  RenderObject createRenderObject(BuildContext context) => _RenderNoIntrinsicHeight();
}

class _RenderNoIntrinsicHeight extends RenderProxyBox {
  @override
  double computeMinIntrinsicHeight(double width) => 0;

  @override
  double computeMaxIntrinsicHeight(double width) => 0;
}

class _Glow extends StatelessWidget {
  const _Glow();

  @override
  Widget build(BuildContext context) => const Stack(
    fit: StackFit.expand,
    children: [
      DecoratedBox(
        decoration: BoxDecoration(
          gradient: RadialGradient(
            center: Alignment(1, -1),
            radius: .85,
            colors: [Color(0x38FFC20F), Color(0x00FFC20F)],
            stops: [0, .7],
          ),
        ),
      ),
      DecoratedBox(
        decoration: BoxDecoration(
          gradient: RadialGradient(
            center: Alignment(-1, 1),
            radius: 1,
            colors: [Color(0x8C7A40D6), Color(0x007A40D6)],
            stops: [0, .7],
          ),
        ),
      ),
    ],
  );
}

/// Lưới chấm mờ dần xuống dưới: đỡ trống mà không tranh với chữ.
class _DotGrid extends StatelessWidget {
  const _DotGrid();

  @override
  Widget build(BuildContext context) => ShaderMask(
    blendMode: BlendMode.dstIn,
    shaderCallback: (r) => const LinearGradient(
      begin: Alignment.topCenter,
      end: Alignment.bottomCenter,
      colors: [Colors.black, Colors.transparent],
      stops: [0, .75],
    ).createShader(r),
    child: const CustomPaint(painter: _DotPainter()),
  );
}

class _DotPainter extends CustomPainter {
  const _DotPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()..color = const Color(0x21FFFFFF);
    for (double y = 9; y < size.height; y += 18) {
      for (double x = 9; x < size.width; x += 18) {
        canvas.drawCircle(Offset(x, y), 1, p);
      }
    }
  }

  @override
  bool shouldRepaint(_DotPainter old) => false;
}

class _Brand extends StatelessWidget {
  const _Brand();

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(11),
          boxShadow: const [
            BoxShadow(color: Color(0x33FFFFFF), spreadRadius: 1.5),
            BoxShadow(color: Color(0x4D000000), offset: Offset(0, 6), blurRadius: 16),
          ],
        ),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(11),
          child: SvgPicture.asset('assets/images/sellio-mark.svg', excludeFromSemantics: true),
        ),
      ),
      const SizedBox(width: 10),
      Text.rich(
        const TextSpan(
          text: 'Sell',
          children: [
            TextSpan(
              text: 'io',
              style: TextStyle(color: AppColors.accent),
            ),
          ],
        ),
        semanticsLabel: 'Sellio',
        style: const TextStyle(color: Colors.white, fontSize: 21, fontWeight: FontWeight.w700, letterSpacing: -.2),
      ),
    ],
  );
}

class _Greet extends StatelessWidget {
  const _Greet({required this.title, required this.subtitle, required this.showSubtitle});

  final String title;
  final String subtitle;
  final bool showSubtitle;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(
        title,
        style: TextStyle(
          color: Colors.white,
          fontSize: showSubtitle ? 27 : 23,
          fontWeight: FontWeight.w700,
          height: 1.18,
          letterSpacing: -.5,
        ),
      ),
      if (showSubtitle) ...[
        const SizedBox(height: 6),
        Text(subtitle, style: const TextStyle(color: Color(0xBDFFFFFF), fontSize: 14.5)),
      ],
    ],
  );
}

/// Minh hoạ thẻ doanh thu + thẻ đơn mới — cho biết đây là app bán hàng. Chỉ trang trí.
class _Deco extends StatefulWidget {
  const _Deco();

  @override
  State<_Deco> createState() => _DecoState();
}

class _DecoState extends State<_Deco> with SingleTickerProviderStateMixin {
  late final _float = AnimationController(vsync: this, duration: const Duration(seconds: 3));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.disableAnimationsOf(context)) {
      _float.stop();
    } else if (!_float.isAnimating) {
      _float.repeat(reverse: true);
    }
  }

  @override
  void dispose() {
    _float.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Không dùng LayoutBuilder: màn đăng nhập đo chiều cao bằng IntrinsicHeight,
    // mà LayoutBuilder không trả được kích thước nội tại.
    return ExcludeSemantics(
      child: Center(
        child: ConstrainedBox(
          // 336 = hai lần 168: mép phải thẻ đơn cách tâm 168px như bản HTML.
          constraints: const BoxConstraints(maxWidth: 336),
          child: AnimatedBuilder(
            animation: _float,
            builder: (context, _) {
              final t = Curves.easeInOut.transform(_float.value);
              return Stack(
                fit: StackFit.expand,
                clipBehavior: Clip.none,
                children: [
                  Align(
                    // Chỗ trống thấp hơn thẻ (có thông báo, chữ phóng to…) thì thu nhỏ thay vì tràn.
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      // Đệm cho phần thẻ nghiêng và trôi lên xuống, khỏi chạm logo / lời chào.
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 16),
                        child: Transform.translate(
                          offset: Offset(0, -6 * t),
                          child: Transform.rotate(
                            angle: -4 * math.pi / 180,
                            child: const SizedBox(width: 268, child: _RevenueCard()),
                          ),
                        ),
                      ),
                    ),
                  ),
                  // Đáy thẻ đơn nằm ở 94% chiều cao vùng minh hoạ (bottom: 6%).
                  FractionallySizedBox(
                    heightFactor: .94,
                    alignment: Alignment.topCenter,
                    child: Align(
                      alignment: Alignment.bottomRight,
                      child: Transform.translate(
                        offset: Offset(0, 5 * t),
                        child: Transform.rotate(angle: 3 * math.pi / 180, child: const _OrderCard()),
                      ),
                    ),
                  ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _RevenueCard extends StatelessWidget {
  const _RevenueCard();

  static const _spark =
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 46" preserveAspectRatio="none">'
      '<defs><linearGradient id="sg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffc20f" stop-opacity=".45"/>'
      '<stop offset="1" stop-color="#ffc20f" stop-opacity="0"/></linearGradient></defs>'
      '<path d="M0 38 C20 34 30 30 48 31 S80 22 100 25 S132 14 150 18 S186 8 204 10 S228 4 240 3 V46 H0Z" fill="url(#sg)"/>'
      '<path d="M0 38 C20 34 30 30 48 31 S80 22 100 25 S132 14 150 18 S186 8 204 10 S228 4 240 3" fill="none" '
      'stroke="#ffc20f" stroke-width="2.5" stroke-linecap="round"/></svg>';

  @override
  Widget build(BuildContext context) {
    const r = BorderRadius.all(Radius.circular(20));
    return CustomPaint(
      painter: const _OuterShadow(
        radius: 20,
        shadow: BoxShadow(color: Color(0x8C0A001E), offset: Offset(0, 20), blurRadius: 40, spreadRadius: -12),
      ),
      child: ClipRRect(
        borderRadius: r,
        child: BackdropFilter(
          filter: ImageFilter.blur(sigmaX: 10, sigmaY: 10),
          child: Container(
            padding: const EdgeInsets.fromLTRB(18, 16, 18, 12),
            decoration: BoxDecoration(
              borderRadius: r,
              border: Border.all(color: const Color(0x29FFFFFF)),
              gradient: const LinearGradient(
                begin: Alignment(-.35, -1),
                end: Alignment(.35, 1),
                colors: [Color(0x29FFFFFF), Color(0x0DFFFFFF)],
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Doanh thu hôm nay', style: TextStyle(color: Color(0xB3FFFFFF), fontSize: 12)),
                const SizedBox(height: 2),
                const Text(
                  '12.450.000 ₫',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 22,
                    fontWeight: FontWeight.w700,
                    letterSpacing: -.2,
                    fontFeatures: [FontFeature.tabularFigures()],
                  ),
                ),
                const SizedBox(height: 6),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(color: const Color(0x2EFFC20F), borderRadius: BorderRadius.circular(99)),
                  child: const Text(
                    '▲ 18% so với hôm qua',
                    style: TextStyle(color: AppColors.accent, fontSize: 11.5, fontWeight: FontWeight.w600),
                  ),
                ),
                const SizedBox(height: 8),
                SvgPicture.string(_spark, width: double.infinity, height: 46, fit: BoxFit.fill),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Bóng chỉ vẽ NGOÀI thẻ như box-shadow của CSS — BoxShadow của Flutter vẽ cả
/// dưới thẻ, mà thẻ kính trong suốt nên lộ thành một mảng tối.
class _OuterShadow extends CustomPainter {
  const _OuterShadow({required this.radius, required this.shadow});

  final double radius;
  final BoxShadow shadow;

  @override
  void paint(Canvas canvas, Size size) {
    final card = RRect.fromRectAndRadius(Offset.zero & size, Radius.circular(radius));
    final bong = card.shift(shadow.offset).inflate(shadow.spreadRadius);
    canvas
      ..save()
      ..clipPath(
        Path.combine(
          PathOperation.difference,
          Path()..addRect((Offset.zero & size).inflate(shadow.blurRadius * 2 + shadow.offset.distance)),
          Path()..addRRect(card),
        ),
      )
      ..drawRRect(bong, shadow.toPaint())
      ..restore();
  }

  @override
  bool shouldRepaint(_OuterShadow old) => old.shadow != shadow || old.radius != radius;
}

class _OrderCard extends StatelessWidget {
  const _OrderCard();

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.fromLTRB(9, 9, 14, 9),
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(14),
      boxShadow: const [BoxShadow(color: Color(0x800A001E), offset: Offset(0, 14), blurRadius: 30, spreadRadius: -8)],
    ),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 32,
          height: 32,
          alignment: Alignment.center,
          decoration: BoxDecoration(color: AppColors.accent, borderRadius: BorderRadius.circular(10)),
          child: const AppIcon(AppIcons.receipt, size: 18, color: AppColors.brandInk, strokeWidth: 2.2),
        ),
        const SizedBox(width: 10),
        const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text('Đơn mới · HD0248', style: TextStyle(color: AppColors.muted, fontSize: 11)),
            Text(
              '+ 350.000 ₫',
              style: TextStyle(
                color: AppColors.ink,
                fontSize: 13.5,
                fontWeight: FontWeight.w600,
                fontFeatures: [FontFeature.tabularFigures()],
              ),
            ),
          ],
        ),
      ],
    ),
  );
}
