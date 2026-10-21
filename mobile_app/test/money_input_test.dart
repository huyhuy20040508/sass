import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/core/format/vn_number.dart';
import 'package:selliotech/core/ui/money_input.dart';

/// Ô nhập tiền — lỗi 87.
///
/// Hộp "Thu tiền" mở ra hiện 24200000 trong khi mọi số tiền khác trong app đều
/// là 24.200.000. Gõ tới đâu chấm tới đó, còn giá trị gửi API vẫn là số thô.
void main() {
  group('undot', () {
    test('bỏ chấm ra số thô', () {
      expect(undot('24.200.000'), 24200000);
      expect(undot('24200000'), 24200000);
    });

    test('chuỗi không có chữ số nào thì null — ô trống không thành 0', () {
      expect(undot(''), isNull);
      expect(undot('...'), isNull);
      expect(undot('abc'), isNull);
    });
  });

  group('ô nhập', () {
    late TextEditingController ctl;

    Future<void> bay(WidgetTester t, {String dau = ''}) async {
      ctl = TextEditingController(text: dau);
      addTearDown(ctl.dispose);
      await t.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: TextField(controller: ctl, inputFormatters: const [MoneyInputFormatter()]),
          ),
        ),
      );
    }

    testWidgets('gõ tới đâu chấm tới đó', (t) async {
      await bay(t);
      await t.enterText(find.byType(TextField), '24200000');
      expect(ctl.text, '24.200.000');
      expect(undot(ctl.text), 24200000);
    });

    testWidgets('dán chuỗi đã có chấm không bị nhân đôi dấu', (t) async {
      await bay(t);
      await t.enterText(find.byType(TextField), '1.234.567');
      expect(ctl.text, '1.234.567');
    });

    testWidgets('chữ cái bị bỏ, không làm hỏng số đang gõ', (t) async {
      await bay(t, dau: '500.000');
      await t.enterText(find.byType(TextField), '500.000abc');
      expect(ctl.text, '500.000');
    });

    testWidgets('xoá hết thì ô trống, không tự thành 0', (t) async {
      await bay(t, dau: '24.200.000');
      await t.enterText(find.byType(TextField), '');
      expect(ctl.text, '');
    });

    /// Con trỏ đi theo số chữ số đứng trước nó: thêm một dấu chấm là mọi vị trí
    /// phía sau dịch một ô, để nguyên thì gõ giữa chuỗi sẽ nhảy lung tung.
    testWidgets('con trỏ ở đúng chỗ sau khi thêm dấu chấm', (t) async {
      await bay(t);
      await t.enterText(find.byType(TextField), '1234');
      expect(ctl.text, '1.234');
      expect(ctl.selection.end, 5);
    });
  });
}
