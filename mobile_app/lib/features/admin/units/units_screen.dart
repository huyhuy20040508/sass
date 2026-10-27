import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_text.dart';
import '../../../core/ui/app_icon.dart';
import '../../auth/permissions.dart';
import '../shell/list_kit.dart';
import '../shell/more_menu.dart' show MoreHost;
import '../shell/shell_widgets.dart' show showDashSheet;
import 'units_data.dart';

const _ok = Color(0xFF0F7A3D);

/// Đơn vị tính theo design/html/don-vi-tinh-mobile.html (v1): cùng chức năng "Danh sách
/// đơn vị tính" của PC (bảng Mã · Tên · Trạng thái + hộp Thêm/Sửa). Nằm trong khung app.
class UnitsScreen extends ConsumerStatefulWidget {
  const UnitsScreen({super.key, required this.host});

  final MoreHost host;

  @override
  ConsumerState<UnitsScreen> createState() => _UnitsScreenState();
}

class _UnitsScreenState extends ConsumerState<UnitsScreen> {
  final _search = TextEditingController();
  Timer? _debounce;
  String _q = '';
  ActiveFilter _st = ActiveFilter.all;
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

  Future<void> _setActive(Unit u, bool on) async {
    final repo = ref.read(unitRepoProvider);
    try {
      await repo.setActive(u.id, on);
      repo.refresh();
    } on ApiException catch (e) {
      return _toast(e.message);
    }
    if (!mounted) return;
    _toast(
      on ? 'Đã bật lại ${u.name}' : 'Đã tắt ${u.name}',
      action: 'Hoàn tác',
      onAction: () async {
        try {
          await repo.setActive(u.id, !on);
          repo.refresh();
        } on ApiException catch (e) {
          if (mounted) _toast(e.message);
        }
      },
    );
  }

  Future<void> _actions(Unit u, UnitBook b) async {
    final perms = permsOf(ref);
    final n = b.used(u);
    final pick = await showDashSheet<String>(
      context,
      title: u.name,
      subtitle: '${u.code} · ${b.useText(u) ?? 'chưa dùng'}',
      body: (ctx) {
        Widget item(String key, AppIcons icon, String label, {String? note, bool danger = false, bool off = false}) =>
            KitActionRow(
              key: Key('unit_act_$key'),
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
            if (perms.can(Quyen.suaDonVi)) item('edit', AppIcons.edit, 'Sửa tên, mã'),
            if (perms.can(Quyen.themDonVi)) item('copy', AppIcons.copy, 'Nhân bản'),
            if (perms.can(Quyen.suaDonVi))
              u.active
                  ? item(
                      'toggle',
                      AppIcons.eyeOff,
                      'Tắt đơn vị',
                      note: n > 0
                          ? '$n sản phẩm đang dùng vẫn giữ nguyên; chỉ ẩn khỏi ô chọn khi khai hàng mới'
                          : 'Ẩn khỏi ô chọn đơn vị khi khai hàng',
                    )
                  : item('toggle', AppIcons.check, 'Bật lại'),
            if (perms.can(Quyen.xoaDonVi))
              item(
                'del',
                AppIcons.trash,
                'Xoá đơn vị',
                danger: true,
                off: n > 0,
                note: n > 0 ? 'Đang dùng ở $n sản phẩm — tắt thay vì xoá' : null,
              ),
          ],
        );
      },
    );
    if (!mounted || pick == null) return;
    switch (pick) {
      case 'edit':
        await _openForm(b, edit: u);
      case 'copy':
        await _openForm(b, copy: u);
      case 'toggle':
        await _setActive(u, !u.active);
      case 'del':
        if (n > 0) return _toast('Không xoá được: ${u.name} đang dùng ở $n sản phẩm. Tắt đi để thôi dùng.');
        await _askDelete([u], b);
    }
  }

  Future<void> _askDelete(List<Unit> list, UnitBook b) async {
    final ok = [
      for (final u in list)
        if (b.used(u) == 0) u,
    ];
    final no = [
      for (final u in list)
        if (b.used(u) > 0) u,
    ];
    if (list.length == 1 && no.isNotEmpty) {
      return _toast('Không xoá được: ${no.first.name} đang dùng ở ${b.used(no.first)} sản phẩm.');
    }
    final yes = await showDashSheet<bool>(
      context,
      title: ok.length == 1 ? 'Xoá đơn vị “${ok.first.name}”?' : 'Xoá ${ok.length} đơn vị?',
      subtitle: 'Không hoàn tác được.',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (ok.length > 1)
            Padding(
              padding: const EdgeInsets.fromLTRB(4, 0, 4, 10),
              child: Text(
                ok.map((u) => u.name).join(', '),
                style: const TextStyle(color: OrderColors.text, fontSize: 13.5),
              ),
            ),
          if (no.isNotEmpty)
            KitWarn(
              text:
                  'Bỏ qua ${no.length} đơn vị đang có hàng dùng: '
                  '${no.map((u) => '${u.name} (${b.used(u)} sp)').join(', ')}. Tắt đi nếu muốn thôi dùng.',
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
                  key: const Key('unit_del_yes'),
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
    final repo = ref.read(unitRepoProvider);
    var done = 0;
    String? err;
    for (final u in ok) {
      try {
        await repo.delete(u.id);
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
          ? 'Đã xoá $done/${ok.length} đơn vị — $err'
          : done == 1
          ? 'Đã xoá ${ok.first.name}'
          : 'Đã xoá $done đơn vị${no.isNotEmpty ? '; bỏ qua ${no.length}' : ''}',
    );
  }

  Future<void> _openForm(UnitBook b, {Unit? edit, Unit? copy, String preset = ''}) async {
    final last = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      backgroundColor: Colors.white,
      barrierColor: const Color(0x73111827),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (_) => UnitForm(book: b, edit: edit, copy: copy, preset: preset),
    );
    if (last != null && mounted) _toast(last);
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(unitBookProvider);
    final b = async.value;
    final perms = permsOf(ref);
    final pool = b == null
        ? const <Unit>[]
        : [
            for (final u in b.units)
              if (unitMatches(u, _q)) u,
          ];
    final rows = [
      for (final u in pool)
        if (_st.has(u.active)) u,
    ];
    final sel = _sel;
    final picked = b == null || sel == null ? const <Unit>[] : [for (final id in sel) ?b.byId(id)];
    final canAdd = perms.can(Quyen.themDonVi);

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
              KitListHeader(
                title: 'Đơn vị tính',
                keyPrefix: 'unit',
                addTooltip: 'Thêm đơn vị',
                searchHint: 'Tìm tên hoặc mã đơn vị',
                selecting: sel != null,
                onSelect: rows.isNotEmpty && perms.can(Quyen.xoaDonVi)
                    ? () => setState(() => _sel = sel == null ? <int>{} : null)
                    : null,
                onAdd: b != null && canAdd ? () => _openForm(b) : null,
                search: _search,
                onSearch: _onSearch,
                onClear: () {
                  _search.clear();
                  setState(() => _q = '');
                },
                st: _st,
                counts: {for (final s in ActiveFilter.values) s: pool.where((u) => s.has(u.active)).length},
                onSt: (s) => setState(() => _st = s),
              ),
              Expanded(
                child: RefreshIndicator(
                  color: OrderColors.primary,
                  onRefresh: () async {
                    ref.invalidate(unitBookProvider);
                    try {
                      await ref.read(unitBookProvider.future);
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
                            title: 'Không tải được đơn vị tính',
                            text: async.error is ApiException
                                ? (async.error as ApiException).message
                                : 'Thử lại sau ít phút.',
                            action: 'Thử lại',
                            onAction: () => ref.invalidate(unitBookProvider),
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
                          icon: _q.isNotEmpty ? AppIcons.search : AppIcons.scale,
                          title: _q.isNotEmpty
                              ? 'Không có đơn vị nào khớp “$_q”'
                              : _st == ActiveFilter.off
                              ? 'Không có đơn vị nào đang tắt'
                              : 'Chưa có đơn vị tính nào',
                          text: _q.isNotEmpty
                              ? 'Thêm luôn “$_q” làm đơn vị mới?'
                              : 'Đơn vị như Cái, Hộp, Thùng… dùng khi khai hàng và quy đổi.',
                          // Tìm không ra thì thêm luôn đúng chữ vừa gõ.
                          action: !canAdd || (_st == ActiveFilter.off && _q.isEmpty)
                              ? null
                              : _q.isNotEmpty
                              ? 'Thêm “$_q”'
                              : 'Thêm đơn vị',
                          actionKey: const Key('unit_empty_add'),
                          onAction: () => _openForm(b, preset: _q),
                        ),
                      ] else ...[
                        if (_q.isNotEmpty)
                          Padding(
                            padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
                            child: Text(
                              '${rows.length} ĐƠN VỊ KHỚP “$_q”',
                              style: const TextStyle(
                                color: OrderColors.muted,
                                fontSize: 12.5,
                                fontWeight: FontWeight.w700,
                                letterSpacing: .5,
                              ),
                            ),
                          )
                        else
                          const SizedBox(height: 10),
                        Container(
                          margin: const EdgeInsets.symmetric(horizontal: 12),
                          clipBehavior: Clip.antiAlias,
                          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
                          child: Material(
                            color: Colors.white,
                            child: Column(
                              children: [
                                for (final (i, u) in rows.indexed) ...[
                                  if (i > 0) const Divider(height: 1, color: OrderColors.line),
                                  _UnitRow(
                                    key: Key('unit_${u.id}'),
                                    unit: u,
                                    use: b.useText(u, short: true),
                                    query: _q,
                                    selected: sel?.contains(u.id),
                                    onTap: () => sel != null
                                        ? setState(() => sel.contains(u.id) ? sel.remove(u.id) : sel.add(u.id))
                                        : perms.can(Quyen.suaDonVi)
                                        ? _openForm(b, edit: u)
                                        : _actions(u, b),
                                    onMore: () => _actions(u, b),
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
                  keyPrefix: 'unit',
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

class _UnitRow extends StatelessWidget {
  const _UnitRow({
    super.key,
    required this.unit,
    required this.use,
    required this.query,
    required this.onTap,
    required this.onMore,
    this.selected,
  });

  final Unit unit;

  /// null = chưa dùng.
  final String? use;
  final String query;
  final bool? selected;
  final VoidCallback onTap;
  final VoidCallback onMore;

  @override
  Widget build(BuildContext context) {
    final u = unit;
    final sel = selected ?? false;
    final q = foldVn(query.trim());
    return Material(
      color: sel ? OrderColors.primarySoft : Colors.white,
      child: InkWell(
        onTap: onTap,
        onLongPress: selected == null ? onMore : null,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 10, 6, 10),
          child: Row(
            children: [
              if (selected != null) ...[
                Container(
                  key: Key('unit_check_${u.id}'),
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
                  color: u.active ? OrderColors.primarySoft : OrderColors.grid,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: AppIcon(AppIcons.scale, size: 19, color: u.active ? OrderColors.primaryInk : OrderColors.faint),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text.rich(
                      TextSpan(
                        children: [
                          ..._hl(u.name, q),
                          if (!u.active)
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
                        color: u.active ? OrderColors.ink : OrderColors.faint,
                        fontSize: 15,
                        fontWeight: FontWeight.w600,
                        height: 1.3,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text.rich(
                      TextSpan(
                        children: [
                          ..._hl(u.code, q),
                          const TextSpan(text: ' · '),
                          use == null
                              ? const TextSpan(text: 'chưa dùng')
                              : TextSpan(
                                  text: use,
                                  style: const TextStyle(color: _ok, fontWeight: FontWeight.w600),
                                ),
                        ],
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: OrderColors.muted, fontSize: 12),
                    ),
                  ],
                ),
              ),
              if (selected == null)
                SizedBox(
                  width: 34,
                  height: 40,
                  child: IconButton(
                    key: Key('unit_more_${u.id}'),
                    tooltip: 'Thao tác với ${u.name}',
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

// ---------- Hộp Thêm / Sửa / Nhân bản ----------

/// Trả về câu báo của lần lưu cuối (null = đóng mà không lưu gì).
class UnitForm extends ConsumerStatefulWidget {
  const UnitForm({super.key, required this.book, this.edit, this.copy, this.preset = ''});

  final UnitBook book;
  final Unit? edit;

  /// Nhân bản: tên thêm " copy", bỏ mã để API đặt mã mới.
  final Unit? copy;

  /// Tên điền sẵn (tìm không ra → "Thêm “…”").
  final String preset;

  @override
  ConsumerState<UnitForm> createState() => _UnitFormState();
}

class _UnitFormState extends ConsumerState<UnitForm> {
  late final Unit? _u = widget.edit;
  late final _name = TextEditingController(
    text: widget.edit?.name ?? (widget.copy != null ? '${widget.copy!.name} copy' : widget.preset),
  );
  late final _code = TextEditingController(text: _u?.code ?? '');
  final _nameFocus = FocusNode();
  late bool _active = _u?.active ?? true;
  late bool _dirty = widget.copy != null;
  bool _saving = false;
  String? _nameErr, _codeErr, _err;

  /// Đơn vị đã thêm bằng "Lưu & thêm tiếp" — tính vào danh sách khi kiểm trùng.
  final _added = <Unit>[];
  String? _lastMsg;

  @override
  void dispose() {
    _name.dispose();
    _code.dispose();
    _nameFocus.dispose();
    super.dispose();
  }

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
    if (mounted) Navigator.of(context).pop(_lastMsg);
  }

  Future<void> _save({required bool again}) async {
    final name = _name.text.trim().replaceAll(RegExp(r'\s+'), ' ');
    final code = _code.text.trim().toUpperCase();
    final others = [
      for (final x in [...widget.book.units, ..._added])
        if (x.id != _u?.id) x,
    ];
    String? nameErr, codeErr;
    if (name.isEmpty) {
      nameErr = 'Nhập tên đơn vị.';
    } else if (others.any((x) => normUnit(x.name) == normUnit(name))) {
      nameErr = 'Tên đơn vị tính đã tồn tại.';
    }
    if (!unitCodeRe.hasMatch(code)) {
      codeErr = 'Mã đơn vị chỉ gồm chữ không dấu và số, không khoảng trắng.';
    } else if (code.isNotEmpty && others.any((x) => x.code.toUpperCase() == code)) {
      codeErr = 'Mã đơn vị tính đã tồn tại.';
    }
    setState(() {
      _nameErr = nameErr;
      _codeErr = codeErr;
      _err = null;
    });
    if (nameErr != null || codeErr != null) return;
    final repo = ref.read(unitRepoProvider);
    setState(() => _saving = true);
    try {
      final String msg;
      if (_u != null) {
        await repo.update(_u.id, code: code, name: name, active: _active);
        msg = 'Đã cập nhật đơn vị "$name" (${code.isEmpty ? _u.code : code})';
      } else {
        final c = await repo.create(code: code, name: name, active: _active);
        _added.add(c);
        msg = 'Đã thêm đơn vị "$name" (${c.code})';
      }
      repo.refresh();
      if (!mounted) return;
      if (!again) return Navigator.of(context).pop(msg);
      // Giữ hộp mở, xoá trắng để gõ đơn vị kế tiếp; số đã thêm lên tiêu đề cho biết hộp vẫn đang làm việc.
      _lastMsg = msg;
      setState(() {
        _saving = false;
        _dirty = false;
        _active = true;
        _name.clear();
        _code.clear();
      });
      _nameFocus.requestFocus();
      showActionToast(context, msg);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        final f = e.fieldErrors;
        _nameErr = f['name'];
        _codeErr = f['code'];
        if (_nameErr == null && _codeErr == null) _err = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    final edit = _u != null;
    final b = widget.book;
    final use = edit ? b.useText(_u) : null;
    final sugg = edit ? const <String>[] : unitSuggestions([...b.units, ..._added], _name.text);
    final title = edit
        ? 'Sửa đơn vị tính'
        : widget.copy != null && _added.isEmpty
        ? 'Nhân bản đơn vị'
        : _added.isEmpty
        ? 'Thêm đơn vị tính'
        : 'Thêm đơn vị tính · đã thêm ${_added.length}';
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
                padding: EdgeInsets.fromLTRB(20, 0, 20, use != null ? 2 : 12),
                child: Text(
                  title,
                  key: const Key('unit_f_title'),
                  style: const TextStyle(color: OrderColors.ink, fontSize: 18, fontWeight: FontWeight.w700),
                ),
              ),
              if (use != null)
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
                  child: Text('Đang dùng: $use', style: const TextStyle(color: OrderColors.muted, fontSize: 13)),
                ),
              Flexible(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      KitField(
                        label: 'Tên đơn vị',
                        required: true,
                        counter: '${_name.text.length}/100',
                        error: _nameErr,
                        errorKey: const Key('unit_f_name_err'),
                        child: KitInput(
                          key: const Key('unit_f_name'),
                          controller: _name,
                          focusNode: _nameFocus,
                          hint: 'VD: Cái, Hộp, Thùng',
                          maxLength: 100,
                          autofocus: !edit,
                          bad: _nameErr != null,
                          onChanged: (_) => setState(() {
                            _dirty = true;
                            _nameErr = null;
                          }),
                          onSubmitted: (_) => _save(again: !edit),
                        ),
                      ),
                      if (sugg.isNotEmpty) ...[
                        const SizedBox(height: 10),
                        Wrap(
                          spacing: 6,
                          runSpacing: 6,
                          crossAxisAlignment: WrapCrossAlignment.center,
                          children: [
                            const Padding(
                              padding: EdgeInsets.only(right: 2),
                              child: Text(
                                'Hay dùng',
                                style: TextStyle(color: OrderColors.faint, fontSize: 12, fontWeight: FontWeight.w600),
                              ),
                            ),
                            for (final s in sugg)
                              _Sugg(
                                key: Key('unit_sugg_$s'),
                                label: s,
                                onTap: () {
                                  _name.text = s;
                                  setState(() {
                                    _dirty = true;
                                    _nameErr = null;
                                  });
                                  _nameFocus.requestFocus();
                                },
                              ),
                          ],
                        ),
                      ],
                      const SizedBox(height: 14),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: KitField(
                              label: 'Mã',
                              counter: edit ? 'để trống = giữ mã cũ' : 'để trống = tự đặt',
                              error: _codeErr,
                              errorKey: const Key('unit_f_code_err'),
                              child: KitInput(
                                key: const Key('unit_f_code'),
                                controller: _code,
                                hint: _u?.code ?? 'Tự tạo khi lưu',
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
                                  key: const Key('unit_f_active'),
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
                    if (edit)
                      Expanded(
                        child: KitBtn(label: 'Huỷ', onTap: _saving ? null : _leave),
                      )
                    else
                      KitBtn(label: 'Huỷ', onTap: _saving ? null : _leave),
                    const SizedBox(width: 10),
                    // Khai liền nhiều đơn vị không phải mở lại hộp.
                    if (!edit) ...[
                      Expanded(
                        child: KitBtn(
                          key: const Key('unit_f_more'),
                          label: 'Lưu & thêm tiếp',
                          onTap: _saving ? null : () => _save(again: true),
                        ),
                      ),
                      const SizedBox(width: 10),
                    ],
                    Expanded(
                      child: KitBtn(
                        key: const Key('unit_f_save'),
                        label: _saving ? 'Đang lưu…' : 'Lưu',
                        color: OrderColors.primary,
                        onTap: _saving ? null : () => _save(again: false),
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

class _Sugg extends StatelessWidget {
  const _Sugg({super.key, required this.label, required this.onTap});

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
    color: const Color(0xFFFAFBFD),
    shape: const StadiumBorder(side: BorderSide(color: Color(0xFFC9D3E2))),
    child: InkWell(
      customBorder: const StadiumBorder(),
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
        child: Text(
          label,
          style: const TextStyle(color: OrderColors.primaryInk, fontSize: 13.5, fontWeight: FontWeight.w600),
        ),
      ),
    ),
  );
}
