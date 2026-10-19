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
  receipt('<path d="M6 2.5h12v19l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5-2 1.5Z"/><path d="M9 8h6M9 12h6"/>'),
  // Từ design/html/chon-noi-lam-viec-mobile.html
  logout('<path d="M14 4.5h3.5a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H14"/><path d="M10 16.5 5.5 12 10 7.5M5.5 12h10"/>'),
  swap('<path d="m7 15 5 5 5-5M7 9l5-5 5 5"/>'),
  clock('<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/>'),
  hq(
    '<path d="M4 21V5.5L12 3l8 2.5V21"/>'
    '<path d="M2.5 21h19M9 21v-4h6v4M8 8.5h1.5M14.5 8.5H16M8 12.5h1.5M14.5 12.5H16"/>',
  ),
  grid(
    '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/>'
    '<rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
  ),
  cash(
    '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 9.5v.1M18 14.4v.1"/>',
  ),
  // Từ design/html/tong-quan-mobile.html
  down('<path d="m6 9 6 6 6-6"/>'),
  right('<path d="m9 6 6 6-6 6"/>'),
  bell('<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15Z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>'),
  home('<path d="M3.5 10.5 12 3.5l8.5 7V20a1 1 0 0 1-1 1H15v-6H9v6H4.5a1 1 0 0 1-1-1Z"/>'),
  cart(
    '<path d="M3 4h2.5l2.2 10.5h10.6L20.5 7H6.4"/><circle cx="9.5" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/>',
  ),
  box('<path d="m12 3 8.5 4.5v9L12 21l-8.5-4.5v-9Z"/><path d="m3.5 7.5 8.5 4.5 8.5-4.5M12 12v9"/>'),
  menu('<path d="M4 7h16M4 12h16M4 17h16"/>'),
  target('<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".8"/>'),
  doc('<path d="M6 2.5h8.5L19 7v14.5H6Z"/><path d="M14 2.5V7h5M9 12h6M9 16h6"/>'),
  wallet(
    '<path d="M3.5 7.5h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1h-15a1 1 0 0 1-1-1Z"/><path d="M3.5 7.5 15.5 4v3.5M16 13.5h2"/>',
  ),
  chart('<path d="M4 20V4M4 20h16M8 16v-4M12 16V8M16 16v-6"/>'),
  users(
    '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>',
  ),
  returns('<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>'),
  tag('<path d="M3.5 12.5V4.5a1 1 0 0 1 1-1h8l8 8-9 9Z"/><circle cx="8.5" cy="8.5" r="1.5"/>'),
  ticket(
    '<path d="M3.5 7.5a1 1 0 0 1 1-1h15a1 1 0 0 1 1 1V10a2 2 0 0 0 0 4v2.5a1 1 0 0 1-1 1h-15a1 1 0 0 1-1-1V14a2 2 0 0 0 0-4Z"/>'
    '<path d="M14.5 6.5v11"/>',
  ),
  truck(
    '<path d="M2.5 6.5h11v10h-11ZM13.5 10h4l3 3.2v3.3h-7"/><circle cx="6.5" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
  ),
  clip('<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2.5h6V4M8.5 10h7M8.5 14h7M8.5 18h4"/>'),
  factory('<path d="M3 21V10l5 3V10l5 3V7l8 4v10Z"/><path d="M7 17h2M12 17h2M17 17h2"/>'),
  gear(
    '<circle cx="12" cy="12" r="3"/>'
    '<path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>',
  ),
  swapH('<path d="M7 4 3.5 7.5 7 11M3.5 7.5h13M17 20l3.5-3.5L17 13M20.5 16.5h-13"/>'),
  refresh('<path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/>'),
  news('<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M7.5 9h9M7.5 12.5h9M7.5 16h5"/>'),
  // Từ design/html/don-hang-mobile.html
  search('<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>'),
  scan(
    '<path d="M3.5 8V5a1.5 1.5 0 0 1 1.5-1.5h3M16 3.5h3A1.5 1.5 0 0 1 20.5 5v3M20.5 16v3a1.5 1.5 0 0 1-1.5 1.5h-3'
    'M8 20.5H5A1.5 1.5 0 0 1 3.5 19v-3M7 8v8M10 8v8M13.5 8v8M17 8v8"/>',
  ),
  close('<path d="m6 6 12 12M18 6 6 18"/>'),
  filter('<path d="M4 6h16M7 12h10M10 18h4"/>'),
  sort('<path d="M7 4v16M3.5 16.5 7 20l3.5-3.5M17 20V4M13.5 7.5 17 4l3.5 3.5"/>'),
  plus('<path d="M12 5v14M5 12h14"/>'),
  globe('<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>'),
  back('<path d="m15 5-7 7 7 7"/>'),
  dots('<circle cx="5.5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="18.5" cy="12" r="1.4"/>'),
  phone(
    '<path d="M5 3.5h3.5l1.8 4.5-2.3 1.5a11 11 0 0 0 6.5 6.5l1.5-2.3 4.5 1.8V19a1.5 1.5 0 0 1-1.5 1.5A16 16 0 0 1 3.5 5 1.5 1.5 0 0 1 5 3.5Z"/>',
  ),
  print('<path d="M7 8V3.5h10V8"/><rect x="3.5" y="8" width="17" height="9" rx="2"/><path d="M7 14h10v6.5H7Z"/>'),
  ban('<circle cx="12" cy="12" r="8.5"/><path d="m6 6 12 12"/>'),
  share(
    '<circle cx="18" cy="5.5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="18.5" r="2.5"/>'
    '<path d="m8.2 10.8 7.6-4M8.2 13.2l7.6 4"/>',
  ),
  copy(
    '<rect x="8" y="8" width="12.5" height="12.5" rx="2"/>'
    '<path d="M16 8V5a1.5 1.5 0 0 0-1.5-1.5h-9A1.5 1.5 0 0 0 4 5v9a1.5 1.5 0 0 0 1.5 1.5H8"/>',
  ),
  image(
    '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="m4 17 5-4.5 4 3.5 3-2.5 4.5 3.5"/>',
  ),
  edit('<path d="M4 20h4L19 9l-4-4L4 16Z"/><path d="m13.5 6.5 4 4"/>'),
  barcode('<path d="M4 5v14M7 5v14M10.5 5v14M13 5v14M16.5 5v14M20 5v14"/>');

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
