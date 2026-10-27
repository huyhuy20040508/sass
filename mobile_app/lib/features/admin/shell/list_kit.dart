import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../app/theme.dart';
import '../../../core/ui/app_icon.dart';

/// Khối giao diện dùng chung cho các màn danh mục mở từ "Thêm" (Nhóm hàng, Thuộc tính…):
/// nút, ngăn thao tác, ô nhập, thanh chọn nhiều — cùng một khuôn thiết kế mobile.

const kLow = Color(0xFFB45309), kLowBg = Color(0xFFFFF4E5), kOut = Color(0xFFB91C1C);

/// Thông báo nổi có nút (Hoàn tác, Mở…).
void showActionToast(BuildContext context, String text, {String? action, VoidCallback? onAction}) {
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

class KitIconBtn extends StatelessWidget {
  const KitIconBtn({super.key, required this.icon, required this.tooltip, required this.onTap, this.primary = false});

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

class KitEmpty extends StatelessWidget {
  const KitEmpty({
    super.key,
    required this.icon,
    required this.title,
    required this.text,
    this.action,
    this.onAction,
    this.actionKey,
  });

  final Key? actionKey;

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
            key: actionKey,
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

class KitSelBar extends StatelessWidget {
  const KitSelBar({
    super.key,
    required this.count,
    required this.onCancel,
    required this.onDelete,
    this.keyPrefix = 'kit',
  });

  /// Khoá cho test: `prefix_sel_count`, `prefix_sel_delete`.
  final String keyPrefix;

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
            key: Key('${keyPrefix}_sel_count'),
            style: const TextStyle(color: OrderColors.ink, fontSize: 14, fontWeight: FontWeight.w600),
          ),
        ),
        KitBtn(label: 'Huỷ', onTap: onCancel),
        const SizedBox(width: 10),
        KitBtn(key: Key('${keyPrefix}_sel_delete'), label: 'Xoá', icon: AppIcons.trash, color: kOut, onTap: onDelete),
      ],
    ),
  );
}

class KitBtn extends StatelessWidget {
  const KitBtn({super.key, required this.label, required this.onTap, this.icon, this.color});

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

class KitActionRow extends StatelessWidget {
  const KitActionRow({
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
        ? kOut
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

class KitWarn extends StatelessWidget {
  const KitWarn({super.key, required this.text, this.error = false});

  final String text;
  final bool error;

  @override
  Widget build(BuildContext context) {
    final fg = error ? const Color(0xFF7F1D1D) : const Color(0xFF7C4A03);
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: error ? const Color(0xFFFDECEC) : kLowBg,
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

class KitLabel extends StatelessWidget {
  const KitLabel(this.text, {super.key});

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

class KitField extends StatelessWidget {
  const KitField({
    super.key,
    required this.label,
    required this.child,
    this.required = false,
    this.counter,
    this.error,
    this.errorKey,
  });

  final Key? errorKey;

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
            Text.rich(
              TextSpan(
                text: label,
                children: [
                  if (required)
                    const TextSpan(
                      text: ' *',
                      style: TextStyle(color: kOut),
                    ),
                ],
              ),
              style: const TextStyle(color: OrderColors.ink, fontSize: 13.5, fontWeight: FontWeight.w600),
            ),
            const SizedBox(width: 8),
            // Chú thích phải (vd "để trống = giữ mã cũ") dài hơn ô hẹp thì co chữ lại chứ không tràn.
            if (counter != null)
              Expanded(
                child: Align(
                  alignment: Alignment.centerRight,
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(counter!, style: const TextStyle(color: OrderColors.faint, fontSize: 12)),
                  ),
                ),
              ),
          ],
        ),
      ),
      child,
      if (error != null)
        Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Text(
            error!,
            key: errorKey,
            style: const TextStyle(color: kOut, fontSize: 12.5),
          ),
        ),
    ],
  );
}

class KitInput extends StatelessWidget {
  const KitInput({
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
    this.upper = false,
    this.focusNode,
    this.onSubmitted,
  });

  final FocusNode? focusNode;
  final ValueChanged<String>? onSubmitted;

  /// Ô mã: gõ gì cũng thành chữ in hoa.
  final bool upper;

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
        focusNode: focusNode,
        onSubmitted: onSubmitted,
        enabled: enabled,
        autofocus: autofocus,
        onChanged: onChanged,
        textCapitalization: upper ? TextCapitalization.characters : TextCapitalization.none,
        inputFormatters: [
          if (maxLength != null) LengthLimitingTextInputFormatter(maxLength),
          if (upper) TextInputFormatter.withFunction((_, v) => v.copyWith(text: v.text.toUpperCase())),
        ],
        style: TextStyle(color: enabled ? OrderColors.ink : OrderColors.muted, fontSize: fontSize),
        decoration: InputDecoration(
          hintText: hint,
          hintStyle: TextStyle(color: OrderColors.faint, fontSize: fontSize),
          filled: true,
          fillColor: enabled ? Colors.white : OrderColors.grid,
          contentPadding: const EdgeInsets.symmetric(horizontal: 14),
          enabledBorder: border(bad ? kOut : OrderColors.line),
          disabledBorder: border(OrderColors.line),
          focusedBorder: border(bad ? kOut : OrderColors.primary),
        ),
      ),
    );
  }
}

class OffChip extends StatelessWidget {
  const OffChip({super.key, this.text = 'Ngừng dùng'});

  final String text;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
    decoration: BoxDecoration(color: kLowBg, borderRadius: BorderRadius.circular(5)),
    child: Text(
      text,
      style: const TextStyle(color: kLow, fontSize: 10.5, fontWeight: FontWeight.w700),
    ),
  );
}

/// Lọc theo trạng thái bật/tắt (PC gọi "Đang dùng" — đổi chữ cho khỏi lẫn với "dùng ở n sản phẩm").
enum ActiveFilter {
  all('Tất cả'),
  on('Đang bật'),
  off('Đã tắt');

  const ActiveFilter(this.label);

  final String label;

  bool has(bool active) => this == all || (this == on) == active;
}

class KitListHeader extends StatelessWidget {
  const KitListHeader({
    super.key,
    required this.title,
    required this.keyPrefix,
    required this.addTooltip,
    required this.searchHint,
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

  final String title;

  /// Khoá cho test: `prefix_select`, `prefix_search`, `prefix_st_all`…
  final String keyPrefix;
  final String addTooltip;
  final String searchHint;
  final bool selecting;
  final VoidCallback? onSelect;
  final VoidCallback? onAdd;
  final TextEditingController search;
  final ValueChanged<String> onSearch;
  final VoidCallback onClear;
  final ActiveFilter st;
  final Map<ActiveFilter, int> counts;
  final ValueChanged<ActiveFilter> onSt;

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
                  Expanded(
                    child: Text(
                      title,
                      style: const TextStyle(
                        color: OrderColors.ink,
                        fontSize: 21,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -.4,
                      ),
                    ),
                  ),
                  if (onSelect != null)
                    TextButton(
                      key: Key('${keyPrefix}_select'),
                      onPressed: onSelect,
                      style: TextButton.styleFrom(
                        foregroundColor: OrderColors.primaryInk,
                        minimumSize: const Size(0, 36),
                        padding: const EdgeInsets.symmetric(horizontal: 10),
                        textStyle: const TextStyle(fontFamily: appFont, fontSize: 14.5, fontWeight: FontWeight.w600),
                      ),
                      child: Text(selecting ? 'Xong' : 'Chọn'),
                    ),
                  if (onAdd != null) KitIconBtn(icon: AppIcons.plus, tooltip: addTooltip, onTap: onAdd!, primary: true),
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
                    key: Key('${keyPrefix}_search'),
                    controller: search,
                    onChanged: onSearch,
                    textInputAction: TextInputAction.search,
                    style: const TextStyle(color: OrderColors.ink, fontSize: 16),
                    decoration: InputDecoration(
                      isCollapsed: true,
                      filled: false,
                      border: InputBorder.none,
                      enabledBorder: InputBorder.none,
                      focusedBorder: InputBorder.none,
                      hintText: searchHint,
                      hintStyle: const TextStyle(color: OrderColors.faint, fontSize: 16),
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
                for (final s in ActiveFilter.values) ...[
                  if (s != ActiveFilter.all) const SizedBox(width: 8),
                  KitStChip(
                    key: Key('${keyPrefix}_st_${s.name}'),
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
class KitStChip extends StatelessWidget {
  const KitStChip({super.key, required this.label, required this.count, required this.on, required this.onTap});

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
