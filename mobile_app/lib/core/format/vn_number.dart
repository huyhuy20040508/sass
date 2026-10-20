/// Định dạng số kiểu Việt Nam (chấm ngăn nghìn, phẩy thập phân) — khớp
/// toLocaleString('vi-VN') của bản HTML và number_format của web_Shop.
library;

/// 1234567 → "1.234.567".
String dot(num n) {
  final r = n.round();
  final s = r.abs().toString();
  final b = StringBuffer();
  for (var i = 0; i < s.length; i++) {
    if (i > 0 && (s.length - i) % 3 == 0) b.write('.');
    b.write(s[i]);
  }
  return r < 0 ? '-$b' : b.toString();
}

/// Số thập phân tối đa [digits] chữ số, bỏ số 0 thừa: 12.50 → "12,5".
String decimal(num n, int digits) {
  final f = n.toStringAsFixed(digits);
  final parts = f.split('.');
  var frac = parts.length > 1 ? parts[1].replaceFirst(RegExp(r'0+$'), '') : '';
  final whole = dot(int.parse(parts[0]));
  // -0,4 → toStringAsFixed cho "-0.4" mà int.parse("-0") mất dấu.
  final neg = f.startsWith('-') && !whole.startsWith('-') ? '-' : '';
  return frac.isEmpty ? '$neg$whole' : '$neg$whole,$frac';
}

/// Phần trăm: pct(31.8) → "31,8%".
String pct(num n, [int digits = 1]) => '${decimal(n, digits)}%';

/// "2026-10-17" → "17/10".
String dm(String ymd) => '${ymd.substring(8, 10)}/${ymd.substring(5, 7)}';
