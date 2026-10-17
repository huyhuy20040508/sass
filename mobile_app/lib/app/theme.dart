import 'package:flutter/material.dart';

/// Màu lấy từ design/html/dang-nhap-mobile.html (bản v2, tím và vàng của logo Sellio).
abstract final class AppColors {
  static const brand = Color(0xFF3A1266);
  static const brandInk = Color(0xFF24093F);
  static const brand2 = Color(0xFF5B2AA0);
  static const brandSoft = Color(0xFFF4EFFB);
  static const accent = Color(0xFFFFC20F);
  static const ink = Color(0xFF1D1B26);
  static const text = Color(0xFF3E3D4A);
  static const muted = Color(0xFF6B6A78);
  static const line = Color(0xFFE3E1EA);
  static const field = Color(0xFFF7F6FA);
  static const danger = Color(0xFFC62828);
  static const dangerSoft = Color(0xFFFDEEEE);
  static const warn = Color(0xFF7A5000);
  static const warnSoft = Color(0xFFFFF5D6);
  static const focus = Color(0x385B2AA0);
}

/// Bộ màu navy của màn chọn nơi làm việc (design/html/chon-noi-lam-viec-mobile.html#…-navy),
/// cùng tông với bản PC đang chạy.
abstract final class NavyColors {
  static const hd1 = Color(0xFF0B1B3A);
  static const hd2 = Color(0xFF14284F);
  static const hd3 = Color(0xFF1D3A6E);
  static const glow = Color(0x4D1890FF);
  static const pick = Color(0xFF1890FF);
  static const pickSoft = Color(0xFFE8F3FF);
  static const page = Color(0xFFF4F3F7);
  static const ink = Color(0xFF14121C);
  static const text = Color(0xFF474655);
  static const muted = Color(0xFF6B6A78);
  static const line = Color(0xFFEBE9F0);

  static const header = LinearGradient(
    begin: Alignment(-0.26, -1),
    end: Alignment(0.26, 1),
    colors: [hd1, hd2, hd3],
    stops: [0, .62, 1],
  );
}

const appFont = 'BeVietnamPro';

ThemeData buildTheme() {
  final scheme = ColorScheme.fromSeed(
    seedColor: AppColors.brand,
    primary: AppColors.brand,
    secondary: AppColors.accent,
    error: AppColors.danger,
  );
  const radius = BorderRadius.all(Radius.circular(12));
  return ThemeData(
    colorScheme: scheme,
    fontFamily: appFont,
    scaffoldBackgroundColor: Colors.white,
    textTheme: const TextTheme().apply(bodyColor: AppColors.text, displayColor: AppColors.ink),
    appBarTheme: const AppBarTheme(backgroundColor: AppColors.brand, foregroundColor: Colors.white),
    inputDecorationTheme: const InputDecorationTheme(
      filled: true,
      fillColor: AppColors.field,
      border: OutlineInputBorder(
        borderRadius: radius,
        borderSide: BorderSide(color: AppColors.line),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: radius,
        borderSide: BorderSide(color: AppColors.line),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: radius,
        borderSide: BorderSide(color: AppColors.brand, width: 2),
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        minimumSize: const Size.fromHeight(52),
        shape: const RoundedRectangleBorder(borderRadius: radius),
        textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w500),
      ),
    ),
  );
}
