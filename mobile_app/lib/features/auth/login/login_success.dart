import 'package:flutter/material.dart';

import '../../../app/theme.dart';
import '../../../core/ui/app_icon.dart';
import 'login_hero.dart';

/// Màn chuyển tiếp "Đăng nhập thành công" phủ lên màn đăng nhập trước khi vào app.
class LoginSuccess extends StatefulWidget {
  const LoginSuccess({super.key});

  @override
  State<LoginSuccess> createState() => _LoginSuccessState();
}

class _LoginSuccessState extends State<LoginSuccess> with TickerProviderStateMixin {
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
      label: 'Đăng nhập thành công. Đang vào Quản trị',
      child: ExcludeSemantics(
        child: DecoratedBox(
          decoration: const BoxDecoration(gradient: heroGradient),
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
                        width: 76,
                        height: 76,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(color: AppColors.accent, borderRadius: BorderRadius.circular(24)),
                        child: const AppIcon(AppIcons.check, size: 38, color: AppColors.brandInk, strokeWidth: 2.6),
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  const Text(
                    'Đăng nhập thành công',
                    style: TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 12),
                  const Text('Đang vào Quản trị…', style: TextStyle(color: Color(0xBFFFFFFF), fontSize: 15)),
                  const SizedBox(height: 20),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(2),
                    child: SizedBox(
                      width: 140,
                      height: 4,
                      child: ColoredBox(
                        color: const Color(0x26FFFFFF),
                        child: AnimatedBuilder(
                          animation: _bar,
                          builder: (context, _) {
                            // Thanh vàng dài 40% (56px) chạy từ -100% tới 260% bề rộng của chính nó.
                            final left = 56 * (-1 + 3.6 * Curves.easeInOut.transform(_bar.value));
                            return Stack(
                              children: [
                                Positioned(
                                  left: left,
                                  top: 0,
                                  bottom: 0,
                                  width: 56,
                                  child: DecoratedBox(
                                    decoration: BoxDecoration(
                                      color: AppColors.accent,
                                      borderRadius: BorderRadius.circular(2),
                                    ),
                                  ),
                                ),
                              ],
                            );
                          },
                        ),
                      ),
                    ),
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
