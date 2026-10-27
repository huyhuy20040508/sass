import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/core/api/api_client.dart';
import 'package:selliotech/features/admin/goods/goods_data.dart';
import 'package:selliotech/features/auth/auth_controller.dart';
import 'package:selliotech/features/admin/goods/goods_io.dart';

/// Xuất file hàng hoá từ app.
///
/// Giá bán THẬT của hàng nhiều phiên bản nằm ở `product_variants.price`, còn
/// `products.base_price` chỉ là giá gốc lúc tạo, không đồng bộ khi sửa giá
/// phiên bản. Bản xuất cũ lấy base_price nên tệp ghi 18.000.000 trong khi quầy
/// bán 22.000.000 — sửa tệp rồi nhập lại là mang cái giá sai vào hàng mới.
/// Nay mỗi phiên bản một dòng, đúng thứ người ta cầm đi soạn hàng và dán giá.
const _sanPham = [
  {
    'id': 3,
    'sku': 'HH000002',
    'name': 'laptop asus gamming',
    'base_price': 18000000,
    'cost_price': 17000000,
    'vat': 0,
    'is_multi_variant': true,
    'category': {'name': 'Laptop'},
    'unit': {'name': 'Cái'},
    'variants': [
      {'id': 2, 'sku': 'HH000002-RTX-3050', 'name': 'RTX 3050 · 8GB', 'price': 22000000, 'cost_price': 21000000},
      {'id': 9, 'sku': 'HH000002-RTX-4060', 'name': 'RTX 4060 · 16GB', 'price': 31000000, 'cost_price': 29000000},
    ],
  },
  {
    'id': 5,
    'sku': 'HH000003',
    'name': 'iphone 15',
    'base_price': 25000000,
    'vat': 0,
    'is_multi_variant': false,
    'category': {'name': 'Điện thoại'},
    'unit': {'name': 'Cái'},
    'variants': [
      {'id': 7, 'sku': 'HH000003', 'name': '', 'price': 26500000},
    ],
  },
];

class _Api implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? _, Future<void>? _) async {
    final p = o.path;
    if (p.endsWith('/products')) {
      return _json({
        'data': _sanPham,
        'meta': {'page': 1, 'page_size': 200, 'total': 2, 'total_pages': 1},
      });
    }
    return _json({'data': const []});
  }

  ResponseBody _json(Map<String, dynamic> body) => ResponseBody.fromString(
    jsonEncode(body),
    200,
    headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    },
  );

  @override
  void close({bool force = false}) {}
}

GoodsItem _hang(Map<String, dynamic> j, List<Map<String, dynamic>> ton) =>
    GoodsItem(j, [for (final t in ton) StockRow(t)]);

Future<List<List<String>>> _xuat(WidgetTester t, List<GoodsItem> rows) async {
  late WidgetRef ref;
  await t.pumpWidget(
    ProviderScope(
      overrides: [
        apiClientProvider.overrideWith(
          (r) => ApiClient(r.read(authProvider.notifier), dio: Dio()..httpClientAdapter = _Api()),
        ),
      ],
      child: Consumer(
        builder: (_, r, _) {
          ref = r;
          return const SizedBox();
        },
      ),
    ),
  );
  // runAsync: Dio chạy qua Timer thật, mà testWidgets dựng đồng hồ giả — không
  // có nó thì lượt gọi API treo mãi.
  final bytes = await t.runAsync(() => exportGoodsCsv(ref, rows: rows, stockLabel: 'Kho trung tâm'));

  return parseCsv(utf8.decode(bytes!));
}

void main() {
  testWidgets('hàng nhiều phiên bản: mỗi phiên bản một dòng, giá là giá phiên bản', (t) async {
    final rows = await _xuat(t, [
      _hang(Map<String, dynamic>.from(_sanPham[0]), [
        {'product_id': 3, 'variant_id': 2, 'variant_name': 'RTX 3050 · 8GB', 'stock_quantity': 171},
        {'product_id': 3, 'variant_id': 9, 'variant_name': 'RTX 4060 · 16GB', 'stock_quantity': 4},
      ]),
    ]);

    final cot = {for (var i = 0; i < rows[0].length; i++) rows[0][i]: i};
    expect(rows[0], contains('Phiên bản'));
    expect(rows.length, 3, reason: 'tiêu đề + 2 phiên bản');

    expect(rows[1][cot['Phiên bản']!], 'RTX 3050 · 8GB');
    expect(rows[1][cot['Giá bán']!], '22000000', reason: 'không phải base_price 18.000.000');
    expect(rows[1][cot['Giá vốn']!], '21000000');
    expect(rows[1][cot['Tồn kho (Kho trung tâm)']!], '171');
    expect(rows[1][cot['Mã hàng']!], 'HH000002-RTX-3050');

    expect(rows[2][cot['Giá bán']!], '31000000');
    expect(rows[2][cot['Tồn kho (Kho trung tâm)']!], '4');

    // Vẫn là MỘT mặt hàng: cùng mã SP, cùng số biến thể.
    expect(rows[1][cot['Mã SP']!], rows[2][cot['Mã SP']!]);
    expect(rows[1][cot['Số biến thể']!], '2');

    // Không còn con số của base_price trong tệp.
    expect(rows.any((r) => r.contains('18000000')), isFalse);
  });

  testWidgets('hàng một phiên bản: một dòng, cột Phiên bản trống, mã hàng là mã mặt hàng', (t) async {
    final rows = await _xuat(t, [
      _hang(Map<String, dynamic>.from(_sanPham[1]), [
        {'product_id': 5, 'variant_id': 7, 'variant_name': '', 'stock_quantity': 12},
      ]),
    ]);

    final cot = {for (var i = 0; i < rows[0].length; i++) rows[0][i]: i};
    expect(rows.length, 2);
    expect(rows[1][cot['Phiên bản']!], '');
    expect(rows[1][cot['Mã hàng']!], 'HH000003');
    expect(rows[1][cot['Giá bán']!], '26500000', reason: 'giá dòng mặc định, không phải base_price');
    // Chưa khai giá vốn thì để trống, không ghi 0.
    expect(rows[1][cot['Giá vốn']!], '');
    expect(rows[1][cot['Số biến thể']!], '0');
    expect(rows[1][cot['Tồn kho (Kho trung tâm)']!], '12');
  });
}
