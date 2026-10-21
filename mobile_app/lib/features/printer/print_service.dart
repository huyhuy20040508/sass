import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/config.dart';
import '../auth/auth_controller.dart';
import 'escpos.dart';
import 'printer_config.dart';
import 'printer_link.dart';
import 'receipt_doc.dart';
import 'receipt_render.dart';

/// Một phiếu đã dựng xong: ảnh để xem trước + ảnh 1 bit để gửi máy in.
class Slip {
  const Slip(this.preview, this.mono);

  final ui.Image preview;
  final MonoImage mono;
}

/// Lấy dữ liệu, dựng và in phiếu từ điện thoại ra máy in nhiệt đã cài ở màn Máy in.
class SlipService {
  SlipService(this._api, this._link, {Dio? http}) : _http = http ?? Dio();

  final ApiClient _api;
  final PrinterLink _link;
  final Dio _http;

  /// Tên / địa chỉ / logo ở Cài đặt → Chung. Tài khoản thu ngân không đọc được
  /// trang cài đặt thì in tên cửa hàng của phiên — phiếu ra được còn hơn không.
  Future<(ShopInfo, String)> _shop(String fallbackName) async {
    try {
      final d = await _api.get<Map<String, dynamic>>('/admin/settings');
      final v = Map<String, dynamic>.from((d['values'] as Map?) ?? const {});
      String s(String k) => '${v[k] ?? ''}'.trim();
      return (
        ShopInfo(
          name: s('site_name').isNotEmpty ? s('site_name') : fallbackName,
          address: s('store_address'),
          phone: s('contact_phone'),
        ),
        s('store_logo'),
      );
    } on Exception {
      return (ShopInfo(name: fallbackName), '');
    }
  }

  /// Mẫu in của chi nhánh LẬP chứng từ, không phải chi nhánh app đang chọn.
  Future<ReceiptTemplate> _mau(String ma, int? shopId) async {
    try {
      return ReceiptTemplate.fromJson(await _api.get<Object?>('/admin/orders/pos/mau-in/$ma', branch: shopId));
    } on Exception {
      return const ReceiptTemplate();
    }
  }

  /// Ảnh logo / QR tải hỏng thì bỏ dòng đó, không bỏ cả phiếu.
  Future<ui.Image?> _anh(String url) async {
    if (url.isEmpty) return null;
    try {
      final u = Uri.parse(AppConfig.apiBaseUrl).resolve(url).toString();
      final r = await _http.get<List<int>>(
        u,
        options: Options(responseType: ResponseType.bytes, receiveTimeout: const Duration(seconds: 8)),
      );
      final codec = await ui.instantiateImageCodec(Uint8List.fromList(r.data!));
      return (await codec.getNextFrame()).image;
    } on Exception {
      return null;
    }
  }

  /// QR chuyển khoản đúng tổng tiền vào tài khoản mặc định của chi nhánh — như phiếu web.
  Future<String> _qr(Map<String, dynamic> don) async {
    try {
      final ds = await _api.get<List<dynamic>>(
        '/admin/orders/pos/payment-methods',
        branch: (don['shop_id'] as num?)?.toInt(),
      );
      for (final pt in ds.whereType<Map>()) {
        if ((pt['type'] as num?)?.toInt() != 4) continue;
        for (final tk in ((pt['details'] as List?) ?? const []).whereType<Map>()) {
          if (tk['is_default'] == true && '${tk['bank_account'] ?? ''}'.isNotEmpty) {
            return Uri.https('qr.sepay.vn', '/img', {
              'acc': '${tk['bank_account']}',
              'bank': '${tk['bank_name'] ?? ''}',
              'amount': '${((don['total_amount'] as num?) ?? 0).round()}',
              'des': 'TT ${don['order_code'] ?? ''}',
            }).toString();
          }
        }
      }
    } on Exception {
      // Không đọc được tài khoản: phiếu không có QR.
    }
    return '';
  }

  Future<Slip> _ve(List<RLine> lines, int dots, Map<String, ui.Image> images) async {
    final img = await SlipPainter(dots, images: images).paint(lines);
    return Slip(img, await SlipPainter.toMono(img));
  }

  /// Phiếu tính tiền của một đơn ([don] = GET /admin/orders/{id}).
  Future<Slip> receipt(Map<String, dynamic> don, {required int dots, required String tenantName}) async {
    final (shop, logoUrl) = await _shop(tenantName);
    final tpl = await _mau('hd', (don['shop_id'] as num?)?.toInt());
    final images = <String, ui.Image>{};
    if (tpl.on('logo')) {
      final l = await _anh(logoUrl);
      if (l != null) images['logo'] = l;
    }
    // QR chỉ có nghĩa với đơn trả bằng chuyển khoản; đơn tiền mặt / thẻ mà in QR thì khách tưởng phải chuyển thêm.
    if (tpl.on('qr') && don['payment_method'] == 'bank_transfer') {
      final q = await _anh(await _qr(don));
      if (q != null) images['qr'] = q;
    }
    final lines = buildReceipt(don, tpl, shop, logo: images.containsKey('logo'), qr: images.containsKey('qr'));
    return _ve(lines, dots, images);
  }

  /// Phiếu trả hàng — đường đọc của quầy, thu ngân không cần quyền vào khu Trả hàng.
  Future<Slip> returnSlip(int returnId, {required int dots, required String tenantName}) async {
    final tra = await _api.get<Map<String, dynamic>>('/admin/orders/pos/tra-hang/$returnId');
    final (shop, _) = await _shop(tenantName);
    final tpl = await _mau('tra', (tra['shop_id'] as num?)?.toInt());
    return _ve(buildReturnSlip(tra, tpl, shop), dots, const {});
  }

  Future<Slip> testSlip(PrinterConfig c, {required String tenantName}) =>
      _ve(buildTestSlip(tenantName, c.paper, c.label), c.dots, const {});

  /// Gửi [copies] bản — mỗi bản một tờ, máy có dao thì cắt rời.
  Future<void> send(PrinterConfig c, Slip s, {int copies = 1}) =>
      _link.send(c, EscPos.job(List.filled(copies.clamp(1, 9), s.mono), cutPaper: c.cut));
}

final slipServiceProvider = Provider<SlipService>(
  (ref) => SlipService(ref.read(apiClientProvider), ref.read(printerLinkProvider)),
);
