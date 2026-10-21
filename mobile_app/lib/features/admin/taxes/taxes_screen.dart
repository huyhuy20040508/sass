import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/ui/app_icon.dart';
import '../../auth/permissions.dart';
import '../shell/list_kit.dart';
import '../shell/more_menu.dart' show MoreHost;
import 'taxes_data.dart';

const _ok = Color(0xFF0F7A3D);

/// Mã hoá đơn (KCT / KKKNT): màu tím cho khỏi đọc nhầm thành phần trăm.
const _codeBg = Color(0xFFF3ECFF), _codeFg = Color(0xFF6D28D9), _codeLine = Color(0xFF8B5CF6);

/// Thuế theo design/html/thue-mobile.html (v1): cùng chức năng màn "Thuế" của PC (bảng
/// Mã · Tên · Mức thuế · Trạng thái + hộp sửa mức). Bốn loại cố định — không thêm, xoá,
/// tìm; chạm thẻ để sửa mức và bật/tắt. Nằm trong khung app.
class TaxesScreen extends ConsumerWidget {
  const TaxesScreen({super.key, required this.host});

  final MoreHost host;

  Future<void> _edit(BuildContext context, WidgetRef ref, TaxBook b, TaxKind k) async {
    final res = await showModalBottomSheet<_Saved>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      backgroundColor: Colors.white,
      barrierColor: const Color(0x73111827),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (_) => TaxForm(book: b, kind: k),
    );
    if (res == null || !context.mounted) return;
    final repo = ref.read(taxRepoProvider);
    final now = res.levels.map(k.label).join(', ');
    if (!res.levelsChanged) {
      // Chỉ bật/tắt: có Hoàn tác như các màn danh mục khác.
      return showActionToast(
        context,
        res.active ? 'Đã bật ${k.name}' : 'Đã tắt ${k.name}',
        action: 'Hoàn tác',
        onAction: () => _undo(context, repo, k, res),
      );
    }
    showActionToast(
      context,
      'Đã lưu ${k.name}: $now${res.activeChanged ? (res.active ? ' · đã bật' : ' · đã tắt') : ''}',
      action: res.activeChanged ? 'Hoàn tác' : null,
      onAction: res.activeChanged ? () => _undo(context, repo, k, res) : null,
    );
  }

  Future<void> _undo(BuildContext context, TaxRepo repo, TaxKind k, _Saved res) async {
    try {
      if (res.levelsChanged) await repo.setLevels(k.id, k.levels);
      if (res.activeChanged) await repo.setActive(k.id, k.active);
    } on ApiException catch (e) {
      if (context.mounted) showActionToast(context, e.message);
    }
    repo.refresh();
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(taxBookProvider);
    final b = async.value;
    final canEdit = permsOf(ref).can(Quyen.suaThue);
    final top = MediaQuery.paddingOf(context).top;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) host.close();
      },
      child: AnnotatedRegion<SystemUiOverlayStyle>(
        value: SystemUiOverlayStyle.dark,
        child: ColoredBox(
          color: OrderColors.page,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Container(
                padding: EdgeInsets.only(top: top + 4, bottom: 10),
                decoration: const BoxDecoration(
                  color: Colors.white,
                  boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, 1))],
                ),
                child: const Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    SizedBox(
                      height: 48,
                      child: Padding(
                        padding: EdgeInsets.symmetric(horizontal: 16),
                        child: Align(
                          alignment: Alignment.centerLeft,
                          child: Text(
                            'Thuế',
                            style: TextStyle(
                              color: OrderColors.ink,
                              fontSize: 21,
                              fontWeight: FontWeight.w700,
                              letterSpacing: -.4,
                            ),
                          ),
                        ),
                      ),
                    ),
                    Padding(
                      padding: EdgeInsets.symmetric(horizontal: 16),
                      child: Text(
                        '4 loại thuế cố định. Chọn mức nào được hiện ở màn bán, mua và khai hàng.',
                        style: TextStyle(color: OrderColors.muted, fontSize: 13, height: 1.45),
                      ),
                    ),
                  ],
                ),
              ),
              Expanded(
                child: RefreshIndicator(
                  color: OrderColors.primary,
                  onRefresh: () async {
                    ref.invalidate(taxBookProvider);
                    try {
                      await ref.read(taxBookProvider.future);
                    } on ApiException catch (e) {
                      if (context.mounted) showActionToast(context, e.message);
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
                            title: 'Không tải được thuế',
                            text: async.error is ApiException
                                ? (async.error as ApiException).message
                                : 'Thử lại sau ít phút.',
                            action: 'Thử lại',
                            onAction: () => ref.invalidate(taxBookProvider),
                          ),
                        )
                      else if (b == null)
                        const Padding(
                          padding: EdgeInsets.only(top: 60),
                          child: Center(child: CircularProgressIndicator(color: OrderColors.primary)),
                        )
                      else
                        for (final k in b.kinds)
                          _TaxCard(
                            key: Key('tax_${k.id}'),
                            kind: k,
                            book: b,
                            canEdit: canEdit,
                            onTap: canEdit ? () => _edit(context, ref, b, k) : null,
                          ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _TaxCard extends StatelessWidget {
  const _TaxCard({super.key, required this.kind, required this.book, required this.canEdit, this.onTap});

  final TaxKind kind;
  final TaxBook book;
  final bool canEdit;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final k = kind;
    final on = k.active;
    return Container(
      margin: const EdgeInsets.fromLTRB(12, 10, 12, 0),
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
      child: Material(
        color: Colors.white,
        child: InkWell(
          onTap: onTap,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 14, 14, 0),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: 40,
                      height: 40,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: on ? OrderColors.primarySoft : OrderColors.grid,
                        borderRadius: BorderRadius.circular(11),
                      ),
                      child: AppIcon(
                        AppIcons.percent,
                        size: 19,
                        color: on ? OrderColors.primaryInk : OrderColors.faint,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text.rich(
                            TextSpan(
                              text: k.name,
                              children: [
                                if (!on)
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
                              color: on ? OrderColors.ink : OrderColors.muted,
                              fontSize: 15.5,
                              fontWeight: FontWeight.w700,
                              height: 1.3,
                            ),
                          ),
                          const SizedBox(height: 3),
                          Text(k.desc, style: const TextStyle(color: OrderColors.muted, fontSize: 12.5, height: 1.45)),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
                child: Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    for (final v in k.levels)
                      _LevelChip(label: k.label(v), code: isTaxCode(v), off: !on, use: book.usage(k, v)),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              const Divider(height: 1, color: OrderColors.line),
              SizedBox(
                height: 46,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 14),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          on
                              ? '${k.levels.length}/${k.choices.length} mức đang hiện'
                              : 'Đang tắt — màn nghiệp vụ không bày ô thuế này',
                          style: const TextStyle(color: OrderColors.muted, fontSize: 12.5),
                        ),
                      ),
                      if (canEdit) ...[
                        const Text(
                          'Sửa',
                          style: TextStyle(color: OrderColors.primaryInk, fontSize: 14, fontWeight: FontWeight.w600),
                        ),
                        const SizedBox(width: 2),
                        const AppIcon(AppIcons.right, size: 16, color: OrderColors.primaryInk),
                      ],
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _LevelChip extends StatelessWidget {
  const _LevelChip({required this.label, required this.code, required this.off, this.use});

  final String label;
  final bool code;
  final bool off;
  final String? use;

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = off
        ? (OrderColors.grid, OrderColors.faint)
        : code
        ? (_codeBg, _codeFg)
        : (OrderColors.primarySoft, OrderColors.primaryInk);
    return Container(
      height: 30,
      padding: const EdgeInsets.symmetric(horizontal: 10),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(8)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            label,
            style: TextStyle(
              color: fg,
              fontSize: 14,
              fontWeight: FontWeight.w700,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
          if (use != null) ...[
            const SizedBox(width: 6),
            Text(
              use!,
              style: const TextStyle(color: _ok, fontSize: 11.5, fontWeight: FontWeight.w600),
            ),
          ],
        ],
      ),
    );
  }
}

class _Saved {
  const _Saved(this.levels, this.active, {required this.levelsChanged, required this.activeChanged});

  final List<int> levels;
  final bool active;
  final bool levelsChanged;
  final bool activeChanged;
}

// ---------- Hộp sửa mức — hộp #modalTax của PC ----------

class TaxForm extends ConsumerStatefulWidget {
  const TaxForm({super.key, required this.book, required this.kind});

  final TaxBook book;
  final TaxKind kind;

  @override
  ConsumerState<TaxForm> createState() => _TaxFormState();
}

class _TaxFormState extends ConsumerState<TaxForm> {
  late final TaxKind _k = widget.kind;
  late final Set<int> _sel = {..._k.levels};
  late bool _on = _k.active;
  bool _saving = false;
  String? _err;

  bool get _dirty => _on != _k.active || _sel.length != _k.levels.length || !_sel.containsAll(_k.levels);

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

  Future<void> _save() async {
    if (_sel.isEmpty) return;
    final levels = _k.ordered(_sel);
    final levelsChanged = levels.join(',') != _k.levels.join(',');
    final activeChanged = _on != _k.active;
    if (!levelsChanged && !activeChanged) return Navigator.of(context).pop();
    final repo = ref.read(taxRepoProvider);
    setState(() {
      _saving = true;
      _err = null;
    });
    try {
      // Hai đường API riêng: mức và trạng thái — chỉ gọi đường nào có đổi.
      if (levelsChanged) await repo.setLevels(_k.id, levels);
      if (activeChanged) await repo.setActive(_k.id, _on);
      repo.refresh();
      if (mounted) {
        Navigator.of(context).pop(_Saved(levels, _on, levelsChanged: levelsChanged, activeChanged: activeChanged));
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _err = e.fieldErrors['muc'] ?? e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    final k = _k;
    final b = widget.book;
    final all = _sel.length == k.choices.length;
    final hasCode = k.choices.any((c) => isTaxCode(c.$1));
    // Bỏ một mức đang có hàng khai: hàng cũ giữ nguyên mức (API không đổi vat của mặt hàng),
    // chỉ không chọn được cho hàng mới.
    final lost = [
      for (final v in k.levels)
        if (!_sel.contains(v) && b.usage(k, v) != null) v,
    ];
    return PopScope(
      canPop: !_dirty && !_saving,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop && !_saving) _leave();
      },
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
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 2),
              child: Text(
                k.name,
                style: const TextStyle(color: OrderColors.ink, fontSize: 18, fontWeight: FontWeight.w700),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
              child: Text(k.desc, style: const TextStyle(color: OrderColors.muted, fontSize: 13)),
            ),
            Flexible(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Material(
                      color: OrderColors.grid,
                      borderRadius: BorderRadius.circular(12),
                      child: InkWell(
                        borderRadius: BorderRadius.circular(12),
                        onTap: () => setState(() => _on = !_on),
                        child: Padding(
                          padding: const EdgeInsets.fromLTRB(14, 10, 8, 10),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Text(
                                      'Bật loại thuế này',
                                      style: TextStyle(
                                        color: OrderColors.ink,
                                        fontSize: 14.5,
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                    const SizedBox(height: 2),
                                    Text(
                                      _on
                                          ? 'Màn bán, mua, khai hàng có ô chọn thuế này'
                                          : 'Tắt: các màn nghiệp vụ thôi bày ô chọn thuế này',
                                      style: const TextStyle(color: OrderColors.muted, fontSize: 12),
                                    ),
                                  ],
                                ),
                              ),
                              Switch(
                                key: const Key('tax_f_on'),
                                value: _on,
                                activeTrackColor: OrderColors.primary,
                                onChanged: (v) => setState(() => _on = v),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),
                    Row(
                      children: [
                        Expanded(
                          child: Text.rich(
                            TextSpan(
                              text: 'Mức được hiện',
                              children: [
                                TextSpan(
                                  text: '  ${_sel.length}/${k.choices.length}',
                                  style: const TextStyle(
                                    color: OrderColors.muted,
                                    fontSize: 12.5,
                                    fontWeight: FontWeight.w500,
                                  ),
                                ),
                              ],
                            ),
                            key: const Key('tax_f_count'),
                            style: const TextStyle(color: OrderColors.ink, fontSize: 14.5, fontWeight: FontWeight.w700),
                          ),
                        ),
                        TextButton(
                          key: const Key('tax_f_all'),
                          onPressed: () => setState(() {
                            if (all) {
                              _sel.clear();
                            } else {
                              _sel.addAll(k.choices.map((c) => c.$1));
                            }
                          }),
                          style: TextButton.styleFrom(
                            foregroundColor: OrderColors.primaryInk,
                            minimumSize: const Size(0, 30),
                            padding: const EdgeInsets.symmetric(horizontal: 4),
                            textStyle: const TextStyle(
                              fontFamily: appFont,
                              fontSize: 13.5,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          child: Text(all ? 'Bỏ hết' : 'Chọn hết'),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    LayoutBuilder(
                      builder: (context, c) {
                        final w = (c.maxWidth - 16) / 3;
                        return Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            for (final (v, name) in k.choices)
                              SizedBox(
                                width: w,
                                child: _Pick(
                                  key: Key('tax_pick_$v'),
                                  label: name,
                                  code: isTaxCode(v),
                                  on: _sel.contains(v),
                                  use: b.usage(k, v),
                                  onTap: () => setState(() => _sel.contains(v) ? _sel.remove(v) : _sel.add(v)),
                                ),
                              ),
                          ],
                        );
                      },
                    ),
                    if (hasCode)
                      const Padding(
                        padding: EdgeInsets.only(top: 12),
                        child: Text.rich(
                          TextSpan(
                            children: [
                              TextSpan(
                                text: 'KCT',
                                style: TextStyle(fontWeight: FontWeight.w700),
                              ),
                              TextSpan(text: ' = không chịu thuế, '),
                              TextSpan(
                                text: 'KKKNT',
                                style: TextStyle(fontWeight: FontWeight.w700),
                              ),
                              TextSpan(
                                text: ' = không kê khai, tính nộp thuế — là mã trên hoá đơn điện tử, không phải phần trăm.',
                              ),
                            ],
                          ),
                          style: TextStyle(color: OrderColors.muted, fontSize: 12.5, height: 1.45),
                        ),
                      ),
                    if (lost.isNotEmpty) ...[
                      const SizedBox(height: 12),
                      KitWarn(
                        key: const Key('tax_f_warn'),
                        text:
                            'Bỏ ${lost.map((v) => '${k.label(v)} (đang khai ở ${b.usage(k, v, long: true)})').join(', ')}: '
                            'hàng cũ giữ nguyên mức này, chỉ không chọn được khi khai hàng mới.',
                      ),
                    ],
                    if (_sel.isEmpty)
                      const Padding(
                        padding: EdgeInsets.only(top: 10),
                        child: Text(
                          'Phải giữ lại ít nhất một mức thuế.',
                          key: Key('tax_f_empty'),
                          style: TextStyle(color: kOut, fontSize: 12.5),
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
                      key: const Key('tax_f_save'),
                      label: _saving ? 'Đang lưu…' : 'Lưu',
                      color: OrderColors.primary,
                      onTap: _saving || _sel.isEmpty ? null : _save,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Pick extends StatelessWidget {
  const _Pick({super.key, required this.label, required this.code, required this.on, required this.onTap, this.use});

  final String label;
  final bool code;
  final bool on;
  final String? use;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final line = on ? (code ? _codeLine : OrderColors.primary) : OrderColors.line;
    final bg = on ? (code ? _codeBg : OrderColors.primarySoft) : Colors.white;
    final fg = on ? (code ? _codeFg : OrderColors.primaryInk) : OrderColors.text;
    return Semantics(
      selected: on,
      button: true,
      child: Material(
        color: bg,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: line, width: 1.5),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: onTap,
          child: SizedBox(
            height: 58,
            child: Stack(
              children: [
                Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        label,
                        style: TextStyle(
                          color: fg,
                          fontSize: code ? 15 : 17,
                          fontWeight: FontWeight.w700,
                          fontFeatures: const [FontFeature.tabularFigures()],
                        ),
                      ),
                      if (use != null)
                        Text(
                          use!,
                          style: const TextStyle(color: _ok, fontSize: 11, fontWeight: FontWeight.w600),
                        ),
                    ],
                  ),
                ),
                if (on)
                  Positioned(
                    top: 5,
                    right: 5,
                    child: Container(
                      width: 16,
                      height: 16,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(color: code ? _codeLine : OrderColors.primary, shape: BoxShape.circle),
                      child: const AppIcon(AppIcons.check, size: 11, strokeWidth: 3, color: Colors.white),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
