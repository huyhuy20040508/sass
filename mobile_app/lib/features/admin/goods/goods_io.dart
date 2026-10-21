import 'dart:convert';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:share_plus/share_plus.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/auth_controller.dart';
import 'goods_data.dart';
import 'product_form_data.dart';

/// Nhập / xuất hàng hoá bằng tệp CSV — cùng cột, cùng luật với nút "Nâng cao" trên PC
/// (web_Shop ProductController::export / importTemplate / import), để tệp làm ở máy
/// nào cũng dùng được ở máy kia.

const _bom = '﻿';

/// Chọn một tệp CSV trên máy → (tên tệp, nội dung); huỷ thì null.
typedef PickCsv = Future<(String, Uint8List)?> Function();

/// Đưa tệp cho người dùng giữ: bảng chia sẻ trên điện thoại (Lưu vào Tệp, Zalo, Drive…),
/// tải xuống trên web.
typedef SaveFile = Future<void> Function(Uint8List bytes, String name);

final pickCsvProvider = Provider<PickCsv>(
  (_) => () async {
    final f = await FilePicker.pickFile(type: FileType.custom, allowedExtensions: const ['csv', 'txt']);
    return f == null ? null : (f.name, await f.xFile.readAsBytes());
  },
);

final saveFileProvider = Provider<SaveFile>(
  (_) =>
      (bytes, name) => SharePlus.instance.share(
        ShareParams(
          files: [XFile.fromData(bytes, mimeType: 'text/csv', name: name)],
          fileNameOverrides: [name],
        ),
      ),
);

// ---------- CSV ----------

String _cell(Object? v) {
  final s = '${v ?? ''}';
  return s.contains(RegExp(r'[",\r\n]')) ? '"${s.replaceAll('"', '""')}"' : s;
}

/// Có BOM đầu tệp: thiếu nó Excel đọc tiếng Việt thành chữ lỗi.
Uint8List csvBytes(List<List<Object?>> rows) =>
    Uint8List.fromList(utf8.encode('$_bom${rows.map((r) => r.map(_cell).join(',')).join('\r\n')}\r\n'));

/// Đọc CSV (dấu phẩy, ô có ngoặc kép, xuống dòng trong ô). Bỏ BOM, bỏ dòng trống.
List<List<String>> parseCsv(String text) {
  if (text.startsWith(_bom)) text = text.substring(1);
  final rows = <List<String>>[];
  var row = <String>[];
  final cell = StringBuffer();
  var quoted = false;
  void endCell() {
    row.add(cell.toString());
    cell.clear();
  }

  void endRow() {
    endCell();
    if (row.any((c) => c.trim().isNotEmpty)) rows.add(row);
    row = <String>[];
  }

  for (var i = 0; i < text.length; i++) {
    final ch = text[i];
    if (quoted) {
      if (ch == '"' && i + 1 < text.length && text[i + 1] == '"') {
        cell.write('"');
        i++;
      } else if (ch == '"') {
        quoted = false;
      } else {
        cell.write(ch);
      }
    } else if (ch == '"') {
      quoted = true;
    } else if (ch == ',') {
      endCell();
    } else if (ch == '\n' || ch == '\r') {
      if (ch == '\r' && i + 1 < text.length && text[i + 1] == '\n') i++;
      endRow();
    } else {
      cell.write(ch);
    }
  }
  if (cell.isNotEmpty || row.isNotEmpty) endRow();
  return rows;
}

// ---------- Tệp mẫu ----------

const importColumns = [
  'name',
  'category_id',
  'unit_code',
  'location_code',
  'vat',
  'base_price',
  'cost_price',
  'the',
  'bien_the',
];

/// Đúng tệp mẫu của PC: không có cột tồn (hàng nhập vào ở tồn 0), đơn vị / vị trí khai bằng MÃ.
Uint8List importTemplate() => csvBytes([
  importColumns,
  ['iPhone 15 128GB', '4', 'CAI', 'VT001', '10', '22000000', '19500000', 'Bán chạy nhất', ''],
  ['Ốp lưng silicon', '3', 'CAI', '', '8', '120000', '60000', 'Món mới', 'Đen,Trắng,Xanh'],
]);

// ---------- Xuất ----------

const _exportPages = 100;

/// Xuất đúng những mặt hàng đang hiện (theo lọc, theo thứ tự trên màn). Thông tin đầy
/// đủ (ĐVT, vị trí, thẻ, chi nhánh) lấy lại từ /products bản đầy đủ; tồn lấy theo chi
/// nhánh đang xem như trên danh sách. Tải thiếu thì báo lỗi chứ không đưa tệp cắt dở.
Future<Uint8List> exportGoodsCsv(WidgetRef ref, {required List<GoodsItem> rows, required String? stockLabel}) async {
  final api = ref.read(apiClientProvider);
  final branch = ref.read(goodsBranchProvider);
  Future<ApiPage> page(int p) => api.getPage(
    '/products',
    query: {'all': 'true', 'shop_id': ?branch?.toString(), 'page': '$p', 'page_size': '200'},
  );
  Future<List<Map>> list(String path) async {
    try {
      return (await api.get<List<dynamic>>(path, query: {'page_size': '200'})).whereType<Map>().toList();
    } on ApiException {
      return const [];
    }
  }

  final (first, units, locs) = await (page(1), list('/admin/don-vi-tinh'), list('/admin/vi-tri')).wait;
  final pages = [
    first,
    ...await Future.wait([for (var p = 2; p <= math.min(first.totalPages, _exportPages); p++) page(p)]),
  ];
  final full = <int, Map>{
    for (final pg in pages)
      for (final m in pg.data.whereType<Map>()) (m['id'] as num).toInt(): m,
  };
  final missing = rows.where((it) => !full.containsKey(it.id)).length;
  if (missing > 0) {
    throw ApiException(
      message: first.total > _exportPages * 200
          ? 'Có ${first.total} mặt hàng, vượt mức ${_exportPages * 200} mỗi lần xuất. Lọc hẹp lại rồi xuất nhiều đợt.'
          : 'Chưa tải đủ thông tin $missing mặt hàng — thử lại.',
    );
  }
  final unitName = {for (final u in units) (u['id'] as num).toInt(): '${u['name'] ?? ''}'};
  final locName = {
    for (final l in locs) (l['id'] as num).toInt(): '${l['code'] ?? ''} ${l['name'] ?? l['ten'] ?? ''}'.trim(),
  };
  String names(Object? v) => [
    for (final x in (v as List?) ?? const [])
      if (x is Map) '${x['name'] ?? ''}' else '$x',
  ].where((s) => s.isNotEmpty).join(', ');

  return csvBytes([
    [
      'Mã SP',
      'Mã hàng',
      'Tên hàng hóa',
      'Nhóm hàng hóa',
      'ĐVT',
      'Vị trí',
      'VAT',
      'Giá bán',
      'Giá vốn',
      stockLabel == null ? 'Tồn kho' : 'Tồn kho ($stockLabel)',
      'Chi nhánh',
      'Thẻ',
      'Số biến thể',
      'Trạng thái',
    ],
    for (final it in rows)
      if (full[it.id] case final p?)
        [
          'SP${'${it.id}'.padLeft(6, '0')}',
          p['sku'] ?? '',
          p['name'] ?? '',
          (p['category'] as Map?)?['name'] ?? '',
          (p['unit'] as Map?)?['name'] ?? unitName[(p['unit_id'] as num?)?.toInt()] ?? '',
          (p['location'] as Map?) != null
              ? '${p['location']['code'] ?? ''} ${p['location']['name'] ?? ''}'.trim()
              : locName[(p['location_id'] as num?)?.toInt()] ?? '',
          vatName(((p['vat'] as num?) ?? 0).toInt()),
          ((p['base_price'] as num?) ?? 0).toInt(),
          // Ô trống = chưa khai giá vốn — ghi 0 thì người đọc tưởng giá vốn bằng không.
          (p['cost_price'] as num?)?.toInt() ?? '',
          it.stockKnown ? it.stock : '',
          // Ô trống = mọi chi nhánh, như trên PC.
          names(p['shops']),
          names(p['tags']),
          p['is_multi_variant'] == true ? ((p['variants'] as List?) ?? const []).length : 0,
          it.active ? 'Hoạt động' : 'Không hoạt động',
        ],
  ]);
}

// ---------- Nhập ----------

class ImportRow {
  const ImportRow(this.line, this.name, this.body, [this.error]);

  /// Số dòng trong tệp (dòng tiêu đề là 1).
  final int line;
  final String name;

  /// Thân POST /admin/products; null khi dòng sai ngay từ đầu.
  final Map<String, dynamic>? body;
  final String? error;
}

class ImportResult {
  const ImportResult(this.ok, this.errors);

  final int ok;

  /// "Dòng 3 (Áo thun): không có đơn vị tính mã "XX"."
  final List<String> errors;
}

num _num(String s) => num.tryParse(s.replaceAll(RegExp(r'[^\d.]'), '')) ?? 0;

/// Mã hàng kiểu PC khi cửa hàng TẮT quy tắc tự đánh mã: chữ đầu các từ + 4 số ngẫu nhiên
/// (tên bán lẻ trùng nhau rất dễ mà mã hàng thì không được trùng).
String importSku(String name, math.Random rnd) {
  final words = slugify(name).split('-').where((w) => w.isNotEmpty).toList();
  final head = (words.length >= 2 ? words.take(4).map((w) => w[0]).join() : (words.firstOrNull ?? '')).toUpperCase();
  final tail = rnd.nextInt(10000).toString().padLeft(4, '0');
  final h = head.length > 4 ? head.substring(0, 4) : head;
  return h.isEmpty ? 'SP-$tail' : '$h-$tail';
}

/// Đọc tệp thành từng dòng sẵn để gửi. Đơn vị / vị trí tra theo MÃ (không phân biệt hoa
/// thường, tính cả mã đang tắt — người dùng nhìn thấy mã ấy trên màn mà bị từ chối là
/// không hiểu vì sao). Mã lạ thì báo lỗi dòng đó, không lặng lẽ nhập với ô trống.
List<ImportRow> buildImportRows(
  List<List<String>> csv, {
  required Map<String, int> unitByCode,
  required Map<String, int> locByCode,
  required bool autoSku,
  math.Random? rnd,
}) {
  if (csv.isEmpty) return const [];
  final idx = {for (final (i, h) in csv.first.indexed) h.trim().toLowerCase(): i};
  final r = rnd ?? math.Random();
  String get(List<String> row, String k) => idx[k] != null && idx[k]! < row.length ? row[idx[k]!].trim() : '';
  List<String> list(String s) => s.split(RegExp(r'[,;]+')).map((x) => x.trim()).where((x) => x.isNotEmpty).toList();

  final out = <ImportRow>[];
  for (final (i, row) in csv.skip(1).indexed) {
    final line = i + 2;
    final name = get(row, 'name');
    if (name.isEmpty) continue;
    final cat = int.tryParse(get(row, 'category_id')) ?? 0;
    if (cat <= 0) {
      out.add(ImportRow(line, name, null, 'thiếu hoặc sai category_id.'));
      continue;
    }
    final loc = get(row, 'location_code').toUpperCase();
    if (loc.isNotEmpty && !locByCode.containsKey(loc)) {
      out.add(ImportRow(line, name, null, 'không có vị trí mã "$loc".'));
      continue;
    }
    final unit = get(row, 'unit_code').toUpperCase();
    if (unit.isNotEmpty && !unitByCode.containsKey(unit)) {
      out.add(ImportRow(line, name, null, 'không có đơn vị tính mã "$unit".'));
      continue;
    }
    // Quy tắc tự đánh mã đang bật thì để trống cho máy chủ đánh số theo quy tắc.
    final sku = autoSku ? '' : importSku(name, r);
    final cost = get(row, 'cost_price'), vat = get(row, 'vat');
    final variants = [
      for (final (pos, v) in list(get(row, 'bien_the')).indexed)
        {
          'id': 0,
          // Mã cha trống thì mã biến thể cũng để trống — máy chủ ghép sau khi có mã cha.
          'sku': sku.isEmpty ? '' : slugify('$sku-$v').toUpperCase(),
          'barcode': '',
          'name': v,
          'attributes': const [],
          'pos': pos,
          'price': null,
          'cost_price': null,
          'weight_gram': 0,
          'image': '',
          'is_active': true,
        },
    ];
    out.add(
      ImportRow(line, name, {
        'category_id': cat,
        'location_id': locByCode[loc] ?? 0,
        'unit_id': unitByCode[unit] ?? 0,
        'name': name,
        'slug': slugify(name),
        'sku': sku,
        'description': '',
        // Trống = lấy mức thuế mặc định của nhóm hàng (API tự điền).
        'vat': vat.isEmpty ? null : _num(vat).toInt(),
        'base_price': _num(get(row, 'base_price')),
        'cost_price': cost.isEmpty ? null : _num(cost),
        'tags': list(get(row, 'the')),
        'thumbnail': '',
        // Vào ở trạng thái tạm ẩn, tồn 0: kiểm lại rồi mới cho bán, như PC.
        'status': 'hidden',
        'is_multi_variant': variants.isNotEmpty,
        'variants': variants,
      }),
    );
  }
  return out;
}

/// Bảng tra MÃ (viết hoa) → id cho đơn vị tính / vị trí. API lỗi thì rỗng.
Future<(Map<String, int>, Map<String, int>)> importLookups(WidgetRef ref) async {
  final api = ref.read(apiClientProvider);
  Future<Map<String, int>> codes(String path) async {
    try {
      final rows = (await api.get<List<dynamic>>(path, query: {'page_size': '500'})).whereType<Map>();
      return {
        for (final r in rows)
          if ('${r['code'] ?? ''}'.isNotEmpty) '${r['code']}'.toUpperCase(): (r['id'] as num).toInt(),
      };
    } on ApiException {
      return const {};
    }
  }

  return (await codes('/admin/don-vi-tinh'), await codes('/admin/vi-tri'));
}

/// Gửi từng dòng; dòng hỏng ghi lại DÒNG NÀO + VÌ SAO (câu lỗi của API) rồi đi tiếp.
Future<ImportResult> runImport(WidgetRef ref, List<ImportRow> rows, {void Function(int done)? onProgress}) async {
  var ok = 0;
  final errors = <String>[];
  for (final (i, r) in rows.indexed) {
    if (r.body == null) {
      errors.add('Dòng ${r.line} (${r.name}): ${r.error}');
    } else {
      try {
        await createProduct(ref, r.body!, refresh: false);
        ok++;
      } on ApiException catch (e) {
        // 422 chỉ nói "Dữ liệu không hợp lệ" — câu cụ thể nằm ở lỗi từng trường.
        final why = e.fieldErrors.isEmpty ? e.message : e.fieldErrors.values.join('; ');
        errors.add('Dòng ${r.line} (${r.name}): $why');
      }
    }
    onProgress?.call(i + 1);
  }
  if (ok > 0) ref.invalidate(goodsCatalogProvider);
  return ImportResult(ok, errors);
}
