import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/ui/app_icon.dart';
import '../../../core/format/vn_number.dart';
import '../../auth/permissions.dart';
import '../../workspace/workspace.dart' show Branch;
import '../../workspace/workspace_widgets.dart' show SheetButton;
import '../dashboard/dashboard_data.dart' show branchLabel, dashBranchesProvider, defaultShopProvider;
import '../shell/shell_widgets.dart';
import 'goods_data.dart';
import 'goods_io.dart';
import 'goods_reorder_screen.dart';
import 'goods_widgets.dart';
import '../../auth/auth_controller.dart';
import 'product_detail_screen.dart';
import 'product_form_data.dart';
import 'product_form_screen.dart';
import 'scanner_screen.dart';

/// Màn Hàng hoá theo design/html/hang-hoa-mobile.html (v5): danh sách gọn kiểu
/// Sapo — ảnh · tên · SKU · giá · tồn, lọc theo trạng thái và nhóm hàng.
class GoodsScreen extends ConsumerStatefulWidget {
  const GoodsScreen({super.key});

  @override
  ConsumerState<GoodsScreen> createState() => _GoodsScreenState();
}

class _GoodsScreenState extends ConsumerState<GoodsScreen> {
  final _scroll = ScrollController();
  final _search = TextEditingController();
  Timer? _debounce;
  bool _shadow = false;

  /// Mặt hàng đang chọn (nhấn giữ một dòng để bắt đầu) — rỗng là không ở chế độ chọn.
  final _picked = <int>{};

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      final s = _scroll.offset > 4;
      if (s != _shadow) setState(() => _shadow = s);
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _scroll.dispose();
    _search.dispose();
    super.dispose();
  }

  GoodsFilter get _f => ref.read(goodsFilterProvider);

  void _set(GoodsFilter f) {
    ref.read(goodsFilterProvider.notifier).set(f);
    if (_scroll.hasClients) _scroll.jumpTo(0);
  }

  void _onSearch(String v) {
    setState(() {});
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 250), () => _set(_f.copyWith(query: v)));
  }

  Future<void> _refresh() async {
    ref.invalidate(goodsCatalogProvider);
    ref.invalidate(goodsSearchProvider);
    try {
      await ref.read(goodsCatalogProvider.future);
      if (mounted) showDashToast(context, 'Đã cập nhật danh sách');
    } on ApiException catch (e) {
      if (mounted) showDashToast(context, e.message);
    }
  }

  Future<void> _add() async {
    // Form tự báo "Đã thêm" và cho chọn bước tiếp; trả về id khi người dùng chọn "Xem sản phẩm".
    final openId = await Navigator.of(
      context,
      rootNavigator: true,
    ).push<int>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => const ProductFormScreen()));
    if (openId == null || !mounted) return;
    try {
      final it = (await ref.read(goodsCatalogProvider.future)).byId(openId);
      if (it != null && mounted) _open(it);
    } on ApiException {
      // Danh mục chưa tải lại được — ở lại danh sách.
    }
  }

  /// Vuốt → Sửa: tải chi tiết rồi mở form sửa.
  Future<void> _editItem(GoodsItem it) async {
    Map<String, dynamic> d;
    try {
      d = await ref.read(apiClientProvider).get<Map<String, dynamic>>('/admin/products/${it.id}');
    } on ApiException catch (e) {
      if (mounted) showDashToast(context, e.message);
      return;
    }
    if (!mounted) return;
    await Navigator.of(
      context,
      rootNavigator: true,
    ).push<Object?>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => ProductFormScreen(detail: d)));
  }

  /// Vuốt → Xoá: hỏi lại (kèm số tồn) rồi xoá.
  Future<void> _deleteItem(GoodsItem it) async {
    if (!await confirmDeleteProduct(context, name: it.name, stock: it.stock) || !mounted) return;
    try {
      await deleteProduct(ref, it.id);
      if (mounted) showDashToast(context, 'Đã xoá ${it.name}');
    } on ApiException catch (e) {
      if (mounted) showDashToast(context, e.message);
    }
  }

  void _toggle(int id) => setState(() => _picked.contains(id) ? _picked.remove(id) : _picked.add(id));

  Future<void> _bulkDelete(List<GoodsItem> picked) async {
    final withStock = picked.where((it) => it.stockKnown && it.stock > 0).length;
    final yes = await showDashSheet<bool>(
      context,
      title: 'Xoá ${picked.length} sản phẩm?',
      subtitle: [
        'Các sản phẩm đã chọn sẽ không còn ở danh sách, quầy và web bán hàng. Đơn và sổ kho cũ vẫn giữ.',
        if (withStock > 0) '$withStock sản phẩm còn tồn ở chi nhánh này.',
      ].join(' '),
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SheetButton(
            key: const Key('bulk_delete_yes'),
            label: 'Xoá ${picked.length} sản phẩm',
            icon: AppIcons.trash,
            danger: true,
            onTap: () => Navigator.of(ctx).pop(true),
          ),
          SheetButton(label: 'Thôi', onTap: () => Navigator.of(ctx).pop(false)),
        ],
      ),
    );
    if (yes != true || !mounted) return;
    try {
      final n = await bulkDeleteProducts(ref, [for (final it in picked) it.id]);
      if (!mounted) return;
      setState(_picked.clear);
      showDashToast(context, 'Đã xoá $n sản phẩm');
    } on ApiException catch (e) {
      if (mounted) showDashToast(context, e.message);
    }
  }

  /// Các việc PC để ở nút "Nâng cao" + hai mũi tên thứ tự — gom một chỗ cho dễ thấy.
  Future<void> _openMore(List<GoodsItem> rows, int? shop, List<Branch> branches) async {
    final perms = permsOf(ref);
    final pick = await showDashSheet<String>(
      context,
      title: 'Tác vụ khác',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (perms.can(Quyen.suaSanPham))
            SheetOption(
              key: const Key('goods_more_reorder'),
              label: 'Đổi thứ tự hiển thị',
              leading: AppIcons.sort,
              onTap: () => Navigator.of(ctx).pop('reorder'),
            ),
          if (perms.can(Quyen.themSanPham)) ...[
            SheetOption(
              key: const Key('goods_more_import'),
              label: 'Nhập hàng từ file CSV',
              leading: AppIcons.doc,
              onTap: () => Navigator.of(ctx).pop('import'),
            ),
            SheetOption(
              key: const Key('goods_more_template'),
              label: 'Tải file mẫu nhập hàng',
              leading: AppIcons.down,
              onTap: () => Navigator.of(ctx).pop('template'),
            ),
          ],
          SheetOption(
            key: const Key('goods_more_export'),
            label: 'Xuất ${dot(rows.length)} sản phẩm ra file CSV',
            leading: AppIcons.share,
            onTap: () => Navigator.of(ctx).pop('export'),
          ),
        ],
      ),
    );
    if (!mounted) return;
    switch (pick) {
      case 'reorder':
        await _reorder();
      case 'import':
        await _import();
      case 'template':
        await _save(importTemplate(), 'mau-nhap-hang-hoa.csv');
      case 'export':
        await _export(rows, branches.length > 1 || shop == 0 ? branchLabel(shop, branches) : null);
    }
  }

  Future<void> _save(Uint8List bytes, String name) async {
    try {
      await ref.read(saveFileProvider)(bytes, name);
    } catch (_) {
      if (mounted) showDashToast(context, 'Không lưu được tệp trên máy này');
    }
  }

  Future<void> _export(List<GoodsItem> rows, String? stockLabel) async {
    if (rows.isEmpty) return showDashToast(context, 'Không có sản phẩm nào để xuất');
    showDashToast(context, 'Đang chuẩn bị tệp ${dot(rows.length)} sản phẩm…');
    final Uint8List bytes;
    try {
      bytes = await exportGoodsCsv(ref, rows: rows, stockLabel: stockLabel);
    } on ApiException catch (e) {
      if (mounted) showDashToast(context, e.message);
      return;
    }
    final n = DateTime.now();
    String two(int v) => '$v'.padLeft(2, '0');
    await _save(
      bytes,
      'hang-hoa-${n.year}${two(n.month)}${two(n.day)}-${two(n.hour)}${two(n.minute)}${two(n.second)}.csv',
    );
  }

  Future<void> _import() async {
    final picked = await ref.read(pickCsvProvider)();
    if (picked == null || !mounted) return;
    final (file, bytes) = picked;
    final csv = parseCsv(utf8.decode(bytes, allowMalformed: true));
    if (csv.isEmpty || !csv.first.map((h) => h.trim().toLowerCase()).contains('name')) {
      return showDashToast(context, 'Tệp không đúng mẫu: thiếu cột name. Tải file mẫu để xem cách điền.');
    }
    final List<ImportRow> rows;
    try {
      final (units, locs) = await importLookups(ref);
      final autoSku = (await ref.read(productFormSourcesProvider.future)).autoSku;
      rows = buildImportRows(csv, unitByCode: units, locByCode: locs, autoSku: autoSku);
    } on ApiException catch (e) {
      if (mounted) showDashToast(context, e.message);
      return;
    }
    if (!mounted) return;
    if (rows.isEmpty) return showDashToast(context, 'Tệp không có dòng hàng nào');
    final good = rows.where((r) => r.body != null).length;
    final yes = await showDashSheet<bool>(
      context,
      title: 'Nhập $good mặt hàng?',
      subtitle: [
        'Từ tệp $file. Mặt hàng nhập vào ở trạng thái tạm ẩn, tồn kho 0 — kiểm lại rồi mới cho bán.',
        if (rows.length > good) '${rows.length - good} dòng sai sẽ được liệt kê sau khi nhập.',
      ].join(' '),
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SheetButton(
            key: const Key('import_yes'),
            label: good > 0 ? 'Nhập $good mặt hàng' : 'Xem dòng sai',
            icon: AppIcons.doc,
            onTap: () => Navigator.of(ctx).pop(true),
          ),
          SheetButton(label: 'Thôi', onTap: () => Navigator.of(ctx).pop(false)),
        ],
      ),
    );
    if (yes != true || !mounted) return;

    final done = ValueNotifier(0);
    final nav = Navigator.of(context, rootNavigator: true);
    unawaited(
      showDialog<void>(
        context: context,
        useRootNavigator: true,
        barrierDismissible: false,
        builder: (_) => PopScope(
          canPop: false,
          child: AlertDialog(
            content: ValueListenableBuilder<int>(
              valueListenable: done,
              builder: (_, n, _) => Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text('Đang nhập $n/${rows.length} dòng…', key: const Key('import_progress')),
                  const SizedBox(height: 12),
                  LinearProgressIndicator(value: rows.isEmpty ? null : n / rows.length, color: OrderColors.primary),
                ],
              ),
            ),
          ),
        ),
      ),
    );
    final res = await runImport(ref, rows, onProgress: (n) => done.value = n);
    nav.pop();
    done.dispose();
    if (!mounted) return;
    await showDashSheet<void>(
      context,
      title: res.ok > 0 ? 'Đã nhập ${res.ok} mặt hàng' : 'Chưa nhập được mặt hàng nào',
      subtitle: [
        if (res.ok > 0) 'Đang ở trạng thái tạm ẩn, tồn kho 0 — xem ở chip "Ngừng bán".',
        if (res.errors.isNotEmpty) '${res.errors.length} dòng chưa nhập được:',
      ].join(' '),
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final e in res.errors.take(20))
            Padding(
              padding: const EdgeInsets.fromLTRB(4, 0, 4, 8),
              child: Text(e, style: const TextStyle(color: Color(0xFFC62828), fontSize: 13.5)),
            ),
          if (res.errors.length > 20)
            Padding(
              padding: const EdgeInsets.fromLTRB(4, 0, 4, 8),
              child: Text(
                '…và ${res.errors.length - 20} dòng khác.',
                style: const TextStyle(color: OrderColors.muted, fontSize: 13.5),
              ),
            ),
          SheetButton(key: const Key('import_done'), label: 'Đóng', onTap: () => Navigator.of(ctx).pop()),
        ],
      ),
    );
  }

  Future<void> _reorder() async {
    final c = ref.read(goodsCatalogProvider).value;
    if (c == null) return;
    if (!c.complete) {
      showDashToast(context, 'Đang tải hết danh sách hàng — thử lại sau giây lát');
      return;
    }
    var saved = false;
    await Navigator.of(context, rootNavigator: true).push(
      MaterialPageRoute<void>(
        builder: (_) =>
            GoodsReorderScreen(items: [...c.items]..sort(GoodsSort.manualOrder), onSaved: () => saved = true),
      ),
    );
    if (!mounted) return;
    // Về xem ngay thứ tự vừa xếp.
    if (_f.sort != GoodsSort.manual) _set(_f.copyWith(sort: GoodsSort.manual));
    if (saved) ref.invalidate(goodsCatalogProvider);
  }

  Future<void> _pickBranch(List<Branch> branches, int? cur) async {
    final id = await showDashSheet<int>(
      context,
      title: 'Xem chi nhánh',
      body: (ctx) => Column(
        children: [
          for (final b in [...branches, const Branch(id: 0, name: 'Tất cả chi nhánh')])
            SheetOption(
              key: Key('goods_branch_${b.id}'),
              label: b.name,
              trailing: b.isHq ? 'Trụ sở' : null,
              selected: cur == b.id,
              onTap: () => Navigator.of(ctx).pop(b.id),
            ),
        ],
      ),
    );
    if (id == null || id == cur) return;
    ref.read(goodsBranchProvider.notifier).set(id);
    if (_scroll.hasClients) _scroll.jumpTo(0);
  }

  Future<void> _scan() async {
    GoodsItem? found;
    await Navigator.of(context, rootNavigator: true).push(
      MaterialPageRoute<void>(
        fullscreenDialog: true,
        builder: (_) => ScannerScreen(
          onCode: (code) async {
            try {
              found = await lookupCode(ref, code);
            } on ApiException {
              found = null;
            }
            return found != null;
          },
        ),
      ),
    );
    if (found != null && mounted) _open(found!);
  }

  void _open(GoodsItem it) => Navigator.of(
    context,
    rootNavigator: true,
  ).push(MaterialPageRoute<void>(builder: (_) => ProductDetailScreen(item: it)));

  Future<void> _pickSort() async {
    final canMove = permsOf(ref).can(Quyen.suaSanPham);
    final s = await showDashSheet<Object>(
      context,
      title: 'Sắp xếp',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final o in GoodsSort.values)
            SheetOption(
              key: Key('goods_sort_${o.name}'),
              label: o.label,
              selected: _f.sort == o,
              onTap: () => Navigator.of(ctx).pop(o),
            ),
          if (canMove) ...[
            const SizedBox(height: 8),
            SheetButton(
              key: const Key('goods_reorder'),
              label: 'Đổi thứ tự hiển thị',
              icon: AppIcons.sort,
              onTap: () => Navigator.of(ctx).pop('reorder'),
            ),
          ],
        ],
      ),
    );
    if (s is GoodsSort) _set(_f.copyWith(sort: s));
    if (s == 'reorder') await _reorder();
  }

  Future<void> _openFilter(GoodsCatalog c, Set<int>? serverIds) async {
    final cats = await showModalBottomSheet<Set<int>>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      backgroundColor: Colors.white,
      barrierColor: const Color(0x73111827),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (_) => _FilterSheet(catalog: c, filter: _f, serverIds: serverIds),
    );
    if (cats != null) _set(_f.copyWith(cats: cats));
  }

  void _resetAll() {
    _search.clear();
    _set(GoodsFilter(sort: _f.sort));
  }

  @override
  Widget build(BuildContext context) {
    final f = ref.watch(goodsFilterProvider);
    final cat = ref.watch(goodsCatalogProvider);
    final q = f.query.trim();
    final serverIds = q.isEmpty ? null : ref.watch(goodsSearchProvider(q)).value;
    final c = cat.value;
    final base = c == null ? const <GoodsItem>[] : matchGoods(c, query: q, cats: f.cats, serverIds: serverIds);
    final perms = permsOf(ref);
    final branches = ref.watch(dashBranchesProvider).value ?? const [];
    final shop = ref.watch(goodsBranchProvider) ?? ref.watch(defaultShopProvider).value;
    final rows = [...base.where(f.status.has)]..sort(f.sort.compare);
    // Chỉ tính những dòng còn đang hiện: đổi lọc rồi mà vẫn xoá cả hàng đã khuất là xoá mù.
    final picked = [
      for (final it in rows)
        if (_picked.contains(it.id)) it,
    ];
    final selecting = _picked.isNotEmpty;

    return PopScope(
      canPop: !selecting,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) setState(_picked.clear);
      },
      child: AnnotatedRegion<SystemUiOverlayStyle>(
        value: SystemUiOverlayStyle.dark,
        child: ColoredBox(
          color: OrderColors.page,
          child: Column(
            children: [
              _Header(
                selectBar: selecting
                    ? _SelectBar(
                        count: picked.length,
                        all: picked.length == rows.length,
                        onClose: () => setState(_picked.clear),
                        onAll: () => setState(() {
                          if (picked.length == rows.length) {
                            _picked.clear();
                          } else {
                            _picked.addAll(rows.map((it) => it.id));
                          }
                        }),
                      )
                    : null,
                search: _search,
                onSearch: _onSearch,
                onClear: () {
                  _search.clear();
                  _onSearch('');
                },
                catCount: f.cats.length,
                status: f.status,
                countOf: c == null ? null : (s) => base.where(s.has).length,
                shadow: _shadow,
                onScan: _scan,
                onMore: c == null ? null : () => _openMore(rows, shop, branches),
                onNew: perms.can(Quyen.themSanPham) ? _add : null,
                branch: branches.length > 1 ? branchLabel(shop, branches) : null,
                onBranch: () => _pickBranch(branches, shop),
                showOut: c?.stockKnown ?? true,
                onFilter: c == null ? null : () => _openFilter(c, serverIds),
                onStatus: (s) => _set(f.copyWith(status: s)),
              ),
              Expanded(
                child: RefreshIndicator(
                  color: OrderColors.primary,
                  onRefresh: _refresh,
                  child: _body(f, cat, rows, perms),
                ),
              ),
              if (selecting)
                Container(
                  padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
                  decoration: const BoxDecoration(
                    color: Colors.white,
                    boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, -1))],
                  ),
                  child: FilledButton.icon(
                    key: const Key('goods_bulk_delete'),
                    onPressed: picked.isEmpty ? null : () => _bulkDelete(picked),
                    style: FilledButton.styleFrom(
                      backgroundColor: const Color(0xFFD93025),
                      minimumSize: const Size.fromHeight(46),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      textStyle: const TextStyle(fontFamily: appFont, fontSize: 15, fontWeight: FontWeight.w700),
                    ),
                    icon: const AppIcon(AppIcons.trash, size: 19, color: Colors.white),
                    label: Text('Xoá ${picked.length} sản phẩm'),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _body(GoodsFilter f, AsyncValue<GoodsCatalog> cat, List<GoodsItem> rows, Perms perms) {
    Widget scroll(List<Widget> children) => ListView(
      controller: _scroll,
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.only(bottom: 18),
      children: children,
    );

    if (cat.hasError && !cat.hasValue) {
      final e = cat.error;
      return scroll([
        _Empty(
          title: 'Không tải được hàng hoá',
          text: e is ApiException ? e.message : 'Thử lại sau ít phút.',
          action: 'Thử lại',
          onAction: _refresh,
        ),
      ]);
    }
    if (!cat.hasValue) {
      // Chừa đúng chỗ thanh "N sản phẩm" để danh sách hiện ra không bị giật.
      return scroll(const [SizedBox(height: 44), GoodsSkeleton()]);
    }

    final meta = Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 12, 6),
      child: Row(
        children: [
          Expanded(
            child: Text('${rows.length} sản phẩm', style: const TextStyle(color: OrderColors.muted, fontSize: 12.5)),
          ),
          TextButton.icon(
            key: const Key('goods_sort_btn'),
            onPressed: _pickSort,
            style: TextButton.styleFrom(
              foregroundColor: OrderColors.ink,
              minimumSize: const Size(0, 28),
              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
              padding: const EdgeInsets.symmetric(horizontal: 4),
              textStyle: const TextStyle(fontFamily: appFont, fontSize: 12.5, fontWeight: FontWeight.w600),
            ),
            icon: const AppIcon(AppIcons.sort, size: 15, color: OrderColors.muted),
            label: Text(f.sort.label),
          ),
        ],
      ),
    );

    if (rows.isEmpty) {
      final narrowed = f.query.trim().isNotEmpty || f.cats.isNotEmpty || f.status != GoodsStatus.all;
      return scroll([
        meta,
        _Empty(
          title: narrowed ? 'Không có sản phẩm nào' : 'Chưa có sản phẩm nào',
          text: narrowed ? 'Thử đổi từ khoá hoặc bỏ bộ lọc.' : 'Thêm sản phẩm trên web quản trị để bắt đầu bán.',
          action: narrowed ? 'Xem tất cả' : null,
          onAction: _resetAll,
        ),
      ]);
    }

    // Dòng 0 = thanh số lượng, dòng cuối = "Đã hiện hết"; giữa là hàng hoá.
    return ListView.builder(
      controller: _scroll,
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.only(bottom: 18),
      itemCount: rows.length + 2,
      itemBuilder: (_, i) {
        if (i == 0) return meta;
        if (i == rows.length + 1) {
          final c = cat.value!;
          return Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 6),
            child: Center(
              child: c.loadError != null
                  ? TextButton(
                      key: const Key('goods_load_retry'),
                      onPressed: _refresh,
                      child: Text('Chưa tải hết: ${c.loadError} — chạm để tải lại', textAlign: TextAlign.center),
                    )
                  : !c.complete
                  ? Row(
                      key: const Key('goods_loading_more'),
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const SizedBox(
                          width: 14,
                          height: 14,
                          child: CircularProgressIndicator(strokeWidth: 2, color: OrderColors.primary),
                        ),
                        const SizedBox(width: 8),
                        Text(
                          'Đang tải thêm… ${dot(c.items.length)}/${dot(c.total)} sản phẩm',
                          style: const TextStyle(color: OrderColors.faint, fontSize: 12),
                        ),
                      ],
                    )
                  : Text(
                      'Đã hiện hết ${rows.length} sản phẩm',
                      style: const TextStyle(color: OrderColors.faint, fontSize: 12),
                    ),
            ),
          );
        }
        final it = rows[i - 1];
        final low = cat.value!.lowStock;
        if (_picked.isNotEmpty) {
          final row = GoodsRow(
            key: Key('goods_${it.id}'),
            item: it,
            low: low,
            selected: _picked.contains(it.id),
            onTap: () => _toggle(it.id),
          );
          return i == 1 ? row : Column(children: [const GoodsDivider(), row]);
        }
        // Nhấn giữ để chọn nhiều — chỉ để xoá hàng loạt, nên cần quyền xoá.
        final hold = perms.can(Quyen.xoaSanPham) ? () => _toggle(it.id) : null;
        final actions = [
          if (perms.can(Quyen.suaSanPham))
            SwipeAction(
              key: Key('swipe_edit_${it.id}'),
              label: 'Sửa',
              icon: AppIcons.edit,
              color: OrderColors.primary,
              onTap: () => _editItem(it),
            ),
          if (perms.can(Quyen.xoaSanPham))
            SwipeAction(
              key: Key('swipe_delete_${it.id}'),
              label: 'Xoá',
              icon: AppIcons.trash,
              color: const Color(0xFFD93025),
              onTap: () => _deleteItem(it),
            ),
        ];
        // Không có quyền sửa / xoá thì dòng không vuốt được.
        final row = actions.isEmpty
            ? GoodsRow(key: Key('goods_${it.id}'), item: it, low: low, onTap: () => _open(it), onLongPress: hold)
            : SwipeRow(
                key: ValueKey('swipe_${it.id}'),
                onTap: () => _open(it),
                actions: actions,
                builder: (tap) =>
                    GoodsRow(key: Key('goods_${it.id}'), item: it, low: low, onTap: tap, onLongPress: hold),
              );
        return i == 1 ? row : Column(children: [const GoodsDivider(), row]);
      },
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({
    this.selectBar,
    required this.shadow,
    required this.search,
    required this.onSearch,
    required this.onClear,
    required this.catCount,
    required this.status,
    required this.countOf,
    required this.onScan,
    this.onMore,
    required this.onNew,
    required this.onFilter,
    required this.onStatus,
    this.branch,
    this.onBranch,
    this.showOut = true,
  });

  /// Đang chọn nhiều: thay hàng tiêu đề bằng thanh "Đã chọn n".
  final Widget? selectBar;

  /// Tên chi nhánh đang xem (null = cửa hàng một chi nhánh, không hiện dòng chọn).
  final String? branch;
  final VoidCallback? onBranch;

  /// Có số tồn thì mới có chip "Hết hàng".
  final bool showOut;
  final bool shadow;
  final TextEditingController search;
  final ValueChanged<String> onSearch;
  final VoidCallback onClear;
  final int catCount;
  final GoodsStatus status;
  final int Function(GoodsStatus)? countOf;
  final VoidCallback onScan;

  /// Ngăn "Tác vụ khác": đổi thứ tự, nhập / xuất tệp. null = danh mục chưa tải xong.
  final VoidCallback? onMore;

  /// null = không có quyền thêm sản phẩm (ẩn nút +).
  final VoidCallback? onNew;
  final VoidCallback? onFilter;
  final ValueChanged<GoodsStatus> onStatus;

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return AnimatedContainer(
      duration: const Duration(milliseconds: 200),
      padding: EdgeInsets.only(top: top + 4),
      decoration: BoxDecoration(
        color: Colors.white,
        boxShadow: [
          const BoxShadow(color: OrderColors.line, offset: Offset(0, 1)),
          if (shadow)
            const BoxShadow(color: Color(0x4D0F172A), offset: Offset(0, 8), blurRadius: 18, spreadRadius: -12),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            height: 48,
            child:
                selectBar ??
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 12, 0),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text(
                              'Hàng hoá',
                              style: TextStyle(
                                color: OrderColors.ink,
                                fontSize: 21,
                                fontWeight: FontWeight.w700,
                                letterSpacing: -.4,
                              ),
                            ),
                            if (branch != null)
                              GestureDetector(
                                key: const Key('goods_branch'),
                                onTap: onBranch,
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    const AppIcon(AppIcons.store, size: 14, color: OrderColors.muted),
                                    const SizedBox(width: 3),
                                    Flexible(
                                      child: Text(
                                        branch!,
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                        style: const TextStyle(color: OrderColors.muted, fontSize: 12.5),
                                      ),
                                    ),
                                    const SizedBox(width: 3),
                                    const AppIcon(AppIcons.down, size: 14, color: OrderColors.muted),
                                  ],
                                ),
                              ),
                          ],
                        ),
                      ),
                      if (onMore != null) ...[
                        _IconBtn(icon: AppIcons.dots, tooltip: 'Tác vụ khác', onTap: onMore!),
                        const SizedBox(width: 2),
                      ],
                      _IconBtn(icon: AppIcons.scan, tooltip: 'Quét mã vạch', onTap: onScan),
                      if (onNew != null) ...[
                        const SizedBox(width: 8),
                        _IconBtn(icon: AppIcons.plus, tooltip: 'Thêm hàng hoá', onTap: onNew!, primary: true),
                      ],
                    ],
                  ),
                ),
          ),
          Container(
            height: 40,
            margin: const EdgeInsets.fromLTRB(12, 4, 12, 0),
            padding: const EdgeInsets.only(left: 12, right: 6),
            decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(10)),
            child: Row(
              children: [
                const AppIcon(AppIcons.search, color: OrderColors.faint),
                const SizedBox(width: 8),
                Expanded(
                  child: TextField(
                    key: const Key('goods_search'),
                    controller: search,
                    onChanged: onSearch,
                    textInputAction: TextInputAction.search,
                    style: const TextStyle(color: OrderColors.ink, fontSize: 16),
                    decoration: const InputDecoration(
                      isCollapsed: true,
                      filled: false,
                      border: InputBorder.none,
                      enabledBorder: InputBorder.none,
                      focusedBorder: InputBorder.none,
                      hintText: 'Tìm tên, mã SKU, mã vạch',
                      hintStyle: TextStyle(color: OrderColors.faint, fontSize: 16),
                    ),
                  ),
                ),
                if (search.text.isNotEmpty)
                  SizedBox(
                    width: 28,
                    height: 28,
                    child: IconButton(
                      tooltip: 'Xoá tìm',
                      padding: EdgeInsets.zero,
                      onPressed: onClear,
                      icon: const AppIcon(AppIcons.close, size: 18, color: OrderColors.faint),
                    ),
                  ),
                Container(
                  width: 32,
                  height: 32,
                  margin: const EdgeInsets.only(left: 4),
                  decoration: const BoxDecoration(
                    border: Border(left: BorderSide(color: OrderColors.line)),
                  ),
                  child: Stack(
                    clipBehavior: Clip.none,
                    children: [
                      Positioned.fill(
                        child: IconButton(
                          key: const Key('goods_filter_btn'),
                          tooltip: 'Lọc theo nhóm hàng',
                          padding: EdgeInsets.zero,
                          onPressed: onFilter,
                          icon: AppIcon(
                            AppIcons.filter,
                            color: catCount > 0 ? OrderColors.primaryInk : OrderColors.text,
                          ),
                        ),
                      ),
                      if (catCount > 0)
                        Positioned(
                          top: -2,
                          right: -2,
                          child: IgnorePointer(
                            child: Container(
                              key: const Key('goods_filter_count'),
                              constraints: const BoxConstraints(minWidth: 15),
                              height: 15,
                              alignment: Alignment.center,
                              padding: const EdgeInsets.symmetric(horizontal: 4),
                              decoration: BoxDecoration(
                                color: OrderColors.primary,
                                borderRadius: BorderRadius.circular(8),
                              ),
                              child: Text(
                                '$catCount',
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 9.5,
                                  fontWeight: FontWeight.w700,
                                  height: 1.1,
                                ),
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            child: Row(
              children: [
                for (final s in GoodsStatus.values)
                  if (s != GoodsStatus.out || showOut) ...[
                    if (s != GoodsStatus.all) const SizedBox(width: 6),
                    GoodsChip(
                      key: Key('goods_st_${s.name}'),
                      label: s.label,
                      count: countOf?.call(s),
                      on: s == status,
                      onTap: () => onStatus(s),
                    ),
                  ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _SelectBar extends StatelessWidget {
  const _SelectBar({required this.count, required this.all, required this.onClose, required this.onAll});

  final int count;
  final bool all;
  final VoidCallback onClose;
  final VoidCallback onAll;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(4, 0, 8, 0),
    child: Row(
      children: [
        IconButton(
          key: const Key('goods_select_close'),
          tooltip: 'Bỏ chọn',
          onPressed: onClose,
          icon: const AppIcon(AppIcons.close, size: 22, color: OrderColors.ink),
        ),
        Expanded(
          child: Text(
            'Đã chọn $count',
            key: const Key('goods_select_count'),
            style: const TextStyle(color: OrderColors.ink, fontSize: 18, fontWeight: FontWeight.w700),
          ),
        ),
        TextButton(
          key: const Key('goods_select_all'),
          onPressed: onAll,
          style: TextButton.styleFrom(
            foregroundColor: OrderColors.primaryInk,
            textStyle: const TextStyle(fontFamily: appFont, fontSize: 14, fontWeight: FontWeight.w600),
          ),
          child: Text(all ? 'Bỏ chọn tất cả' : 'Chọn tất cả'),
        ),
      ],
    ),
  );
}

class _IconBtn extends StatelessWidget {
  const _IconBtn({required this.icon, required this.tooltip, required this.onTap, this.primary = false});

  final AppIcons icon;
  final String tooltip;
  final VoidCallback onTap;
  final bool primary;

  @override
  Widget build(BuildContext context) => Tooltip(
    message: tooltip,
    child: Material(
      color: primary ? OrderColors.primary : Colors.transparent,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: SizedBox(
          width: 40,
          height: 40,
          child: Center(child: AppIcon(icon, size: 22, color: primary ? Colors.white : OrderColors.ink)),
        ),
      ),
    ),
  );
}

class _Empty extends StatelessWidget {
  const _Empty({required this.title, required this.text, this.action, required this.onAction});

  final String title;
  final String text;
  final String? action;
  final VoidCallback onAction;

  @override
  Widget build(BuildContext context) => Container(
    color: Colors.white,
    padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 56),
    child: Column(
      children: [
        const AppIcon(AppIcons.box, size: 44, color: Color(0xFFCBD2DC)),
        const SizedBox(height: 10),
        Text(
          title,
          style: const TextStyle(color: OrderColors.ink, fontSize: 15, fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 4),
        Text(
          text,
          textAlign: TextAlign.center,
          style: const TextStyle(color: OrderColors.muted),
        ),
        if (action != null) ...[
          const SizedBox(height: 14),
          FilledButton(
            key: const Key('goods_reset'),
            onPressed: onAction,
            style: FilledButton.styleFrom(
              backgroundColor: OrderColors.primarySoft,
              foregroundColor: OrderColors.primaryInk,
              minimumSize: const Size(0, 38),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              textStyle: const TextStyle(fontFamily: appFont, fontWeight: FontWeight.w600, fontSize: 14),
            ),
            child: Text(action!),
          ),
        ],
      ],
    ),
  );
}

/// Ngăn "Lọc theo nhóm hàng": chọn nhiều nhóm; nút "Xem N sản phẩm" đếm ngay trên máy.
class _FilterSheet extends StatefulWidget {
  const _FilterSheet({required this.catalog, required this.filter, required this.serverIds});

  final GoodsCatalog catalog;
  final GoodsFilter filter;
  final Set<int>? serverIds;

  @override
  State<_FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends State<_FilterSheet> {
  late final Set<int> _d = {...widget.filter.cats};

  @override
  Widget build(BuildContext context) {
    final f = widget.filter;
    final n = matchGoods(
      widget.catalog,
      query: f.query,
      cats: _d,
      serverIds: widget.serverIds,
    ).where(f.status.has).length;
    final bottom = MediaQuery.viewPaddingOf(context).bottom;
    return ConstrainedBox(
      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * .88),
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
            padding: const EdgeInsets.fromLTRB(20, 0, 12, 10),
            child: Row(
              children: [
                const Expanded(
                  child: Text(
                    'Lọc theo nhóm hàng',
                    style: TextStyle(color: OrderColors.ink, fontSize: 18, fontWeight: FontWeight.w700),
                  ),
                ),
                TextButton(
                  onPressed: () => setState(_d.clear),
                  style: TextButton.styleFrom(
                    foregroundColor: OrderColors.primaryInk,
                    textStyle: const TextStyle(fontFamily: appFont, fontSize: 13, fontWeight: FontWeight.w600),
                  ),
                  child: const Text('Đặt lại'),
                ),
              ],
            ),
          ),
          Flexible(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
              child: widget.catalog.categories.isEmpty
                  ? const Text('Cửa hàng chưa có nhóm hàng nào.', style: TextStyle(color: OrderColors.muted))
                  : Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        for (final c in widget.catalog.categories)
                          GoodsChip(
                            key: Key('goods_cat_${c.id}'),
                            label: c.name,
                            on: _d.contains(c.id),
                            height: 36,
                            fontSize: 13,
                            onTap: () => setState(() => _d.contains(c.id) ? _d.remove(c.id) : _d.add(c.id)),
                          ),
                      ],
                    ),
            ),
          ),
          Container(
            padding: EdgeInsets.fromLTRB(16, 12, 16, bottom + 12),
            decoration: const BoxDecoration(
              border: Border(top: BorderSide(color: OrderColors.line)),
            ),
            child: Row(
              children: [
                Expanded(
                  child: GoodsBtn(label: 'Đóng', onTap: () => Navigator.of(context).pop()),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: GoodsBtn(
                    key: const Key('goods_apply_filter'),
                    label: 'Xem $n sản phẩm',
                    primary: true,
                    onTap: () => Navigator.of(context).pop({..._d}),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
