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
          child: Row(
            children: [
              Expanded(
                child: GoodsBtn(
                  label: 'In mã vạch',
                  icon: AppIcons.barcode,
                  onTap: () => showDashToast(context, 'In tem mã vạch trên mobile — sắp có'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: GoodsBtn(
                  label: 'Sửa sản phẩm',
                  icon: AppIcons.edit,
                  primary: true,
                  onTap: () => showDashToast(context, 'Sửa sản phẩm trên mobile — sắp có'),
                ),
              ),
            ],
          ),
        ),
        body: Column(
          children: [
            _Head(onMore: () => _more(context, ref, it)),
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
                    _hero(it),
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

  Widget _hero(GoodsItem it) => _Block(
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
              GoodsImage(url: it.thumb, size: 96, radius: 12),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      it.name,
                      style: const TextStyle(
                        color: OrderColors.ink,
                        fontSize: 17,
                        fontWeight: FontWeight.w700,
                        height: 1.35,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text('SKU: ${it.sku}', style: const TextStyle(color: OrderColors.muted, fontSize: 12.5)),
                    const SizedBox(height: 8),
                    _StatusDot(active: it.active),
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
                  child: _PriceCell(label: 'Giá bán lẻ', value: priceText(it)),
                ),
                const VerticalDivider(width: 1, thickness: 1, color: OrderColors.line),
                Expanded(
                  child: _PriceCell(label: 'Giá vốn', value: it.cost == null ? null : dot(it.cost!)),
                ),
              ],
            ),
          ),
        ),
      ],
    ),
  );

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

  Future<void> _more(BuildContext context, WidgetRef ref, GoodsItem it) async {
    final pick = await showDashSheet<String>(
      context,
      title: 'Thao tác',
      body: (ctx) => Column(
        children: [
          SheetOption(label: 'Nhập hàng', leading: AppIcons.truck, onTap: () => Navigator.of(ctx).pop('import')),
          SheetOption(
            key: const Key('product_duplicate'),
            label: 'Sao chép sản phẩm',
            leading: AppIcons.copy,
            onTap: () => Navigator.of(ctx).pop('copy'),
          ),
          SheetOption(
            key: const Key('product_toggle'),
            label: it.active ? 'Ngừng bán' : 'Mở bán lại',
            leading: it.active ? AppIcons.eyeOff : AppIcons.eye,
            danger: it.active,
            onTap: () => Navigator.of(ctx).pop('toggle'),
          ),
        ],
      ),
    );
    if (pick == null || !context.mounted) return;
    if (pick == 'import') return showDashToast(context, 'Tạo phiếu nhập trên mobile — sắp có');

    final copy = pick == 'copy';
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
  const _Head({required this.onMore});

  final VoidCallback onMore;

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
          IconButton(
            key: const Key('product_more'),
            tooltip: 'Thao tác khác',
            constraints: const BoxConstraints.tightFor(width: 40, height: 40),
            style: IconButton.styleFrom(tapTargetSize: MaterialTapTargetSize.shrinkWrap),
            padding: EdgeInsets.zero,
            onPressed: onMore,
            icon: const AppIcon(AppIcons.dots, size: 22, color: OrderColors.ink),
          ),
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
  const _PriceCell({required this.label, required this.value});

  final String label;

  /// null = chưa khai (giá vốn).
  final String? value;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(color: OrderColors.muted, fontSize: 12)),
        const SizedBox(height: 2),
        value == null
            ? Text(
                'Chưa có',
                style: TextStyle(color: OrderColors.orange.$2, fontSize: 13, fontWeight: FontWeight.w600),
              )
            : Text(
                value!,
                style: const TextStyle(
                  color: OrderColors.ink,
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                  fontFeatures: tnum,
                ),
              ),
      ],
    ),
  );
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
