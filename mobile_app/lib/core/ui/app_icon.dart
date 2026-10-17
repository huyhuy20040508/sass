import 'package:flutter/widgets.dart';
import 'package:flutter_svg/flutter_svg.dart';

/// Bộ icon nét của bản thiết kế (design/html/dang-nhap-mobile.html), chép
/// nguyên đường vẽ để app trông đúng như bản HTML đã duyệt.
enum AppIcons {
  store(
    '<path d="M3.5 9 5 4h14l1.5 5"/><path d="M3 9h18v.5a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z"/>'
    '<path d="M5 12.5V20h14v-7.5"/><path d="M10 20v-4.5h4V20"/>',
  ),
  user('<circle cx="12" cy="8" r="4"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>'),
  lock(
    '<rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/><path d="M12 14.5v2"/>',
  ),
  eye('<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>'),
  eyeOff(
    '<path d="M10.6 5.6A9.6 9.6 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a16 16 0 0 1-2.4 3.2M6.6 6.6C3.9 8.3 2.5 12 2.5 12S6 18.5 12 18.5c1.9 0 3.5-.6 4.9-1.5"/>'
    '<path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="m3 3 18 18"/>',
  ),
  arrow('<path d="M5 12h14M13 6l6 6-6 6"/>'),
  alert('<circle cx="12" cy="12" r="9.5"/><path d="M12 7.5v5M12 16.2v.1"/>'),
  timer('<circle cx="12" cy="13.5" r="7.5"/><path d="M12 10v3.5l2.2 2M9.5 2.5h5"/>'),
  wifiOff(
    '<path d="m3 3 18 18M8.8 16.4a4.6 4.6 0 0 1 6.4 0M5.2 12.8a9.6 9.6 0 0 1 4.4-2.3M18.8 12.8a9.6 9.6 0 0 0-2-1.4'
    'M2 9.2a15 15 0 0 1 3.8-2.6M22 9.2A14.6 14.6 0 0 0 11 5.5M12 20v.1"/>',
  ),
  caps('<path d="M12 4 4.5 11.5H8.5V15h7v-3.5h4Z"/><path d="M8.5 19.5h7"/>'),
  shield('<path d="M12 21.5s7.5-3.5 7.5-9.5V5.5L12 2.5l-7.5 3V12c0 6 7.5 9.5 7.5 9.5Z"/><path d="m9 12 2 2 4-4"/>'),
  mail('<rect x="2.5" y="4.5" width="19" height="15" rx="2.5"/><path d="m3 7 9 6 9-6"/>'),
  check('<path d="M20 6.5 9.5 17 4 11.5"/>'),
  receipt('<path d="M6 2.5h12v19l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5-2 1.5Z"/><path d="M9 8h6M9 12h6"/>');

  const AppIcons(this.paths);

  final String paths;
}

class AppIcon extends StatelessWidget {
  const AppIcon(this.icon, {super.key, this.size = 20, required this.color, this.strokeWidth = 1.8});

  final AppIcons icon;
  final double size;
  final Color color;
  final double strokeWidth;

  // Ô nhập dựng lại mỗi lần gõ phím; giữ sẵn chuỗi SVG để khỏi ghép lại mỗi lần.
  static final _cache = <(AppIcons, Color, double), String>{};

  @override
  Widget build(BuildContext context) {
    final svg = _cache.putIfAbsent(
      (icon, color, strokeWidth),
      () =>
          '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="${_hex(color)}" '
          'stroke-opacity="${color.a.toStringAsFixed(3)}" stroke-width="$strokeWidth" stroke-linecap="round" '
          'stroke-linejoin="round">${icon.paths}</svg>',
    );
    return SvgPicture.string(svg, width: size, height: size, excludeFromSemantics: true);
  }
}

/// Dấu xoá trong ô nhập: hình tròn đặc, dấu x trắng.
class ClearMark extends StatelessWidget {
  const ClearMark({super.key, required this.color});

  final Color color;

  @override
  Widget build(BuildContext context) => SvgPicture.string(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="${_hex(color)}"/>'
    '<path d="m15 9-6 6M9 9l6 6" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>',
    width: 18,
    height: 18,
    excludeFromSemantics: true,
  );
}

String _hex(Color c) => '#${(c.toARGB32() & 0xFFFFFF).toRadixString(16).padLeft(6, '0')}';
