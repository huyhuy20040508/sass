import '../../core/format/vn_number.dart';
import '../admin/orders/orders_data.dart' show methodLabel;

/// Một dòng của phiếu in. Phiếu dựng thành danh sách dòng rồi mới vẽ: phần
/// QUYẾT ĐỊNH in gì (theo mẫu, theo đơn) test được bằng chữ, không cần vẽ ảnh.
sealed class RLine {
  const RLine();
}

enum RAlign { left, center }

class RText extends RLine {
  const RText(this.text, {this.align = RAlign.left, this.size = 1, this.bold = false});

  final String text;
  final RAlign align;
  final double size;
  final bool bold;
}

/// Hai cột: chữ bên trái, số tiền bên phải (không bao giờ xuống dòng).
class RPair extends RLine {
  const RPair(this.left, this.right, {this.size = 1, this.bold = false, this.indent = 0});

  final String left;
  final String right;
  final double size;
  final bool bold;
  final double indent;
}

class RRule extends RLine {
  const RRule({this.thick = false});

  final bool thick;
}

/// Ô viền nét đứt — dòng "Bạn đã tiết kiệm …".
class RBox extends RLine {
  const RBox(this.text);

  final String text;
}

/// Ảnh tải về (logo, QR chuyển khoản) — vẽ theo phần [width] của bề ngang giấy.
class RImage extends RLine {
  const RImage(this.key, {required this.width, this.caption = ''});

  final String key;
  final double width;
  final String caption;
}

class RBarcode extends RLine {
  const RBarcode(this.code);

  final String code;
}

class RGap extends RLine {
  const RGap(this.height);

  final double height;
}

/// Mẫu in của chi nhánh (GET /admin/orders/pos/mau-in/{ma}): dòng nào bật, chữ chân phiếu.
class ReceiptTemplate {
  const ReceiptTemplate({this.options = const {}, this.settings = const {}});

  factory ReceiptTemplate.fromJson(Object? j) {
    final m = j is Map ? j : const {};
    return ReceiptTemplate(
      options: {for (final e in ((m['options'] as Map?) ?? const {}).entries) '${e.key}': e.value == true},
      settings: Map<String, dynamic>.from((m['settings'] as Map?) ?? const {}),
    );
  }

  final Map<String, bool> options;
  final Map<String, dynamic> settings;

  /// Dòng không có trong mẫu (API hỏng, mẫu cũ) thì in — như phiếu bên web.
  bool on(String k) => options[k] ?? true;

  String text(String k) => '${settings[k] ?? ''}'.trim();

  /// Khổ của MẪU — chỉ để gợi ý; máy in thật của điện thoại quyết khổ.
  int get paper => '${settings['kho']}' == '58' ? 58 : 80;
}

class ShopInfo {
  const ShopInfo({this.name = '', this.address = '', this.phone = ''});

  final String name;
  final String address;
  final String phone;
}

num _n(Object? v) => v is num ? v : num.tryParse('$v') ?? 0;
String _s(Object? v) => v == null ? '' : '$v'.trim();
String _tien(Object? v) => dot(_n(v).round());
String _gio(Object? v) {
  final t = DateTime.tryParse('${v ?? ''}')?.toLocal();
  if (t == null) return '';
  String h(int x) => x.toString().padLeft(2, '0');
  return '${h(t.hour)}:${h(t.minute)} ${h(t.day)}/${h(t.month)}/${t.year}';
}

String _phanTram(Object? v) {
  final s = _n(v).toStringAsFixed(2).replaceAll(RegExp(r'\.?0+$'), '');
  return s.replaceAll('.', ',');
}

/// Phiếu tính tiền — cùng thứ tự và cùng luật với v2/pos/receipt.blade.php của
/// web, để in từ điện thoại hay từ máy tính quầy ra tờ phiếu giống nhau.
List<RLine> buildReceipt(
  Map<String, dynamic> don,
  ReceiptTemplate tpl,
  ShopInfo shop, {
  bool logo = false,
  bool qr = false,
}) {
  final hien = tpl.on;
  final items = [
    for (final it in ((don['items'] as List?) ?? const []).whereType<Map>())
      if (hien('qua') || it['is_gift'] != true) it,
  ];
  num tienHang = 0, botMon = 0, soMon = 0;
  for (final it in items) {
    tienHang += _n(it['unit_price']) * _n(it['quantity']);
    botMon += _n(it['discount_amount']);
    soMon += _n(it['quantity']);
  }
  final giamTay = _n(don['order_discount_amount']);
  final giamKm = _n(don['promotion_discount']);
  final giamHang = _n(don['rank_discount']);
  final tienDiem = _n(don['points_amount']);
  final giamMa = (_n(don['discount_amount']) - giamTay - giamKm - giamHang - tienDiem).clamp(0, double.infinity);
  // Điểm đổi ra tiền là tiền của chính khách — không tính vào "tiết kiệm".
  final tietKiem = botMon + giamMa + giamTay + giamKm + giamHang;
  final tv = don['thanh_vien'] is Map ? don['thanh_vien'] as Map : null;
  final hang = _s(tv?['hang']);
  final khach = _s(don['recipient_name']), sdt = _s(don['recipient_phone']);
  final ma = _s(don['order_code']);
  final pm = _s(don['payment_method']);
  final dua = don['amount_tendered'];

  return [
    if (logo) const RImage('logo', width: .55),
    if (hien('ten') && shop.name.isNotEmpty)
      RText(shop.name.toUpperCase(), align: RAlign.center, size: 1.35, bold: true),
    if (hien('diachi') && shop.address.isNotEmpty) RText(shop.address, align: RAlign.center, size: .86),
    if (hien('sdt') && shop.phone.isNotEmpty) RText('ĐT: ${shop.phone}', align: RAlign.center, size: .86),
    const RRule(),
    const RText('HOÁ ĐƠN BÁN HÀNG', align: RAlign.center, size: 1.1, bold: true),
    if (hien('sohd') || hien('tg'))
      RPair(hien('sohd') ? 'Số: $ma' : '', hien('tg') ? _gio(don['placed_at'] ?? don['created_at']) : '', size: .86),
    if (hien('thungan') && _s(don['created_by_name']).isNotEmpty)
      RText('Thu ngân: ${_s(don['created_by_name'])}', size: .86),
    if (hien('khach') && (khach.isNotEmpty || sdt.isNotEmpty))
      RText('Khách: ${[khach, hang, sdt].where((x) => x.isNotEmpty).join(' · ')}', size: .86),
    if (_s(don['buyer_company']).isNotEmpty) RText('Đơn vị: ${_s(don['buyer_company'])}', size: .86),
    if (_s(don['buyer_tax_code']).isNotEmpty) RText('MST: ${_s(don['buyer_tax_code'])}', size: .86),
    const RRule(),
    for (final it in items) ...[
      RText(
        '${_s(it['product_name'])}'
        '${hien('bienthe') && _s(it['variant_name']).isNotEmpty ? ' (${_s(it['variant_name'])})' : ''}'
        '${it['is_gift'] == true ? ' (Tặng)' : ''}',
        bold: true,
      ),
      RPair(
        '${_n(it['quantity'])}${hien('dvt') && _s(it['unit_name']).isNotEmpty ? ' ${_s(it['unit_name'])}' : ''} × ${_tien(it['unit_price'])}',
        _tien(_n(it['unit_price']) * _n(it['quantity'])),
        indent: 6,
      ),
      if (hien('chitietgia') && _n(it['discount_amount']) > 0)
        RPair('Bớt ${_phanTram(it['discount_percent'])}%', '-${_tien(it['discount_amount'])}', size: .86, indent: 6),
      const RGap(3),
    ],
    const RRule(),
    RPair('Tiền hàng ($soMon món)', _tien(tienHang)),
    if (botMon > 0) RPair('Bớt theo món', '-${_tien(botMon)}'),
    if (giamMa > 0)
      RPair('Mã ${_s(don['voucher_code']).isNotEmpty ? _s(don['voucher_code']) : 'giảm giá'}', '-${_tien(giamMa)}'),
    if (giamTay > 0)
      RPair(
        'Giảm cả đơn${_n(don['order_discount_percent']) > 0 ? ' ${_phanTram(don['order_discount_percent'])}%' : ''}',
        '-${_tien(giamTay)}',
      ),
    if (giamKm > 0) RPair('Khuyến mãi', '-${_tien(giamKm)}'),
    if (giamHang > 0) RPair('Giảm hạng${hang.isNotEmpty ? ' $hang' : ''}', '-${_tien(giamHang)}'),
    if (tienDiem > 0) RPair('Đổi ${_tien(don['points_used'])} điểm', '-${_tien(tienDiem)}'),
    if (_n(don['surcharge_amount']) > 0)
      RPair(
        'Phụ thu${_s(don['surcharge_note']).isNotEmpty ? ': ${_s(don['surcharge_note'])}' : ''}',
        _tien(don['surcharge_amount']),
      ),
    if (hien('vat') && _n(don['vat_amount']) > 0) RPair('Thuế VAT', _tien(don['vat_amount'])),
    if (_n(don['shipping_fee']) > 0) RPair('Phí giao hàng', _tien(don['shipping_fee'])),
    const RRule(thick: true),
    RPair('TỔNG CỘNG', _tien(don['total_amount']), size: 1.3, bold: true),
    const RRule(thick: true),
    // Tiền mặt in số khách đưa (dòng dưới là tiền thối); hình thức khác in đúng số đã thu.
    if (pm.isNotEmpty) RPair(methodLabel(pm), _tien(hien('tienthua') && dua != null ? dua : don['total_amount'])),
    if (hien('tienthua') && dua != null && _n(don['change_amount']) > 0)
      RPair('Tiền thối', _tien(don['change_amount']), bold: true),
    if (hien('tietkiem') && tietKiem > 0) RBox('Bạn đã tiết kiệm ${_tien(tietKiem)}đ'),
    if (hien('diem') && _n(don['points_earned']) > 0)
      RPair('Điểm tích thêm', '+${_tien(don['points_earned'])}', size: .86),
    if (hien('diemconlai') && tv != null)
      RPair('${hang.isNotEmpty ? 'Hạng $hang · ' : ''}Điểm hiện có', _tien(tv['diem']), size: .86),
    if (hien('ghichu') && _s(don['note']).isNotEmpty) ...[
      const RRule(),
      RText('Ghi chú: ${_s(don['note'])}', size: .86),
    ],
    if (qr) ...[
      const RRule(),
      RImage('qr', width: .47, caption: 'Quét để chuyển khoản ${_tien(don['total_amount'])}đ'),
    ],
    if ((hien('wifi') && tpl.text('wifi_txt').isNotEmpty) ||
        (hien('chinhsach') && tpl.text('chinhsach_txt').isNotEmpty)) ...[
      const RRule(),
      if (hien('wifi') && tpl.text('wifi_txt').isNotEmpty)
        RText('Wifi: ${tpl.text('wifi_txt')}', align: RAlign.center, size: .86),
      if (hien('chinhsach') && tpl.text('chinhsach_txt').isNotEmpty)
        RText(tpl.text('chinhsach_txt'), align: RAlign.center, size: .86),
    ],
    if (hien('footer'))
      RText(
        tpl.text('footer_txt').isNotEmpty ? tpl.text('footer_txt') : 'Cảm ơn quý khách!',
        align: RAlign.center,
        bold: true,
      ),
    if (hien('mavach') && ma.isNotEmpty) RBarcode(ma),
    if (hien('sao')) const RText('**********', align: RAlign.center),
  ];
}

const _lyDoTra = {
  'defective': 'Sản phẩm lỗi/hỏng',
  'wrong_item': 'Giao sai sản phẩm',
  'wrong_size': 'Sai size',
  'not_as_described': 'Không giống mô tả',
  'changed_mind': 'Đổi ý, không có nhu cầu',
  'other': 'Lý do khác',
};
const _hoanBang = {
  'bank_transfer': 'Chuyển khoản',
  'cash': 'Tiền mặt',
  'ewallet': 'Ví điện tử',
  'none': 'Không hoàn tiền (đổi hàng)',
};

/// Phiếu trả hàng — theo v2/pos/return-slip.blade.php và mẫu "tra" của chi nhánh.
List<RLine> buildReturnSlip(Map<String, dynamic> tra, ReceiptTemplate tpl, ShopInfo shop) {
  final hien = tpl.on;
  final don = tra['order'] is Map ? Map<String, dynamic>.from(tra['order'] as Map) : <String, dynamic>{};
  // Dòng trả không chụp ĐVT: lấy theo dòng của đơn gốc.
  final dvt = {
    for (final it in ((don['items'] as List?) ?? const []).whereType<Map>()) '${it['id']}': _s(it['unit_name']),
  };
  return [
    if (hien('ten') && shop.name.isNotEmpty)
      RText(shop.name.toUpperCase(), align: RAlign.center, size: 1.35, bold: true),
    if (hien('diachi') && shop.address.isNotEmpty) RText(shop.address, align: RAlign.center, size: .86),
    const RRule(),
    const RText('PHIẾU TRẢ HÀNG', align: RAlign.center, size: 1.1, bold: true),
    if (hien('maphieu') || hien('tgtra'))
      RPair(
        hien('maphieu') ? 'Số: ${_s(tra['return_code'])}' : '',
        hien('tgtra') ? _gio(tra['created_at']) : '',
        size: .86,
      ),
    if (hien('khachtra') && _s(don['recipient_name']).isNotEmpty)
      RText(
        'Người trả: ${_s(don['recipient_name'])}'
        '${hien('sdttra') && _s(don['recipient_phone']).isNotEmpty ? ' · ${_s(don['recipient_phone'])}' : ''}',
        size: .86,
      ),
    if (hien('sohd') || hien('tg') || hien('thungan') || hien('khach') || hien('pttt')) ...[
      const RRule(),
      RText('Đơn gốc${hien('sohd') ? ': ${_s(don['order_code'])}' : ''}', size: .86, bold: true),
      if (hien('tg') && _gio(don['placed_at'] ?? don['created_at']).isNotEmpty)
        RText('Thanh toán lúc: ${_gio(don['placed_at'] ?? don['created_at'])}', size: .86),
      if (hien('thungan') && _s(don['created_by_name']).isNotEmpty)
        RText('Thu ngân: ${_s(don['created_by_name'])}', size: .86),
      if (hien('khach') && _s(don['recipient_name']).isNotEmpty)
        RText('Khách: ${_s(don['recipient_name'])}', size: .86),
      if (hien('pttt') && _s(don['payment_method']).isNotEmpty)
        RText('Hình thức: ${methodLabel(_s(don['payment_method']))}', size: .86),
    ],
    const RRule(),
    for (final it in ((tra['items'] as List?) ?? const []).whereType<Map>()) ...[
      RText(
        '${_s(it['product_name'])}${hien('bienthe') && _s(it['variant_name']).isNotEmpty ? ' (${_s(it['variant_name'])})' : ''}',
        bold: true,
      ),
      RPair(
        '${_n(it['quantity'])}${hien('dvt') && (dvt['${it['order_item_id']}'] ?? '').isNotEmpty ? ' ${dvt['${it['order_item_id']}']}' : ''}'
        ' × ${_tien(it['unit_price'])}',
        _tien(it['total_price'] ?? _n(it['unit_price']) * _n(it['quantity'])),
        indent: 6,
      ),
      const RGap(3),
    ],
    const RRule(thick: true),
    if (_n(tra['deduction']) > 0) ...[
      RPair('Tiền hàng trả', _tien(tra['items_amount'])),
      RPair('Khấu trừ', '-${_tien(tra['deduction'])}'),
    ],
    if (hien('tienhoan')) RPair('HOÀN LẠI', _tien(tra['refund_amount']), size: 1.25, bold: true),
    if (hien('pthoan') && _s(tra['refund_method']).isNotEmpty)
      RPair('Hình thức hoàn', _hoanBang[_s(tra['refund_method'])] ?? _s(tra['refund_method'])),
    const RRule(thick: true),
    if (hien('lydo') && _s(tra['reason']).isNotEmpty)
      RText('Lý do: ${_lyDoTra[_s(tra['reason'])] ?? _s(tra['reason'])}', size: .86),
    if (hien('noidung') && _s(tra['reason_note']).isNotEmpty) RText('Ghi chú: ${_s(tra['reason_note'])}', size: .86),
    if (hien('kyCuaHang') || hien('kyKhach')) ...[
      const RGap(8),
      RPair(hien('kyCuaHang') ? 'Cửa hàng' : '', hien('kyKhach') ? 'Khách hàng' : '', size: .86),
      const RGap(56),
    ],
  ];
}

/// Phiếu in thử — kiểm khổ giấy, chữ có dấu, dao cắt.
List<RLine> buildTestSlip(String shop, int paper, String printer) => [
  if (shop.isNotEmpty) RText(shop.toUpperCase(), align: RAlign.center, size: 1.35, bold: true),
  const RText('IN THỬ', align: RAlign.center, size: 1.1, bold: true),
  const RRule(),
  RPair('Máy in', printer),
  RPair('Khổ giấy', '${paper}mm'),
  const RText('Tiếng Việt: ăâđêôơư ÁÀẢÃẠ ỆỘỨ', size: .95),
  const RRule(thick: true),
  const RPair('TỔNG CỘNG', '1.234.567', size: 1.3, bold: true),
  const RRule(thick: true),
  const RText(
    'Chữ đủ nét, không mất dấu và cột tiền thẳng mép phải là máy in đã sẵn sàng.',
    align: RAlign.center,
    size: .86,
  ),
  const RBarcode('INTHU123'),
];
