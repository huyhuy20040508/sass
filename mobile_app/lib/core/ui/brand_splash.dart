import 'package:flutter/material.dart';

import '../../app/theme.dart';
import 'app_icon.dart';

/// Màn chuyển tiếp phủ kín: ô vàng có icon bật lên, hai dòng chữ, thanh vàng chạy.
/// Dùng cho "Đăng nhập thành công" và "Đang vào Thu ngân/Quản trị".
class BrandSplash extends StatefulWidget {
  const BrandSplash({
    super.key,
    required this.gradient,
    required this.icon,
    required this.iconColor,
    required this.title,
    required this.subtitle,
    this.boxSize = 76,
    this.boxRadius = 24,
    this.iconSize = 38,
    this.iconStroke = 2.6,
    this.titleGap = 12,
  });

  final Gradient gradient;
  final AppIcons icon;
  final Color iconColor;
  final String title;
  final String subtitle;
  final double boxSize;
  final double boxRadius;
  final double iconSize;
  final double iconStroke;
  final double titleGap;

  @override
  State<BrandSplash> createState() => _BrandSplashState();
}

class _BrandSplashState extends State<BrandSplash> with TickerProviderStateMixin {
  late final _pop = AnimationController(vsync: this, duration: const Duration(milliseconds: 400))..forward();
  late final _bar = AnimationController(vsync: this, duration: const Duration(milliseconds: 1100));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.disableAnimationsOf(context)) {
      _pop.value = 1;
    } else if (!_bar.isAnimating) {
      _bar.repeat();
    }
  }

  @override
  void dispose() {
    _pop.dispose();
    _bar.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Semantics(
      liveRegion: true,
      label: '${widget.title}. ${widget.subtitle}',
      child: ExcludeSemantics(
        child: DecoratedBox(
          decoration: BoxDecoration(gradient: widget.gradient),
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  ScaleTransition(
                    scale: CurvedAnimation(parent: _pop, curve: const Cubic(.3, 1.6, .5, 1)),
                    child: FadeTransition(
                      opacity: _pop,
                      child: Container(
                        width: widget.boxSize,
                        height: widget.boxSize,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: AppColors.accent,
                          borderRadius: BorderRadius.circular(widget.boxRadius),
                        ),
                        child: AppIcon(
                          widget.icon,
                          size: widget.iconSize,
                          color: widget.iconColor,
                          strokeWidth: widget.iconStroke,
                        ),
                      ),
                    ),
                  ),
                  SizedBox(height: widget.titleGap),
                  Text(
                    widget.title,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    widget.subtitle,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Color(0xBFFFFFFF), fontSize: 15),
                  ),
                  const SizedBox(height: 20),
                  _LoadingBar(animation: _bar),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _LoadingBar extends StatelessWidget {
  const _LoadingBar({required this.animation});

  final Animation<double> animation;

  @override
  Widget build(BuildContext context) => ClipRRect(
    borderRadius: BorderRadius.circular(2),
    child: SizedBox(
      width: 140,
      height: 4,
      child: ColoredBox(
        color: const Color(0x26FFFFFF),
        child: AnimatedBuilder(
          animation: animation,
          builder: (context, _) {
            // Thanh vàng dài 40% (56px) chạy từ -100% tới 260% bề rộng của chính nó.
            final left = 56 * (-1 + 3.6 * Curves.easeInOut.transform(animation.value));
            return Stack(
              children: [
                Positioned(
                  left: left,
                  top: 0,
                  bottom: 0,
                  width: 56,
                  child: const DecoratedBox(
                    decoration: BoxDecoration(
                      color: AppColors.accent,
                      borderRadius: BorderRadius.all(Radius.circular(2)),
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
