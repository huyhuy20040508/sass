import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_number.dart';
import '../../../core/ui/app_icon.dart';
import '../../workspace/workspace_widgets.dart' show SheetButton;
import '../shell/shell_widgets.dart';
import '../../auth/permissions.dart';
import 'goods_data.dart';
import 'goods_widgets.dart';
import 'label_print.dart';
import 'product_form_data.dart';
import 'product_form_screen.dart';

String _dmy(DateTime t) => '${t.day.toString().padLeft(2, '0')}/${t.month.toString().padLeft(2, '0')}/${t.year}';

const _statusLabel = {'active': 'Đang bán', 'hidden': 'Tạm ẩn', 'discontinued': 'Ngừng kinh doanh'};

List<Map> _variantsOf(Map? d) => ((d?['variants'] as List?) ?? const []).whereType<Map>().toList();

List<Map> _attrsOf(Map v) => ((v['attributes'] as List?) ?? const []).whereType<Map>().toList();

/// Hàng nhiều phiên bản = có phiên bản mang tổ hợp thuộc tính (như PC).
bool _isMulti(Map? d) => _variantsOf(d).any((v) => _attrsOf(v).isNotEmpty);

Map? _defaultVariant(Map? d) {
  final vs = _variantsOf(d);
  return vs.where((v) => v['is_default'] == true).firstOrNull ?? (_isMulti(d) ? null : vs.firstOrNull);
}

String _valueName(Map a) => '${a['value'] is Map ? (a['value'] as Map)['name'] : a['value'] ?? ''}';

String _attrName(Map a) => '${a['attribute'] is Map ? (a['attribute'] as Map)['name'] : a['name'] ?? ''}';

/// Tên phiên bản theo giá trị thuộc tính "Đen / 128GB" (như PC); không có thuộc tính thì tên đang lưu.
String _variantName(Map v, String product) {
  final at = _attrsOf(v);
  if (at.isNotEmpty) return at.map(_valueName).join(' / ');
  final n = '${v['name'] ?? ''}';
  return n.isEmpty ? product : n;
}

/// Chi tiết sản phẩm theo design/html/hang-hoa-mobile.html (v9) — cùng nội dung hộp
/// "xem" của PC: ảnh + giá, thao tác nhanh, tồn theo chi nhánh, thuộc tính + phiên bản,
/// thông tin (tab Chi tiết của PC), bốn công tắc bán hàng.
class ProductDetailScreen extends ConsumerWidget {
  const ProductDetailScreen({super.key, required this.item});

  /// Dòng đã có ở danh sách — dựng ngay phần đầu, chờ API cho phần còn lại.
  final GoodsItem item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Đổi trạng thái xong danh mục tải lại → đọc bản mới của chính mặt hàng này.
    final cat = ref.watch(goodsCatalogProvider).value;
    final it = cat?.byId(item.id) ?? item;
    final detail = ref.watch(productDetailProvider(it.id));
    final branches = ref.watch(productBranchStockProvider((it.id, it.sku)));
    final src = ref.watch(productFormSourcesProvider).value ?? const FormSources();
    final d = detail.value;
    final perms = permsOf(ref);

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.dark,
      child: Scaffold(
        backgroundColor: OrderColors.page,
        // Không có quyền sửa thì không có chân màn.
        bottomNavigationBar: !perms.can(Quyen.suaSanPham)
            ? null
            : Container(
                padding: EdgeInsets.fromLTRB(12, 10, 12, MediaQuery.viewPaddingOf(context).bottom + 10),
                decoration: const BoxDecoration(
                  color: Colors.white,
                  boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, -1))],
                ),
                child: GoodsBtn(
                  key: const Key('product_edit'),
                  label: 'Sửa sản phẩm',
                  icon: AppIcons.edit,
                  primary: true,
                  onTap: () => _edit(context, ref, d),
                ),
              ),
        body: Column(
          children: [
            const _Head(),
            Expanded(
              child: RefreshIndicator(
                color: OrderColors.primary,
                onRefresh: () async {
                  ref.invalidate(productDetailProvider(it.id));
                  ref.invalidate(productBranchStockProvider((it.id, it.sku)));
                  ref.invalidate(goodsCatalogProvider);
                  try {
                    await ref.read(productDetailProvider(it.id).future);
                  } on ApiException catch (e) {
                    if (context.mounted) showDashToast(context, e.message);
                  }
                },
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.only(bottom: 12),
                  children: [
                    _hero(context, ref, it, d, perms),
                    _quick(context, ref, it, perms),
                    _Block(title: 'Tồn kho', child: _stock(branches)),
                    if (_isMulti(d)) _attributes(it, d!),
                    _Block(
                      title: 'Thông tin',
                      child: d == null ? _wait(detail) : _info(it, d, cat?.categories ?? const [], src),
                    ),
                    if (d != null) _flags(it, d),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _labels(BuildContext context, WidgetRef ref, GoodsItem it) async {
    final d = ref.read(productDetailProvider(it.id)).value;
    final items = labelItemsOf(d, it);
    if (items.isEmpty) return showDashToast(context, 'Đang tải chi tiết sản phẩm, thử lại sau giây lát');
    await Navigator.of(
      context,
      rootNavigator: true,
    ).push<void>(MaterialPageRoute(builder: (_) => LabelPrintScreen(items: items)));
  }

  Future<void> _edit(BuildContext context, WidgetRef ref, Map<String, dynamic>? d) async {
    if (d == null) return showDashToast(context, 'Đang tải chi tiết sản phẩm, thử lại sau giây lát');
    final r = await Navigator.of(
      context,
      rootNavigator: true,
    ).push<Object?>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => ProductFormScreen(detail: d)));
    // Xoá xong thì chi tiết không còn gì để xem — về danh sách.
    if (r == 'deleted' && context.mounted) Navigator.of(context).pop();
  }

  Widget _wait(AsyncValue<Map<String, dynamic>> detail) {
    if (detail.hasError) {
      final e = detail.error;
      return Text(
        e is ApiException ? e.message : 'Không tải được chi tiết sản phẩm.',
        style: const TextStyle(color: OrderColors.muted, fontSize: 13),
      );
    }
    return const _Loading();
  }

  Widget _hero(BuildContext context, WidgetRef ref, GoodsItem it, Map<String, dynamic>? d, Perms perms) {
    final multi = _isMulti(d);
    final n = _variantsOf(d).length;
    final status = '${d?['status'] ?? it.j['status'] ?? (it.active ? 'active' : 'hidden')}';
    // Giá bán = giá gốc của mặt hàng (như ô Giá bán trên PC); chưa tải chi tiết thì tạm theo danh sách.
    final base = (d?['base_price'] as num?) ?? it.minPrice;
    // Lãi tính trên phiên bản mặc định (hàng nhiều phiên bản) hoặc giá gốc.
    final def = _defaultVariant(d);
    final gv = multi ? def ?? _variantsOf(d).firstOrNull : null;
    final gp = gv == null ? base : ((gv['price'] as num?) ?? 0);
    final gc = gv == null ? it.cost : gv['cost_price'] as num?;
    final gain = gc == null || gp <= 0 ? null : gp - gc;
    return _Block(
      first: true,
      // Lưới giá tràn hết bề ngang khối nên khối này tự lo phần đệm.
      flush: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                GoodsImage(url: '${d?['thumbnail'] ?? it.thumb}', size: 104, radius: 14),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${d?['name'] ?? it.name}',
                        style: const TextStyle(
                          color: OrderColors.ink,
                          fontSize: 18,
                          fontWeight: FontWeight.w700,
                          height: 1.35,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'SKU: ${d?['sku'] ?? it.sku}',
                        style: const TextStyle(color: OrderColors.muted, fontSize: 12.5),
                      ),
                      const SizedBox(height: 8),
                      Wrap(
                        spacing: 5,
                        runSpacing: 5,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          _StatusDot(label: _statusLabel[status] ?? 'Đang bán', on: status == 'active'),
                          if (d?['is_serial'] == true || (d == null && it.isSerial))
                            const SmallTag('IMEI', OrderColors.purple),
                          if (multi) SmallTag('$n phiên bản', (OrderColors.primarySoft, OrderColors.primaryInk)),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          Container(
            decoration: const BoxDecoration(
              border: Border(top: BorderSide(color: OrderColors.line)),
            ),
            child: IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(
                    child: _PriceCell(
                      key: const Key('edit_price'),
                      label: 'Giá bán',
                      value: dot(base),
                      color: OrderColors.primaryInk,
                      onTap: perms.can(Quyen.suaSanPham) ? () => _editMoney(context, ref, it, d, price: true) : null,
                    ),
                  ),
                  const VerticalDivider(width: 1, thickness: 1, color: OrderColors.line),
                  Expanded(
                    child: _PriceCell(
                      key: const Key('edit_cost'),
                      label: 'Giá vốn',
                      value: it.cost == null ? null : dot(it.cost!),
                      onTap: perms.can(Quyen.giaVon) ? () => _editMoney(context, ref, it, d, price: false) : null,
                    ),
                  ),
                  const VerticalDivider(width: 1, thickness: 1, color: OrderColors.line),
                  Expanded(
                    child: _PriceCell(
                      key: const Key('gain_cell'),
                      label: 'Lãi / sp',
                      value: gain == null ? null : dot(gain),
                      color: gain != null && gain < 0 ? OrderColors.red.$2 : OrderColors.green.$2,
                      note: gain == null ? null : pct(gain / gp * 100),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Thao tác nhanh: 4 nút tròn có nhãn như app Sapo.
  Widget _quick(BuildContext context, WidgetRef ref, GoodsItem it, Perms perms) {
    Widget btn(String label, AppIcons icon, VoidCallback onTap, {bool danger = false, Key? key}) => Expanded(
      child: InkWell(
        key: key,
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Column(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: danger ? OrderColors.red.$1 : OrderColors.primarySoft,
                  shape: BoxShape.circle,
                ),
                child: Center(
                  child: AppIcon(icon, size: 21, color: danger ? OrderColors.red.$2 : OrderColors.primaryInk),
                ),
              ),
              const SizedBox(height: 6),
              Text(
                label,
                maxLines: 1,
                style: const TextStyle(color: OrderColors.text, fontSize: 12, fontWeight: FontWeight.w500),
              ),
            ],
          ),
        ),
      ),
    );
    return Container(
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 12),
      color: Colors.white,
      child: Row(
        children: [
          btn('Nhập hàng', AppIcons.truck, () => showDashToast(context, 'Tạo phiếu nhập trên mobile — sắp có')),
          btn('In mã vạch', AppIcons.barcode, () => _labels(context, ref, it), key: const Key('product_labels')),
          if (perms.can(Quyen.themSanPham))
            btn(
              'Sao chép',
              AppIcons.copy,
              () => _confirm(context, ref, it, copy: true),
              key: const Key('product_duplicate'),
            ),
          if (perms.can(Quyen.suaSanPham))
            btn(
              it.active ? 'Ngừng bán' : 'Mở bán',
              it.active ? AppIcons.eyeOff : AppIcons.eye,
              () => _confirm(context, ref, it, copy: false),
              danger: true,
              key: const Key('product_toggle'),
            ),
        ],
      ),
    );
  }

  /// Sửa nhanh: giá bán = giá gốc (PUT cả mặt hàng); giá vốn = phiên bản mặc định
  /// (PUT /admin/inventory/cost). Phiên bản khác sửa ở form Sửa sản phẩm.
  Future<void> _editMoney(
    BuildContext context,
    WidgetRef ref,
    GoodsItem it,
    Map<String, dynamic>? d, {
    required bool price,
  }) async {
    final multi = _isMulti(d);
    final def = _defaultVariant(d) ?? _variantsOf(d).firstOrNull;
    final row = it.variants.firstOrNull;
    final vid = (def?['id'] as num?)?.toInt() ?? row?.variantId;
    // Giá bán phải gửi lại cả mặt hàng — chờ chi tiết tải xong mới sửa được.
    if (d == null && (price || vid == null)) {
      return showDashToast(context, 'Đang tải chi tiết sản phẩm, thử lại sau giây lát');
    }
    final base = (d?['base_price'] as num?) ?? it.minPrice;
    final curCost = def != null ? def['cost_price'] as num? : row?.cost;
    final gp = multi ? ((def?['price'] as num?) ?? base) : base;
    final n = await showModalBottomSheet<num>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      backgroundColor: Colors.white,
      barrierColor: const Color(0x73111827),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (_) => _MoneySheet(
        price: price,
        initial: price ? base : curCost,
        otherPrice: gp,
        otherCost: curCost,
        note: !multi
            ? null
            : price
            ? const TextSpan(
                text: 'Đây là giá gốc (như ô Giá bán trên PC). Giá riêng từng phiên bản giữ nguyên — sửa ở form Sửa sản phẩm.',
              )
            : TextSpan(
                text: 'Áp cho phiên bản ',
                children: [
                  TextSpan(
                    text: def == null ? '' : _variantName(def, it.name),
                    style: const TextStyle(color: OrderColors.ink, fontWeight: FontWeight.w700),
                  ),
                  const TextSpan(text: '. Phiên bản khác sửa ở form Sửa sản phẩm.'),
                ],
              ),
      ),
    );
    if (n == null || !context.mounted) return;
    try {
      if (price) {
        await setBasePrice(ref, d!, n);
      } else {
        await setVariantCost(ref, it.id, vid!, n);
      }
      if (context.mounted) showDashToast(context, price ? 'Đã lưu giá bán ${dot(n)} đ' : 'Đã lưu giá vốn ${dot(n)} đ');
    } on ApiException catch (e) {
      if (context.mounted) showDashToast(context, e.message);
    }
  }

  Widget _stock(AsyncValue<List<(String, num)>> branches) {
    if (branches.hasError && !branches.hasValue) {
      final e = branches.error;
      return Text(
        e is ApiException ? e.message : 'Không tải được tồn theo chi nhánh.',
        style: const TextStyle(color: OrderColors.muted, fontSize: 13),
      );
    }
    final rows = branches.value;
    if (rows == null) return const _Loading();
    final total = rows.fold<num>(0, (a, r) => a + r.$2);
    const head = TextStyle(color: OrderColors.muted, fontSize: 12, fontWeight: FontWeight.w500);
    TableRow line(String name, num q, {bool sum = false}) {
      final st = TextStyle(
        color: OrderColors.ink,
        fontSize: 13.5,
        fontWeight: sum ? FontWeight.w700 : FontWeight.w400,
        fontFeatures: tnum,
      );
      Widget cell(String t, {bool left = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Text(
          t,
          textAlign: left ? TextAlign.left : TextAlign.right,
          style: left && !sum ? st.copyWith(color: OrderColors.text) : st,
        ),
      );
      return TableRow(
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: OrderColors.line)),
        ),
        children: [cell(name, left: true), cell(dot(q)), cell(dot(q))],
      );
    }

    return Table(
      columnWidths: const {0: FlexColumnWidth(1.6), 1: FlexColumnWidth(), 2: FlexColumnWidth()},
      children: [
        const TableRow(
          children: [
            Padding(
              padding: EdgeInsets.only(bottom: 8),
              child: Text('Chi nhánh', style: head),
            ),
            Padding(
              padding: EdgeInsets.only(bottom: 8),
              child: Text('Tồn kho', textAlign: TextAlign.right, style: head),
            ),
            Padding(
              padding: EdgeInsets.only(bottom: 8),
              child: Text('Có thể bán', textAlign: TextAlign.right, style: head),
            ),
          ],
        ),
        if (rows.isEmpty) line('Chưa có tồn', 0),
        for (final (name, q) in rows) line(name, q),
        if (rows.length > 1) line('Tổng', total, sum: true),
      ],
    );
  }

  /// Tab "Thuộc tính" của PC: mỗi thuộc tính một dòng (giá trị mặc định đánh dấu), dưới là các phiên bản.
  Widget _attributes(GoodsItem it, Map<String, dynamic> d) {
    final vs = _variantsOf(d);
    final def = _defaultVariant(d);
    final defVals = {for (final a in _attrsOf(def ?? const {})) (a['value_id'] as num).toInt()};
    final attrs = <int, (String, List<(int, String)>)>{};
    for (final v in vs) {
      for (final a in _attrsOf(v)) {
        final aid = (a['attribute_id'] as num).toInt(), vid = (a['value_id'] as num).toInt();
        final x = attrs.putIfAbsent(aid, () => (_attrName(a), []));
        if (!x.$2.any((e) => e.$1 == vid)) x.$2.add((vid, _valueName(a)));
      }
    }
    return _Block(
      titleWidget: Text.rich(
        TextSpan(
          text: 'Thuộc tính',
          children: [
            TextSpan(
              text: '  ${vs.length} phiên bản',
              style: const TextStyle(color: OrderColors.muted, fontSize: 12.5, fontWeight: FontWeight.w500),
            ),
          ],
        ),
        style: const TextStyle(color: OrderColors.ink, fontSize: 14.5, fontWeight: FontWeight.w700),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final (i, (name, vals)) in attrs.values.indexed)
            Container(
              padding: EdgeInsets.only(top: i == 0 ? 0 : 8, bottom: 8),
              decoration: i == 0
                  ? null
                  : const BoxDecoration(
                      border: Border(top: BorderSide(color: OrderColors.line)),
                    ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: 110,
                    child: Padding(
                      padding: const EdgeInsets.only(top: 1),
                      child: Text(name, style: const TextStyle(color: OrderColors.muted, fontSize: 13)),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Wrap(
                      spacing: 4,
                      runSpacing: 4,
                      children: [
                        for (final (vid, vn) in vals)
                          defVals.contains(vid) ? _CheckTag(vn) : SmallTag(vn, (OrderColors.grid, OrderColors.muted)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          Container(
            margin: const EdgeInsets.only(top: 4),
            decoration: const BoxDecoration(
              border: Border(top: BorderSide(color: OrderColors.line)),
            ),
            child: Column(
              children: [
                for (final (i, v) in vs.indexed)
                  Container(
                    key: Key('variant_${v['id']}'),
                    padding: const EdgeInsets.symmetric(vertical: 10),
                    decoration: i == 0
                        ? null
                        : const BoxDecoration(
                            border: Border(top: BorderSide(color: OrderColors.line)),
                          ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        GoodsImage(
                          url: '${v['image'] ?? ''}'.isEmpty ? '${d['thumbnail'] ?? it.thumb}' : '${v['image']}',
                          size: 44,
                          radius: 8,
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text.rich(
                                TextSpan(
                                  text: _variantName(v, it.name),
                                  children: [
                                    if (v['is_default'] == true)
                                      const WidgetSpan(
                                        alignment: PlaceholderAlignment.middle,
                                        child: Padding(
                                          padding: EdgeInsets.only(left: 4),
                                          child: SmallTag('Mặc định', (
                                            OrderColors.primarySoft,
                                            OrderColors.primaryInk,
                                          )),
                                        ),
                                      ),
                                  ],
                                ),
                                style: const TextStyle(
                                  color: OrderColors.ink,
                                  fontSize: 13.5,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              Text(
                                [
                                  '${v['sku'] ?? ''}',
                                  if ('${v['barcode'] ?? ''}'.isNotEmpty) '${v['barcode']}',
                                ].join(' · '),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(color: OrderColors.muted, fontSize: 12),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 12),
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.end,
                          children: [
                            Text(
                              dot((v['price'] as num?) ?? 0),
                              style: const TextStyle(
                                color: OrderColors.ink,
                                fontSize: 13.5,
                                fontWeight: FontWeight.w700,
                                fontFeatures: tnum,
                              ),
                            ),
                            Text(
                              'Vốn ${v['cost_price'] == null ? '—' : dot(v['cost_price'] as num)}'
                              ' · Tồn ${dot((v['stock_quantity'] as num?) ?? 0)}',
                              style: const TextStyle(color: OrderColors.muted, fontSize: 12, fontFeatures: tnum),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Tab "Chi tiết" của PC — đủ ô, đúng thứ tự; ô trống ghi rõ là trống chứ không giấu đi.
  Widget _info(GoodsItem it, Map<String, dynamic> d, List<GoodsCategory> cats, FormSources src) {
    final multi = _isMulti(d);
    final def = _defaultVariant(d);
    final vat = ((d['vat'] as num?) ?? 0).toInt();
    final base = (d['base_price'] as num?) ?? 0;
    final unit = '${(d['unit'] as Map?)?['name'] ?? ''}';
    String unitName(int id) => src.units.where((u) => u.$1 == id).firstOrNull?.$2 ?? '#$id';
    final convs = ((d['unit_conversions'] as List?) ?? const []).whereType<Map>().toList();
    final catId = ((d['category_id'] ?? (d['category'] as Map?)?['id']) as num?)?.toInt();
    final c = cats.where((x) => x.id == catId).firstOrNull;
    final catText = c != null
        ? [...categoryParents(c, cats), c.name].join(' › ')
        : '${(d['category'] as Map?)?['name'] ?? it.categoryName}';
    final tax = src.taxes.where((t) => t.levels.contains(vat)).firstOrNull?.name ?? '—';
    final shops = ((d['shops'] as List?) ?? const []).whereType<Map>().map((s) => '${s['name']}').toList();
    final tags = [for (final t in ((d['tags'] as List?) ?? const [])) t is Map ? '${t['name']}' : '$t'];
    final desc = '${d['description'] ?? ''}'.trim();
    final barcode = '${def?['barcode'] ?? ''}';
    final loc = (d['location'] as Map?)?['name'];

    Widget none(String t) => Text(
      t,
      textAlign: TextAlign.right,
      style: const TextStyle(color: OrderColors.faint),
    );
    Widget val(String t) => Text(
      t,
      textAlign: TextAlign.right,
      style: const TextStyle(color: OrderColors.ink, fontWeight: FontWeight.w500, fontFeatures: tnum),
    );
    final rows = <(String, Widget)>[
      ('Mã hàng', val('${d['sku'] ?? it.sku}')),
      ('Mã vạch', barcode.isNotEmpty ? val(barcode) : none(multi ? 'Theo từng phiên bản' : 'Chưa có')),
      ('Nhóm hàng', catText.isEmpty ? none('—') : val(catText)),
      ('Đơn vị tính', unit.isEmpty ? none('Chưa chọn') : val(unit)),
      if (convs.isNotEmpty)
        (
          'Quy đổi đơn vị',
          val(
            convs
                .map((q) => '1 ${unitName((q['unit_id'] as num).toInt())} = ${dot(q['quantity'] as num)} $unit')
                .join('\n'),
          ),
        ),
      ('Loại thuế', val(tax)),
      ('% VAT', val(vatName(vat))),
      ('Giá sau thuế', val(dot(vat > 0 ? (base * (100 + vat) / 100).round() : base))),
      ('Vị trí', loc == null ? none('Chưa xếp kệ') : val('$loc')),
      ('Chi nhánh bán', val(shops.isEmpty ? 'Tất cả chi nhánh' : shops.join(', '))),
      (
        'Thẻ hàng hoá',
        tags.isEmpty
            ? none('Chưa gắn')
            : Wrap(
                alignment: WrapAlignment.end,
                spacing: 4,
                runSpacing: 4,
                children: [for (final t in tags) SmallTag(t, (OrderColors.grid, OrderColors.muted))],
              ),
      ),
    ];
    return DefaultTextStyle.merge(
      style: const TextStyle(fontSize: 13.5),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final (i, (k, v)) in rows.indexed)
            Container(
              key: Key('kv_$k'),
              padding: EdgeInsets.only(top: i == 0 ? 0 : 9, bottom: 9),
              decoration: i == 0
                  ? null
                  : const BoxDecoration(
                      border: Border(top: BorderSide(color: OrderColors.line)),
                    ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(k, style: const TextStyle(color: OrderColors.muted)),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Align(alignment: Alignment.topRight, child: v),
                  ),
                ],
              ),
            ),
          // Mô tả dài: nhãn trên, nội dung dưới.
          Container(
            key: const Key('kv_Mô tả'),
            padding: const EdgeInsets.only(top: 9),
            decoration: const BoxDecoration(
              border: Border(top: BorderSide(color: OrderColors.line)),
            ),
            child: desc.isEmpty
                ? Row(
                    children: [
                      const Text('Mô tả', style: TextStyle(color: OrderColors.muted)),
                      const Spacer(),
                      none('Chưa có'),
                    ],
                  )
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Mô tả', style: TextStyle(color: OrderColors.muted)),
                      const SizedBox(height: 4),
                      Text(desc, style: const TextStyle(color: OrderColors.ink, height: 1.45)),
                    ],
                  ),
          ),
        ],
      ),
    );
  }

  /// Bốn công tắc ở cột ảnh của form PC + dòng đã bán / ngày tạo / ngày sửa.
  Widget _flags(GoodsItem it, Map<String, dynamic> d) {
    DateTime? t(Object? v) => v == null ? null : DateTime.tryParse('$v')?.toLocal();
    final created = t(d['created_at']) ?? it.createdAt, updated = t(d['updated_at']);
    Widget flag(bool on, String label) => Container(
      key: Key('flag_${label}_${on ? 'on' : 'off'}'),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
      decoration: BoxDecoration(
        color: on ? const Color(0xFFE9F7EF) : OrderColors.grid,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          AppIcon(on ? AppIcons.check : AppIcons.close, size: 16, color: on ? OrderColors.green.$2 : OrderColors.faint),
          const SizedBox(width: 8),
          Flexible(
            child: Text(
              label,
              style: TextStyle(
                color: on ? OrderColors.green.$2 : OrderColors.faint,
                fontSize: 13,
                fontWeight: on ? FontWeight.w600 : FontWeight.w400,
              ),
            ),
          ),
        ],
      ),
    );
    return _Block(
      title: 'Bán hàng',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: flag(d['status'] == 'active', 'Đang bán')),
              const SizedBox(width: 8),
              Expanded(child: flag(d['print_label'] == true, 'In tem')),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(child: flag(d['is_stock_deducted'] != false, 'Trừ kho')),
              const SizedBox(width: 8),
              Expanded(child: flag(d['is_serial'] == true, 'Serial / IMEI')),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            [
              'Đã bán ${dot((d['sold_count'] as num?) ?? it.sold)}',
              'Tạo ${_dmy(created)}',
              if (updated != null) 'Sửa ${_dmy(updated)}',
            ].join(' · '),
            key: const Key('product_meta'),
            style: const TextStyle(color: OrderColors.faint, fontSize: 12),
          ),
        ],
      ),
    );
  }

  Future<void> _confirm(BuildContext context, WidgetRef ref, GoodsItem it, {required bool copy}) async {
    final ok = await showDashSheet<bool>(
      context,
      title: copy
          ? 'Sao chép sản phẩm?'
          : it.active
          ? 'Ngừng bán sản phẩm?'
          : 'Mở bán lại?',
      subtitle: copy
          ? 'Tạo một sản phẩm mới giống hệt, để ở trạng thái ẩn để bạn sửa rồi mới bán.'
          : it.active
          ? 'Sản phẩm không còn hiện ở quầy và web bán hàng. Tồn kho giữ nguyên.'
          : 'Sản phẩm hiện lại ở quầy và web bán hàng.',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SheetButton(
            key: const Key('product_confirm'),
            label: copy
                ? 'Sao chép'
                : it.active
                ? 'Ngừng bán'
                : 'Mở bán',
            icon: copy
                ? AppIcons.copy
                : it.active
                ? AppIcons.eyeOff
                : AppIcons.eye,
            danger: !copy && it.active,
            onTap: () => Navigator.of(ctx).pop(true),
          ),
          SheetButton(label: 'Thôi', onTap: () => Navigator.of(ctx).pop(false)),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      if (copy) {
        await duplicateProduct(ref, it.id);
      } else {
        await setProductActive(ref, it.id, !it.active);
      }
      if (context.mounted) {
        showDashToast(
          context,
          copy
              ? 'Đã tạo bản sao "${it.name} (Bản sao)" — đang ẩn'
              : it.active
              ? 'Đã ngừng bán ${it.name}'
              : 'Đã mở bán lại ${it.name}',
        );
      }
    } on ApiException catch (e) {
      if (context.mounted) showDashToast(context, e.message);
    }
  }
}

class _Head extends StatelessWidget {
  const _Head();

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return Container(
      padding: EdgeInsets.fromLTRB(8, top + 4, 8, 8),
      decoration: const BoxDecoration(
        color: Colors.white,
        boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, 1))],
      ),
      child: Row(
        children: [
          IconButton(
            tooltip: 'Quay lại',
            constraints: const BoxConstraints.tightFor(width: 40, height: 40),
            style: IconButton.styleFrom(tapTargetSize: MaterialTapTargetSize.shrinkWrap),
            padding: EdgeInsets.zero,
            onPressed: () => Navigator.of(context).maybePop(),
            icon: const AppIcon(AppIcons.back, size: 22, color: OrderColors.ink),
          ),
          const Expanded(
            child: Text(
              'Chi tiết sản phẩm',
              textAlign: TextAlign.center,
              style: TextStyle(color: OrderColors.ink, fontSize: 16.5, fontWeight: FontWeight.w700),
            ),
          ),
          // Cân tiêu đề vào giữa (thao tác đã ra hàng nút nhanh trong thân màn).
          const SizedBox(width: 40),
        ],
      ),
    );
  }
}

/// Khối trắng liền mép, cách khối trên 8.
class _Block extends StatelessWidget {
  const _Block({required this.child, this.title, this.titleWidget, this.first = false, this.flush = false});

  final Widget child;
  final String? title;
  final Widget? titleWidget;
  final bool first;

  /// Không đệm — con tự đặt phần đệm (khối có phần tràn mép).
  final bool flush;

  @override
  Widget build(BuildContext context) => Container(
    margin: EdgeInsets.only(top: first ? 0 : 8),
    padding: flush ? EdgeInsets.zero : const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    color: Colors.white,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (title != null || titleWidget != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child:
                titleWidget ??
                Text(
                  title!,
                  style: const TextStyle(color: OrderColors.ink, fontSize: 14.5, fontWeight: FontWeight.w700),
                ),
          ),
        child,
      ],
    ),
  );
}

class _StatusDot extends StatelessWidget {
  const _StatusDot({required this.label, required this.on});

  final String label;
  final bool on;

  @override
  Widget build(BuildContext context) {
    final c = on ? OrderColors.green.$2 : OrderColors.faint;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 7,
          height: 7,
          decoration: BoxDecoration(color: c, shape: BoxShape.circle),
        ),
        const SizedBox(width: 5),
        Text(
          label,
          style: TextStyle(color: c, fontSize: 12.5, fontWeight: FontWeight.w600),
        ),
      ],
    );
  }
}

class _PriceCell extends StatelessWidget {
  const _PriceCell({super.key, required this.label, required this.value, this.color, this.note, this.onTap});

  final String label;

  /// null = chưa có (giá vốn chưa khai / chưa tính được lãi).
  final String? value;
  final Color? color;
  final String? note;

  /// Có thì ô chạm để sửa (hiện bút chì cạnh nhãn).
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final body = Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(label, style: const TextStyle(color: OrderColors.muted, fontSize: 12)),
              if (onTap != null) ...[
                const SizedBox(width: 4),
                const AppIcon(AppIcons.edit, size: 13, color: OrderColors.primaryInk),
              ],
            ],
          ),
          const SizedBox(height: 2),
          if (value == null)
            Text(
              onTap != null ? '+ Nhập' : '—',
              style: TextStyle(
                color: onTap != null ? OrderColors.primaryInk : OrderColors.orange.$2,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            )
          else
            FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerLeft,
              child: Text(
                value!,
                style: TextStyle(
                  color: color ?? OrderColors.ink,
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                  fontFeatures: tnum,
                ),
              ),
            ),
          if (note != null)
            Text(
              note!,
              style: TextStyle(color: color, fontSize: 11.5, fontWeight: FontWeight.w600),
            ),
        ],
      ),
    );
    return onTap == null ? body : InkWell(onTap: onTap, child: body);
  }
}

/// Ngăn nhập số tiền: tự chấm ngăn nghìn khi gõ, tính lãi ngay.
class _MoneySheet extends StatefulWidget {
  const _MoneySheet({
    required this.price,
    required this.initial,
    required this.otherPrice,
    required this.otherCost,
    this.note,
  });

  /// true = sửa giá bán, false = giá vốn.
  final bool price;
  final num? initial;
  final num otherPrice;
  final num? otherCost;

  /// Ghi chú dưới ô (giá gốc / phiên bản được áp).
  final InlineSpan? note;

  @override
  State<_MoneySheet> createState() => _MoneySheetState();
}

class _MoneySheetState extends State<_MoneySheet> {
  late final _c = TextEditingController(text: widget.initial == null ? '' : dot(widget.initial!));
  String? _err;

  @override
  void initState() {
    super.initState();
    _c.selection = TextSelection(baseOffset: 0, extentOffset: _c.text.length);
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  int get _val => int.tryParse(_c.text.replaceAll(RegExp(r'\D'), '')) ?? 0;

  void _onChanged(String _) {
    final n = _val;
    final t = n == 0 ? '' : dot(n);
    if (t != _c.text) {
      _c.value = TextEditingValue(
        text: t,
        selection: TextSelection.collapsed(offset: t.length),
      );
    }
    setState(() => _err = null);
  }

  void _save() {
    // Như PC: chỉ chặn ô trống, giá 0 vẫn hợp lệ.
    if (widget.price && _c.text.trim().isEmpty) return setState(() => _err = 'Nhập giá bán.');
    Navigator.of(context).pop(_val);
  }

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    final price = widget.price ? _val : widget.otherPrice;
    final cost = widget.price ? widget.otherCost : _val;
    final gain = cost == null || price <= 0 ? null : price - cost;
    return Padding(
      padding: EdgeInsets.only(bottom: mq.viewInsets.bottom),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 40,
              height: 5,
              margin: const EdgeInsets.only(top: 10, bottom: 12),
              decoration: BoxDecoration(color: const Color(0xFFD6DBE3), borderRadius: BorderRadius.circular(3)),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  widget.price ? 'Sửa giá bán' : 'Giá vốn',
                  style: const TextStyle(color: OrderColors.ink, fontSize: 18, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 10),
                Container(
                  height: 56,
                  padding: const EdgeInsets.symmetric(horizontal: 14),
                  decoration: BoxDecoration(
                    border: Border.all(color: _err == null ? OrderColors.primary : OrderColors.red.$2, width: 1.5),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Row(
                    children: [
                      Expanded(
                        child: TextField(
                          key: const Key('money_input'),
                          controller: _c,
                          autofocus: true,
                          keyboardType: TextInputType.number,
                          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
                          onChanged: _onChanged,
                          onSubmitted: (_) => _save(),
                          style: const TextStyle(
                            color: OrderColors.ink,
                            fontSize: 24,
                            fontWeight: FontWeight.w700,
                            fontFeatures: tnum,
                          ),
                          decoration: const InputDecoration(
                            isCollapsed: true,
                            filled: false,
                            border: InputBorder.none,
                            enabledBorder: InputBorder.none,
                            focusedBorder: InputBorder.none,
                            hintText: '0',
                            hintStyle: TextStyle(color: OrderColors.faint, fontSize: 24),
                          ),
                        ),
                      ),
                      const Text(
                        'đ',
                        style: TextStyle(color: OrderColors.muted, fontSize: 15, fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                ),
                if (_err != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: Text(_err!, style: TextStyle(color: OrderColors.red.$2, fontSize: 12.5)),
                  ),
                if (gain != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 10),
                    child: Text.rich(
                      TextSpan(
                        text: 'Lãi mỗi sản phẩm: ',
                        children: [
                          TextSpan(
                            text: '${dot(gain)}đ · ${pct(gain / price * 100)}',
                            style: TextStyle(
                              color: gain > 0 ? OrderColors.green.$2 : OrderColors.red.$2,
                              fontWeight: FontWeight.w700,
                              fontFeatures: tnum,
                            ),
                          ),
                        ],
                      ),
                      key: const Key('money_hint'),
                      style: const TextStyle(color: OrderColors.muted, fontSize: 12.5),
                    ),
                  ),
                if (widget.note != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 10),
                    child: Text.rich(
                      widget.note!,
                      key: const Key('money_note'),
                      style: const TextStyle(color: OrderColors.muted, fontSize: 12.5, height: 1.5),
                    ),
                  ),
                const SizedBox(height: 12),
              ],
            ),
          ),
          Container(
            padding: EdgeInsets.fromLTRB(16, 12, 16, mq.viewPadding.bottom + 12),
            decoration: const BoxDecoration(
              border: Border(top: BorderSide(color: OrderColors.line)),
            ),
            child: Row(
              children: [
                Expanded(
                  child: GoodsBtn(label: 'Huỷ', onTap: () => Navigator.of(context).pop()),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: GoodsBtn(key: const Key('money_save'), label: 'Lưu', primary: true, onTap: _save),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Loading extends StatelessWidget {
  const _Loading();

  @override
  Widget build(BuildContext context) => const Padding(
    padding: EdgeInsets.symmetric(vertical: 10),
    child: Center(
      child: SizedBox(
        width: 22,
        height: 22,
        child: CircularProgressIndicator(strokeWidth: 2, color: OrderColors.primary),
      ),
    ),
  );
}

/// Giá trị mặc định của thuộc tính: thẻ xanh có dấu tick.
class _CheckTag extends StatelessWidget {
  const _CheckTag(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Container(
    height: 20,
    padding: const EdgeInsets.symmetric(horizontal: 7),
    decoration: BoxDecoration(color: OrderColors.primarySoft, borderRadius: BorderRadius.circular(6)),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        const AppIcon(AppIcons.check, size: 12, color: OrderColors.primaryInk),
        const SizedBox(width: 3),
        Text(
          text,
          style: const TextStyle(color: OrderColors.primaryInk, fontSize: 11, fontWeight: FontWeight.w600),
        ),
      ],
    ),
  );
}
