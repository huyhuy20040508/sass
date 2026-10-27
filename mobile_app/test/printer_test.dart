import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/core/api/api_client.dart';
import 'package:selliotech/features/printer/escpos.dart';
import 'package:selliotech/features/admin/shell/more_menu.dart';
import 'package:selliotech/features/printer/print_service.dart';
import 'package:selliotech/features/printer/print_sheet.dart';
import 'package:selliotech/features/printer/printer_config.dart';
import 'package:selliotech/features/printer/printer_link.dart';
import 'package:selliotech/features/printer/printer_settings_screen.dart';
import 'package:selliotech/features/printer/receipt_doc.dart';
import 'package:selliotech/features/printer/receipt_render.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Ghi lại thay cho máy in thật.
class _Link implements PrinterLink {
  final sent = <(PrinterConfig, List<int>)>[];
  List<PairedPrinter> paired_ = const [PairedPrinter('XP-P323B', '66:22:33:44:55:66')];
  PrinterException? loi;

  @override
  Future<void> send(PrinterConfig c, List<int> bytes) async {
    if (loi != null) throw loi!;
    sent.add((c, bytes));
  }

  @override
  Future<List<PairedPrinter>> paired() async {
    if (loi != null) throw loi!;
    return paired_;
  }
}

/// Gói Bluetooth của bản web ném lỗi NGOÀI Exception (thiếu kênh nền tảng);
/// tầng PrinterLink gom lại thành một câu người đọc hiểu được.
class _LinkVo implements PrinterLink {
  @override
  Future<void> send(PrinterConfig c, List<int> bytes) async {}

  @override
  Future<List<PairedPrinter>> paired() async =>
      throw const PrinterException('Không đọc được Bluetooth — bật Bluetooth và cấp quyền rồi thử lại.');
}

/// Chữ của mọi dòng, để so bằng chuỗi.
String chu(List<RLine> ls) => ls
    .map(
      (l) => switch (l) {
        RText(:final text) => text,
        RPair(:final left, :final right) => '$left | $right',
        RBox(:final text) => '[$text]',
        RImage(:final key, :final caption) => '<$key> $caption',
        RBarcode(:final code) => '||| $code',
        RRule(:final thick) => thick ? '===' : '---',
        RGap() => '',
      },
    )
    .join('\n');

Map<String, dynamic> _don({String pm = 'cash'}) => {
  'order_code': 'DH000063',
  'shop_id': 2,
  'placed_at': '2026-10-14T10:04:00+07:00',
  'payment_method': pm,
  'created_by_name': 'Nguyễn Thu Hà',
  'recipient_name': 'Trần Minh Anh',
  'recipient_phone': '0912345678',
  'items': [
    {
      'product_name': 'Tai nghe',
      'variant_name': 'Trắng',
      'unit_name': 'Cái',
      'quantity': 2,
      'unit_price': 290000,
      'discount_amount': 58000,
      'discount_percent': 10,
    },
    {'product_name': 'Cáp', 'unit_name': 'Sợi', 'quantity': 1, 'unit_price': 0, 'is_gift': true},
  ],
  'discount_amount': 30000,
  'rank_discount': 30000,
  'total_amount': 492000,
  'amount_tendered': 500000,
  'change_amount': 8000,
  'points_earned': 49,
  'thanh_vien': {'hang': 'Vàng', 'diem': 1916},
};

void main() {
  group('ESC/POS', () {
    test('ảnh 1 bit: ngưỡng tối, trong suốt là trắng, độn bề ngang cho tròn byte', () {
      // 3×1: đen, trắng, đen-trong-suốt.
      final rgba = Uint8List.fromList([0, 0, 0, 255, 255, 255, 255, 255, 0, 0, 0, 0]);
      final m = MonoImage.fromRgba(rgba, 3, 1);
      expect(m.width, 8);
      expect(m.bits, [0x80]);
      expect([m.dot(0, 0), m.dot(1, 0), m.dot(2, 0)], [true, false, false]);
    });

    test('bỏ hàng trắng cuối để máy không đẩy giấy thừa', () {
      final m = MonoImage(8, 4, Uint8List.fromList([0xFF, 0, 0, 0])).trimBottom();
      expect(m.height, 1);
    });

    test('raster GS v 0 chia dải, phiếu có khởi động + cắt', () {
      final m = MonoImage(16, 300, Uint8List(2 * 300));
      final r = EscPos.raster(m);
      // 300 hàng = 128 + 128 + 44, mỗi dải một tiêu đề 8 byte.
      expect(r.length, 3 * 8 + 2 * 300);
      expect(r.sublist(0, 8), [0x1D, 0x76, 0x30, 0, 2, 0, 128, 0]);
      final job = EscPos.job([m, m]);
      expect(job.sublist(0, 2), EscPos.init);
      expect(_dem(job, EscPos.cut()), 2, reason: 'hai bản thì cắt hai lần');
      expect(_dem(EscPos.job([m], cutPaper: false), EscPos.cut()), 0);
    });

    test('mã vạch Code 128 trùng từng vạch với bản PHP của phiếu web', () {
      const php =
          '00000000001101001000010110001000110001010001001110110010011101100100111011001001110110011001110100110010111001010001111011000111010110000000000';
      expect(code128Modules('DH000063').map((b) => b ? '1' : '0').join(), php);
      expect(code128Modules('ầ'), isEmpty);
    });
  });

  group('Nội dung phiếu', () {
    test('đủ dòng theo mẫu mặc định, giống phiếu web', () {
      final s = chu(
        buildReceipt(_don(), const ReceiptTemplate(), const ShopInfo(name: 'Selliotech', phone: '0796666468')),
      );
      for (final can in [
        'SELLIOTECH',
        'ĐT: 0796666468',
        'HOÁ ĐƠN BÁN HÀNG',
        'Số: DH000063',
        'Khách: Trần Minh Anh · Vàng · 0912345678',
        'Tai nghe (Trắng)',
        '2 Cái × 290.000 | 580.000',
        'Bớt 10% | -58.000',
        'Cáp (Tặng)',
        'Giảm hạng Vàng | -30.000',
        'TỔNG CỘNG | 492.000',
        'Tiền mặt | 500.000',
        'Tiền thối | 8.000',
        '[Bạn đã tiết kiệm 88.000đ]',
        'Hạng Vàng · Điểm hiện có | 1.916',
        'Cảm ơn quý khách!',
        '||| DH000063',
      ]) {
        expect(s, contains(can));
      }
      expect(s, isNot(contains('<qr>')), reason: 'QR chỉ khi nơi gọi đã tải được ảnh QR');
    });

    test('tắt dòng trong mẫu thì không in; wifi / chính sách lấy chữ của mẫu', () {
      final tpl = ReceiptTemplate(
        options: const {
          'dvt': false,
          'tietkiem': false,
          'mavach': false,
          'diemconlai': false,
          'qua': false,
          'wifi': true,
          'chinhsach': true,
        },
        settings: const {
          'wifi_txt': 'Shop · MK 123',
          'chinhsach_txt': 'Đổi trong 7 ngày',
          'footer_txt': 'Hẹn gặp lại!',
        },
      );
      final s = chu(buildReceipt(_don(), tpl, const ShopInfo(name: 'X')));
      for (final khong in ['Cái ×', 'tiết kiệm', 'Điểm hiện có', '|||', 'Cáp']) {
        expect(s, isNot(contains(khong)), reason: 'dòng đã tắt vẫn in: $khong');
      }
      expect(s, allOf(contains('Wifi: Shop · MK 123'), contains('Đổi trong 7 ngày'), contains('Hẹn gặp lại!')));
    });

    test('đơn chuyển khoản: dòng thu là tổng tiền, có QR khi đã tải ảnh', () {
      final d = _don(pm: 'bank_transfer')..remove('amount_tendered');
      final s = chu(buildReceipt(d, const ReceiptTemplate(), const ShopInfo(), qr: true));
      expect(s, contains('Chuyển khoản | 492.000'));
      expect(s, contains('<qr> Quét để chuyển khoản 492.000đ'));
      expect(s, isNot(contains('Tiền thối')));
    });

    test('phiếu trả lấy ĐVT theo dòng của đơn gốc', () {
      final s = chu(
        buildReturnSlip(
          {
            'return_code': 'TH000007',
            'reason': 'defective',
            'refund_method': 'cash',
            'refund_amount': 261000,
            'items': [
              {
                'order_item_id': 501,
                'product_name': 'Tai nghe',
                'quantity': 1,
                'unit_price': 261000,
                'total_price': 261000,
              },
            ],
            'order': {
              'order_code': 'DH000088',
              'items': [
                {'id': 501, 'unit_name': 'Cái'},
              ],
            },
          },
          const ReceiptTemplate(),
          const ShopInfo(name: 'Shop'),
        ),
      );
      expect(s, allOf(contains('PHIẾU TRẢ HÀNG'), contains('Số: TH000007'), contains('1 Cái × 261.000 | 261.000')));
      expect(
        s,
        allOf(
          contains('HOÀN LẠI | 261.000'),
          contains('Lý do: Sản phẩm lỗi/hỏng'),
          contains('Hình thức hoàn | Tiền mặt'),
        ),
      );
    });
  });

  group('Vẽ và gửi', () {
    testWidgets('vẽ đúng bề ngang đầu in, khổ 58 hẹp hơn', (t) async {
      final lines = buildReceipt(_don(), const ReceiptTemplate(), const ShopInfo(name: 'Selliotech'));
      final (m80, m58) = await t.runAsync(() async {
        final a = await SlipPainter.toMono(await SlipPainter(576).paint(lines));
        final b = await SlipPainter.toMono(await SlipPainter(384).paint(lines));
        return (a, b);
      }) as (MonoImage, MonoImage);
      expect(m80.width, 576);
      expect(m58.width, 384);
      expect(m80.height, greaterThan(400));
      expect(m80.bits.any((b) => b != 0), isTrue, reason: 'phiếu phải có chữ');
    });

    test('máy in mạng nhận đúng chuỗi lệnh qua cổng 9100', () async {
      final may = await ServerSocket.bind(InternetAddress.loopbackIPv4, 0);
      final nhan = <int>[];
      final xong = Completer<void>();
      may.listen((s) => s.listen(nhan.addAll, onDone: xong.complete));
      final job = EscPos.job([
        MonoImage(8, 2, Uint8List.fromList([0xF0, 0x0F])),
      ]);
      await const DeviceLink().send(PrinterConfig(kind: PrinterKind.lan, address: '127.0.0.1', port: may.port), job);
      await xong.future.timeout(const Duration(seconds: 5));
      await may.close();
      expect(nhan, job);
    });

    test('máy in mạng tắt: báo câu thu ngân hiểu được', () async {
      final tam = await ServerSocket.bind(InternetAddress.loopbackIPv4, 0);
      final port = tam.port;
      await tam.close();
      expect(
        () => const DeviceLink().send(PrinterConfig(kind: PrinterKind.lan, address: '127.0.0.1', port: port), [0]),
        throwsA(isA<PrinterException>().having((e) => e.message, 'message', contains('cùng mạng Wi-Fi'))),
      );
    });
  });

  group('Bấm In hoá đơn', () {
    Future<_Link> mo(WidgetTester t, {Map<String, Object> prefs = const {}}) async {
      SharedPreferences.setMockInitialValues(prefs);
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final link = _Link();
      await t.pumpWidget(
        ProviderScope(
          overrides: [
            printerLinkProvider.overrideWithValue(link),
            slipServiceProvider.overrideWith((ref) => _Slips(link)),
          ],
          child: MaterialApp(
            home: Scaffold(
              body: Consumer(
                builder: (ctx, ref, _) => TextButton(
                  key: const Key('nut_in'),
                  onPressed: () => openPrintSheet(ctx, ref, code: 'DH000063', order: _don()),
                  child: const Text('In hoá đơn'),
                ),
              ),
            ),
          ),
        ),
      );
      return link;
    }

    testWidgets('chưa cài máy in thì mở màn Máy in trước', (t) async {
      await mo(t);
      await t.tap(find.byKey(const Key('nut_in')));
      await t.pumpAndSettle();
      expect(find.text('Máy in hoá đơn'), findsOneWidget);
      await t.pump(const Duration(seconds: 3)); // hết thông báo nổi
    });

    testWidgets('đã cài: xem đúng tờ sẽ in, mặc định 1 bản, chọn 2 bản thì in 2 tờ', (t) async {
      final cfg = const PrinterConfig(kind: PrinterKind.lan, address: '192.168.1.50', paper: 80).toJson();
      final link = await mo(t, prefs: {PrinterConfigNotifier.key: jsonEncode(cfg)});
      await t.tap(find.byKey(const Key('nut_in')));
      // Dựng ảnh phiếu cần đồng hồ thật; chạy xen kẽ tới khi ảnh xem trước hiện.
      for (var i = 0; i < 40 && find.byKey(const Key('print_preview')).evaluate().isEmpty; i++) {
        await t.pump(const Duration(milliseconds: 50));
        await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));
      }
      await t.pump(const Duration(milliseconds: 400));
      expect(find.text('In hoá đơn DH000063'), findsOneWidget);
      expect(find.byKey(const Key('print_preview')), findsOneWidget, reason: 'ảnh xem trước dựng xong');
      expect(find.text('In 1 bản'), findsOneWidget);
      await t.tap(find.byKey(const Key('print_copies_plus')));
      await t.pump();
      await t.runAsync(() async {
        await t.tap(find.byKey(const Key('print_go')));
        await Future<void>.delayed(const Duration(milliseconds: 300));
      });
      await t.pumpAndSettle();
      expect(link.sent, hasLength(1));
      expect(_dem(link.sent.single.$2, EscPos.cut()), 2, reason: '2 bản = 2 tờ cắt rời');
      expect(find.text('In hoá đơn DH000063'), findsNothing, reason: 'in xong thì đóng hộp');
      await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 2600)));
      await t.pumpAndSettle();
    });
  });

  group('Cấu hình máy in', () {
    test('lưu / đọc lại, dữ liệu hỏng thì coi như chưa cài', () {
      const c = PrinterConfig(
        kind: PrinterKind.bluetooth,
        address: '66:22:33:44:55:66',
        name: 'XP',
        paper: 58,
        cut: false,
      );
      final l = PrinterConfig.fromJson(c.toJson())!;
      expect(
        [l.kind, l.address, l.name, l.paper, l.cut, l.dots],
        [PrinterKind.bluetooth, c.address, 'XP', 58, false, 384],
      );
      expect(PrinterConfig.fromJson({'kind': 'lan'}), isNull);
      expect(PrinterConfig.fromJson('rác'), isNull);
    });

    Future<_Link> mo(WidgetTester t, {Map<String, Object> prefs = const {}}) async {
      SharedPreferences.setMockInitialValues(prefs);
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final link = _Link();
      await t.pumpWidget(
        ProviderScope(
          overrides: [
            printerLinkProvider.overrideWithValue(link),
            slipServiceProvider.overrideWith((ref) => _Slips(link)),
          ],
          child: const MaterialApp(home: PrinterSettingsScreen()),
        ),
      );
      await t.pumpAndSettle();
      return link;
    }

    testWidgets('Bluetooth: chọn máy đã ghép đôi, in thử, lưu', (t) async {
      final link = await mo(t);
      await t.tap(find.byKey(const Key('printer_save')));
      await t.pump();
      expect(find.text('Chọn một máy in Bluetooth trong danh sách.'), findsOneWidget);

      await t.tap(find.byKey(const Key('printer_bt_scan')));
      await t.pumpAndSettle();
      await t.tap(find.byKey(const Key('printer_bt_66:22:33:44:55:66')));
      await t.tap(find.byKey(const Key('printer_paper_58')));
      await t.pump();
      await t.runAsync(() async {
        await t.tap(find.byKey(const Key('printer_test')));
        await Future<void>.delayed(const Duration(milliseconds: 300));
      });
      await t.pumpAndSettle();
      expect(link.sent, hasLength(1));
      expect(link.sent.single.$1.paper, 58);
      expect(find.text('Đã gửi phiếu in thử tới XP-P323B'), findsOneWidget);
      // Thông báo này bật trong lúc chạy đồng hồ thật (vẽ ảnh phiếu) — chờ nó tắt hẳn.
      await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 2600)));
      await t.pumpAndSettle();

      await t.tap(find.byKey(const Key('printer_save')));
      await t.pumpAndSettle();
      final luu = PrinterConfig.fromJson(_json(await SharedPreferences.getInstance()));
      expect([luu?.kind, luu?.address, luu?.paper], [PrinterKind.bluetooth, '66:22:33:44:55:66', 58]);
      await t.pump(const Duration(seconds: 5)); // hết thông báo nổi
    });

    testWidgets('Wi-Fi: kiểm IP trước khi lưu', (t) async {
      await mo(t);
      await t.tap(find.byKey(const Key('printer_kind_lan')));
      await t.pump();
      await t.enterText(find.byKey(const Key('printer_ip')), '192.168.1');
      await t.tap(find.byKey(const Key('printer_save')));
      await t.pump();
      expect(find.textContaining('Nhập địa chỉ IP'), findsOneWidget);
      await t.enterText(find.byKey(const Key('printer_ip')), '192.168.1.100');
      await t.tap(find.byKey(const Key('printer_save')));
      await t.pumpAndSettle();
      final luu = PrinterConfig.fromJson(_json(await SharedPreferences.getInstance()));
      expect([luu?.kind, luu?.address, luu?.port], [PrinterKind.lan, '192.168.1.100', 9100]);
      await t.pump(const Duration(seconds: 5)); // hết thông báo nổi
    });

    testWidgets('Bluetooth bị tắt / chưa cho quyền: hiện câu hướng dẫn', (t) async {
      final link = await mo(t);
      link.loi = const PrinterException('Bluetooth của điện thoại đang tắt. Bật Bluetooth rồi bấm lại.');
      await t.tap(find.byKey(const Key('printer_bt_scan')));
      await t.pumpAndSettle();
      expect(find.text('Bluetooth của điện thoại đang tắt. Bật Bluetooth rồi bấm lại.'), findsOneWidget);

      link
        ..loi = null
        ..paired_ = const [];
      await t.tap(find.byKey(const Key('printer_bt_scan')));
      await t.pumpAndSettle();
      expect(find.text('Điện thoại chưa ghép đôi máy Bluetooth nào.'), findsOneWidget);
    });
  });

  /// Lỗi 97: màn này mở bằng Navigator.push trong khi bốn màn cùng menu Thêm là
  /// view nằm trong khung app (thanh dưới vẫn hiện, tab Thêm sáng). Và bản web
  /// bấm "Xem máy in đã ghép đôi" thì gói Bluetooth ném lỗi NGOÀI Exception nên
  /// không ai bắt — màn im lặng, người dùng tưởng bấm hụt.
  group('Cài đặt thiết bị nằm trong khung app', () {
    test('menu Thêm khai màn này là view, không phải màn mở chồng', () {
      final muc = moreModules.expand((m) => m.items).firstWhere((i) => i.name == 'Cài đặt thiết bị');

      expect(muc.view, isNotNull, reason: 'phải là view như Nhóm hàng hoá / Đơn vị tính');
      expect(muc.page, isNull);
      expect(muc.ready, isTrue);
    });

    testWidgets('nút lùi gọi host.close() chứ không bật khỏi Navigator', (t) async {
      SharedPreferences.setMockInitialValues(const {});
      var dong = 0;
      await t.pumpWidget(
        ProviderScope(
          overrides: [printerLinkProvider.overrideWithValue(_Link())],
          child: MaterialApp(
            home: PrinterSettingsScreen(host: MoreHost(goTab: (_) {}, close: () => dong++)),
          ),
        ),
      );
      await t.pumpAndSettle();

      await t.tap(find.byTooltip('Quay lại'));
      await t.pumpAndSettle();

      expect(dong, 1);
      // Vẫn còn trên màn — khung app mới là chỗ quyết định hiện gì tiếp theo.
      expect(find.text('Máy in hoá đơn'), findsOneWidget);
    });

    testWidgets('Bluetooth ném lỗi lạ (bản web) thì vẫn có câu báo, không im lặng', (t) async {
      SharedPreferences.setMockInitialValues(const {});
      await t.pumpWidget(
        ProviderScope(
          overrides: [printerLinkProvider.overrideWithValue(_LinkVo())],
          child: const MaterialApp(home: PrinterSettingsScreen()),
        ),
      );
      await t.pumpAndSettle();

      await t.tap(find.byKey(const Key('printer_bt_scan')));
      await t.pumpAndSettle();

      expect(find.text('Không đọc được Bluetooth — bật Bluetooth và cấp quyền rồi thử lại.'), findsOneWidget);
      expect(t.takeException(), isNull);
    });
  });
}

Object? _json(SharedPreferences p) {
  final raw = p.getString(PrinterConfigNotifier.key);
  return raw == null ? null : jsonDecode(raw);
}

int _dem(List<int> a, List<int> b) {
  var n = 0;
  for (var i = 0; i + b.length <= a.length; i++) {
    var khop = true;
    for (var j = 0; j < b.length; j++) {
      if (a[i + j] != b[j]) {
        khop = false;
        break;
      }
    }
    if (khop) n++;
  }
  return n;
}

class _NoToken implements TokenSource {
  @override
  String? get accessToken => null;
  @override
  int? get chiNhanhId => null;
  @override
  Future<String?> refresh() async => null;
  @override
  Future<void> onSessionExpired() async {}
}

/// Phiếu vẽ thật bằng SlipPainter, không gọi API nào.
class _Slips extends SlipService {
  _Slips(PrinterLink link) : super(ApiClient(_NoToken()), link);

  @override
  Future<Slip> receipt(Map<String, dynamic> don, {required int dots, required String tenantName}) async {
    final img = await SlipPainter(dots).paint(buildReceipt(don, const ReceiptTemplate(), const ShopInfo(name: 'Shop')));
    return Slip(img, await SlipPainter.toMono(img));
  }
}
