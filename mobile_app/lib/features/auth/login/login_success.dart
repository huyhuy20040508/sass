import 'package:flutter/material.dart';

import '../../../app/theme.dart';
import '../../../core/ui/app_icon.dart';
import '../../../core/ui/brand_splash.dart';
import 'login_hero.dart';

/// Màn chuyển tiếp "Đăng nhập thành công" phủ lên màn đăng nhập trước khi vào app.
class LoginSuccess extends StatelessWidget {
  const LoginSuccess({super.key});

  @override
  Widget build(BuildContext context) => const BrandSplash(
    gradient: heroGradient,
    icon: AppIcons.check,
    iconColor: AppColors.brandInk,
    title: 'Đăng nhập thành công',
    subtitle: 'Đang vào Quản trị…',
  );
}
