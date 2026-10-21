import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_number.dart';
import '../../../core/format/vn_text.dart';
import '../../../core/ui/app_icon.dart';
import '../../auth/permissions.dart';
import '../goods/goods_data.dart';
import '../goods/product_form_data.dart' show vatName;
import '../shell/more_menu.dart' show MoreHost;
import '../shell/shell_widgets.dart';
import 'categories_data.dart';

const _low = Color(0xFFB45309), _lowBg = Color(0xFFFFF4E5), _out = Color(0xFFB91C1C);

/// Nhóm hàng hoá theo design/html/nhom-hang-mobile.html (v3): cùng chức năng màn "Quản lý
/// nhóm hàng hoá" của PC (cây bên trái + bảng nhóm con), đổi sang chạm vào từng tầng.
/// Nằm trong khung app (mở từ Thêm, thanh dưới vẫn hiện) — màn đầu không có nút lùi.
class CategoriesScreen extends ConsumerStatefulWidget {
  const CategoriesScreen({super.key, required this.host});

  final MoreHost host;

  @override
  ConsumerState<CategoriesScreen> createState() => _CategoriesScreenState();
}

class _CategoriesScreenState extends ConsumerState<CategoriesScreen> {
  final _search = TextEditingController();
  final _scroll = ScrollController();
  Timer? _debounce;

  /// Đường đang đứng: id nhóm từ gốc xuống; rỗng = màn đầu.
  List<int> _path = [];
  String _q = '';

  /// Đang chọn nhiều để xoá (null = không).
  Set<int>? _sel;

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    _scroll.dispose();
    super.dispose();
  }

  CategoryTree? get _tree => ref.read(categoryTreeProvider).value;

  void _jumpTop() {
    if (_scroll.hasClients) _scroll.jumpTo(0);
  }

  void _go(int id) {
    final t = _tree;
    final c = t?.byId(id);
    if (t == null || c == null) return;
    _search.clear();
    setState(() {
      _q = '';
      _sel = null;
      _path = [...t.pathOf(c).map((x) => x.id), id];
    });
    _jumpTop();
  }

  /// Lùi một bậc: thoát chọn → thoát tìm → lên nhóm cha → rời màn.
  void _up() {
    if (_sel != null) return setState(() => _sel = null);
    if (_q.isNotEmpty) {
      _search.clear();
      return setState(() => _q = '');
    }
    if (_path.isEmpty) return widget.host.close();
    setState(() => _path = _path.sublist(0, _path.length - 1));
    _jumpTop();
  }

  void _onSearch(String v) {
    setState(() {});
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 180), () {
      if (mounted) setState(() => _q = v.trim());
    });
  }

  void _toast(String text, {String? action, VoidCallback? onAction}) {
    final m = ScaffoldMessenger.of(context);
    m.hideCurrentSnackBar();
    m.showSnackBar(
      SnackBar(
        content: Text(text, style: const TextStyle(fontFamily: appFont, fontSize: 13)),
        behavior: SnackBarBehavior.floating,
        backgroundColor: OrderColors.ink,
        duration: Duration(milliseconds: action == null ? 2600 : 4500),
        margin: const EdgeInsets.fromLTRB(16, 0, 16, 14),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        action: action == null
            ? null
            : SnackBarAction(label: action, textColor: const Color(0xFF8EC5FF), onPressed: onAction ?? () {}),
      ),
    );
  }

  /// Bật/tắt qua ⋯ (dòng không có công tắc — chạm nhầm lúc cuộn là tắt mất nhóm), có Hoàn tác.
  Future<void> _setActive(Category c, bool on) async {
    final repo = ref.read(categoryRepoProvider);
    try {
      await repo.update(c, active: on);
      repo.refresh();
    } on ApiException catch (e) {
      return _toast(e.message);
    }
    if (!mounted) return;
    _toast(
      on ? 'Đã dùng lại ${c.name}' : 'Đã ngừng dùng ${c.name}',
      action: 'Hoàn tác',
      onAction: () async {
        try {
          await repo.update(c, active: !on);
          repo.refresh();
        } on ApiException catch (e) {
          if (mounted) _toast(e.message);
        }
      },
    );
  }

  /// Sang Hàng hoá lọc sẵn nhóm này (nhóm con tự tính theo bộ lọc nhóm).
  void _goGoods(Category c) {
    final f = ref.read(goodsFilterProvider);
    ref.read(goodsFilterProvider.notifier).set(GoodsFilter(cats: {c.id}, sort: f.sort));
    widget.host.goTab(AdminTab.goods);
  }

  Future<void> _actions(Category c, CategoryTree t, {bool here = false}) async {
    final perms = permsOf(ref);
    final k = t.kids(c.id).length, p = t.products(c.id);
    final why = t.whyNotDelete(c);
    final pick = await showDashSheet<String>(
      context,
      title: c.name,
      subtitle: [c.code, if (k > 0) '$k nhóm con', '${dot(p)} sản phẩm'].join(' · '),
      body: (ctx) {
        Widget item(String key, AppIcons icon, String label, {String? note, bool danger = false, bool off = false}) =>
            _ActionRow(
              key: Key('cat_act_$key'),
              icon: icon,
              label: label,
              note: note,
              danger: danger,
              off: off,
              onTap: () => Navigator.of(ctx).pop(key),
            );
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (!here) item('open', AppIcons.right, 'Mở nhóm'),
            if (perms.can(Quyen.suaNhom)) item('edit', AppIcons.edit, 'Sửa tên, VAT, nhóm con'),
            if (perms.can(Quyen.themNhom)) item('add', AppIcons.sub, 'Thêm nhóm con'),
            if (perms.can(Quyen.suaNhom))
              c.active
                  ? item('toggle', AppIcons.eyeOff, 'Ngừng dùng', note: 'Ẩn khỏi ô chọn nhóm khi thêm hàng')
                  : item('toggle', AppIcons.check, 'Dùng lại'),
            if (!here) item('goods', AppIcons.box, 'Xem ${dot(p)} sản phẩm'),
            if (perms.can(Quyen.xoaNhom))
              item(
                'del',
                AppIcons.trash,
                'Xoá nhóm',
                danger: true,
                off: why != null,
                note: k > 0
                    ? 'Đang chứa nhóm con — xoá nhóm con trước'
                    : p > 0
                    ? 'Đang có sản phẩm — chuyển hàng sang nhóm khác trước'
                    : null,
              ),
          ],
        );
      },
    );
    if (!mounted || pick == null) return;
    switch (pick) {
      case 'open':
        _go(c.id);
      case 'edit':
        await _openForm(t, editing: c);
      case 'add':
        await _openForm(t, parentId: c.id);
      case 'toggle':
        await _setActive(c, !c.active);
      case 'goods':
        _goGoods(c);
      case 'del':
        if (why != null) {
          return _toast(
            k > 0
                ? 'Nhóm đang chứa nhóm con — hãy xoá cấp dưới trước.'
                : 'Nhóm đang có ${dot(p)} sản phẩm — chuyển hàng sang nhóm khác trước.',
          );
        }
        await _askDelete([c], t);
    }
  }

  Future<void> _askDelete(List<Category> cs, CategoryTree t) async {
    final ok = [
      for (final c in cs)
        if (t.whyNotDelete(c) == null) c,
    ];
    final no = [
      for (final c in cs)
        if (t.whyNotDelete(c) != null) c,
    ];
    if (cs.length == 1 && no.isNotEmpty) return _toast('Không xoá được: ${no.first.name} ${t.whyNotDelete(no.first)}.');
    final yes = await showDashSheet<bool>(
      context,
      title: ok.length == 1 ? 'Xoá nhóm “${ok.first.name}”?' : 'Xoá ${ok.length} nhóm?',
      subtitle: 'Không hoàn tác được.',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (ok.length > 1)
            Padding(
              padding: const EdgeInsets.fromLTRB(4, 0, 4, 10),
              child: Text(
                ok.map((c) => c.name).join(', '),
                style: const TextStyle(color: OrderColors.text, fontSize: 13.5),
              ),
            ),
          if (no.isNotEmpty)
            _Warn(
              key: const Key('cat_del_skip'),
              text: 'Bỏ qua ${no.length} nhóm: ${no.map((c) => '${c.name} (${t.whyNotDelete(c)})').join(', ')}.',
            ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _Btn(label: 'Huỷ', onTap: () => Navigator.of(ctx).pop(false)),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _Btn(
                  key: const Key('cat_del_yes'),
                  label: ok.length > 1 ? 'Xoá ${ok.length} nhóm' : 'Xoá',
                  icon: AppIcons.trash,
                  color: _out,
                  onTap: ok.isEmpty ? null : () => Navigator.of(ctx).pop(true),
                ),
              ),
            ],
          ),
        ],
      ),
    );
    if (yes != true || !mounted) return;
    final repo = ref.read(categoryRepoProvider);
    var done = 0;
    String? err;
    for (final c in ok) {
      try {
        await repo.delete(c.id);
        done++;
      } on ApiException catch (e) {
        err ??= e.message;
      }
    }
    repo.refresh();
    if (!mounted) return;
    final gone = {for (final c in ok) c.id};
    setState(() {
      _sel = null;
      _path = [
        for (final id in _path)
          if (!gone.contains(id)) id,
      ];
    });
    _toast(
      err != null
          ? 'Đã xoá $done/${ok.length} nhóm — $err'
          : done == 1
          ? 'Đã xoá ${ok.first.name}'
          : 'Đã xoá $done nhóm${no.isNotEmpty ? '; bỏ qua ${no.length}' : ''}',
    );
  }

  Future<void> _openForm(CategoryTree t, {Category? editing, int? parentId}) async {
    final res = await showModalBottomSheet<SaveResult>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      backgroundColor: Colors.white,
      barrierColor: const Color(0x73111827),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (_) => CategoryForm(tree: t, editing: editing, parentId: editing?.parentId ?? parentId!),
    );
    if (res == null || !mounted) return;
    final kids = res.newKids > 0 ? ' + ${res.newKids} nhóm con' : '';
    final fail = res.failedKids > 0 ? ' (${res.failedKids} nhóm con chưa lưu được)' : '';
    if (editing != null) return _toast('Đã cập nhật ${res.cat.name}$kids$fail');
    _toast('Đã thêm ${res.cat.name}$kids$fail', action: 'Mở', onAction: () => _go(res.cat.id));
  }

  /// Nút +: đang đứng trong nhóm thì thêm vào nhóm ấy (PC: nhóm đang chọn trên cây);
  /// ở màn đầu thì hỏi bỏ vào nhóm gốc nào.
  Future<void> _add(CategoryTree t) async {
    final cur = _path.isEmpty ? null : t.byId(_path.last);
    if (cur != null) return _openForm(t, parentId: cur.id);
    final id = await showDashSheet<int>(
      context,
      title: 'Thêm nhóm vào đâu?',
      subtitle: 'Nhóm mới luôn nằm trong một nhóm có sẵn.',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final r in t.roots)
            _ActionRow(
              key: Key('cat_pick_${r.id}'),
              icon: AppIcons.layers,
              label: r.name,
              note: '${t.kids(r.id).length} nhóm con',
              onTap: () => Navigator.of(ctx).pop(r.id),
            ),
        ],
      ),
    );
    if (id != null && mounted) await _openForm(t, parentId: id);
  }

  void _toggleSel(Category c) {
    if (c.locked) return;
    setState(() => _sel!.contains(c.id) ? _sel!.remove(c.id) : _sel!.add(c.id));
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(categoryTreeProvider);
    final t = async.value;
    final perms = permsOf(ref);
    final cur = t == null || _path.isEmpty ? null : t.byId(_path.last);
    // Nhóm đang đứng vừa bị xoá (ở máy khác / ở ⋯) thì lùi về chỗ còn tồn tại.
    if (t != null && _path.isNotEmpty && cur == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        setState(
          () => _path = [
            for (final id in _path)
              if (t.byId(id) != null) id,
          ],
        );
      });
    }
    final pool = t == null || _q.isNotEmpty
        ? const <Category>[]
        : cur != null
        ? t.kids(cur.id)
        : [for (final r in t.roots) ...t.kids(r.id)];
    final canSel = perms.can(Quyen.xoaNhom) && pool.any((c) => !c.locked);
    final sel = _sel;
    final picked = t == null || sel == null ? const <Category>[] : [for (final id in sel) ?t.byId(id)];

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _up();
      },
      child: AnnotatedRegion<SystemUiOverlayStyle>(
        value: SystemUiOverlayStyle.dark,
        child: ColoredBox(
          color: OrderColors.page,
          child: Column(
            children: [
              _Header(
                title: _q.isNotEmpty ? 'Tìm nhóm hàng' : cur?.name ?? 'Nhóm hàng hoá',
                onBack: _path.isEmpty ? null : _up,
                selecting: sel != null,
                onSelect: canSel ? () => setState(() => _sel = sel == null ? <int>{} : null) : null,
                onMore: cur != null && !cur.locked && _q.isEmpty && t != null
                    ? () => _actions(cur, t, here: true)
                    : null,
                onAdd: t != null && perms.can(Quyen.themNhom) ? () => _add(t) : null,
                search: _search,
                onSearch: _onSearch,
                onClear: () {
                  _search.clear();
                  setState(() => _q = '');
                },
                crumbs: t == null || _path.isEmpty || _q.isNotEmpty
                    ? const []
                    : [for (final id in _path) t.byId(id)?.name ?? ''],
                onCrumb: (i) {
                  setState(() => _path = i < 0 ? [] : _path.sublist(0, i + 1));
                  _jumpTop();
                },
              ),
              Expanded(
                child: RefreshIndicator(
                  color: OrderColors.primary,
                  onRefresh: () async {
                    ref.invalidate(categoryTreeProvider);
                    try {
                      await ref.read(categoryTreeProvider.future);
                    } on ApiException catch (e) {
                      if (mounted) _toast(e.message);
                    }
                  },
                  child: ListView(
                    controller: _scroll,
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.only(bottom: 24),
                    children: [
                      if (t == null && async.hasError)
                        _Empty(
                          icon: AppIcons.alert,
                          title: 'Không tải được nhóm hàng',
                          text: async.error is ApiException
                              ? (async.error as ApiException).message
                              : 'Thử lại sau ít phút.',
                          action: 'Thử lại',
                          onAction: () => ref.invalidate(categoryTreeProvider),
                        )
                      else if (t == null)
                        const Padding(
                          padding: EdgeInsets.only(top: 60),
                          child: Center(child: CircularProgressIndicator(color: OrderColors.primary)),
                        )
                      else if (_q.isNotEmpty)
                        ..._searchView(t)
                      else if (cur == null)
                        ..._rootView(t)
                      else
                        ..._groupView(t, cur, perms),
                    ],
                  ),
                ),
              ),
              if (sel != null && t != null)
                _SelBar(
                  count: picked.length,
                  onCancel: () => setState(() => _sel = null),
                  onDelete: picked.isEmpty ? null : () => _askDelete(picked, t),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _row(CategoryTree t, Category c, {bool path = false}) {
    final sel = _sel;
    return _CatRow(
      key: Key('cat_${c.id}'),
      cat: c,
      meta: [
        c.code,
        if (t.kids(c.id).isNotEmpty) '${t.kids(c.id).length} nhóm con',
        '${dot(t.products(c.id))} sản phẩm',
      ].join(' · '),
      hasKids: t.kids(c.id).isNotEmpty,
      path: path ? t.pathOf(c).map((x) => x.name).join(' › ') : null,
      query: path ? _q : null,
      selected: sel?.contains(c.id),
      onTap: () => sel != null ? _toggleSel(c) : _go(c.id),
      onMore: c.locked ? null : () => _actions(c, t),
      onLongPress: c.locked || sel != null ? null : () => _actions(c, t),
    );
  }

  /// Màn đầu: mỗi nhóm gốc một thẻ, bày sẵn nhóm cấp 1 — như cây PC mở sẵn 2 tầng; nhiều
  /// nhóm thì chỉ 6 dòng đầu, còn lại vào trong nhóm gốc mà xem.
  static const _peek = 6;

  List<Widget> _rootView(CategoryTree t) => [
    for (final r in t.roots)
      _Card(
        key: Key('cat_root_${r.id}'),
        children: [
          InkWell(
            key: Key('cat_head_${r.id}'),
            onTap: () => _go(r.id),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(14, 14, 14, 12),
              child: Row(
                children: [
                  Container(
                    width: 42,
                    height: 42,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(12),
                      gradient: const LinearGradient(
                        begin: Alignment(-.5, -1),
                        end: Alignment(.5, 1),
                        colors: [Color(0xFF1D3A6E), OrderColors.navy],
                      ),
                    ),
                    child: AppIcon(r.locked ? AppIcons.layers : AppIcons.folder, size: 19, color: Colors.white),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Flexible(
                              child: Text(
                                r.name,
                                style: const TextStyle(
                                  color: OrderColors.ink,
                                  fontSize: 16,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                            if (r.locked) ...[const SizedBox(width: 6), const _Lock()],
                          ],
                        ),
                        const SizedBox(height: 2),
                        Text(
                          '${t.kids(r.id).isEmpty ? 'Chưa có nhóm con' : '${t.kids(r.id).length} nhóm con'} · ${dot(t.products(r.id))} sản phẩm',
                          style: const TextStyle(color: OrderColors.muted, fontSize: 12.5),
                        ),
                      ],
                    ),
                  ),
                  const AppIcon(AppIcons.right, size: 18, color: OrderColors.faint),
                ],
              ),
            ),
          ),
          for (final k in t.kids(r.id).take(_peek)) ...[const Divider(height: 1, color: OrderColors.line), _row(t, k)],
          if (t.kids(r.id).length > _peek) ...[
            const Divider(height: 1, color: OrderColors.line),
            _LinkBtn(
              key: Key('cat_all_${r.id}'),
              label: 'Xem tất cả ${t.kids(r.id).length} nhóm',
              onTap: () => _go(r.id),
            ),
          ],
        ],
      ),
    Padding(
      padding: const EdgeInsets.fromLTRB(20, 14, 20, 0),
      child: Text(
        '${t.all.length} nhóm · chạm vào một nhóm để xem nhóm con',
        textAlign: TextAlign.center,
        style: const TextStyle(color: OrderColors.faint, fontSize: 12),
      ),
    ),
  ];

  List<Widget> _groupView(CategoryTree t, Category c, Perms perms) {
    final ks = t.kids(c.id), own = t.ownCount[c.id] ?? 0;
    final canAdd = perms.can(Quyen.themNhom);
    return [
      // Vào trong nhóm: tên đã ở tiêu đề — chỉ còn một dải mã · VAT · số hàng, nhóm con lên cao.
      Container(
        margin: const EdgeInsets.fromLTRB(12, 10, 12, 0),
        padding: const EdgeInsets.fromLTRB(14, 10, 6, 10),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
        child: Row(
          children: [
            Expanded(
              child: c.locked
                  ? const Row(
                      children: [
                        _Lock(),
                        SizedBox(width: 6),
                        Flexible(
                          child: Text('không sửa, không xoá', style: TextStyle(color: OrderColors.muted, fontSize: 13)),
                        ),
                      ],
                    )
                  : Text.rich(
                      key: const Key('cat_gbar'),
                      TextSpan(
                        style: const TextStyle(color: OrderColors.muted, fontSize: 13, height: 1.45),
                        children: [
                          const TextSpan(text: 'Mã '),
                          TextSpan(
                            text: c.code,
                            style: const TextStyle(color: OrderColors.ink, fontWeight: FontWeight.w600),
                          ),
                          const TextSpan(text: ' · VAT '),
                          TextSpan(
                            text: vatName(c.vat),
                            style: const TextStyle(color: OrderColors.ink, fontWeight: FontWeight.w600),
                          ),
                        ],
                      ),
                    ),
            ),
            Material(
              color: OrderColors.primarySoft,
              borderRadius: BorderRadius.circular(10),
              child: InkWell(
                key: const Key('cat_goods'),
                borderRadius: BorderRadius.circular(10),
                onTap: () => _goGoods(c),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 9, 8, 9),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        '${dot(t.products(c.id))} sản phẩm',
                        style: const TextStyle(
                          color: OrderColors.primaryInk,
                          fontSize: 13.5,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(width: 2),
                      const AppIcon(AppIcons.right, size: 16, color: OrderColors.primaryInk),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
      if (!c.active)
        Container(
          margin: const EdgeInsets.fromLTRB(12, 8, 12, 0),
          padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
          decoration: BoxDecoration(color: _lowBg, borderRadius: BorderRadius.circular(12)),
          child: Row(
            children: [
              const AppIcon(AppIcons.eyeOff, size: 18, color: Color(0xFF7C4A03)),
              const SizedBox(width: 10),
              const Expanded(
                child: Text(
                  'Đang ngừng dùng — không chọn được khi thêm hàng.',
                  style: TextStyle(color: Color(0xFF7C4A03), fontSize: 13, height: 1.4),
                ),
              ),
              if (perms.can(Quyen.suaNhom))
                TextButton(
                  key: const Key('cat_reuse'),
                  onPressed: () => _setActive(c, true),
                  style: TextButton.styleFrom(
                    backgroundColor: Colors.white,
                    foregroundColor: _low,
                    minimumSize: const Size(0, 34),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(9)),
                    textStyle: const TextStyle(fontFamily: appFont, fontSize: 13, fontWeight: FontWeight.w700),
                  ),
                  child: const Text('Dùng lại'),
                ),
            ],
          ),
        ),
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 18, 12, 8),
        child: Row(
          children: [
            Expanded(
              child: Text(
                'NHÓM CON${ks.isNotEmpty ? ' (${ks.length})' : ''}',
                style: const TextStyle(
                  color: OrderColors.muted,
                  fontSize: 12.5,
                  fontWeight: FontWeight.w700,
                  letterSpacing: .5,
                ),
              ),
            ),
            if (ks.isNotEmpty && canAdd)
              TextButton.icon(
                key: const Key('cat_add_here'),
                onPressed: () => _openForm(t, parentId: c.id),
                style: TextButton.styleFrom(
                  foregroundColor: OrderColors.primaryInk,
                  minimumSize: const Size(0, 30),
                  padding: const EdgeInsets.symmetric(horizontal: 4),
                  textStyle: const TextStyle(fontFamily: appFont, fontSize: 13.5, fontWeight: FontWeight.w600),
                ),
                icon: const AppIcon(AppIcons.plus, size: 16, color: OrderColors.primaryInk),
                label: const Text('Thêm'),
              ),
          ],
        ),
      ),
      if (ks.isNotEmpty)
        _Card(
          margin: const EdgeInsets.symmetric(horizontal: 12),
          children: [
            for (final (i, k) in ks.indexed) ...[
              if (i > 0) const Divider(height: 1, color: OrderColors.line),
              _row(t, k),
            ],
          ],
        )
      else
        _Empty(
          icon: AppIcons.folder,
          title: 'Chưa có nhóm con',
          text:
              '${own > 0 ? 'Nhóm này đang chứa ${dot(own)} sản phẩm. ' : ''}Thêm nhóm con để chia nhỏ hàng trong ${c.name}.',
          action: canAdd ? 'Thêm nhóm con' : null,
          onAction: () => _openForm(t, parentId: c.id),
        ),
    ];
  }

  List<Widget> _searchView(CategoryTree t) {
    final rs = t.search(_q);
    return [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 18, 16, 8),
        child: Text(
          '${rs.length} KẾT QUẢ',
          style: const TextStyle(
            color: OrderColors.muted,
            fontSize: 12.5,
            fontWeight: FontWeight.w700,
            letterSpacing: .5,
          ),
        ),
      ),
      if (rs.isEmpty)
        _Empty(
          icon: AppIcons.search,
          title: 'Không có nhóm nào khớp “$_q”',
          text: 'Thử tên ngắn hơn, hoặc thêm nhóm mới.',
        )
      else
        _Card(
          margin: const EdgeInsets.symmetric(horizontal: 12),
          children: [
            for (final (i, c) in rs.indexed) ...[
              if (i > 0) const Divider(height: 1, color: OrderColors.line),
              _row(t, c, path: true),
            ],
          ],
        ),
    ];
  }
}

// ---------- Đầu màn ----------

class _Header extends StatelessWidget {
  const _Header({
    required this.title,
    required this.onBack,
    required this.selecting,
    required this.onSelect,
    required this.onMore,
    required this.onAdd,
    required this.search,
    required this.onSearch,
    required this.onClear,
    required this.crumbs,
    required this.onCrumb,
  });

  final String title;
  final VoidCallback? onBack;
  final bool selecting;
  final VoidCallback? onSelect;
  final VoidCallback? onMore;
  final VoidCallback? onAdd;
  final TextEditingController search;
  final ValueChanged<String> onSearch;
  final VoidCallback onClear;
  final List<String> crumbs;

  /// -1 = "Tất cả" (màn đầu).
  final ValueChanged<int> onCrumb;

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return Container(
      padding: EdgeInsets.only(top: top + 4, bottom: 8),
      decoration: const BoxDecoration(
        color: Colors.white,
        boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, 1))],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            height: 48,
            child: Padding(
              padding: EdgeInsets.fromLTRB(onBack == null ? 16 : 6, 0, 10, 0),
              child: Row(
                children: [
                  if (onBack != null) _IconBtn(icon: AppIcons.back, tooltip: 'Quay lại', onTap: onBack!),
                  Expanded(
                    child: Text(
                      title,
                      key: const Key('cat_title'),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: OrderColors.ink,
                        fontSize: onBack == null ? 21 : 19,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -.4,
                      ),
                    ),
                  ),
                  if (onSelect != null)
                    TextButton(
                      key: const Key('cat_select'),
                      onPressed: onSelect,
                      style: TextButton.styleFrom(
                        foregroundColor: OrderColors.primaryInk,
                        minimumSize: const Size(0, 36),
                        padding: const EdgeInsets.symmetric(horizontal: 10),
                        textStyle: const TextStyle(fontFamily: appFont, fontSize: 14.5, fontWeight: FontWeight.w600),
                      ),
                      child: Text(selecting ? 'Xong' : 'Chọn'),
                    ),
                  if (onMore != null) _IconBtn(icon: AppIcons.dots, tooltip: 'Thao tác với nhóm này', onTap: onMore!),
                  if (onAdd != null) _IconBtn(icon: AppIcons.plus, tooltip: 'Thêm nhóm', onTap: onAdd!, primary: true),
                ],
              ),
            ),
          ),
          Container(
            height: 40,
            margin: const EdgeInsets.fromLTRB(12, 2, 12, 0),
            padding: const EdgeInsets.only(left: 12, right: 6),
            decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(10)),
            child: Row(
              children: [
                const AppIcon(AppIcons.search, color: OrderColors.faint),
                const SizedBox(width: 8),
                Expanded(
                  child: TextField(
                    key: const Key('cat_search'),
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
                      hintText: 'Tìm tên hoặc mã nhóm',
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
                      icon: const AppIcon(AppIcons.close, size: 18, color: OrderColors.muted),
                    ),
                  ),
              ],
            ),
          ),
          if (crumbs.isNotEmpty)
            SizedBox(
              height: 36,
              child: ListView(
                key: const Key('cat_crumbs'),
                scrollDirection: Axis.horizontal,
                reverse: true,
                padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                children: [
                  // reverse: nấc đang đứng luôn hiện, đường dài thì cuộn sang trái xem nấc trên.
                  Row(
                    children: [
                      _Crumb(label: 'Tất cả', onTap: () => onCrumb(-1)),
                      for (final (i, n) in crumbs.indexed) ...[
                        const AppIcon(AppIcons.right, size: 14, color: OrderColors.faint),
                        _Crumb(label: n, current: i == crumbs.length - 1, onTap: () => onCrumb(i)),
                      ],
                    ],
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _Crumb extends StatelessWidget {
  const _Crumb({required this.label, required this.onTap, this.current = false});

  final String label;
  final VoidCallback onTap;
  final bool current;

  @override
  Widget build(BuildContext context) => InkWell(
    borderRadius: BorderRadius.circular(8),
    onTap: current ? null : onTap,
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
      child: Text(
        label,
        style: TextStyle(
          color: current ? OrderColors.ink : OrderColors.primaryInk,
          fontSize: 13,
          fontWeight: current ? FontWeight.w700 : FontWeight.w500,
        ),
      ),
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

// ---------- Khối & dòng ----------

class _Card extends StatelessWidget {
  const _Card({super.key, required this.children, this.margin = const EdgeInsets.fromLTRB(12, 10, 12, 0)});

  final List<Widget> children;
  final EdgeInsets margin;

  @override
  Widget build(BuildContext context) => Container(
    margin: margin,
    clipBehavior: Clip.antiAlias,
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(14),
      boxShadow: OrderColors.cardShadow,
    ),
    child: Material(
      color: Colors.white,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children),
    ),
  );
}

class _Lock extends StatelessWidget {
  const _Lock();

  @override
  Widget build(BuildContext context) => Container(
    height: 20,
    padding: const EdgeInsets.symmetric(horizontal: 7),
    decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(6)),
    child: const Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        AppIcon(AppIcons.lock, size: 12, color: OrderColors.muted),
        SizedBox(width: 3),
        Text(
          'Nhóm gốc',
          style: TextStyle(color: OrderColors.muted, fontSize: 11, fontWeight: FontWeight.w600),
        ),
      ],
    ),
  );
}

class _CatRow extends StatelessWidget {
  const _CatRow({
    super.key,
    required this.cat,
    required this.meta,
    required this.hasKids,
    required this.onTap,
    this.path,
    this.query,
    this.selected,
    this.onMore,
    this.onLongPress,
  });

  final Category cat;
  final String meta;
  final bool hasKids;
  final String? path;
  final String? query;

  /// null = không ở chế độ chọn.
  final bool? selected;
  final VoidCallback onTap;

  /// null = nhóm gốc (chỉ có mũi tên vào trong).
  final VoidCallback? onMore;
  final VoidCallback? onLongPress;

  @override
  Widget build(BuildContext context) {
    final on = cat.active;
    final sel = selected ?? false;
    return Material(
      color: sel ? OrderColors.primarySoft : Colors.white,
      child: InkWell(
        onTap: onTap,
        onLongPress: onLongPress,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 60),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 8, 6, 8),
            child: Row(
              children: [
                if (selected != null) ...[
                  Container(
                    key: Key('cat_check_${cat.id}'),
                    width: 24,
                    height: 24,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: sel ? OrderColors.primary : Colors.white,
                      borderRadius: BorderRadius.circular(7),
                      border: Border.all(color: sel ? OrderColors.primary : const Color(0xFFC9CED8), width: 2),
                    ),
                    child: sel ? const AppIcon(AppIcons.check, size: 15, strokeWidth: 3, color: Colors.white) : null,
                  ),
                  const SizedBox(width: 10),
                ],
                Container(
                  width: 36,
                  height: 36,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: on ? OrderColors.primarySoft : OrderColors.grid,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: AppIcon(
                    hasKids ? AppIcons.layers : AppIcons.folder,
                    size: 19,
                    color: on ? OrderColors.primaryInk : OrderColors.faint,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text.rich(
                        TextSpan(
                          children: [
                            ..._name(),
                            if (!on)
                              const WidgetSpan(
                                alignment: PlaceholderAlignment.middle,
                                child: Padding(padding: EdgeInsets.only(left: 6), child: _OffChip()),
                              ),
                          ],
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: on ? OrderColors.ink : OrderColors.faint,
                          fontSize: 14.5,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        meta,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(color: OrderColors.muted, fontSize: 12),
                      ),
                      if (path != null && path!.isNotEmpty)
                        Text(
                          path!,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(color: OrderColors.faint, fontSize: 11.5),
                        ),
                    ],
                  ),
                ),
                if (onMore == null)
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 9),
                    child: AppIcon(AppIcons.right, size: 16, color: Color(0xFFC4C9D2)),
                  )
                else if (selected == null)
                  SizedBox(
                    width: 34,
                    height: 40,
                    child: IconButton(
                      key: Key('cat_more_${cat.id}'),
                      tooltip: 'Thao tác với ${cat.name}',
                      padding: EdgeInsets.zero,
                      onPressed: onMore,
                      icon: const AppIcon(AppIcons.dots, size: 20, color: OrderColors.muted),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// Tô chỗ khớp từ khoá (so không dấu; chuỗi bỏ dấu cùng độ dài thì tô đúng vị trí).
  List<InlineSpan> _name() {
    final q = query == null ? '' : foldVn(query!.trim());
    final f = foldVn(cat.name);
    final i = q.isEmpty || f.length != cat.name.length ? -1 : f.indexOf(q);
    if (i < 0) return [TextSpan(text: cat.name)];
    return [
      TextSpan(text: cat.name.substring(0, i)),
      TextSpan(
        text: cat.name.substring(i, i + q.length),
        style: const TextStyle(backgroundColor: Color(0xFFFFF3BF)),
      ),
      TextSpan(text: cat.name.substring(i + q.length)),
    ];
  }
}

class _OffChip extends StatelessWidget {
  const _OffChip();

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
    decoration: BoxDecoration(color: _lowBg, borderRadius: BorderRadius.circular(5)),
    child: const Text(
      'Ngừng dùng',
      style: TextStyle(color: _low, fontSize: 10.5, fontWeight: FontWeight.w700),
    ),
  );
}

class _LinkBtn extends StatelessWidget {
  const _LinkBtn({super.key, required this.label, required this.onTap});

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => InkWell(
    onTap: onTap,
    child: SizedBox(
      height: 44,
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(
            label,
            style: const TextStyle(color: OrderColors.primaryInk, fontSize: 13.5, fontWeight: FontWeight.w600),
          ),
          const SizedBox(width: 4),
          const AppIcon(AppIcons.right, size: 16, color: OrderColors.primaryInk),
        ],
      ),
    ),
  );
}

class _Empty extends StatelessWidget {
  const _Empty({required this.icon, required this.title, required this.text, this.action, this.onAction});

  final AppIcons icon;
  final String title;
  final String text;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.symmetric(horizontal: 12),
    padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 28),
    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
    child: Column(
      children: [
        Container(
          width: 52,
          height: 52,
          alignment: Alignment.center,
          decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(16)),
          child: AppIcon(icon, size: 26, color: OrderColors.faint),
        ),
        const SizedBox(height: 10),
        Text(
          title,
          textAlign: TextAlign.center,
          style: const TextStyle(color: OrderColors.ink, fontSize: 15, fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 4),
        Text(
          text,
          textAlign: TextAlign.center,
          style: const TextStyle(color: OrderColors.muted, fontSize: 13),
        ),
        if (action != null) ...[
          const SizedBox(height: 14),
          OutlinedButton.icon(
            key: const Key('cat_empty_action'),
            onPressed: onAction,
            style: OutlinedButton.styleFrom(
              foregroundColor: OrderColors.primaryInk,
              side: const BorderSide(color: OrderColors.primary),
              minimumSize: const Size(0, 42),
              padding: const EdgeInsets.symmetric(horizontal: 18),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(11)),
              textStyle: const TextStyle(fontFamily: appFont, fontSize: 14, fontWeight: FontWeight.w600),
            ),
            icon: const AppIcon(AppIcons.plus, size: 18, color: OrderColors.primaryInk),
            label: Text(action!),
          ),
        ],
      ],
    ),
  );
}

class _SelBar extends StatelessWidget {
  const _SelBar({required this.count, required this.onCancel, required this.onDelete});

  final int count;
  final VoidCallback onCancel;
  final VoidCallback? onDelete;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
    decoration: const BoxDecoration(
      color: Colors.white,
      boxShadow: [
        BoxShadow(color: OrderColors.line, offset: Offset(0, -1)),
        BoxShadow(color: Color(0x400F172A), offset: Offset(0, -10), blurRadius: 24, spreadRadius: -16),
      ],
    ),
    child: Row(
      children: [
        Expanded(
          child: Text(
            'Đã chọn $count',
            key: const Key('cat_sel_count'),
            style: const TextStyle(color: OrderColors.ink, fontSize: 14, fontWeight: FontWeight.w600),
          ),
        ),
        _Btn(label: 'Huỷ', onTap: onCancel),
        const SizedBox(width: 10),
        _Btn(key: const Key('cat_sel_delete'), label: 'Xoá', icon: AppIcons.trash, color: _out, onTap: onDelete),
      ],
    ),
  );
}

class _Btn extends StatelessWidget {
  const _Btn({super.key, required this.label, required this.onTap, this.icon, this.color});

  final String label;
  final VoidCallback? onTap;
  final AppIcons? icon;

  /// null = nút viền trắng.
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final fill = color;
    final fg = fill == null ? OrderColors.ink : Colors.white;
    return Opacity(
      opacity: onTap == null ? .45 : 1,
      child: Material(
        color: fill ?? Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: fill == null ? const BorderSide(color: OrderColors.line) : BorderSide.none,
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: onTap,
          child: Container(
            height: 46,
            padding: const EdgeInsets.symmetric(horizontal: 18),
            alignment: Alignment.center,
            // Chữ dài (vd "Xoá 12 nhóm" trên máy hẹp) thì co lại, không tràn nút.
            child: FittedBox(
              fit: BoxFit.scaleDown,
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (icon != null) ...[AppIcon(icon!, size: 18, color: fg), const SizedBox(width: 6)],
                  Text(
                    label,
                    style: TextStyle(color: fg, fontSize: 15, fontWeight: FontWeight.w600),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ActionRow extends StatelessWidget {
  const _ActionRow({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    this.note,
    this.danger = false,
    this.off = false,
  });

  final AppIcons icon;
  final String label;
  final String? note;
  final bool danger;

  /// Không làm được (vẫn chạm được để nghe lý do).
  final bool off;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final fg = off
        ? OrderColors.faint
        : danger
        ? _out
        : OrderColors.ink;
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 50),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
          child: Row(
            children: [
              AppIcon(icon, size: 20, color: off || danger ? fg : OrderColors.muted),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(label, style: TextStyle(color: fg, fontSize: 15)),
                    if (note != null) Text(note!, style: const TextStyle(color: OrderColors.faint, fontSize: 12)),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Warn extends StatelessWidget {
  const _Warn({super.key, required this.text, this.error = false});

  final String text;
  final bool error;

  @override
  Widget build(BuildContext context) {
    final fg = error ? const Color(0xFF7F1D1D) : const Color(0xFF7C4A03);
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: error ? const Color(0xFFFDECEC) : _lowBg,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          AppIcon(AppIcons.alert, size: 20, color: fg),
          const SizedBox(width: 10),
          Expanded(
            child: Text(text, style: TextStyle(color: fg, fontSize: 13, height: 1.5)),
          ),
        ],
      ),
    );
  }
}

// ---------- Hộp Thêm / Sửa — khuôn hộp #modalMenuCategory của PC ----------

class CategoryForm extends ConsumerStatefulWidget {
  const CategoryForm({super.key, required this.tree, required this.parentId, this.editing});

  final CategoryTree tree;
  final int parentId;
  final Category? editing;

  @override
  ConsumerState<CategoryForm> createState() => _CategoryFormState();
}

class _CategoryFormState extends ConsumerState<CategoryForm> {
  late final Category? _c = widget.editing;
  late CategoryTree _t = widget.tree;
  late final _name = TextEditingController(text: _c?.name ?? '');
  late final _code = TextEditingController(text: _c?.code ?? '');
  late bool _active = _c?.active ?? true;

  /// Nhóm mới nhận mức thuế của nhóm cha — thuế đi theo ngành hàng (luật saveChildren của PC).
  late int _vat = _c?.vat ?? _t.byId(widget.parentId)?.vat ?? 0;
  late final List<KidRow> _kids = [
    if (_c != null)
      for (final k in _t.kids(_c.id)) KidRow(id: k.id, code: k.code, name: k.name),
  ];
  final _kidCtl = <KidRow, TextEditingController>{};
  final _badKids = <KidRow>{};
  bool _dirty = false;
  bool _saving = false;
  String? _nameErr;
  String? _kidsMsg;
  String? _err;

  @override
  void dispose() {
    _name.dispose();
    _code.dispose();
    for (final c in _kidCtl.values) {
      c.dispose();
    }
    super.dispose();
  }

  TextEditingController _ctl(KidRow k) => _kidCtl[k] ??= TextEditingController(text: k.name);

  Future<bool> _confirm(String title, String yes) async =>
      await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(title, style: const TextStyle(fontSize: 17)),
          actions: [
            TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Thôi')),
            TextButton(
              key: const Key('cat_confirm_yes'),
              onPressed: () => Navigator.of(ctx).pop(true),
              child: Text(yes, style: const TextStyle(color: _out)),
            ),
          ],
        ),
      ) ==
      true;

  Future<void> _leave() async {
    if (!_dirty || await _confirm('Bỏ các thay đổi chưa lưu?', 'Bỏ')) {
      if (mounted) Navigator.of(context).pop();
    }
  }

  /// Dòng đã lưu = xoá thật trên máy chủ (PC cũng vậy); dòng mới gõ thì bỏ tại chỗ.
  Future<void> _removeKid(KidRow k) async {
    if (k.id == null) {
      setState(() {
        _kids.remove(k);
        _badKids.remove(k);
      });
      return;
    }
    final c = _t.byId(k.id)!;
    final why = _t.whyNotDelete(c);
    if (why != null) return setState(() => _kidsMsg = 'Không xoá được ${c.name}: $why.');
    if (!await _confirm('Xoá hẳn nhóm “${c.name}”?', 'Xoá')) return;
    final repo = ref.read(categoryRepoProvider);
    try {
      await repo.delete(c.id);
    } on ApiException catch (e) {
      return setState(() => _kidsMsg = e.message);
    }
    repo.refresh();
    setState(() {
      _kids.remove(k);
      _t = CategoryTree([
        for (final x in _t.all)
          if (x.id != c.id) x,
      ], _t.ownCount);
    });
  }

  /// "Xoá tất cả" của PC: một nhóm con còn nhánh dưới / còn hàng là dừng, không xoá gì.
  Future<void> _clearKids() async {
    final saved = _kids.where((k) => k.id != null).toList();
    if (saved.isEmpty) return setState(_kids.clear);
    final bad = [
      for (final k in saved)
        if (_t.byId(k.id) case final c? when _t.whyNotDelete(c) != null) c,
    ];
    if (bad.isNotEmpty) {
      return setState(
        () => _kidsMsg =
            'Không xoá được: ${bad.map((c) => '${c.name} ${_t.whyNotDelete(c)}').join('; ')}. Xử lý cấp dưới trước.',
      );
    }
    if (!await _confirm('Xoá hẳn ${saved.length} nhóm con đã lưu?', 'Xoá')) return;
    final repo = ref.read(categoryRepoProvider);
    try {
      for (final k in saved) {
        await repo.delete(k.id!);
      }
    } on ApiException catch (e) {
      repo.refresh();
      return setState(() => _kidsMsg = e.message);
    }
    repo.refresh();
    final gone = {for (final k in saved) k.id};
    setState(() {
      _kids.clear();
      _t = CategoryTree([
        for (final x in _t.all)
          if (!gone.contains(x.id)) x,
      ], _t.ownCount);
    });
  }

  Future<void> _save() async {
    final name = _name.text.trim();
    String? nameErr;
    String? kidsMsg;
    final bad = <KidRow>{};
    final except = {?_c?.id};
    if (name.isEmpty) {
      nameErr = 'Chưa nhập tên nhóm hàng hoá.';
    } else if (_t.taken(name, except)) {
      nameErr = 'Tên nhóm hàng hoá này đã có trong cửa hàng.';
    }
    final seen = {normName(name)};
    for (final k in _kids) {
      final n = k.name.trim();
      String? msg;
      if (n.isEmpty) {
        msg = 'Còn nhóm con chưa nhập tên.';
      } else if (seen.contains(normName(n)) || _t.taken(n, {...except, ?k.id})) {
        msg = '“$n” trùng tên nhóm đã có.';
      }
      if (msg != null) {
        bad.add(k);
        kidsMsg ??= msg;
      }
      seen.add(normName(n));
    }
    setState(() {
      _nameErr = nameErr;
      _kidsMsg = kidsMsg;
      _err = null;
      _badKids
        ..clear()
        ..addAll(bad);
    });
    if (nameErr != null || bad.isNotEmpty) return;
    setState(() => _saving = true);
    try {
      final res = await saveCategory(
        ref.read(categoryRepoProvider),
        editing: _c,
        parentId: widget.parentId,
        name: name,
        vat: _vat,
        active: _active,
        kids: _kids,
        tree: _t,
      );
      if (mounted) Navigator.of(context).pop(res);
    } on ApiException catch (e) {
      // Lỗi thì giữ hộp: không mất các dòng nhóm con đang gõ. 422 trùng tên báo tại ô.
      if (!mounted) return;
      setState(() {
        _saving = false;
        if (e.fieldErrors['name'] case final m?) {
          _nameErr = m;
        } else {
          _err = e.message;
        }
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    final par = _t.byId(widget.parentId);
    final vats = ref.watch(categoryVatsProvider).value ?? const [0, 5, 8, 10];
    final levels = [...vats, if (!vats.contains(_vat)) _vat];
    final where = [if (par != null) ..._t.pathOf(par), ?par].map((x) => x.name).join(' › ');
    return PopScope(
      canPop: !_dirty && !_saving,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop && !_saving) _leave();
      },
      child: Padding(
        padding: EdgeInsets.only(bottom: mq.viewInsets.bottom),
        child: ConstrainedBox(
          constraints: BoxConstraints(maxHeight: mq.size.height * .9),
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
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
                child: Text(
                  _c == null ? 'Thêm nhóm hàng hoá' : 'Sửa nhóm hàng hoá',
                  style: const TextStyle(color: OrderColors.ink, fontSize: 18, fontWeight: FontWeight.w700),
                ),
              ),
              Flexible(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                        decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(12)),
                        child: Row(
                          children: [
                            const AppIcon(AppIcons.folder, size: 18, color: OrderColors.muted),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text.rich(
                                TextSpan(
                                  style: const TextStyle(color: OrderColors.muted, fontSize: 13),
                                  children: [
                                    const TextSpan(text: 'Nằm trong '),
                                    TextSpan(
                                      text: where,
                                      style: const TextStyle(color: OrderColors.ink, fontWeight: FontWeight.w600),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 14),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Expanded(
                            child: _Field(
                              label: 'Mã nhóm',
                              child: _Input(
                                key: const Key('cat_f_code'),
                                controller: _code,
                                enabled: false,
                                hint: 'Tự tạo khi lưu',
                              ),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const _Label('Trạng thái'),
                              SizedBox(
                                height: 48,
                                child: Switch(
                                  key: const Key('cat_f_active'),
                                  value: _active,
                                  activeTrackColor: OrderColors.primary,
                                  onChanged: (v) => setState(() {
                                    _active = v;
                                    _dirty = true;
                                  }),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                      const SizedBox(height: 14),
                      _Field(
                        label: 'Tên nhóm',
                        required: true,
                        counter: '${_name.text.length}/150',
                        error: _nameErr,
                        child: _Input(
                          key: const Key('cat_f_name'),
                          controller: _name,
                          hint: 'Nhập tên nhóm hàng hoá',
                          maxLength: 150,
                          autofocus: _c == null,
                          bad: _nameErr != null,
                          onChanged: (_) => setState(() {
                            _dirty = true;
                            _nameErr = null;
                          }),
                        ),
                      ),
                      const SizedBox(height: 14),
                      const _Label('% VAT'),
                      Wrap(
                        spacing: 6,
                        runSpacing: 6,
                        children: [
                          for (final v in levels)
                            _VatChip(
                              key: Key('cat_vat_$v'),
                              label: vatName(v),
                              on: v == _vat,
                              onTap: () => setState(() {
                                _vat = v;
                                _dirty = true;
                              }),
                            ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      const Text(
                        'Thêm hàng vào nhóm này sẽ tự chọn sẵn mức này; nhóm con mới nhận theo.',
                        style: TextStyle(color: OrderColors.muted, fontSize: 12, height: 1.4),
                      ),
                      const SizedBox(height: 20),
                      const Divider(height: 1, color: OrderColors.line),
                      const SizedBox(height: 8),
                      Row(
                        children: [
                          Expanded(
                            child: Text.rich(
                              TextSpan(
                                text: 'Nhóm con',
                                style: const TextStyle(
                                  color: OrderColors.ink,
                                  fontSize: 14.5,
                                  fontWeight: FontWeight.w700,
                                ),
                                children: [
                                  if (_kids.isNotEmpty)
                                    TextSpan(
                                      text: '  (${_kids.length})',
                                      style: const TextStyle(
                                        color: OrderColors.muted,
                                        fontSize: 12.5,
                                        fontWeight: FontWeight.w500,
                                      ),
                                    ),
                                ],
                              ),
                            ),
                          ),
                          if (_kids.isNotEmpty)
                            TextButton(
                              key: const Key('cat_kids_clear'),
                              onPressed: _clearKids,
                              style: TextButton.styleFrom(
                                foregroundColor: _out,
                                minimumSize: const Size(0, 30),
                                padding: const EdgeInsets.symmetric(horizontal: 4),
                                textStyle: const TextStyle(
                                  fontFamily: appFont,
                                  fontSize: 13,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              child: const Text('Xoá tất cả'),
                            ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      if (_kids.isEmpty)
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(12)),
                          child: const Text(
                            'Chưa có nhóm con nào.',
                            textAlign: TextAlign.center,
                            style: TextStyle(color: OrderColors.muted, fontSize: 13),
                          ),
                        ),
                      for (final (i, k) in _kids.indexed)
                        Padding(
                          padding: EdgeInsets.only(top: i == 0 ? 0 : 8),
                          child: Row(
                            children: [
                              SizedBox(
                                width: 58,
                                child: Text(
                                  k.id == null ? 'Mới' : k.code,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(
                                    color: k.id == null ? OrderColors.primaryInk : OrderColors.muted,
                                    fontSize: 12,
                                    fontWeight: k.id == null ? FontWeight.w600 : FontWeight.w400,
                                  ),
                                ),
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: _Input(
                                  key: Key('cat_kid_$i'),
                                  controller: _ctl(k),
                                  hint: 'Tên nhóm con',
                                  height: 44,
                                  fontSize: 15,
                                  maxLength: 150,
                                  bad: _badKids.contains(k),
                                  onChanged: (v) => setState(() {
                                    k.name = v;
                                    _dirty = true;
                                    _badKids.remove(k);
                                    _kidsMsg = null;
                                  }),
                                ),
                              ),
                              const SizedBox(width: 8),
                              SizedBox(
                                width: 36,
                                height: 36,
                                child: IconButton(
                                  key: Key('cat_kid_x_$i'),
                                  tooltip: 'Bỏ dòng ${i + 1}',
                                  padding: EdgeInsets.zero,
                                  onPressed: () => _removeKid(k),
                                  icon: AppIcon(
                                    k.id == null ? AppIcons.close : AppIcons.trash,
                                    size: 18,
                                    color: OrderColors.muted,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      if (_kidsMsg != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Text(
                            _kidsMsg!,
                            key: const Key('cat_kids_msg'),
                            style: const TextStyle(color: _out, fontSize: 12.5),
                          ),
                        ),
                      const SizedBox(height: 10),
                      Material(
                        color: const Color(0xFFFAFBFD),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                          side: const BorderSide(color: Color(0xFFC9D3E2), width: 1.5),
                        ),
                        child: InkWell(
                          key: const Key('cat_kid_add'),
                          borderRadius: BorderRadius.circular(12),
                          onTap: () => setState(() {
                            _kids.add(KidRow());
                            _dirty = true;
                          }),
                          child: const SizedBox(
                            height: 44,
                            child: Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                AppIcon(AppIcons.plus, size: 18, color: OrderColors.primaryInk),
                                SizedBox(width: 6),
                                Text(
                                  'Thêm dòng nhóm con',
                                  style: TextStyle(
                                    color: OrderColors.primaryInk,
                                    fontSize: 14,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                      if (_err != null) ...[const SizedBox(height: 12), _Warn(text: _err!, error: true)],
                    ],
                  ),
                ),
              ),
              Container(
                padding: EdgeInsets.fromLTRB(20, 12, 20, mq.viewPadding.bottom + 12),
                decoration: const BoxDecoration(
                  border: Border(top: BorderSide(color: OrderColors.line)),
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: _Btn(label: 'Huỷ', onTap: _saving ? null : _leave),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _saving
                          ? const _Btn(
                              key: Key('cat_f_save'),
                              label: 'Đang lưu…',
                              color: OrderColors.primary,
                              onTap: null,
                            )
                          : _Btn(key: const Key('cat_f_save'), label: 'Lưu', color: OrderColors.primary, onTap: _save),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Label extends StatelessWidget {
  const _Label(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 6),
    child: Text(
      text,
      style: const TextStyle(color: OrderColors.ink, fontSize: 13.5, fontWeight: FontWeight.w600),
    ),
  );
}

class _Field extends StatelessWidget {
  const _Field({required this.label, required this.child, this.required = false, this.counter, this.error});

  final String label;
  final Widget child;
  final bool required;
  final String? counter;
  final String? error;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Row(
          children: [
            Expanded(
              child: Text.rich(
                TextSpan(
                  text: label,
                  children: [
                    if (required)
                      const TextSpan(
                        text: ' *',
                        style: TextStyle(color: _out),
                      ),
                  ],
                ),
                style: const TextStyle(color: OrderColors.ink, fontSize: 13.5, fontWeight: FontWeight.w600),
              ),
            ),
            if (counter != null) Text(counter!, style: const TextStyle(color: OrderColors.faint, fontSize: 12)),
          ],
        ),
      ),
      child,
      if (error != null)
        Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Text(
            error!,
            key: const Key('cat_f_name_err'),
            style: const TextStyle(color: _out, fontSize: 12.5),
          ),
        ),
    ],
  );
}

class _Input extends StatelessWidget {
  const _Input({
    super.key,
    required this.controller,
    this.hint,
    this.enabled = true,
    this.bad = false,
    this.onChanged,
    this.maxLength,
    this.autofocus = false,
    this.height = 48,
    this.fontSize = 16,
  });

  final TextEditingController controller;
  final String? hint;
  final bool enabled;
  final bool bad;
  final ValueChanged<String>? onChanged;
  final int? maxLength;
  final bool autofocus;
  final double height;
  final double fontSize;

  @override
  Widget build(BuildContext context) {
    OutlineInputBorder border(Color c, [double w = 1.5]) => OutlineInputBorder(
      borderRadius: BorderRadius.circular(12),
      borderSide: BorderSide(color: c, width: w),
    );
    return SizedBox(
      height: height,
      child: TextField(
        controller: controller,
        enabled: enabled,
        autofocus: autofocus,
        onChanged: onChanged,
        inputFormatters: [if (maxLength != null) LengthLimitingTextInputFormatter(maxLength)],
        style: TextStyle(color: enabled ? OrderColors.ink : OrderColors.muted, fontSize: fontSize),
        decoration: InputDecoration(
          hintText: hint,
          hintStyle: TextStyle(color: OrderColors.faint, fontSize: fontSize),
          filled: true,
          fillColor: enabled ? Colors.white : OrderColors.grid,
          contentPadding: const EdgeInsets.symmetric(horizontal: 14),
          enabledBorder: border(bad ? _out : OrderColors.line),
          disabledBorder: border(OrderColors.line),
          focusedBorder: border(bad ? _out : OrderColors.primary),
        ),
      ),
    );
  }
}

class _VatChip extends StatelessWidget {
  const _VatChip({super.key, required this.label, required this.on, required this.onTap});

  final String label;
  final bool on;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
    selected: on,
    button: true,
    child: Material(
      color: on ? OrderColors.primarySoft : Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(color: on ? OrderColors.primary : OrderColors.line, width: 1.5),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: onTap,
        // Không dùng Container(alignment:) — nó giãn hết bề ngang Wrap, mỗi mức thuế thành một dòng.
        child: Container(
          height: 36,
          constraints: const BoxConstraints(minWidth: 52),
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: Center(
            widthFactor: 1,
            child: Text(
              label,
              style: TextStyle(
                color: on ? OrderColors.primaryInk : OrderColors.text,
                fontSize: 14,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ),
      ),
    ),
  );
}
