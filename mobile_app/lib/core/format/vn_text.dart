/// Chữ tiếng Việt: bỏ dấu để tìm và xếp tên không phân biệt dấu / hoa thường.
library;

const _from = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ';
const _to = 'aaaaaaaaaaaaaaaaaeeeeeeeeeeeiiiiiooooooooooooooooouuuuuuuuuuuyyyyyd';

final _map = {for (var i = 0; i < _from.length; i++) _from[i]: _to[i]};

/// "Ốp Lưng Đẹp" → "op lung dep".
String foldVn(String s) {
  final b = StringBuffer();
  for (final ch in s.toLowerCase().split('')) {
    b.write(_map[ch] ?? ch);
  }
  return b.toString();
}

/// So tên theo bảng chữ cái tiếng Việt gần đúng: bỏ dấu trước, trùng thì so nguyên chữ.
int compareVn(String a, String b) {
  final c = foldVn(a).compareTo(foldVn(b));
  return c != 0 ? c : a.compareTo(b);
}
