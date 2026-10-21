import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_text.dart';
import '../../../core/ui/app_icon.dart';
import '../../auth/permissions.dart';
import '../shell/list_kit.dart';
import '../shell/more_menu.dart' show MoreHost;
import '../shell/shell_widgets.dart' show showDashSheet;
import 'attributes_data.dart';

const _ok = Color(0xFF0F7A3D), _okBg = Color(0xFFE9F7EF);

enum _St {
  all('Tất cả'),
  on('Đang bật'),
  off('Đã tắt');

  const _St(this.label);

  final String label;

  bool has(Attr a) => this == all || (this == on) == a.active;
}

/// Thuộc tính theo design/html/thuoc-tinh-mobile.html (v2): cùng chức năng "Danh sách
/// thuộc tính" của PC (bảng Mã · Tên · Chi tiết · Trạng thái + hộp có bảng giá trị).
/// Nằm trong khung app, mở từ Thêm.
class AttributesScreen extends ConsumerStatefulWidget {
  const AttributesScreen({super.key, required this.host});

  final MoreHost host;

  @override
  ConsumerState<AttributesScreen> createState() => _AttributesScreenState();
}

class _AttributesScreenState extends ConsumerState<AttributesScreen> {
  final _search = TextEditingController();
  Timer? _debounce;
  String _q = '';
  _St _st = _St.all;
  Set<int>? _sel;

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  void _onSearch(String v) {
    setState(() {});
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 180), () {
      if (mounted) setState(() => _q = v.trim());
    });
  }

  void _toast(String t, {String? action, VoidCallback? onAction}) =>
      showActionToast(context, t, action: action, onAction: onAction);

  Future<void> _setActive(Attr a, bool on) async {
    final repo = ref.read(attrRepoProvider);
    try {
      await repo.setActive(a.id, on);
      repo.refresh();
    } on ApiException catch (e) {
      return _toast(e.message);
    }
    if (!mounted) return;
    _toast(
      on ? 'Đã bật lại ${a.name}' : 'Đã tắt ${a.name}',
      action: 'Hoàn tác',
      onAction: () async {
        try {
          await repo.setActive(a.id, !on);
          repo.refresh();
        } on ApiException catch (e) {
          if (mounted) _toast(e.message);
        }
      },
    );
  }

  Future<void> _actions(Attr a, AttrBook b) async {
    final perms = permsOf(ref);
    final used = b.used(a);
    final pick = await showDashSheet<String>(
      context,
      title: a.name,
      subtitle: [
        a.code,
        '${a.values.length} giá trị',
        if (used.isNotEmpty) 'dùng ở ${used.length} sản phẩm',
      ].join(' · '),
      body: (ctx) {
        Widget item(String key, AppIcons icon, String label, {String? note, bool danger = false, bool off = false}) =>
            KitActionRow(
              key: Key('attr_act_$key'),
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
            if (used.isNotEmpty)
              Container(
                margin: const EdgeInsets.only(bottom: 8),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                decoration: BoxDecoration(color: _okBg, borderRadius: BorderRadius.circular(12)),
                child: Text.rich(
                  TextSpan(
                    text: 'Đang dùng ở: ',
                    children: [
                      TextSpan(
                        text: used.join(', '),
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
                  style: const TextStyle(color: Color(0xFF14532D), fontSize: 13, height: 1.5),
                ),
              ),
            if (perms.can(Quyen.suaThuocTinh)) item('edit', AppIcons.edit, 'Sửa tên, mã, giá trị'),
            if (perms.can(Quyen.themThuocTinh))
              item('copy', AppIcons.copy, 'Nhân bản', note: 'Chép tên các giá trị sang thuộc tính mới'),
            if (perms.can(Quyen.suaThuocTinh))
              a.active
                  ? item(
                      'toggle',
                      AppIcons.eyeOff,
                      'Tắt thuộc tính',
                      note: 'Ô chọn thuộc tính lúc khai mặt hàng thôi bày nó ra',
                    )
                  : item('toggle', AppIcons.check, 'Bật lại'),
            if (perms.can(Quyen.xoaThuocTinh))
              item(
                'del',
                AppIcons.trash,
                'Xoá thuộc tính',
                danger: true,
                off: used.isNotEmpty,
                note: used.isNotEmpty ? 'Đang dùng ở ${used.length} sản phẩm — tắt thay vì xoá' : null,
              ),
          ],
        );
      },
    );
    if (!mounted || pick == null) return;
    switch (pick) {
      case 'edit':
        await _openForm(b, edit: a);
      case 'copy':
        await _openForm(b, copy: a);
      case 'toggle':
        await _setActive(a, !a.active);
      case 'del':
        if (used.isNotEmpty) {
          return _toast('Không xoá được: ${a.name} đang dùng ở ${used.length} sản phẩm. Tắt đi để thôi dùng.');
        }
        await _askDelete([a], b);
    }
  }

  Future<void> _askDelete(List<Attr> list, AttrBook b) async {
    final ok = [
      for (final a in list)
        if (b.used(a).isEmpty) a,
    ];
    final no = [
      for (final a in list)
        if (b.used(a).isNotEmpty) a,
    ];
    if (list.length == 1 && no.isNotEmpty) {
      return _toast('Không xoá được: ${no.first.name} đang dùng ở ${b.used(no.first).length} sản phẩm.');
    }
    final nVals = ok.fold(0, (s, a) => s + a.values.length);
    final yes = await showDashSheet<bool>(
      context,
      title: ok.length == 1 ? 'Xoá thuộc tính “${ok.first.name}”?' : 'Xoá ${ok.length} thuộc tính?',
      subtitle: 'Xoá cả $nVals giá trị bên trong. Không hoàn tác được.',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (ok.length > 1)
            Padding(
              padding: const EdgeInsets.fromLTRB(4, 0, 4, 10),
              child: Text(
                ok.map((a) => a.name).join(', '),
                style: const TextStyle(color: OrderColors.text, fontSize: 13.5),
              ),
            ),
          if (no.isNotEmpty)
            KitWarn(
              text:
                  'Bỏ qua ${no.length} thuộc tính đang có hàng dùng: '
                  '${no.map((a) => '${a.name} (${b.used(a).length} sp)').join(', ')}. Tắt đi nếu muốn thôi dùng.',
            ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: KitBtn(label: 'Huỷ', onTap: () => Navigator.of(ctx).pop(false)),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: KitBtn(
                  key: const Key('attr_del_yes'),
                  label: ok.length > 1 ? 'Xoá ${ok.length}' : 'Xoá',
                  icon: AppIcons.trash,
                  color: kOut,
                  onTap: ok.isEmpty ? null : () => Navigator.of(ctx).pop(true),
                ),
              ),
            ],
          ),
        ],
      ),
    );
    if (yes != true || !mounted) return;
    final repo = ref.read(attrRepoProvider);
    var done = 0;
    String? err;
    for (final a in ok) {
      try {
        await repo.delete(a.id);
        done++;
      } on ApiException catch (e) {
        err ??= e.message;
      }
    }
    repo.refresh();
    if (!mounted) return;
    setState(() => _sel = null);
    _toast(
      err != null
          ? 'Đã xoá $done/${ok.length} thuộc tính — $err'
          : done == 1
          ? 'Đã xoá ${ok.first.name}'
          : 'Đã xoá $done thuộc tính${no.isNotEmpty ? '; bỏ qua ${no.length}' : ''}',
    );
  }

  Future<void> _openForm(AttrBook b, {Attr? edit, Attr? copy}) async {
    final res = await showModalBottomSheet<(Attr, bool)>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      backgroundColor: Colors.white,
      barrierColor: const Color(0x73111827),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (_) => AttrForm(book: b, edit: edit, copy: copy),
    );
    if (res == null || !mounted) return;
    final (a, isNew) = res;
    // Thêm mà để tắt trong lúc đang lọc "Đang bật": về Tất cả kẻo tưởng không lưu được.
    if (isNew && !a.active && _st == _St.on) setState(() => _st = _St.all);
    _toast('${isNew ? 'Đã thêm' : 'Đã cập nhật'} thuộc tính "${a.name}" · ${a.values.length} giá trị');
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(attrBookProvider);
    final b = async.value;
    final perms = permsOf(ref);
    final pool = b == null
        ? const <Attr>[]
        : [
            for (final a in b.attrs)
              if (attrMatches(a, _q)) a,
          ];
    final rows = [
      for (final a in pool)
        if (_st.has(a)) a,
    ];
    final sel = _sel;
    final picked = b == null || sel == null ? const <Attr>[] : [for (final id in sel) ?b.byId(id)];

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        if (_sel != null) return setState(() => _sel = null);
        if (_q.isNotEmpty) {
          _search.clear();
          return setState(() => _q = '');
        }
        widget.host.close();
      },
      child: AnnotatedRegion<SystemUiOverlayStyle>(
        value: SystemUiOverlayStyle.dark,
        child: ColoredBox(
          color: OrderColors.page,
          child: Column(
            children: [
              _Header(
                selecting: sel != null,
                onSelect: rows.isNotEmpty && perms.can(Quyen.xoaThuocTinh)
                    ? () => setState(() => _sel = sel == null ? <int>{} : null)
                    : null,
                onAdd: b != null && perms.can(Quyen.themThuocTinh) ? () => _openForm(b) : null,
                search: _search,
                onSearch: _onSearch,
                onClear: () {
                  _search.clear();
                  setState(() => _q = '');
                },
                st: _st,
                counts: {for (final s in _St.values) s: pool.where(s.has).length},
                onSt: (s) => setState(() => _st = s),
              ),
              Expanded(
                child: RefreshIndicator(
                  color: OrderColors.primary,
                  onRefresh: () async {
                    ref.invalidate(attrBookProvider);
                    try {
                      await ref.read(attrBookProvider.future);
                    } on ApiException catch (e) {
                      if (mounted) _toast(e.message);
                    }
                  },
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.only(bottom: 24),
                    children: [
                      if (b == null && async.hasError)
                        Padding(
                          padding: const EdgeInsets.only(top: 14),
                          child: KitEmpty(
                            icon: AppIcons.alert,
                            title: 'Không tải được thuộc tính',
                            text: async.error is ApiException
                                ? (async.error as ApiException).message
                                : 'Thử lại sau ít phút.',
                            action: 'Thử lại',
                            onAction: () => ref.invalidate(attrBookProvider),
                          ),
                        )
                      else if (b == null)
                        const Padding(
                          padding: EdgeInsets.only(top: 60),
                          child: Center(child: CircularProgressIndicator(color: OrderColors.primary)),
                        )
                      else if (rows.isEmpty) ...[
                        const SizedBox(height: 14),
                        KitEmpty(
                          icon: _q.isNotEmpty ? AppIcons.search : AppIcons.tag,
                          title: _q.isNotEmpty
                              ? 'Không có thuộc tính nào khớp “$_q”'
                              : _st == _St.off
                              ? 'Không có thuộc tính nào đang tắt'
                              : 'Chưa có thuộc tính nào',
                          text: _q.isNotEmpty
                              ? 'Thử từ khác, hoặc thêm thuộc tính mới.'
                              : 'Thuộc tính như Màu sắc, Size… dùng để tạo phiên bản sản phẩm.',
                          action: _q.isEmpty && _st != _St.off && perms.can(Quyen.themThuocTinh)
                              ? 'Thêm thuộc tính'
                              : null,
                          onAction: () => _openForm(b),
                        ),
                      ] else ...[
                        Padding(
                          padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
                          child: Text(
                            '${rows.length} THUỘC TÍNH${_q.isNotEmpty ? ' KHỚP “$_q”' : ''}',
                            key: const Key('attr_count'),
                            style: const TextStyle(
                              color: OrderColors.muted,
                              fontSize: 12.5,
                              fontWeight: FontWeight.w700,
                              letterSpacing: .5,
                            ),
                          ),
                        ),
                        Container(
                          margin: const EdgeInsets.symmetric(horizontal: 12),
                          clipBehavior: Clip.antiAlias,
                          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
                          child: Material(
                            color: Colors.white,
                            child: Column(
                              children: [
                                for (final (i, a) in rows.indexed) ...[
                                  if (i > 0) const Divider(height: 1, color: OrderColors.line),
                                  _AttrRow(
                                    key: Key('attr_${a.id}'),
                                    attr: a,
                                    used: b.used(a).length,
                                    query: _q,
                                    selected: sel?.contains(a.id),
                                    onTap: () => sel != null
                                        ? setState(() => sel.contains(a.id) ? sel.remove(a.id) : sel.add(a.id))
                                        : perms.can(Quyen.suaThuocTinh)
                                        ? _openForm(b, edit: a)
                                        : _actions(a, b),
                                    onMore: () => _actions(a, b),
                                  ),
                                ],
                              ],
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
              if (sel != null && b != null)
                KitSelBar(
                  keyPrefix: 'attr',
                  count: picked.length,
                  onCancel: () => setState(() => _sel = null),
                  onDelete: picked.isEmpty ? null : () => _askDelete(picked, b),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({
    required this.selecting,
    required this.onSelect,
    required this.onAdd,
    required this.search,
    required this.onSearch,
    required this.onClear,
    required this.st,
    required this.counts,
    required this.onSt,
  });

  final bool selecting;
  final VoidCallback? onSelect;
  final VoidCallback? onAdd;
  final TextEditingController search;
  final ValueChanged<String> onSearch;
  final VoidCallback onClear;
  final _St st;
  final Map<_St, int> counts;
  final ValueChanged<_St> onSt;

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return Container(
      padding: EdgeInsets.only(top: top + 4, bottom: 10),
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
              padding: const EdgeInsets.fromLTRB(16, 0, 10, 0),
              child: Row(
                children: [
                  const Expanded(
                    child: Text(
                      'Thuộc tính',
                      style: TextStyle(
                        color: OrderColors.ink,
                        fontSize: 21,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -.4,
                      ),
                    ),
                  ),
                  if (onSelect != null)
                    TextButton(
                      key: const Key('attr_select'),
                      onPressed: onSelect,
                      style: TextButton.styleFrom(
                        foregroundColor: OrderColors.primaryInk,
                        minimumSize: const Size(0, 36),
                        padding: const EdgeInsets.symmetric(horizontal: 10),
                        textStyle: const TextStyle(fontFamily: appFont, fontSize: 14.5, fontWeight: FontWeight.w600),
                      ),
                      child: Text(selecting ? 'Xong' : 'Chọn'),
                    ),
                  if (onAdd != null)
                    KitIconBtn(icon: AppIcons.plus, tooltip: 'Thêm thuộc tính', onTap: onAdd!, primary: true),
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
                    key: const Key('attr_search'),
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
                      hintText: 'Tìm thuộc tính, mã hoặc giá trị',
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
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
            child: Row(
              children: [
                for (final s in _St.values) ...[
                  if (s != _St.all) const SizedBox(width: 8),
                  _StChip(
                    key: Key('attr_st_${s.name}'),
                    label: s.label,
                    count: counts[s] ?? 0,
                    on: s == st,
                    onTap: () => onSt(s),
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

/// Chip lọc trạng thái: chọn thì nền navy (PC gọi "Đang dùng" — đổi chữ cho khỏi lẫn
/// với "dùng ở n sản phẩm").
class _StChip extends StatelessWidget {
  const _StChip({super.key, required this.label, required this.count, required this.on, required this.onTap});

  final String label;
  final int count;
  final bool on;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
    selected: on,
    button: true,
    child: Material(
      color: on ? OrderColors.navy : Colors.white,
      shape: StadiumBorder(side: BorderSide(color: on ? OrderColors.navy : OrderColors.line)),
      child: InkWell(
        customBorder: const StadiumBorder(),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                label,
                style: TextStyle(
                  color: on ? Colors.white : OrderColors.text,
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(width: 6),
              Text(
                '$count',
                style: TextStyle(
                  color: on ? const Color(0xFFB9C6DD) : OrderColors.faint,
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  fontFeatures: const [FontFeature.tabularFigures()],
                ),
              ),
            ],
          ),
        ),
      ),
    ),
  );
}

class _AttrRow extends StatelessWidget {
  const _AttrRow({
    super.key,
    required this.attr,
    required this.used,
    required this.query,
    required this.onTap,
    required this.onMore,
    this.selected,
  });

  final Attr attr;
  final int used;
  final String query;

  /// null = không ở chế độ chọn.
  final bool? selected;
  final VoidCallback onTap;
  final VoidCallback onMore;

  @override
  Widget build(BuildContext context) {
    final a = attr;
    final sel = selected ?? false;
    final q = foldVn(query.trim());
    // Đang tìm theo giá trị: đưa giá trị khớp lên đầu dãy xem trước, không thì nó chìm sau "+n".
    final vs = q.isEmpty
        ? a.values
        : [...a.values.where((v) => foldVn(v.name).contains(q)), ...a.values.where((v) => !foldVn(v.name).contains(q))];
    return Material(
      color: sel ? OrderColors.primarySoft : Colors.white,
      child: InkWell(
        onTap: onTap,
        onLongPress: selected == null ? onMore : null,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 6, 12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (selected != null) ...[
                Container(
                  key: Key('attr_check_${a.id}'),
                  width: 24,
                  height: 24,
                  margin: const EdgeInsets.only(top: 8),
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
                margin: const EdgeInsets.only(top: 2),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: a.active ? OrderColors.primarySoft : OrderColors.grid,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: AppIcon(AppIcons.tag, size: 19, color: a.active ? OrderColors.primaryInk : OrderColors.faint),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text.rich(
                      TextSpan(
                        children: [
                          ..._hl(a.name, q),
                          if (!a.active)
                            const WidgetSpan(
                              alignment: PlaceholderAlignment.middle,
                              child: Padding(
                                padding: EdgeInsets.only(left: 6),
                                child: OffChip(text: 'Đã tắt'),
                              ),
                            ),
                        ],
                      ),
                      style: TextStyle(
                        color: a.active ? OrderColors.ink : OrderColors.faint,
                        fontSize: 15,
                        fontWeight: FontWeight.w600,
                        height: 1.3,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text.rich(
                      TextSpan(
                        text: '${a.code} · ${a.values.length} giá trị · ',
                        children: [
                          used > 0
                              ? TextSpan(
                                  text: 'dùng ở $used sản phẩm',
                                  style: const TextStyle(color: _ok, fontWeight: FontWeight.w600),
                                )
                              : const TextSpan(text: 'chưa dùng'),
                        ],
                      ),
                      style: const TextStyle(color: OrderColors.muted, fontSize: 12),
                    ),
                    if (vs.isNotEmpty) ...[
                      const SizedBox(height: 7),
                      _ValuePreview(key: Key('attr_prev_${a.id}'), names: [for (final v in vs) v.name], query: q),
                    ],
                  ],
                ),
              ),
              if (selected == null)
                SizedBox(
                  width: 34,
                  height: 40,
                  child: IconButton(
                    key: Key('attr_more_${a.id}'),
                    tooltip: 'Thao tác với ${a.name}',
                    padding: EdgeInsets.zero,
                    onPressed: onMore,
                    icon: const AppIcon(AppIcons.dots, size: 20, color: OrderColors.muted),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Tô chỗ khớp (so không dấu; chuỗi bỏ dấu cùng độ dài thì tô đúng vị trí).
List<InlineSpan> _hl(String text, String folded) {
  final f = foldVn(text);
  final i = folded.isEmpty || f.length != text.length ? -1 : f.indexOf(folded);
  if (i < 0) return [TextSpan(text: text)];
  return [
    TextSpan(text: text.substring(0, i)),
    TextSpan(
      text: text.substring(i, i + folded.length),
      style: const TextStyle(backgroundColor: Color(0xFFFFF3BF)),
    ),
    TextSpan(text: text.substring(i + folded.length)),
  ];
}

/// Giá trị xem trước gói trong MỘT hàng: bày vừa chiều ngang, dư thì "+n" — danh sách
/// ngắn hơn hẳn so với bày 5 giá trị xuống dòng như cột Chi tiết của PC.
class _ValuePreview extends StatelessWidget {
  const _ValuePreview({super.key, required this.names, required this.query});

  final List<String> names;
  final String query;

  static const _style = TextStyle(fontSize: 12, fontFamily: appFont);
  static const _gap = 4.0, _pad = 14.0, _more = 44.0;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, c) {
      final scaler = MediaQuery.textScalerOf(context);
      double width(String t) =>
          (TextPainter(
            text: TextSpan(text: t, style: _style),
            textDirection: TextDirection.ltr,
            textScaler: scaler,
          )..layout()).width +
          _pad;
      var x = 0.0, shown = 0;
      for (var i = 0; i < names.length && i < 20; i++) {
        final w = width(names[i]) + _gap;
        final rest = names.length - (i + 1) > 0 ? _more : 0;
        if (x + w + rest > c.maxWidth && i > 0) break;
        x += w;
        shown++;
      }
      final hidden = names.length - shown;
      return ClipRect(
        child: Row(
          children: [
            for (final n in names.take(shown)) ...[
              _Chip(
                child: Text.rich(TextSpan(children: _hl(n, query)), maxLines: 1, style: _style),
              ),
              const SizedBox(width: _gap),
            ],
            if (hidden > 0)
              _Chip(
                more: true,
                child: Text(
                  '+$hidden',
                  key: const Key('attr_prev_more'),
                  style: _style.copyWith(color: OrderColors.primaryInk, fontWeight: FontWeight.w600),
                ),
              ),
          ],
        ),
      );
    },
  );
}

class _Chip extends StatelessWidget {
  const _Chip({required this.child, this.more = false});

  final Widget child;
  final bool more;

  @override
  Widget build(BuildContext context) => Container(
    height: 22,
    padding: const EdgeInsets.symmetric(horizontal: 7),
    alignment: Alignment.center,
    decoration: BoxDecoration(
      color: more ? OrderColors.primarySoft : OrderColors.grid,
      borderRadius: BorderRadius.circular(6),
    ),
    child: DefaultTextStyle.merge(
      style: const TextStyle(color: OrderColors.text),
      child: child,
    ),
  );
}

// ---------- Hộp Thêm / Sửa / Nhân bản — khuôn hộp của PC ----------

class _Row {
  _Row({this.id, String code = '', String name = ''})
    : code = TextEditingController(text: code),
      name = TextEditingController(text: name);

  final int? id;
  final TextEditingController code;
  final TextEditingController name;
  bool badCode = false;
  bool badName = false;

  void dispose() {
    code.dispose();
    name.dispose();
  }
}

class AttrForm extends ConsumerStatefulWidget {
  const AttrForm({super.key, required this.book, this.edit, this.copy});

  final AttrBook book;
  final Attr? edit;

  /// Nhân bản: giữ tên giá trị, bỏ mã để API đặt mã mới.
  final Attr? copy;

  @override
  ConsumerState<AttrForm> createState() => _AttrFormState();
}

class _AttrFormState extends ConsumerState<AttrForm> {
  static const _codesKey = 'attrs.showCodes';

  late final Attr? _a = widget.edit;
  late final _src = widget.edit ?? widget.copy;
  late final _code = TextEditingController(text: _a?.code ?? '');
  late final _name = TextEditingController(
    text: _src == null ? '' : '${_src.name}${widget.copy != null ? ' copy' : ''}',
  );
  late bool _active = _a?.active ?? true;
  late final List<_Row> _rows = [
    if (_src == null)
      _Row()
    else
      for (final v in _src.values) _a != null ? _Row(id: v.id, code: v.code, name: v.name) : _Row(name: v.name),
  ];
  final _bulk = TextEditingController();
  late bool _dirty = widget.copy != null;
  bool _saving = false;
  bool _showCodes = false;
  bool _showBulk = false;
  String? _codeErr, _nameErr, _valMsg, _err;

  @override
  void initState() {
    super.initState();
    // Mã giá trị phần mềm tự đặt (RAM01…) nên mặc định giấu cột mã cho ô tên rộng; nhớ lựa chọn.
    SharedPreferences.getInstance()
        .then((p) {
          if (mounted && p.getBool(_codesKey) == true) setState(() => _showCodes = true);
        })
        .catchError((_) {});
  }

  @override
  void dispose() {
    _code.dispose();
    _name.dispose();
    _bulk.dispose();
    for (final r in _rows) {
      r.dispose();
    }
    super.dispose();
  }

  void _codes(bool on) {
    setState(() => _showCodes = on);
    SharedPreferences.getInstance().then((p) => p.setBool(_codesKey, on)).catchError((_) => false);
  }

  int _use(_Row r) => widget.book.valueUsed(r.id);

  Future<void> _leave() async {
    if (_dirty) {
      final yes = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Bỏ các thay đổi chưa lưu?', style: TextStyle(fontSize: 17)),
          actions: [
            TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Thôi')),
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(true),
              child: const Text('Bỏ', style: TextStyle(color: kOut)),
            ),
          ],
        ),
      );
      if (yes != true) return;
    }
    if (mounted) Navigator.of(context).pop();
  }

  void _add() {
    if (_rows.length >= maxAttrValues) {
      return setState(() => _valMsg = 'Tối đa $maxAttrValues giá trị cho một thuộc tính.');
    }
    setState(() {
      _rows.add(_Row());
      _dirty = true;
    });
  }

  void _addBulk() {
    final have = {for (final r in _rows) normAttr(r.name.text)};
    var add = [
      for (final n in splitBulk(_bulk.text))
        if (!have.contains(normAttr(n))) n,
    ];
    setState(() {
      // Dòng trống (bấm thêm rồi để đấy) nhường chỗ cho các giá trị vừa dán.
      final blank = [
        for (final r in _rows)
          if (r.id == null && r.name.text.trim().isEmpty) r,
      ];
      for (final r in blank) {
        _rows.remove(r);
        r.dispose();
      }
      final room = maxAttrValues - _rows.length;
      _valMsg = null;
      if (add.length > room) {
        _valMsg = 'Tối đa $maxAttrValues giá trị — chỉ thêm được $room.';
        add = add.take(room).toList();
      }
      _rows.addAll([for (final n in add) _Row(name: n)]);
      _dirty = true;
      _showBulk = false;
      _bulk.clear();
    });
    showActionToast(context, 'Đã thêm ${add.length} giá trị vào danh sách');
  }

  void _remove(_Row r) {
    final u = _use(r);
    if (u > 0) {
      return setState(
        () => _valMsg =
            '“${r.name.text.trim()}” đang gắn vào $u sản phẩm — bỏ đi thì phiên bản đó mất giá trị. Đổi tên được, không bỏ được.',
      );
    }
    setState(() {
      _rows.remove(r);
      r.dispose();
      _dirty = true;
    });
  }

  void _clear() {
    final used = [
      for (final r in _rows)
        if (_use(r) > 0) r,
    ];
    setState(() {
      for (final r in [..._rows]) {
        if (_use(r) > 0) continue;
        _rows.remove(r);
        r.dispose();
      }
      _dirty = true;
      _valMsg = used.isEmpty
          ? null
          : 'Còn ${used.length} giá trị đang gắn vào sản phẩm (${used.map((r) => r.name.text.trim()).join(', ')}) — chỉ bỏ được các giá trị chưa dùng.';
    });
  }

  Future<void> _save() async {
    final name = _name.text.trim(), code = _code.text.trim().toUpperCase();
    final others = [
      for (final x in widget.book.attrs)
        if (x.id != _a?.id) x,
    ];
    String? codeErr, nameErr, valMsg;
    if (name.isEmpty) {
      nameErr = 'Nhập tên thuộc tính.';
    } else if (others.any((x) => normAttr(x.name) == normAttr(name))) {
      nameErr = 'Tên thuộc tính đã tồn tại.';
    }
    if (!attrCodeRe.hasMatch(code)) {
      codeErr = 'Chỉ gồm chữ không dấu và số, không khoảng trắng.';
    } else if (code.isNotEmpty && others.any((x) => x.code.toUpperCase() == code)) {
      codeErr = 'Mã thuộc tính đã tồn tại.';
    }
    // Dòng để trống tên coi như bấm thêm rồi để đấy — PC (và API) cũng bỏ qua.
    final seenN = <String>{}, seenC = <String>{};
    for (final r in _rows) {
      r.badCode = r.badName = false;
      final n = r.name.text.trim();
      if (n.isEmpty) continue;
      final c = r.code.text.trim().toUpperCase();
      String? m;
      if (!attrCodeRe.hasMatch(c)) {
        m = 'Mã giá trị chỉ gồm chữ không dấu và số.';
        r.badCode = true;
      } else if (c.isNotEmpty && seenC.contains(c)) {
        m = 'Mã giá trị bị trùng trong cùng thuộc tính ($c).';
        r.badCode = true;
      } else if (seenN.contains(normAttr(n))) {
        m = 'Tên giá trị bị trùng trong cùng thuộc tính (“$n”).';
        r.badName = true;
      }
      valMsg ??= m;
      seenN.add(normAttr(n));
      if (c.isNotEmpty) seenC.add(c);
    }
    setState(() {
      _codeErr = codeErr;
      _nameErr = nameErr;
      _valMsg = valMsg;
      _err = null;
      // Mã gõ sai mà cột mã đang ẩn thì hiện ra cho thấy chỗ sai.
      if (_rows.any((r) => r.badCode)) _showCodes = true;
    });
    if (codeErr != null || nameErr != null || valMsg != null) return;

    final repo = ref.read(attrRepoProvider);
    final body = repo.body(
      code: code,
      name: name,
      active: _active,
      values: [
        for (final r in _rows)
          if (r.name.text.trim().isNotEmpty)
            AttrValue(id: r.id, code: r.code.text.trim().toUpperCase(), name: r.name.text.trim()),
      ],
    );
    setState(() => _saving = true);
    try {
      final Attr saved;
      if (_a != null) {
        await repo.update(_a.id, body);
        saved = Attr(
          id: _a.id,
          code: code.isEmpty ? _a.code : code,
          name: name,
          active: _active,
          values: [for (final v in body['values'] as List) AttrValue(code: '${v['code']}', name: '${v['name']}')],
        );
      } else {
        final c = await repo.create(body);
        saved = Attr(
          id: c.id,
          code: c.code,
          name: name,
          active: _active,
          values: [for (final v in body['values'] as List) AttrValue(code: '${v['code']}', name: '${v['name']}')],
        );
      }
      repo.refresh();
      if (mounted) Navigator.of(context).pop((saved, _a == null));
    } on ApiException catch (e) {
      // Giữ hộp, báo đúng ô: trùng mã / trùng tên / lỗi của bảng giá trị.
      if (!mounted) return;
      setState(() {
        _saving = false;
        final f = e.fieldErrors;
        if (f.isEmpty) {
          _err = e.message;
        } else {
          _codeErr = f['code'];
          _nameErr = f['name'];
          _valMsg = f['values'] ?? f.entries.where((x) => x.key.startsWith('values')).map((x) => x.value).firstOrNull;
          if (_codeErr == null && _nameErr == null && _valMsg == null) _err = e.message;
        }
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    final used = _a == null ? 0 : widget.book.used(_a).length;
    final filled = _rows.where((r) => r.name.text.trim().isNotEmpty).length;
    final bulkN = splitBulk(_bulk.text).length;
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
                padding: EdgeInsets.fromLTRB(20, 0, 20, used > 0 ? 2 : 12),
                child: Text(
                  _a != null
                      ? 'Sửa thuộc tính'
                      : widget.copy != null
                      ? 'Nhân bản thuộc tính'
                      : 'Thêm thuộc tính',
                  style: const TextStyle(color: OrderColors.ink, fontSize: 18, fontWeight: FontWeight.w700),
                ),
              ),
              if (used > 0)
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
                  child: Text(
                    'Đang dùng ở $used sản phẩm',
                    style: const TextStyle(color: OrderColors.muted, fontSize: 13),
                  ),
                ),
              Flexible(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: KitField(
                              label: 'Mã',
                              counter: _a != null ? 'để trống = giữ mã cũ' : 'để trống = tự đặt',
                              error: _codeErr,
                              errorKey: const Key('attr_f_code_err'),
                              child: KitInput(
                                key: const Key('attr_f_code'),
                                controller: _code,
                                hint: _a?.code ?? 'Tự tạo khi lưu',
                                maxLength: 20,
                                upper: true,
                                bad: _codeErr != null,
                                onChanged: (_) => setState(() {
                                  _dirty = true;
                                  _codeErr = null;
                                }),
                              ),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const KitLabel('Trạng thái'),
                              SizedBox(
                                height: 48,
                                child: Switch(
                                  key: const Key('attr_f_active'),
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
                      KitField(
                        label: 'Tên thuộc tính',
                        required: true,
                        counter: '${_name.text.length}/100',
                        error: _nameErr,
                        errorKey: const Key('attr_f_name_err'),
                        child: KitInput(
                          key: const Key('attr_f_name'),
                          controller: _name,
                          hint: 'VD: Màu sắc, Size, Dung lượng',
                          maxLength: 100,
                          autofocus: _a == null && widget.copy == null,
                          bad: _nameErr != null,
                          onChanged: (_) => setState(() {
                            _dirty = true;
                            _nameErr = null;
                          }),
                        ),
                      ),
                      const SizedBox(height: 20),
                      const Divider(height: 1, color: OrderColors.line),
                      const SizedBox(height: 8),
                      Row(
                        children: [
                          Expanded(
                            child: Text.rich(
                              TextSpan(
                                text: 'Giá trị',
                                children: [
                                  if (filled > 0)
                                    TextSpan(
                                      text: '  ($filled)',
                                      style: const TextStyle(
                                        color: OrderColors.muted,
                                        fontSize: 12.5,
                                        fontWeight: FontWeight.w500,
                                      ),
                                    ),
                                ],
                              ),
                              style: const TextStyle(
                                color: OrderColors.ink,
                                fontSize: 14.5,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                          if (_rows.isNotEmpty)
                            _LinkText(key: const Key('attr_v_clear'), label: 'Xoá tất cả', color: kOut, onTap: _clear),
                          _LinkText(
                            key: const Key('attr_v_codes'),
                            label: _showCodes ? 'Ẩn mã' : 'Hiện mã',
                            color: OrderColors.primaryInk,
                            onTap: () => _codes(!_showCodes),
                          ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      if (_rows.isEmpty)
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(12)),
                          child: const Text(
                            'Chưa có giá trị nào.',
                            textAlign: TextAlign.center,
                            style: TextStyle(color: OrderColors.muted, fontSize: 13),
                          ),
                        )
                      else
                        Padding(
                          padding: const EdgeInsets.only(bottom: 6),
                          child: Row(
                            children: [
                              if (_showCodes) const SizedBox(width: 100, child: _HeadText('Mã')),
                              const Expanded(child: _HeadText('Tên giá trị')),
                            ],
                          ),
                        ),
                      for (final (i, r) in _rows.indexed) ...[
                        Padding(
                          padding: EdgeInsets.only(top: i == 0 ? 0 : 8),
                          child: Row(
                            children: [
                              if (_showCodes) ...[
                                SizedBox(
                                  width: 92,
                                  child: KitInput(
                                    key: Key('attr_v_code_$i'),
                                    controller: r.code,
                                    hint: 'Tự đặt',
                                    height: 44,
                                    fontSize: 13,
                                    maxLength: 32,
                                    upper: true,
                                    bad: r.badCode,
                                    onChanged: (_) => setState(() {
                                      _dirty = true;
                                      r.badCode = false;
                                      _valMsg = null;
                                    }),
                                  ),
                                ),
                                const SizedBox(width: 8),
                              ],
                              Expanded(
                                child: KitInput(
                                  key: Key('attr_v_name_$i'),
                                  controller: r.name,
                                  hint: 'Tên giá trị',
                                  height: 44,
                                  fontSize: 15,
                                  maxLength: 100,
                                  bad: r.badName,
                                  onChanged: (_) => setState(() {
                                    _dirty = true;
                                    r.badName = false;
                                    _valMsg = null;
                                  }),
                                ),
                              ),
                              const SizedBox(width: 8),
                              SizedBox(
                                width: 36,
                                height: 36,
                                child: IconButton(
                                  key: Key('attr_v_x_$i'),
                                  tooltip: _use(r) > 0 ? 'Đang dùng, không bỏ được' : 'Bỏ giá trị ${i + 1}',
                                  padding: EdgeInsets.zero,
                                  onPressed: () => _remove(r),
                                  icon: AppIcon(
                                    _use(r) > 0 ? AppIcons.lock : AppIcons.close,
                                    size: 18,
                                    color: _use(r) > 0 ? _ok : OrderColors.muted,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                        if (_use(r) > 0)
                          Padding(
                            padding: EdgeInsets.only(left: _showCodes ? 100 : 0, top: 3, bottom: 1),
                            child: Text(
                              'dùng ở ${_use(r)} sản phẩm',
                              style: const TextStyle(color: _ok, fontSize: 11.5, fontWeight: FontWeight.w600),
                            ),
                          ),
                      ],
                      if (_valMsg != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Text(
                            _valMsg!,
                            key: const Key('attr_v_msg'),
                            style: const TextStyle(color: kOut, fontSize: 12.5),
                          ),
                        ),
                      const SizedBox(height: 10),
                      Row(
                        children: [
                          Expanded(
                            child: Material(
                              color: const Color(0xFFFAFBFD),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(12),
                                side: const BorderSide(color: Color(0xFFC9D3E2), width: 1.5),
                              ),
                              child: InkWell(
                                key: const Key('attr_v_add'),
                                borderRadius: BorderRadius.circular(12),
                                onTap: _add,
                                child: const SizedBox(
                                  height: 44,
                                  child: FittedBox(
                                    fit: BoxFit.scaleDown,
                                    child: Row(
                                      children: [
                                        AppIcon(AppIcons.plus, size: 18, color: OrderColors.primaryInk),
                                        SizedBox(width: 6),
                                        Text(
                                          'Thêm giá trị',
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
                            ),
                          ),
                          const SizedBox(width: 8),
                          Material(
                            color: Colors.white,
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12),
                              side: const BorderSide(color: OrderColors.line, width: 1.5),
                            ),
                            child: InkWell(
                              key: const Key('attr_v_bulk'),
                              borderRadius: BorderRadius.circular(12),
                              onTap: () => setState(() => _showBulk = !_showBulk),
                              child: const SizedBox(
                                height: 44,
                                child: Padding(
                                  padding: EdgeInsets.symmetric(horizontal: 14),
                                  child: Row(
                                    children: [
                                      AppIcon(AppIcons.menu, size: 18, color: OrderColors.ink),
                                      SizedBox(width: 6),
                                      Text(
                                        'Thêm nhiều',
                                        style: TextStyle(
                                          color: OrderColors.ink,
                                          fontSize: 14,
                                          fontWeight: FontWeight.w600,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            ),
                          ),
                        ],
                      ),
                      if (_showBulk)
                        Container(
                          margin: const EdgeInsets.only(top: 10),
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(12)),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              TextField(
                                key: const Key('attr_bulk_in'),
                                controller: _bulk,
                                autofocus: true,
                                minLines: 3,
                                maxLines: 6,
                                onChanged: (_) => setState(() {}),
                                style: const TextStyle(color: OrderColors.ink, fontSize: 15),
                                decoration: InputDecoration(
                                  hintText: 'Mỗi dòng một giá trị, hoặc cách nhau bằng dấu phẩy\nVD: S, M, L, XL',
                                  hintStyle: const TextStyle(color: OrderColors.faint, fontSize: 14),
                                  filled: true,
                                  fillColor: Colors.white,
                                  contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                                  enabledBorder: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(10),
                                    borderSide: const BorderSide(color: OrderColors.line, width: 1.5),
                                  ),
                                  focusedBorder: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(10),
                                    borderSide: const BorderSide(color: OrderColors.primary, width: 1.5),
                                  ),
                                ),
                              ),
                              const SizedBox(height: 8),
                              Row(
                                children: [
                                  Expanded(
                                    child: Text(
                                      '$bulkN giá trị',
                                      style: const TextStyle(color: OrderColors.muted, fontSize: 12),
                                    ),
                                  ),
                                  FilledButton(
                                    key: const Key('attr_bulk_ok'),
                                    onPressed: bulkN == 0 ? null : _addBulk,
                                    style: FilledButton.styleFrom(
                                      backgroundColor: OrderColors.primary,
                                      minimumSize: const Size(0, 38),
                                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                                      textStyle: const TextStyle(
                                        fontFamily: appFont,
                                        fontSize: 14,
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                    child: const Text('Thêm vào danh sách'),
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      if (_err != null) ...[const SizedBox(height: 12), KitWarn(text: _err!, error: true)],
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
                      child: KitBtn(label: 'Huỷ', onTap: _saving ? null : _leave),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: KitBtn(
                        key: const Key('attr_f_save'),
                        label: _saving ? 'Đang lưu…' : 'Lưu',
                        color: OrderColors.primary,
                        onTap: _saving ? null : _save,
                      ),
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

class _HeadText extends StatelessWidget {
  const _HeadText(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Text(
    text,
    style: const TextStyle(color: OrderColors.faint, fontSize: 11.5, fontWeight: FontWeight.w600),
  );
}

class _LinkText extends StatelessWidget {
  const _LinkText({super.key, required this.label, required this.color, required this.onTap});

  final String label;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => TextButton(
    onPressed: onTap,
    style: TextButton.styleFrom(
      foregroundColor: color,
      minimumSize: const Size(0, 30),
      padding: const EdgeInsets.symmetric(horizontal: 6),
      textStyle: const TextStyle(fontFamily: appFont, fontSize: 13, fontWeight: FontWeight.w600),
    ),
    child: Text(label),
  );
}
