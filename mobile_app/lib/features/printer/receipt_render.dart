import 'dart:math' as math;
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/painting.dart';

import 'escpos.dart';
import 'receipt_doc.dart';

/// Vẽ phiếu thành ảnh đúng bề ngang đầu in (576 chấm cuộn 80mm, 384 chấm cuộn
/// 58mm, 203 dpi). Cùng một ảnh vừa để xem trước trên màn hình vừa để in — tờ
/// in ra giống hệt cái thu ngân vừa nhìn.
class SlipPainter {
  SlipPainter(this.dots, {this.images = const {}}) : base = dots <= 384 ? 21 : 25, side = dots <= 384 ? 8 : 16;

  final int dots;
  final Map<String, ui.Image> images;

  /// Cỡ chữ gốc (chấm) — ứng với 10,5px / 12,5px của phiếu web ở 96 dpi.
  final double base;

  /// Lề hai bên: 1mm / 2mm như phiếu web.
  final double side;

  double get _w => dots - 2 * side;

  TextPainter _text(String s, {double size = 1, bool bold = false, TextAlign align = TextAlign.left, double? max}) =>
      TextPainter(
        text: TextSpan(
          text: s,
          // Font của app, có sẵn trong gói cài: phiếu ra giống nhau trên mọi điện thoại, đủ dấu tiếng Việt.
          style: TextStyle(
            fontFamily: 'BeVietnamPro',
            color: const Color(0xFF000000),
            fontSize: base * size,
            height: 1.3,
            fontWeight: bold ? FontWeight.w700 : FontWeight.w400,
            fontFeatures: const [ui.FontFeature.tabularFigures()],
          ),
        ),
        textAlign: align,
        textDirection: TextDirection.ltr,
      )..layout(maxWidth: max ?? _w);

  /// Mỗi dòng trả về chiều cao + hàm vẽ ở toạ độ y.
  (double, void Function(Canvas, double)) _line(RLine l) {
    switch (l) {
      case RText(:final text, :final align, :final size, :final bold):
        final tp = _text(
          text,
          size: size,
          bold: bold,
          align: align == RAlign.center ? TextAlign.center : TextAlign.left,
        );
        return (tp.height, (c, y) => tp.paint(c, Offset(side + (align == RAlign.center ? (_w - tp.width) / 2 : 0), y)));
      case RPair(:final left, :final right, :final size, :final bold, :final indent):
        final r = _text(right, size: size, bold: bold, max: _w);
        final l2 = _text(left, size: size, bold: bold, max: math.max(40, _w - indent - r.width - 10));
        return (
          math.max(l2.height, r.height),
          (c, y) {
            l2.paint(c, Offset(side + indent, y));
            r.paint(c, Offset(dots - side - r.width, y));
          },
        );
      case RRule(:final thick):
        return (
          16,
          (c, y) {
            final p = Paint()..color = const Color(0xFF000000);
            if (thick) {
              c.drawRect(Rect.fromLTWH(side, y + 6, _w, 4), p);
            } else {
              for (var x = side; x < dots - side; x += 10) {
                c.drawRect(Rect.fromLTWH(x, y + 7, math.min(6, dots - side - x), 2), p);
              }
            }
          },
        );
      case RBox(:final text):
        final tp = _text(text, bold: true, align: TextAlign.center, max: _w - 16);
        final h = tp.height + 16;
        return (
          h + 8,
          (c, y) {
            final p = Paint()..color = const Color(0xFF000000);
            final r = Rect.fromLTWH(side, y + 4, _w, h);
            for (var x = r.left; x < r.right; x += 10) {
              c.drawRect(Rect.fromLTWH(x, r.top, math.min(6, r.right - x), 2), p);
              c.drawRect(Rect.fromLTWH(x, r.bottom - 2, math.min(6, r.right - x), 2), p);
            }
            for (var yy = r.top; yy < r.bottom; yy += 10) {
              c.drawRect(Rect.fromLTWH(r.left, yy, 2, math.min(6, r.bottom - yy)), p);
              c.drawRect(Rect.fromLTWH(r.right - 2, yy, 2, math.min(6, r.bottom - yy)), p);
            }
            tp.paint(c, Offset(side + (_w - tp.width) / 2, y + 12));
          },
        );
      case RImage(:final key, :final width, :final caption):
        final img = images[key];
        if (img == null) return (0, (_, _) {});
        final w = _w * width;
        final h = w * img.height / img.width;
        final cap = caption.isEmpty ? null : _text(caption, size: .86, align: TextAlign.center);
        return (
          h + 6 + (cap?.height ?? 0),
          (c, y) {
            final dst = Rect.fromLTWH((dots - w) / 2, y + 2, w, h);
            c.drawImageRect(
              img,
              Rect.fromLTWH(0, 0, img.width.toDouble(), img.height.toDouble()),
              dst,
              Paint()..filterQuality = FilterQuality.high,
            );
            cap?.paint(c, Offset(side + (_w - cap.width) / 2, y + h + 4));
          },
        );
      case RBarcode(:final code):
        final mods = code128Modules(code);
        if (mods.isEmpty) return (0, (_, _) {});
        // Vạch rộng TRÒN số chấm: vạch lẻ chấm in nhiệt ra lúc dày lúc mỏng, máy quét đọc trượt.
        final unit = math.max(1, (_w * .85 / mods.length).floor());
        final bw = mods.length * unit;
        final cap = _text(code, size: .86, align: TextAlign.center);
        const bh = 72.0;
        return (
          bh + 8 + cap.height,
          (c, y) {
            final p = Paint()..color = const Color(0xFF000000);
            final x0 = ((dots - bw) / 2).floorToDouble();
            for (var i = 0; i < mods.length; i++) {
              if (mods[i]) c.drawRect(Rect.fromLTWH(x0 + i * unit, y + 6, unit.toDouble(), bh), p);
            }
            cap.paint(c, Offset(side + (_w - cap.width) / 2, y + 8 + bh));
          },
        );
      case RGap(:final height):
        return (height, (_, _) {});
    }
  }

  /// Ảnh màu (để xem trước); [MonoImage] cho máy in lấy bằng [toMono].
  Future<ui.Image> paint(List<RLine> lines) async {
    const top = 20.0, bottom = 24.0;
    final ve = [for (final l in lines) _line(l)];
    final h = (top + ve.fold<double>(0, (a, b) => a + b.$1) + bottom).ceil();
    final rec = ui.PictureRecorder();
    final c = Canvas(rec)
      ..drawRect(Rect.fromLTWH(0, 0, dots.toDouble(), h.toDouble()), Paint()..color = const Color(0xFFFFFFFF));
    var y = top;
    for (final (cao, draw) in ve) {
      draw(c, y);
      y += cao;
    }
    return rec.endRecording().toImage(dots, h);
  }

  static Future<MonoImage> toMono(ui.Image img) async {
    final data = await img.toByteData(format: ui.ImageByteFormat.rawRgba);
    return MonoImage.fromRgba(data!.buffer.asUint8List(), img.width, img.height).trimBottom(keep: 24);
  }
}

/// Code 128 bộ B thành dãy module (true = vạch đen), kèm vùng trắng 10 module hai
/// bên — cùng bảng với App\Support\MaVach của web, để phiếu hai nơi quét như nhau.
List<bool> code128Modules(String text) {
  const mau = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', //
    '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
    '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
    '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
    '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
    '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
  ];
  const startB = 104;
  final ma = <int>[startB];
  var tong = startB, viTri = 1;
  for (final o in text.codeUnits) {
    if (o < 32 || o > 126) continue;
    ma.add(o - 32);
    tong += (o - 32) * viTri++;
  }
  if (ma.length == 1) return const [];
  ma
    ..add(tong % 103)
    ..add(106);
  final out = List<bool>.filled(10, false, growable: true);
  for (final m in ma) {
    for (final (i, ch) in mau[m].split('').indexed) {
      out.addAll(List.filled(int.parse(ch), i.isEven));
    }
  }
  return out..addAll(List.filled(10, false));
}

/// Tiện cho test: ảnh xám bất kỳ → byte RGBA.
Future<Uint8List> rgbaOf(ui.Image img) async =>
    (await img.toByteData(format: ui.ImageByteFormat.rawRgba))!.buffer.asUint8List();
