import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/ui/app_icon.dart';
import '../../auth/auth_controller.dart';
import '../../workspace/workspace.dart' show Branch;
import '../dashboard/dashboard_data.dart' show branchLabel, clockProvider, dashBranchesProvider;
import '../shell/shell_widgets.dart';
import 'order_detail_screen.dart';
import 'order_widgets.dart';
import 'orders_data.dart';

/// Màn Đơn hàng theo design/html/don-hang-mobile.html (v2): sổ chứng từ bán
/// hàng — đơn bán và phiếu trả chung một danh sách, như màn Quản lý đơn hàng PC.
class OrdersScreen extends ConsumerStatefulWidget {
  const OrdersScreen({super.key, this.onNewOrder});

  /// Nút "+" — khung app chuyển sang tab Bán hàng.
  final VoidCallback? onNewOrder;

  @override
  ConsumerState<OrdersScreen> createState() => _OrdersScreenState();
}

class _OrdersScreenState extends ConsumerState<OrdersScreen> {
  final _scroll = ScrollController();
  final _search = TextEditingController();
  Timer? _debounce;
  bool _shadow = false;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _scroll.dispose();
    _search.dispose();
    super.dispose();
  }

  OrdersFilter get _f => ref.read(ordersFilterProvider);

  void _set(OrdersFilter f) {
    ref.read(ordersFilterProvider.notifier).set(f);
    if (_scroll.hasClients) _scroll.jumpTo(0);
  }

  void _onScroll() {
    final s = _scroll.offset > 4;
    if (s != _shadow) setState(() => _shadow = s);
    final q = ref.read(ordersQueryProvider);
    if (q != null && _scroll.position.extentAfter < 600) ref.read(ordersListProvider(q).notifier).loadMore();
  }

  void _onSearch(String v) {
    setState(() {});
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), () => _set(_f.copyWith(query: v)));
  }

  Future<void> _refresh() async {
    final q = ref.read(ordersQueryProvider);
    if (q == null) return;
    ref.invalidate(ordersListProvider(q));
    ref.invalidate(ordersSummaryProvider(q));
    ref.invalidate(orderDetailProvider);
    try {
      await ref.read(ordersListProvider(q).future);
    } on ApiException catch (e) {
      if (mounted) showDashToast(context, e.message);
    }
  }

  void _open(SoDonRow r) => Navigator.of(
    context,
    rootNavigator: true,
  ).push(MaterialPageRoute<void>(builder: (_) => OrderDetailScreen(row: r)));

  Future<void> _pickBranch() async {
    final cur = ref.read(ordersQueryProvider)?.shopId;
    final branches = await ref.read(dashBranchesProvider.future);
    if (!mounted) return;
    final id = await showDashSheet<int>(
      context,
      title: 'Xem chi nhánh',
      body: (ctx) => Column(
        children: [
          for (final b in [...branches, const Branch(id: 0, name: 'Tất cả chi nhánh')])
            SheetOption(
              key: Key('orders_branch_${b.id}'),
              label: b.name,
              trailing: b.isHq ? 'Trụ sở' : null,
              selected: cur == b.id,
              onTap: () => Navigator.of(ctx).pop(b.id),
            ),
        ],
      ),
    );
    if (id != null) _set(_f.copyWith(shopId: id));
  }

  Future<void> _pickSort() async {
    final s = await showDashSheet<OrderSort>(
      context,
      title: 'Sắp xếp',
      body: (ctx) => Column(
        children: [
          for (final o in OrderSort.values)
            SheetOption(
              key: Key('sort_${o.name}'),
              label: o.label,
              selected: _f.sort == o,
              onTap: () => Navigator.of(ctx).pop(o),
            ),
        ],
      ),
    );
    if (s != null) _set(_f.copyWith(sort: s));
  }

  Future<void> _openFilter() async {
    final applied = await showModalBottomSheet<OrdersFilter>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      backgroundColor: Colors.white,
      barrierColor: const Color(0x73111827),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => _FilterSheet(initial: ref.read(ordersQueryProvider) ?? _f),
    );
    if (applied != null) _set(applied.copyWith(shopId: _f.shopId));
  }

  void _onKpi(String k) {
    final f = _f;
    _set(switch (k) {
      'unpaid' => f.copyWith(pays: {'unpaid', 'partial'}, tab: OrderTab.all),
      'cancelled' => f.copyWith(tab: OrderTab.cancelled),
      _ => f.copyWith(tab: OrderTab.returned),
    });
  }

  @override
  Widget build(BuildContext context) {
    final f = ref.watch(ordersFilterProvider);
    final q = ref.watch(ordersQueryProvider);
    final branches = ref.watch(dashBranchesProvider).value ?? const [];
    final procOk = ref.watch(procStatusSupportedProvider);
    final list = q == null ? const AsyncLoading<OrdersPage>() : ref.watch(ordersListProvider(q));
    final summary = q == null ? null : ref.watch(ordersSummaryProvider(q)).value;
    final allCount = q == null ? null : ref.watch(ordersListProvider(q.copyWith(tab: OrderTab.all))).value?.total;
    final dem = list.value?.dem;

    int? countOf(OrderTab t) => switch (t) {
      OrderTab.all => allCount,
      OrderTab.cancelled => summary?.cancelled,
      OrderTab.returned => summary?.returnedRows,
      _ => dem?[t.name],
    };
    final tabs = [
      for (final t in OrderTab.values)
        if (!t.needsProcStatus || procOk) t,
    ];

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.dark,
      child: ColoredBox(
        color: OrderColors.page,
        child: Column(
          children: [
            _Header(
              shadow: _shadow,
              branch: q == null ? '…' : branchLabel(q.shopId, branches),
              search: _search,
              onSearch: _onSearch,
              onClear: () {
                _search.clear();
                _onSearch('');
              },
              filterCount: f.filterCount,
              time: f.time,
              tabs: tabs,
              tab: f.tab,
              countOf: countOf,
              onBranch: _pickBranch,
              onScan: () => showDashToast(context, 'Quét mã vạch trên hoá đơn để mở đúng đơn — sắp có'),
              onNew: widget.onNewOrder ?? () => showDashToast(context, 'Tạo đơn mới → màn Bán hàng'),
              onFilter: _openFilter,
              onTime: (t) => _set(f.copyWith(time: t)),
              onTab: (t) => _set(f.copyWith(tab: t)),
            ),
            Expanded(
              child: RefreshIndicator(color: OrderColors.primary, onRefresh: _refresh, child: _body(f, list, summary)),
            ),
          ],
        ),
      ),
    );
  }

  Widget _body(OrdersFilter f, AsyncValue<OrdersPage> list, OrdersSummary? summary) {
    final now = ref.read(clockProvider)();
    final page = list.value;
    final items = <Widget>[
      if (summary != null) OrdersSummaryCard(s: summary, timeLabel: f.time.label, onKpi: _onKpi),
      if (f.filterCount > 0) _ActiveChips(filter: f, onRemove: _set),
      Padding(
        padding: const EdgeInsets.fromLTRB(4, 14, 4, 0),
        child: Row(
          children: [
            Expanded(
              child: Text(
                page == null ? '' : '${page.total} đơn',
                style: const TextStyle(color: OrderColors.muted, fontSize: 12.5),
              ),
            ),
            TextButton.icon(
              key: const Key('sort_btn'),
              onPressed: _pickSort,
              style: TextButton.styleFrom(
                foregroundColor: OrderColors.ink,
                minimumSize: const Size(0, 30),
                padding: const EdgeInsets.symmetric(horizontal: 4),
                textStyle: const TextStyle(fontFamily: appFont, fontSize: 12.5, fontWeight: FontWeight.w600),
              ),
              icon: const AppIcon(AppIcons.sort, size: 15, color: OrderColors.muted),
              label: Text(f.sort.label),
            ),
          ],
        ),
      ),
    ];

    if (list.hasError && page == null) {
      final e = list.error;
      items.add(
        _Empty(
          title: 'Không tải được đơn hàng',
          text: e is ApiException ? e.message : 'Thử lại sau ít phút.',
          action: 'Thử lại',
          onAction: _refresh,
        ),
      );
    } else if (page == null) {
      items.add(
        const Padding(
          padding: EdgeInsets.all(40),
          child: Center(child: CircularProgressIndicator(color: OrderColors.primary)),
        ),
      );
    } else if (page.rows.isEmpty) {
      final narrowed = f.filterCount > 0 || f.query.isNotEmpty || f.tab != OrderTab.all;
      items.add(
        _Empty(
          title: 'Chưa có đơn nào',
          text: f.time == OrderTime.today
              ? 'Hôm nay chưa bán được đơn nào.'
              : 'Thử đổi thời gian, bỏ bớt bộ lọc hoặc từ khoá.',
          action: narrowed ? 'Xoá lọc' : null,
          onAction: () {
            _search.clear();
            _set(f.clearFilters());
          },
        ),
      );
    } else {
      DateTime key(SoDonRow r) => f.sort == OrderSort.updated ? r.updatedAt : r.createdAt;
      String day(DateTime d) => '${d.year}-${d.month}-${d.day}';
      if (!f.sort.byTime) items.add(const SizedBox(height: 8));
      String? last;
      for (final r in page.rows) {
        if (f.sort.byTime && day(key(r)) != last) {
          last = day(key(r));
          final cur = last;
          items.add(DayHeader(label: dayLabel(key(r), now), count: page.rows.where((x) => day(key(x)) == cur).length));
        }
        items.add(OrderCard(row: r, onTap: () => _open(r)));
      }
      items.add(
        Padding(
          padding: const EdgeInsets.fromLTRB(0, 14, 0, 4),
          child: Center(
            child: page.hasMore
                ? const SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(strokeWidth: 2, color: OrderColors.primary),
                  )
                : Text('Đã hiện hết ${page.total} đơn', style: const TextStyle(color: OrderColors.faint, fontSize: 12)),
          ),
        ),
      );
    }

    return ListView.builder(
      controller: _scroll,
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(10, 12, 10, 18),
      itemCount: items.length,
      itemBuilder: (_, i) => items[i],
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({
    required this.shadow,
    required this.branch,
    required this.search,
    required this.onSearch,
    required this.onClear,
    required this.filterCount,
    required this.time,
    required this.tabs,
    required this.tab,
    required this.countOf,
    required this.onBranch,
    required this.onScan,
    required this.onNew,
    required this.onFilter,
    required this.onTime,
    required this.onTab,
  });

  final bool shadow;
  final String branch;
  final TextEditingController search;
  final ValueChanged<String> onSearch;
  final VoidCallback onClear;
  final int filterCount;
  final OrderTime time;
  final List<OrderTab> tabs;
  final OrderTab tab;
  final int? Function(OrderTab) countOf;
  final VoidCallback onBranch;
  final VoidCallback onScan;
  final VoidCallback onNew;
  final VoidCallback onFilter;
  final ValueChanged<OrderTime> onTime;
  final ValueChanged<OrderTab> onTab;

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return AnimatedContainer(
      duration: const Duration(milliseconds: 200),
      padding: EdgeInsets.only(top: top + 4),
      decoration: BoxDecoration(
        color: Colors.white,
        boxShadow: shadow
            ? const [
                BoxShadow(color: OrderColors.line, offset: Offset(0, 1)),
                BoxShadow(color: Color(0x400F172A), offset: Offset(0, 6), blurRadius: 16, spreadRadius: -10),
              ]
            : const [],
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
                  Expanded(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'Đơn hàng',
                          style: TextStyle(
                            color: OrderColors.ink,
                            fontSize: 21,
                            fontWeight: FontWeight.w700,
                            letterSpacing: -.4,
                          ),
                        ),
                        GestureDetector(
                          key: const Key('orders_branch'),
                          onTap: onBranch,
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              const AppIcon(AppIcons.store, size: 14, color: OrderColors.muted),
                              const SizedBox(width: 3),
                              Flexible(
                                child: Text(
                                  branch,
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
                  _IconBtn(icon: AppIcons.scan, tooltip: 'Quét mã đơn', onTap: onScan),
                  const SizedBox(width: 8),
                  _IconBtn(icon: AppIcons.plus, tooltip: 'Tạo đơn', onTap: onNew, primary: true),
                ],
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 6, 12, 0),
            child: Row(
              children: [
                Expanded(
                  child: Container(
                    height: 42,
                    padding: const EdgeInsets.only(left: 12, right: 6),
                    decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(12)),
                    child: Row(
                      children: [
                        const AppIcon(AppIcons.search, color: OrderColors.faint),
                        const SizedBox(width: 8),
                        Expanded(
                          child: TextField(
                            key: const Key('orders_search'),
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
                              hintText: 'Tìm mã đơn, tên, SĐT khách',
                              hintStyle: TextStyle(color: OrderColors.faint, fontSize: 16),
                            ),
                          ),
                        ),
                        if (search.text.isNotEmpty)
                          IconButton(
                            tooltip: 'Xoá tìm',
                            onPressed: onClear,
                            icon: const AppIcon(AppIcons.close, size: 18, color: OrderColors.faint),
                            visualDensity: VisualDensity.compact,
                          ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Stack(
                  clipBehavior: Clip.none,
                  children: [
                    Material(
                      color: filterCount > 0 ? OrderColors.primarySoft : OrderColors.grid,
                      borderRadius: BorderRadius.circular(12),
                      child: InkWell(
                        key: const Key('filter_btn'),
                        borderRadius: BorderRadius.circular(12),
                        onTap: onFilter,
                        child: Container(
                          height: 42,
                          padding: const EdgeInsets.symmetric(horizontal: 12),
                          child: Row(
                            children: [
                              AppIcon(
                                AppIcons.filter,
                                size: 18,
                                color: filterCount > 0 ? OrderColors.primaryInk : OrderColors.ink,
                              ),
                              const SizedBox(width: 6),
                              Text(
                                'Lọc',
                                style: TextStyle(
                                  color: filterCount > 0 ? OrderColors.primaryInk : OrderColors.ink,
                                  fontSize: 13.5,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                    if (filterCount > 0)
                      Positioned(
                        top: -4,
                        right: -4,
                        child: Container(
                          constraints: const BoxConstraints(minWidth: 18),
                          height: 18,
                          alignment: Alignment.center,
                          padding: const EdgeInsets.symmetric(horizontal: 5),
                          decoration: BoxDecoration(
                            color: OrderColors.primary,
                            borderRadius: BorderRadius.circular(9),
                            border: Border.all(color: Colors.white, width: 2),
                          ),
                          child: Text(
                            '$filterCount',
                            style: const TextStyle(color: Colors.white, fontSize: 10.5, height: 1.1),
                          ),
                        ),
                      ),
                  ],
                ),
              ],
            ),
          ),
          // Vài mục nên dựng hết (SingleChildScrollView) — mục đang chọn tự cuộn vào tầm nhìn.
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
            child: Row(
              children: [
                for (final t in OrderTime.values) ...[
                  _TimeChip(label: t.label, on: t == time, onTap: () => onTime(t), key: Key('time_${t.name}')),
                  const SizedBox(width: 6),
                ],
              ],
            ),
          ),
          const SizedBox(height: 6),
          Container(
            height: 44,
            decoration: const BoxDecoration(
              border: Border(bottom: BorderSide(color: OrderColors.line)),
            ),
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 6),
              child: Row(
                children: [
                  for (final t in tabs)
                    _StatusTab(
                      key: Key('tab_${t.name}'),
                      tab: t,
                      count: countOf(t),
                      on: t == tab,
                      onTap: () => onTab(t),
                    ),
                ],
              ),
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
      color: primary ? OrderColors.primary : OrderColors.grid,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: SizedBox(
          width: 40,
          height: 40,
          child: Center(child: AppIcon(icon, color: primary ? Colors.white : OrderColors.ink)),
        ),
      ),
    ),
  );
}

class _TimeChip extends StatelessWidget {
  const _TimeChip({super.key, required this.label, required this.on, required this.onTap});

  final String label;
  final bool on;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    if (on) _reveal(context);
    return Material(
      color: on ? OrderColors.navy : Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(color: on ? OrderColors.navy : OrderColors.line),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Container(
          height: 32,
          padding: const EdgeInsets.symmetric(horizontal: 12),
          alignment: Alignment.center,
          child: Text(
            label,
            style: TextStyle(color: on ? Colors.white : OrderColors.text, fontSize: 12.5, fontWeight: FontWeight.w600),
          ),
        ),
      ),
    );
  }
}

/// Đưa mục đang chọn của dải cuộn ngang vào giữa tầm nhìn.
void _reveal(BuildContext context) => WidgetsBinding.instance.addPostFrameCallback((_) {
  if (context.mounted) Scrollable.ensureVisible(context, alignment: .5, duration: const Duration(milliseconds: 200));
});

class _StatusTab extends StatelessWidget {
  const _StatusTab({super.key, required this.tab, required this.count, required this.on, required this.onTap});

  final OrderTab tab;
  final int? count;
  final bool on;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = on ? OrderColors.primaryInk : OrderColors.muted;
    if (on) _reveal(context);
    return Semantics(
      selected: on,
      button: true,
      child: InkWell(
        onTap: onTap,
        child: Stack(
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 11),
              child: Center(
                child: Text.rich(
                  TextSpan(
                    text: tab.label,
                    children: [
                      if (count != null)
                        TextSpan(
                          text: ' $count',
                          style: TextStyle(color: on ? OrderColors.primaryInk : OrderColors.faint),
                        ),
                    ],
                  ),
                  style: TextStyle(
                    color: c,
                    fontSize: 13.5,
                    fontWeight: FontWeight.w600,
                    fontFeatures: const [FontFeature.tabularFigures()],
                  ),
                ),
              ),
            ),
            if (on)
              Positioned(
                left: 11,
                right: 11,
                bottom: 0,
                height: 2.5,
                child: Container(
                  decoration: BoxDecoration(color: OrderColors.primary, borderRadius: BorderRadius.circular(2)),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _ActiveChips extends StatelessWidget {
  const _ActiveChips({required this.filter, required this.onRemove});

  final OrdersFilter filter;
  final ValueChanged<OrdersFilter> onRemove;

  @override
  Widget build(BuildContext context) {
    final f = filter;
    final chips = <(String, OrdersFilter)>[
      for (final p in f.pays) (payLabels[p] ?? p, f.copyWith(pays: {...f.pays}..remove(p))),
      for (final m in f.methods) (methodLabels[m] ?? m, f.copyWith(methods: {...f.methods}..remove(m))),
      for (final c in f.channels) (channelLabels[c] ?? c, f.copyWith(channels: {...f.channels}..remove(c))),
      if (f.creators.isNotEmpty) ('${f.creators.length} nhân viên', f.copyWith(creators: const {})),
    ];
    return Padding(
      padding: const EdgeInsets.fromLTRB(2, 10, 2, 0),
      child: Wrap(
        spacing: 6,
        runSpacing: 6,
        children: [
          for (final (label, next) in chips)
            Material(
              color: OrderColors.primarySoft,
              borderRadius: BorderRadius.circular(14),
              child: InkWell(
                borderRadius: BorderRadius.circular(14),
                onTap: () => onRemove(next),
                child: Container(
                  height: 28,
                  padding: const EdgeInsets.fromLTRB(10, 0, 6, 0),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        label,
                        style: const TextStyle(
                          color: OrderColors.primaryInk,
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(width: 4),
                      const AppIcon(AppIcons.close, size: 14, color: OrderColors.primaryInk),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _Empty extends StatelessWidget {
  const _Empty({required this.title, required this.text, this.action, required this.onAction});

  final String title;
  final String text;
  final String? action;
  final VoidCallback onAction;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 48),
    child: Column(
      children: [
        const AppIcon(AppIcons.receipt, size: 44, color: Color(0xFFCBD2DC)),
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
            onPressed: onAction,
            style: FilledButton.styleFrom(
              backgroundColor: OrderColors.primarySoft,
              foregroundColor: OrderColors.primaryInk,
              minimumSize: const Size(0, 38),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(11)),
              textStyle: const TextStyle(fontFamily: appFont, fontWeight: FontWeight.w600, fontSize: 14),
            ),
            child: Text(action!),
          ),
        ],
      ],
    ),
  );
}

/// Ngăn lọc: chọn nhiều trong từng nhóm; nút "Xem N đơn" đếm trước bằng API.
class _FilterSheet extends ConsumerStatefulWidget {
  const _FilterSheet({required this.initial});

  final OrdersFilter initial;

  @override
  ConsumerState<_FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends ConsumerState<_FilterSheet> {
  late OrdersFilter _d = widget.initial;
  int? _preview;
  Timer? _t;
  int _seq = 0;

  @override
  void initState() {
    super.initState();
    _count();
  }

  @override
  void dispose() {
    _t?.cancel();
    super.dispose();
  }

  void _count() {
    _t?.cancel();
    final seq = ++_seq;
    _t = Timer(const Duration(milliseconds: 200), () async {
      try {
        final now = ref.read(clockProvider)();
        final p = await ref
            .read(apiClientProvider)
            .getPage('/admin/orders/so-don', query: {..._d.query$(now), 'page_size': '1'});
        if (mounted && seq == _seq) setState(() => _preview = p.total);
      } on ApiException {
        if (mounted && seq == _seq) setState(() => _preview = null);
      }
    });
  }

  void _toggle<T>(Set<T> cur, T v, OrdersFilter Function(Set<T>) apply) {
    final n = {...cur};
    n.contains(v) ? n.remove(v) : n.add(v);
    setState(() => _d = apply(n));
    _count();
  }

  @override
  Widget build(BuildContext context) {
    final creators = ref.watch(orderCreatorsProvider).value ?? const [];
    final bottom = MediaQuery.viewPaddingOf(context).bottom;
    Widget group(String title, List<Widget> opts) => Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title.toUpperCase(),
            style: const TextStyle(
              color: OrderColors.muted,
              fontSize: 11.5,
              fontWeight: FontWeight.w700,
              letterSpacing: .7,
            ),
          ),
          const SizedBox(height: 8),
          Wrap(spacing: 8, runSpacing: 8, children: opts),
        ],
      ),
    );
    Widget opt(String label, bool on, VoidCallback onTap, {Key? key}) => Material(
      key: key,
      color: on ? OrderColors.primarySoft : Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(18),
        side: BorderSide(color: on ? OrderColors.primary : OrderColors.line),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: onTap,
        child: Container(
          height: 36,
          padding: const EdgeInsets.symmetric(horizontal: 13),
          // widthFactor 1: nút ôm theo chữ, không giãn hết dòng trong Wrap.
          child: Center(
            widthFactor: 1,
            child: Text(
              label,
              style: TextStyle(
                color: on ? OrderColors.primaryInk : OrderColors.text,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ),
      ),
    );

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
                    'Lọc đơn hàng',
                    style: TextStyle(color: OrderColors.ink, fontSize: 18, fontWeight: FontWeight.w700),
                  ),
                ),
                TextButton(
                  onPressed: () {
                    setState(
                      () => _d = _d.copyWith(pays: const {}, methods: const {}, channels: const {}, creators: const {}),
                    );
                    _count();
                  },
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
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  group('Thanh toán', [
                    for (final e in payLabels.entries)
                      opt(
                        e.value,
                        _d.pays.contains(e.key),
                        () => _toggle(_d.pays, e.key, (s) => _d.copyWith(pays: s)),
                        key: Key('f_pay_${e.key}'),
                      ),
                  ]),
                  group('Hình thức thu', [
                    for (final e in methodLabels.entries)
                      opt(
                        e.value,
                        _d.methods.contains(e.key),
                        () => _toggle(_d.methods, e.key, (s) => _d.copyWith(methods: s)),
                      ),
                  ]),
                  group('Kênh bán', [
                    for (final e in channelLabels.entries)
                      opt(
                        e.value,
                        _d.channels.contains(e.key),
                        () => _toggle(_d.channels, e.key, (s) => _d.copyWith(channels: s)),
                        key: Key('f_ch_${e.key}'),
                      ),
                  ]),
                  if (creators.isNotEmpty)
                    group('Nhân viên tạo', [
                      for (final (id, name) in creators)
                        opt(
                          name,
                          _d.creators.contains(id),
                          () => _toggle(_d.creators, id, (s) => _d.copyWith(creators: s)),
                        ),
                    ]),
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
                  child: _SheetBtn(label: 'Đóng', onTap: () => Navigator.of(context).pop()),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _SheetBtn(
                    key: const Key('apply_filter'),
                    label: _preview == null ? 'Áp dụng' : 'Xem $_preview đơn',
                    primary: true,
                    onTap: () => Navigator.of(context).pop(_d),
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

class _SheetBtn extends StatelessWidget {
  const _SheetBtn({super.key, required this.label, required this.onTap, this.primary = false});

  final String label;
  final VoidCallback onTap;
  final bool primary;

  @override
  Widget build(BuildContext context) => Material(
    color: primary ? OrderColors.primary : OrderColors.grid,
    borderRadius: BorderRadius.circular(12),
    child: InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: SizedBox(
        height: 48,
        child: Center(
          child: Text(
            label,
            style: TextStyle(
              color: primary ? Colors.white : OrderColors.ink,
              fontSize: 15,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      ),
    ),
  );
}
