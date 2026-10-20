import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_number.dart';
import '../../../core/ui/app_icon.dart';
import '../../workspace/workspace_widgets.dart' show SheetButton;
import '../shell/shell_widgets.dart';
import 'goods_data.dart';
import 'goods_widgets.dart';

String _dmy(DateTime t) => '${t.day.toString().padLeft(2, '0')}/${t.month.toString().padLeft(2, '0')}/${t.year}';

/// Chi tiết sản phẩm kiểu Sapo: các khối trắng xếp liền — ảnh + giá, tồn theo
/// chi nhánh, phiên bản, thông tin.
class ProductDetailScreen extends ConsumerWidget {
  const ProductDetailScreen({super.key, required this.item});

  /// Dòng đã có ở danh sách — dựng ngay phần đầu, chờ API cho phần còn lại.
  final GoodsItem item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Đổi trạng thái xong danh mục tải lại → đọc bản mới của chính mặt hàng này.
    final it = ref.watch(goodsCatalogProvider).value?.byId(item.id) ?? item;
    final detail = ref.watch(productDetailProvider(it.id));
    final branches = ref.watch(productBranchStockProvider((it.id, it.sku)));
    final d = detail.value;

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.dark,
      child: Scaffold(
        backgroundColor: OrderColors.page,
        bottomNavigationBar: Container(
          padding: EdgeInsets.fromLTRB(12, 10, 12, MediaQuery.viewPaddingOf(context).bottom + 10),
          decoration: const BoxDecoration(
            color: Colors.white,
            boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, -1))],
          ),
          child: GoodsBtn(
            label: 'Sửa sản phẩm',
            icon: AppIcons.edit,
            primary: true,
            onTap: () => showDashToast(context, 'Sửa sản phẩm trên mobile — sắp có'),
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
                    _hero(context, ref, it, d),
                    _quick(context, ref, it),
                    _Block(title: 'Tồn kho', child: _stock(branches)),
                    ..._variants(it, d),
                    _Block(title: 'Thông tin', child: _info(it, d, detail)),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _hero(BuildContext context, WidgetRef ref, GoodsItem it, Map<String, dynamic>? d) {
    final n = it.variants.length;
    final cost = it.cost;
    final gain = cost == null ? null : it.minPrice - cost;
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
                GoodsImage(url: it.thumb, size: 104, radius: 14),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        it.name,
                        style: const TextStyle(
                          color: OrderColors.ink,
                          fontSize: 18,
                          fontWeight: FontWeight.w700,
                          height: 1.35,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text('SKU: ${it.sku}', style: const TextStyle(color: OrderColors.muted, fontSize: 12.5)),
                      const SizedBox(height: 8),
                      Wrap(
                        spacing: 5,
                        runSpacing: 5,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          _StatusDot(active: it.active),
                          if (it.isSerial) const SmallTag('IMEI', OrderColors.purple),
                          if (n > 1) SmallTag('$n phiên bản', (OrderColors.primarySoft, OrderColors.primaryInk)),
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
                      value: priceText(it),
                      color: OrderColors.primaryInk,
                      onTap: () => _editMoney(context, ref, it, d, price: true),
                    ),
                  ),
                  const VerticalDivider(width: 1, thickness: 1, color: OrderColors.line),
                  Expanded(
                    child: _PriceCell(
                      key: const Key('edit_cost'),
                      label: 'Giá vốn',
                      value: cost == null ? null : dot(cost),
                      onTap: () => _editMoney(context, ref, it, d, price: false),
                    ),
                  ),
                  const VerticalDivider(width: 1, thickness: 1, color: OrderColors.line),
                  Expanded(
                    child: _PriceCell(
                      key: const Key('gain_cell'),
                      label: 'Lãi / sp',
                      value: gain == null ? null : dot(gain),
                      color: gain != null && gain < 0 ? OrderColors.red.$2 : OrderColors.green.$2,
                      note: gain == null || it.minPrice <= 0 ? null : pct(gain / it.minPrice * 100),
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
  Widget _quick(BuildContext context, WidgetRef ref, GoodsItem it) {
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
          btn('In mã vạch', AppIcons.barcode, () => showDashToast(context, 'In tem mã vạch trên mobile — sắp có')),
          btn(
            'Sao chép',
            AppIcons.copy,
            () => _confirm(context, ref, it, copy: true),
            key: const Key('product_duplicate'),
          ),
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

  /// Sửa nhanh giá bán / giá vốn của phiên bản đầu (hàng nhiều phiên bản thì các
  /// phiên bản khác sửa ở form Sửa sản phẩm).
  Future<void> _editMoney(
    BuildContext context,
    WidgetRef ref,
    GoodsItem it,
    Map<String, dynamic>? d, {
    required bool price,
  }) async {
    final dv = [
      for (final v in ((d?['variants'] as List?) ?? const []).whereType<Map>())
        if (v['is_active'] != false) v,
    ];
    final row = it.variants.firstOrNull;
    final vid = row?.variantId ?? (dv.firstOrNull?['id'] as num?)?.toInt();
    // Giá bán phải gửi lại cả mặt hàng — chờ chi tiết tải xong mới sửa được.
    if (vid == null || (price && d == null)) {
      return showDashToast(context, 'Đang tải chi tiết sản phẩm, thử lại sau giây lát');
    }
    final v = dv.where((x) => (x['id'] as num).toInt() == vid).firstOrNull;
    final curPrice = (v?['price'] as num?) ?? row?.price ?? it.minPrice;
    final curCost = row != null ? row.cost : v?['cost_price'] as num?;
    final vName = '${v?['name'] ?? row?.name ?? ''}';
    final n = await showModalBottomSheet<num>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      backgroundColor: Colors.white,
      barrierColor: const Color(0x73111827),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (_) => _MoneySheet(
        price: price,
        initial: price ? curPrice : curCost,
        otherPrice: curPrice,
        otherCost: curCost,
        variantName: it.variants.length > 1 && vName.isNotEmpty ? vName : null,
      ),
    );
    if (n == null || !context.mounted) return;
    try {
      if (price) {
        await setVariantPrice(ref, d!, vid, n);
      } else {
        await setVariantCost(ref, it.id, vid, n);
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
    if (rows.isEmpty) {
      return const Text('Chưa có tồn ở chi nhánh nào.', style: TextStyle(color: OrderColors.muted, fontSize: 13));
    }
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
        for (final (name, q) in rows) line(name, q),
        if (rows.length > 1) line('Tổng', total, sum: true),
      ],
    );
  }

  List<Widget> _variants(GoodsItem it, Map<String, dynamic>? d) {
    final vs = [
      for (final v in ((d?['variants'] as List?) ?? const []).whereType<Map>())
        if (v['is_active'] != false) v,
    ];
    // Hàng một phiên bản: phiên bản chính là sản phẩm — không lặp lại khối này.
    if (vs.isEmpty || (vs.length == 1 && d?['is_multi_variant'] != true)) return const [];
    return [
      _Block(
        title: 'Phiên bản (${vs.length})',
        child: Column(
          children: [
            for (final (i, v) in vs.indexed)
              Container(
                padding: const EdgeInsets.symmetric(vertical: 10),
                decoration: i == 0
                    ? null
                    : const BoxDecoration(
                        border: Border(top: BorderSide(color: OrderColors.line)),
                      ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    GoodsImage(url: '${v['image'] ?? ''}'.isEmpty ? it.thumb : '${v['image']}', size: 44, radius: 8),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            '${v['name'] ?? ''}'.isEmpty ? it.name : '${v['name']}',
                            style: const TextStyle(color: OrderColors.ink, fontSize: 13.5, fontWeight: FontWeight.w600),
                          ),
                          Text(
                            '${v['sku'] ?? ''}',
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
                          'Tồn: ${dot((v['stock_quantity'] as num?) ?? 0)}',
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
    ];
  }

  Widget _info(GoodsItem it, Map<String, dynamic>? d, AsyncValue<Map<String, dynamic>> detail) {
    if (d == null) {
      if (detail.hasError) {
        final e = detail.error;
        return Text(
          e is ApiException ? e.message : 'Không tải được chi tiết sản phẩm.',
          style: const TextStyle(color: OrderColors.muted, fontSize: 13),
        );
      }
      return const _Loading();
    }
    final unit = '${(d['unit'] as Map?)?['name'] ?? ''}';
    final cat = '${(d['category'] as Map?)?['name'] ?? it.categoryName}';
    final created = DateTime.tryParse('${d['created_at']}')?.toLocal() ?? it.createdAt;
    final kv = [
      ('Nhóm hàng', cat.isEmpty ? '—' : cat),
      ('Đơn vị tính', unit.isEmpty ? '—' : unit),
      ('Thuế VAT', '${decimal((d['vat'] as num?) ?? 0, 2)}%'),
      ('Quản lý IMEI/serial', d['is_serial'] == true ? 'Có' : 'Không'),
      ('Đã bán', dot((d['sold_count'] as num?) ?? it.sold)),
      ('Ngày tạo', _dmy(created)),
    ];
    return Column(
      children: [
        for (final (i, (k, v)) in kv.indexed)
          Container(
            padding: EdgeInsets.only(top: i == 0 ? 0 : 9, bottom: 9),
            decoration: i == 0
                ? null
                : const BoxDecoration(
                    border: Border(top: BorderSide(color: OrderColors.line)),
                  ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(k, style: const TextStyle(color: OrderColors.muted, fontSize: 13.5)),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    v,
                    textAlign: TextAlign.right,
                    style: const TextStyle(
                      color: OrderColors.ink,
                      fontSize: 13.5,
                      fontWeight: FontWeight.w500,
                      fontFeatures: tnum,
                    ),
                  ),
                ),
              ],
            ),
          ),
      ],
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
  const _Block({required this.child, this.title, this.first = false, this.flush = false});

  final Widget child;
  final String? title;
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
        if (title != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Text(
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
  const _StatusDot({required this.active});

  final bool active;

  @override
  Widget build(BuildContext context) {
    final c = active ? OrderColors.green.$2 : OrderColors.faint;
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
          active ? 'Đang bán' : 'Ngừng bán',
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
    this.variantName,
  });

  /// true = sửa giá bán, false = giá vốn.
  final bool price;
  final num? initial;
  final num otherPrice;
  final num? otherCost;
  final String? variantName;

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
    if (widget.price && _val <= 0) return setState(() => _err = 'Giá bán phải lớn hơn 0.');
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
                if (widget.variantName != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 10),
                    child: Text.rich(
                      TextSpan(
                        text: 'Áp cho phiên bản ',
                        children: [
                          TextSpan(
                            text: widget.variantName,
                            style: const TextStyle(color: OrderColors.ink, fontWeight: FontWeight.w700),
                          ),
                          const TextSpan(text: '. Các phiên bản khác sửa ở form Sửa sản phẩm.'),
                        ],
                      ),
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
