import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/ui/app_icon.dart';
import '../shell/shell_widgets.dart';
import 'goods_data.dart';
import 'goods_widgets.dart';
import 'product_detail_screen.dart';
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
    final s = await showDashSheet<GoodsSort>(
      context,
      title: 'Sắp xếp',
      body: (ctx) => Column(
        children: [
          for (final o in GoodsSort.values)
            SheetOption(
              key: Key('goods_sort_${o.name}'),
              label: o.label,
              selected: _f.sort == o,
              onTap: () => Navigator.of(ctx).pop(o),
            ),
        ],
      ),
    );
    if (s != null) _set(_f.copyWith(sort: s));
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

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.dark,
      child: ColoredBox(
        color: OrderColors.page,
        child: Column(
          children: [
            _Header(
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
              onNew: _add,
              onFilter: c == null ? null : () => _openFilter(c, serverIds),
              onStatus: (s) => _set(f.copyWith(status: s)),
            ),
            Expanded(
              child: RefreshIndicator(color: OrderColors.primary, onRefresh: _refresh, child: _body(f, cat, base)),
            ),
          ],
        ),
      ),
    );
  }

  Widget _body(GoodsFilter f, AsyncValue<GoodsCatalog> cat, List<GoodsItem> base) {
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

    final rows = [...base.where(f.status.has)]..sort(f.sort.compare);
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
          return Padding(
            padding: const EdgeInsets.fromLTRB(0, 16, 0, 6),
            child: Center(
              child: Text(
                'Đã hiện hết ${rows.length} sản phẩm',
                style: const TextStyle(color: OrderColors.faint, fontSize: 12),
              ),
            ),
          );
        }
        final it = rows[i - 1];
        final row = GoodsRow(key: Key('goods_${it.id}'), item: it, onTap: () => _open(it));
        return i == 1 ? row : Column(children: [const GoodsDivider(), row]);
      },
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({
    required this.shadow,
    required this.search,
    required this.onSearch,
    required this.onClear,
    required this.catCount,
    required this.status,
    required this.countOf,
    required this.onScan,
    required this.onNew,
    required this.onFilter,
    required this.onStatus,
  });

  final bool shadow;
  final TextEditingController search;
  final ValueChanged<String> onSearch;
  final VoidCallback onClear;
  final int catCount;
  final GoodsStatus status;
  final int Function(GoodsStatus)? countOf;
  final VoidCallback onScan;
  final VoidCallback onNew;
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
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 12, 0),
              child: Row(
                children: [
                  const Expanded(
                    child: Text(
                      'Hàng hoá',
                      style: TextStyle(
                        color: OrderColors.ink,
                        fontSize: 21,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -.4,
                      ),
                    ),
                  ),
                  _IconBtn(icon: AppIcons.scan, tooltip: 'Quét mã vạch', onTap: onScan),
                  const SizedBox(width: 8),
                  _IconBtn(icon: AppIcons.plus, tooltip: 'Thêm hàng hoá', onTap: onNew, primary: true),
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
                for (final s in GoodsStatus.values) ...[
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
