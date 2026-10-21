import 'dart:typed_data';

/// Ảnh 1 bit cho máy in nhiệt: mỗi bit 1 = một chấm đen, hàng nối hàng,
/// [width] luôn chia hết cho 8 (một byte = 8 chấm ngang).
class MonoImage {
  const MonoImage(this.width, this.height, this.bits);

  final int width;
  final int height;
  final Uint8List bits;

  int get bytesPerRow => width ~/ 8;

  bool dot(int x, int y) => bits[y * bytesPerRow + (x >> 3)] & (0x80 >> (x & 7)) != 0;

  /// Từ ảnh RGBA (như ui.Image.toByteData rawRgba): chấm đen khi đủ tối. Điểm
  /// trong suốt coi là giấy trắng. Bề ngang lẻ được độn trắng cho tròn byte.
  factory MonoImage.fromRgba(Uint8List rgba, int width, int height, {int threshold = 160}) {
    final w = (width + 7) & ~7;
    final out = Uint8List(w ~/ 8 * height);
    for (var y = 0; y < height; y++) {
      for (var x = 0; x < width; x++) {
        final i = (y * width + x) * 4;
        final a = rgba[i + 3];
        if (a < 128) continue;
        final lum = (rgba[i] * 299 + rgba[i + 1] * 587 + rgba[i + 2] * 114) ~/ 1000;
        if (lum < threshold) out[y * (w ~/ 8) + (x >> 3)] |= 0x80 >> (x & 7);
      }
    }
    return MonoImage(w, height, out);
  }

  /// Bỏ các hàng trắng ở cuối: máy in nhiệt đẩy giấy theo đúng chiều cao ảnh.
  MonoImage trimBottom({int keep = 0}) {
    var last = height - 1;
    final r = bytesPerRow;
    while (last >= 0 && bits.sublist(last * r, last * r + r).every((b) => b == 0)) {
      last--;
    }
    final h = (last + 1 + keep).clamp(1, height);
    return MonoImage(width, h, Uint8List.sublistView(bits, 0, h * r));
  }
}

/// Lệnh ESC/POS tối thiểu mà mọi máy in hoá đơn (Xprinter, Gprinter, Epson…)
/// đều hiểu. In cả phiếu bằng ẢNH chứ không gửi chữ: bảng mã chữ của máy in
/// nhiệt không có tiếng Việt có dấu, gửi chữ là ra "Ho? ??n".
abstract final class EscPos {
  static const init = [0x1B, 0x40];

  /// Cắt giấy (một phần) sau khi đẩy thêm [feed] chấm — máy không có dao cắt thì bỏ qua lệnh.
  static List<int> cut({int feed = 80}) => [0x1D, 0x56, 0x42, feed.clamp(0, 255)];

  static List<int> feedLines(int n) => [0x1B, 0x64, n.clamp(0, 255)];

  /// Mở két tiền nối vào máy in (chân 2).
  static const drawer = [0x1B, 0x70, 0x00, 0x19, 0xFA];

  /// GS v 0, chia thành từng dải [band] hàng: nhiều máy in nhiệt rẻ có bộ đệm
  /// nhỏ, gửi một ảnh dài cả mét giấy trong một lệnh là in ra rác hoặc treo máy.
  static List<int> raster(MonoImage img, {int band = 128}) {
    final out = <int>[];
    final r = img.bytesPerRow;
    for (var y0 = 0; y0 < img.height; y0 += band) {
      final h = (img.height - y0).clamp(0, band);
      out
        ..addAll([0x1D, 0x76, 0x30, 0x00, r & 0xFF, r >> 8, h & 0xFF, h >> 8])
        ..addAll(Uint8List.sublistView(img.bits, y0 * r, (y0 + h) * r));
    }
    return out;
  }

  /// Một phiếu hoàn chỉnh: khởi động lại máy, in ảnh, đẩy giấy, cắt.
  static List<int> job(List<MonoImage> pages, {bool cutPaper = true, bool openDrawer = false}) => [
    ...init,
    for (final p in pages) ...[...raster(p), ...feedLines(3), if (cutPaper) ...cut()],
    if (openDrawer) ...drawer,
  ];
}
