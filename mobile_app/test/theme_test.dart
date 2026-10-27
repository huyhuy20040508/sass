import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/app/theme.dart';

/// Lỗi 94: khu làm việc đã navy + xanh mà màu mặc định của Material vẫn là tím
/// của màn đăng nhập, nên viền ô đang gõ, con trỏ, vệt bôi đen, chip… đều tím.
///
/// Bài này canh BỘ MÀU, không canh từng ô: 9 ô nhập nằm rải khắp khu Quản trị,
/// đặt màu tại chỗ từng ô là sót, mà sót một ô là lại thấy tím.
void main() {
  group('theme khu làm việc', () {
    final t = buildAdminTheme();

    test('không còn màu tím của màn đăng nhập', () {
      final tim = {AppColors.brand, AppColors.brand2, AppColors.brandInk};

      expect(tim, isNot(contains(t.colorScheme.primary)));
      expect(tim, isNot(contains(t.textSelectionTheme.cursorColor)));
      expect(tim, isNot(contains((t.inputDecorationTheme.focusedBorder as OutlineInputBorder).borderSide.color)));
      expect(tim, isNot(contains(t.chipTheme.selectedColor)));
    });

    test('viền ô đang gõ, con trỏ và chip đều theo màu nhấn của khu', () {
      expect(t.colorScheme.primary, DashColors.primary);
      expect(t.textSelectionTheme.cursorColor, DashColors.primary);
      expect(t.textSelectionTheme.selectionHandleColor, DashColors.primary);
      expect((t.inputDecorationTheme.focusedBorder as OutlineInputBorder).borderSide.color, DashColors.primary);
      expect(t.chipTheme.selectedColor, DashColors.primary);
      expect(t.appBarTheme.backgroundColor, DashColors.navy);
    });
  });

  /// Màn đăng nhập vẫn giữ bộ màu riêng — không đổi theo khu làm việc.
  test('theme màn đăng nhập giữ nguyên tím + vàng', () {
    final t = buildTheme();

    expect(t.colorScheme.primary, AppColors.brand);
    expect(t.colorScheme.secondary, AppColors.accent);
    expect((t.inputDecorationTheme.focusedBorder as OutlineInputBorder).borderSide.color, AppColors.brand);
  });
}
