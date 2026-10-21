import 'package:flutter/services.dart';

import '../format/vn_number.dart';

/// Ô nhập TIỀN: gõ tới đâu chấm ngăn nghìn tới đó (24.200.000), còn mã đọc giá
/// trị thật bằng [undot] rồi mới gửi API.
///
/// Mọi số tiền khác trong app đều có dấu chấm, nên ô nhập để số trần (24200000)
/// vừa khó đếm chữ số vừa trông như của một phần mềm khác.
class MoneyInputFormatter extends TextInputFormatter {
  const MoneyInputFormatter();

  @override
  TextEditingValue formatEditUpdate(TextEditingValue cu, TextEditingValue moi) {
    final so = undot(moi.text);
    if (so == null) {
      // Xoá hết thì để ô trống, đừng ép thành "0" — người ta đang gõ lại.
      return moi.text.isEmpty ? moi : cu;
    }
    final chu = dot(so);

    // Giữ con trỏ theo SỐ CHỮ SỐ đứng trước nó, không theo vị trí ký tự: thêm
    // một dấu chấm là mọi vị trí phía sau dịch đi một ô.
    final truoc = moi.text.substring(0, moi.selection.end).replaceAll(RegExp(r'[^0-9]'), '').length;
    var i = 0, dem = 0;
    while (i < chu.length && dem < truoc) {
      if (chu.codeUnitAt(i) >= 0x30 && chu.codeUnitAt(i) <= 0x39) dem++;
      i++;
    }

    return TextEditingValue(text: chu, selection: TextSelection.collapsed(offset: i));
  }
}
