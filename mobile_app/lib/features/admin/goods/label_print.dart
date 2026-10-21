import 'dart:math' as math;

import 'package:barcode/barcode.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_number.dart';
import '../../../core/ui/app_icon.dart';
import '../../auth/auth_controller.dart';
import '../shell/shell_widgets.dart';
import 'goods_data.dart';
import 'goods_widgets.dart';

/// Thiết lập tem lấy từ mẫu in "tem" của cửa hàng (Cài đặt thiết bị → Mẫu in).
class LabelSettings {
  const LabelSettings({
    this.width = 40,
    this.height = 30,
    this.cols = 1,
    this.font = 9,
    this.shopName = true,
    this.price = true,
    this.currency = true,
    this.unit = false,
    this.variant = true,
    this.flip = false,
  });

  /// Khổ tem (mm).
  final double width;
  final double height;

  /// Số tem một hàng trên cuộn.
  final int cols;

  /// Cỡ chữ (pt).
  final double font;
  final bool shopName;
  final bool price;
  final bool currency;
  final bool unit;
  final bool variant;

  /// In ngược 180° (máy ra tem đầu dưới).
  final bool flip;

  String get size => '${_n(width)}x${_n(height)}';

  static String _n(double v) => v == v.roundToDouble() ? '${v.round()}' : '$v';

  LabelSettings copyWith({String? size, int? cols, bool? shopName, bool? price}) {
    final wh = (size ?? this.size).split('x');
    return LabelSettings(
      width: double.tryParse(wh.first) ?? width,
      height: double.tryParse(wh.last) ?? height,
      cols: cols ?? this.cols,
      font: font,
      shopName: shopName ?? this.shopName,
      price: price ?? this.price,
      currency: currency,
      unit: unit,
      variant: variant,
      flip: flip,
    );
  }

  factory LabelSettings.fromTemplate(Map<String, dynamic> d) {
    final st = (d['settings'] ?? d['cai_dat']) as Map? ?? const {};
    final o = d['options'] as Map? ?? const {};
    final wh = '${st['kho_tem'] ?? '40x30'}'.split('x');
    bool on(String k, bool def) => o[k] is bool ? o[k] as bool : def;
    return LabelSettings(
      width: double.tryParse(wh.first) ?? 40,
      height: double.tryParse(wh.last) ?? 30,
      cols: ((st['cot'] as num?) ?? 1).toInt().clamp(1, 3),
      font: ((st['co'] as num?) ?? 9).toDouble().clamp(5, 12),
      shopName: on('ten', true),
      price: on('gia', true),
      currency: on('donvitien', true),
      unit: on('dvt', false),
      variant: on('bienthe', true),
      flip: on('nguoc', false),
    );
  }
}

/// Khổ tem hay dùng cho máy in tem nhiệt (mm) — khổ trong mẫu của cửa hàng luôn đứng đầu.
const labelSizes = ['30x20', '35x22', '40x30', '50x30', '70x50'];

final labelTemplateProvider = FutureProvider.autoDispose<LabelSettings>((ref) async {
  try {
    final d = await ref.read(apiClientProvider).get<Map<String, dynamic>>('/admin/orders/pos/mau-in/tem');
    return LabelSettings.fromTemplate(d);
  } on ApiException {
    return const LabelSettings();
  }
});

/// Một dòng cần in: [qty] tem giống nhau.
class LabelItem {
  const LabelItem({
    required this.name,
    required this.code,
    required this.price,
    this.variant = '',
    this.unit = '',
    this.qty = 1,
  });

  final String name;
  final String variant;

  /// Nội dung mã vạch: mã vạch của phiên bản, chưa có thì SKU.
  final String code;
  final num price;
  final String unit;
  final int qty;

  LabelItem withQty(int q) => LabelItem(name: name, variant: variant, code: code, price: price, unit: unit, qty: q);
}

/// Mã dài hơn mốc này in trên tem nhỏ thì vạch quá dày, máy quét khó đọc.
const labelCodeLong = 20;

/// Code128 chỉ nhận ký tự ASCII — mã có dấu tiếng Việt thì không in được thành vạch.
bool labelCodeOk(String code) => code.isNotEmpty && code.codeUnits.every((c) => c >= 32 && c < 127);

/// Dựng PDF tem: mỗi trang là MỘT HÀNG tem trên cuộn (đúng khổ giấy máy in tem).
Future<Uint8List> buildLabelsPdf(List<LabelItem> items, LabelSettings s, {String shop = ''}) async {
  final regular = pw.Font.ttf(await rootBundle.load('assets/fonts/BeVietnamPro-Regular.ttf'));
  final bold = pw.Font.ttf(await rootBundle.load('assets/fonts/BeVietnamPro-Bold.ttf'));
  final doc = pw.Document(
    theme: pw.ThemeData.withFont(base: regular, bold: bold),
  );
  const gap = 2 * PdfPageFormat.mm;
  final w = s.width * PdfPageFormat.mm, h = s.height * PdfPageFormat.mm;
  final format = PdfPageFormat(w * s.cols + gap * (s.cols - 1), h);
  final labels = [
    for (final it in items)
      for (var i = 0; i < it.qty; i++) it,
  ];
  final f = s.font;

  pw.Widget label(LabelItem it) {
    final body = pw.Container(
      width: w,
      height: h,
      padding: const pw.EdgeInsets.symmetric(horizontal: 1.5 * PdfPageFormat.mm, vertical: 1.2 * PdfPageFormat.mm),
      child: pw.Column(
        crossAxisAlignment: pw.CrossAxisAlignment.stretch,
        children: [
          if (s.shopName && shop.isNotEmpty)
            pw.Text(
              shop,
              maxLines: 1,
              style: pw.TextStyle(fontSize: f * .75, fontWeight: pw.FontWeight.bold),
            ),
          pw.Text(
            it.name,
            maxLines: 2,
            style: pw.TextStyle(fontSize: f, fontWeight: pw.FontWeight.bold),
          ),
          if (s.variant && it.variant.isNotEmpty)
            pw.Text(it.variant, maxLines: 1, style: pw.TextStyle(fontSize: f * .8)),
          pw.SizedBox(height: 1),
          pw.Expanded(
            child: labelCodeOk(it.code)
                ? pw.Column(
                    children: [
                      pw.Expanded(
                        child: pw.BarcodeWidget(
                          // Chừa lề trắng hai bên vạch (vùng yên lặng) — máy quét cần để bắt đầu / kết thúc đọc.
                          padding: const pw.EdgeInsets.symmetric(horizontal: 1.2 * PdfPageFormat.mm),
                          barcode: Barcode.code128(),
                          data: it.code,
                          drawText: false,
                        ),
                      ),
                      // Số dưới vạch tự thu nhỏ cho vừa tem (mã dài sẽ tràn nếu để BarcodeWidget tự vẽ).
                      pw.FittedBox(
                        fit: pw.BoxFit.scaleDown,
                        child: pw.Text(it.code, style: pw.TextStyle(fontSize: f * .75)),
                      ),
                    ],
                  )
                : pw.Center(
                    child: pw.Text(it.code, style: pw.TextStyle(fontSize: f * .8)),
                  ),
          ),
          if (s.price)
            pw.Text(
              '${dot(it.price)}${s.currency ? 'đ' : ''}${s.unit && it.unit.isNotEmpty ? '/${it.unit}' : ''}',
              textAlign: pw.TextAlign.right,
              style: pw.TextStyle(fontSize: f * 1.1, fontWeight: pw.FontWeight.bold),
            ),
        ],
      ),
    );
    return s.flip ? pw.Transform.rotateBox(angle: math.pi, child: body) : body;
  }

  for (var i = 0; i < labels.length; i += s.cols) {
    final row = labels.sublist(i, math.min(i + s.cols, labels.length));
    doc.addPage(
      pw.Page(
        pageFormat: format,
        margin: pw.EdgeInsets.zero,
        build: (_) => pw.Row(
          children: [
            for (final (j, it) in row.indexed) ...[if (j > 0) pw.SizedBox(width: gap), label(it)],
          ],
        ),
      ),
    );
  }
  return doc.save();
}

/// In / chia sẻ PDF — tách ra provider để test thay bằng bản ghi lại.
class LabelPrinter {
  const LabelPrinter();

  Future<void> print(Uint8List pdf, String name, PdfPageFormat format) =>
      Printing.layoutPdf(onLayout: (_) async => pdf, name: name, format: format, dynamicLayout: false);

  Future<void> share(Uint8List pdf, String name) => Printing.sharePdf(bytes: pdf, filename: '$name.pdf');

  /// Xem trước đúng bản PDF sẽ in.
  Widget preview(Future<Uint8List> Function() build, String key) => PdfPreview(
    key: ValueKey(key),
    build: (_) => build(),
    useActions: false,
    canChangePageFormat: false,
    canChangeOrientation: false,
    canDebug: false,
    maxPageWidth: 320,
    pdfPreviewPageDecoration: const BoxDecoration(
      color: Colors.white,
      boxShadow: [BoxShadow(color: Color(0x22000000), blurRadius: 6, offset: Offset(0, 2))],
    ),
    scrollViewDecoration: const BoxDecoration(color: OrderColors.page),
  );
}

final labelPrinterProvider = Provider<LabelPrinter>((_) => const LabelPrinter());

/// Dòng in tem từ chi tiết mặt hàng: mỗi phiên bản đang bán một dòng.
List<LabelItem> labelItemsOf(Map<String, dynamic>? d, [GoodsItem? it]) {
  final unit = '${(d?['unit'] as Map?)?['name'] ?? ''}';
  final name = '${d?['name'] ?? it?.name ?? ''}';
  final vs = [
    for (final v in ((d?['variants'] as List?) ?? const []).whereType<Map>())
      if (v['is_active'] != false) v,
  ];
  if (vs.isEmpty) {
    if (it == null) return const [];
    return [
      for (final r in it.variants) LabelItem(name: name, variant: r.name, code: r.sku, price: r.price, unit: unit),
      if (it.variants.isEmpty) LabelItem(name: name, code: it.sku, price: it.minPrice, unit: unit),
    ];
  }
  String variantName(Map v) {
    final at = ((v['attributes'] as List?) ?? const []).whereType<Map>();
    if (at.isNotEmpty) return at.map((a) => '${(a['value'] as Map?)?['name'] ?? ''}').join(' / ');
    return vs.length > 1 ? '${v['name'] ?? ''}' : '';
  }

  return [
    for (final v in vs)
      LabelItem(
        name: name,
        variant: variantName(v),
        code: '${v['barcode'] ?? ''}'.trim().isNotEmpty ? '${v['barcode']}'.trim() : '${v['sku'] ?? d?['sku'] ?? ''}',
        price: (v['price'] as num?) ?? (d?['base_price'] as num?) ?? it?.minPrice ?? 0,
        unit: unit,
      ),
  ];
}

/// Màn In tem mã vạch: chọn số tem từng phiên bản + khổ tem, xem trước rồi in / chia sẻ.
class LabelPrintScreen extends ConsumerStatefulWidget {
  const LabelPrintScreen({super.key, required this.items});

  final List<LabelItem> items;

  @override
  ConsumerState<LabelPrintScreen> createState() => _LabelPrintScreenState();
}

class _LabelPrintScreenState extends ConsumerState<LabelPrintScreen> {
  late final _qty = [for (final _ in widget.items) 1];
  LabelSettings? _s;
  bool _busy = false;

  List<LabelItem> get _picked => [
    for (final (i, it) in widget.items.indexed)
      if (_qty[i] > 0) it.withQty(_qty[i]),
  ];

  int get _total => _qty.fold(0, (a, b) => a + b);

  String get _shop => ref.read(authProvider).value?.tenant.name ?? '';

  Future<Uint8List> _pdf(LabelSettings s) => buildLabelsPdf(_picked, s, shop: _shop);

  Future<void> _run(LabelSettings s, {required bool share}) async {
    if (_total == 0) return showDashToast(context, 'Chọn ít nhất một tem');
    setState(() => _busy = true);
    try {
      final pdf = await _pdf(s);
      final printer = ref.read(labelPrinterProvider);
      final name = 'tem-${widget.items.first.code}';
      if (share) {
        await printer.share(pdf, name);
      } else {
        await printer.print(
          pdf,
          name,
          PdfPageFormat(
            s.width * PdfPageFormat.mm * s.cols + 2 * PdfPageFormat.mm * (s.cols - 1),
            s.height * PdfPageFormat.mm,
          ),
        );
      }
    } catch (e) {
      if (mounted) showDashToast(context, 'Không in được: $e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final tpl = ref.watch(labelTemplateProvider);
    final s = _s ?? tpl.value;
    final top = MediaQuery.paddingOf(context).top;
    final bad = widget.items.where((it) => !labelCodeOk(it.code)).length;
    final long = widget.items.where((it) => labelCodeOk(it.code) && it.code.length > labelCodeLong).length;
    return Scaffold(
      backgroundColor: OrderColors.page,
      bottomNavigationBar: Container(
        padding: EdgeInsets.fromLTRB(12, 10, 12, MediaQuery.viewPaddingOf(context).bottom + 10),
        decoration: const BoxDecoration(
          color: Colors.white,
          boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, -1))],
        ),
        child: Row(
          children: [
            Expanded(
              child: GoodsBtn(
                key: const Key('label_share'),
                label: 'Chia sẻ PDF',
                icon: AppIcons.share,
                onTap: s == null || _busy ? () {} : () => _run(s, share: true),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: GoodsBtn(
                key: const Key('label_print'),
                label: _busy ? 'Đang chuẩn bị…' : 'In $_total tem',
                icon: AppIcons.print,
                primary: true,
                onTap: s == null || _busy ? () {} : () => _run(s, share: false),
              ),
            ),
          ],
        ),
      ),
      body: Column(
        children: [
          Container(
            padding: EdgeInsets.fromLTRB(8, top + 4, 8, 8),
            decoration: const BoxDecoration(
              color: Colors.white,
              boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, 1))],
            ),
            child: Row(
              children: [
                IconButton(
                  tooltip: 'Quay lại',
                  onPressed: () => Navigator.of(context).maybePop(),
                  icon: const AppIcon(AppIcons.back, size: 22, color: OrderColors.ink),
                ),
                const Expanded(
                  child: Text(
                    'In tem mã vạch',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: OrderColors.ink, fontSize: 16.5, fontWeight: FontWeight.w700),
                  ),
                ),
                const SizedBox(width: 48),
              ],
            ),
          ),
          Expanded(
            child: s == null
                ? const Center(child: CircularProgressIndicator(color: OrderColors.primary))
                : ListView(
                    padding: const EdgeInsets.only(bottom: 12),
                    children: [
                      Container(
                        color: Colors.white,
                        padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            const Text(
                              'Số tem',
                              style: TextStyle(color: OrderColors.ink, fontSize: 14.5, fontWeight: FontWeight.w700),
                            ),
                            for (final (i, it) in widget.items.indexed)
                              Container(
                                key: Key('label_row_$i'),
                                padding: const EdgeInsets.symmetric(vertical: 8),
                                decoration: i == 0
                                    ? null
                                    : const BoxDecoration(
                                        border: Border(top: BorderSide(color: OrderColors.line)),
                                      ),
                                child: Row(
                                  children: [
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Text(
                                            it.variant.isEmpty ? it.name : it.variant,
                                            style: const TextStyle(
                                              color: OrderColors.ink,
                                              fontSize: 13.5,
                                              fontWeight: FontWeight.w600,
                                            ),
                                          ),
                                          Text(
                                            '${it.code} · ${dot(it.price)}đ',
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                            style: TextStyle(
                                              color: labelCodeOk(it.code) ? OrderColors.muted : OrderColors.red.$2,
                                              fontSize: 12,
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                    _Stepper(
                                      key: Key('label_qty_$i'),
                                      value: _qty[i],
                                      onChanged: (v) => setState(() => _qty[i] = v),
                                    ),
                                  ],
                                ),
                              ),
                            if (long > 0)
                              Padding(
                                key: const Key('label_long_warn'),
                                padding: const EdgeInsets.only(bottom: 8),
                                child: Text(
                                  'Phiên bản chưa có mã vạch nên tem in theo SKU — SKU dài (trên $labelCodeLong ký tự) '
                                  'làm vạch rất dày, máy quét có thể khó đọc. Nên khai mã vạch ngắn ở form Sửa sản phẩm.',
                                  style: TextStyle(color: OrderColors.orange.$2, fontSize: 12, height: 1.45),
                                ),
                              ),
                            if (bad > 0)
                              Padding(
                                padding: const EdgeInsets.only(bottom: 8),
                                child: Text(
                                  'Mã có dấu hoặc ký tự đặc biệt không in được thành vạch — tem sẽ in chữ. '
                                  'Nhập mã vạch cho phiên bản ở form Sửa sản phẩm.',
                                  style: TextStyle(color: OrderColors.red.$2, fontSize: 12, height: 1.45),
                                ),
                              ),
                          ],
                        ),
                      ),
                      Container(
                        margin: const EdgeInsets.only(top: 8),
                        color: Colors.white,
                        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text(
                              'Khổ tem (mm)',
                              style: TextStyle(color: OrderColors.muted, fontSize: 12.5, fontWeight: FontWeight.w600),
                            ),
                            const SizedBox(height: 8),
                            Wrap(
                              spacing: 6,
                              runSpacing: 6,
                              children: [
                                for (final z in {s.size, ...labelSizes})
                                  GoodsChip(
                                    key: Key('label_size_$z'),
                                    label: z.replaceAll('x', ' × '),
                                    on: z == s.size,
                                    onTap: () => setState(() => _s = (_s ?? s).copyWith(size: z)),
                                  ),
                              ],
                            ),
                            const SizedBox(height: 12),
                            const Text(
                              'Số tem một hàng',
                              style: TextStyle(color: OrderColors.muted, fontSize: 12.5, fontWeight: FontWeight.w600),
                            ),
                            const SizedBox(height: 8),
                            Wrap(
                              spacing: 6,
                              children: [
                                for (final c in [1, 2, 3])
                                  GoodsChip(
                                    key: Key('label_cols_$c'),
                                    label: '$c',
                                    on: c == s.cols,
                                    onTap: () => setState(() => _s = (_s ?? s).copyWith(cols: c)),
                                  ),
                              ],
                            ),
                            const SizedBox(height: 12),
                            Wrap(
                              spacing: 6,
                              children: [
                                GoodsChip(
                                  key: const Key('label_opt_price'),
                                  label: 'Giá bán',
                                  on: s.price,
                                  onTap: () => setState(() => _s = (_s ?? s).copyWith(price: !(_s ?? s).price)),
                                ),
                                GoodsChip(
                                  key: const Key('label_opt_shop'),
                                  label: 'Tên cửa hàng',
                                  on: s.shopName,
                                  onTap: () => setState(() => _s = (_s ?? s).copyWith(shopName: !(_s ?? s).shopName)),
                                ),
                              ],
                            ),
                            const SizedBox(height: 8),
                            const Text(
                              'Mặc định theo mẫu "Tem" ở Cài đặt thiết bị. In qua máy in mạng / USB bằng nút In; '
                              'máy in tem Bluetooth thì Chia sẻ PDF sang app của máy in.',
                              style: TextStyle(color: OrderColors.faint, fontSize: 12, height: 1.45),
                            ),
                          ],
                        ),
                      ),
                      Padding(
                        padding: const EdgeInsets.fromLTRB(16, 14, 16, 6),
                        child: Text(
                          'Xem trước · ${dot(_total)} tem',
                          style: const TextStyle(color: OrderColors.muted, fontSize: 12.5, fontWeight: FontWeight.w600),
                        ),
                      ),
                      if (_total > 0)
                        SizedBox(
                          height: 260,
                          child: ref
                              .read(labelPrinterProvider)
                              .preview(() => _pdf(s), '${s.size}-${s.cols}-${s.price}-${s.shopName}-${_qty.join(',')}'),
                        ),
                    ],
                  ),
          ),
        ],
      ),
    );
  }
}

class _Stepper extends StatelessWidget {
  const _Stepper({super.key, required this.value, required this.onChanged});

  final int value;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    Widget btn(AppIcons i, String tip, int next) => IconButton(
      tooltip: tip,
      onPressed: next < 0 || next > 999 ? null : () => onChanged(next),
      style: IconButton.styleFrom(
        backgroundColor: OrderColors.grid,
        minimumSize: const Size(34, 34),
        fixedSize: const Size(34, 34),
        padding: EdgeInsets.zero,
      ),
      icon: AppIcon(i, size: 16, color: OrderColors.ink),
    );
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        btn(AppIcons.minus, 'Bớt', value - 1),
        SizedBox(
          width: 40,
          child: Text(
            '$value',
            textAlign: TextAlign.center,
            style: const TextStyle(
              color: OrderColors.ink,
              fontSize: 15,
              fontWeight: FontWeight.w700,
              fontFeatures: tnum,
            ),
          ),
        ),
        btn(AppIcons.plus, 'Thêm', value + 1),
      ],
    );
  }
}
