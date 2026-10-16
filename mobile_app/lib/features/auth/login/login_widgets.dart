import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../app/theme.dart';
import '../../../core/ui/app_icon.dart';

/// Ô nhập có nhãn phía trên, icon đầu ô, nút xoá/hiện mật khẩu cuối ô.
class LoginField extends StatefulWidget {
  const LoginField({
    super.key,
    required this.label,
    required this.icon,
    required this.controller,
    required this.focusNode,
    required this.placeholder,
    required this.errorText,
    this.hasError = false,
    this.obscure = false,
    this.onToggleObscure,
    this.textInputAction = TextInputAction.next,
    this.onSubmitted,
    this.onChanged,
    this.inputFormatters,
    this.textCapitalization = TextCapitalization.none,
    this.autofillHints,
    this.readOnly = false,
    this.showCapsLock = false,
  });

  final String label;
  final AppIcons icon;
  final TextEditingController controller;
  final FocusNode focusNode;
  final String placeholder;
  final String errorText;
  final bool hasError;

  /// Ô mật khẩu: có [onToggleObscure] thì hiện nút mắt thay cho nút xoá.
  final bool obscure;
  final VoidCallback? onToggleObscure;
  final TextInputAction textInputAction;
  final ValueChanged<String>? onSubmitted;
  final ValueChanged<String>? onChanged;
  final List<TextInputFormatter>? inputFormatters;
  final TextCapitalization textCapitalization;
  final Iterable<String>? autofillHints;
  final bool readOnly;
  final bool showCapsLock;

  @override
  State<LoginField> createState() => _LoginFieldState();
}

class _LoginFieldState extends State<LoginField> {
  @override
  void initState() {
    super.initState();
    widget.focusNode.addListener(_redraw);
    widget.controller.addListener(_redraw);
  }

  @override
  void didUpdateWidget(LoginField old) {
    super.didUpdateWidget(old);
    if (old.focusNode != widget.focusNode) {
      old.focusNode.removeListener(_redraw);
      widget.focusNode.addListener(_redraw);
    }
    if (old.controller != widget.controller) {
      old.controller.removeListener(_redraw);
      widget.controller.addListener(_redraw);
    }
  }

  @override
  void dispose() {
    widget.focusNode.removeListener(_redraw);
    widget.controller.removeListener(_redraw);
    super.dispose();
  }

  void _redraw() => setState(() {});

  @override
  Widget build(BuildContext context) {
    final focused = widget.focusNode.hasFocus;
    final err = widget.hasError;
    final borderColor = err ? AppColors.danger : (focused ? AppColors.brand2 : AppColors.line);
    final iconColor = err ? AppColors.danger : (focused ? AppColors.brand2 : const Color(0xFFA19FAE));
    final isPassword = widget.onToggleObscure != null;

    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(bottom: 7),
            child: Text(
              widget.label,
              style: const TextStyle(color: AppColors.ink, fontSize: 13.5, fontWeight: FontWeight.w600),
            ),
          ),
          AnimatedContainer(
            duration: const Duration(milliseconds: 150),
            height: 52,
            decoration: BoxDecoration(
              color: err || focused ? Colors.white : AppColors.field,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: borderColor, width: 1.5),
              boxShadow: focused
                  ? [BoxShadow(color: err ? const Color(0x29C62828) : AppColors.focus, spreadRadius: 4)]
                  : const [],
            ),
            child: Row(
              children: [
                SizedBox(
                  width: 46,
                  child: Center(child: AppIcon(widget.icon, color: iconColor)),
                ),
                Expanded(
                  child: TextField(
                    controller: widget.controller,
                    focusNode: widget.focusNode,
                    readOnly: widget.readOnly,
                    obscureText: widget.obscure,
                    enableSuggestions: !isPassword,
                    autocorrect: false,
                    textInputAction: widget.textInputAction,
                    textCapitalization: widget.textCapitalization,
                    inputFormatters: widget.inputFormatters,
                    autofillHints: widget.autofillHints,
                    onSubmitted: widget.onSubmitted,
                    onChanged: widget.onChanged,
                    cursorColor: AppColors.brand2,
                    style: const TextStyle(color: AppColors.ink, fontSize: 16),
                    // filled: false — theme đang tô nền ô, khung ngoài đã lo phần nền rồi.
                    decoration: InputDecoration(
                      isCollapsed: true,
                      filled: false,
                      border: InputBorder.none,
                      enabledBorder: InputBorder.none,
                      focusedBorder: InputBorder.none,
                      hintText: widget.placeholder,
                      hintStyle: const TextStyle(color: Color(0xFFAAA8B6), fontSize: 16),
                    ),
                  ),
                ),
                if (isPassword)
                  _BoxAction(
                    tooltip: widget.obscure ? 'Hiện mật khẩu' : 'Ẩn mật khẩu',
                    onTap: () {
                      widget.onToggleObscure!();
                      widget.focusNode.requestFocus();
                    },
                    child: AppIcon(widget.obscure ? AppIcons.eye : AppIcons.eyeOff, color: const Color(0xFF8F8D9C)),
                  )
                else if (widget.controller.text.isNotEmpty && !widget.readOnly)
                  _BoxAction(
                    tooltip: 'Xoá',
                    onTap: () {
                      widget.controller.clear();
                      widget.onChanged?.call('');
                      widget.focusNode.requestFocus();
                    },
                    child: const ClearMark(color: Color(0xFFB7B5C2)),
                  )
                else
                  const SizedBox(width: 12),
              ],
            ),
          ),
          if (err) _Hint(icon: AppIcons.alert, color: AppColors.danger, text: widget.errorText),
          if (widget.showCapsLock) const _Hint(icon: AppIcons.caps, color: AppColors.warn, text: 'Đang bật Caps Lock'),
        ],
      ),
    );
  }
}

class _BoxAction extends StatelessWidget {
  const _BoxAction({required this.tooltip, required this.onTap, required this.child});

  final String tooltip;
  final VoidCallback onTap;
  final Widget child;

  @override
  Widget build(BuildContext context) => Tooltip(
    message: tooltip,
    excludeFromSemantics: true,
    child: Semantics(
      button: true,
      label: tooltip,
      child: InkResponse(
        onTap: onTap,
        radius: 22,
        child: SizedBox(width: 48, height: 52, child: Center(child: child)),
      ),
    ),
  );
}

class _Hint extends StatelessWidget {
  const _Hint({required this.icon, required this.color, required this.text});

  final AppIcons icon;
  final Color color;
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(top: 6),
    child: Row(
      children: [
        AppIcon(icon, size: 15, color: color),
        const SizedBox(width: 6),
        Text(text, style: TextStyle(color: color, fontSize: 13)),
      ],
    ),
  );
}

/// Công tắc "Ghi nhớ đăng nhập" — 40×24, theo bản HTML chứ không dùng Switch của Material.
class LoginToggle extends StatelessWidget {
  const LoginToggle({super.key, required this.value, required this.onChanged, required this.label});

  final bool value;
  final ValueChanged<bool> onChanged;
  final String label;

  @override
  Widget build(BuildContext context) => MergeSemantics(
    child: Semantics(
      toggled: value,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: () => onChanged(!value),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 44),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              AnimatedContainer(
                duration: const Duration(milliseconds: 200),
                width: 40,
                height: 24,
                padding: const EdgeInsets.all(3),
                decoration: BoxDecoration(
                  color: value ? AppColors.brand2 : const Color(0xFFD4D2DC),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: AnimatedAlign(
                  duration: const Duration(milliseconds: 200),
                  curve: const Cubic(.2, .8, .2, 1),
                  alignment: value ? Alignment.centerRight : Alignment.centerLeft,
                  child: Container(
                    width: 18,
                    height: 18,
                    decoration: const BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                      boxShadow: [BoxShadow(color: Color(0x40000000), offset: Offset(0, 1), blurRadius: 3)],
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Flexible(
                child: Text(label, style: const TextStyle(color: AppColors.ink, fontSize: 14)),
              ),
            ],
          ),
        ),
      ),
    ),
  );
}

/// Nút chính: nền tím chuyển màu, ô vàng có mũi tên ở mép phải.
class SellioButton extends StatefulWidget {
  const SellioButton({super.key, required this.label, required this.onPressed, this.loading = false});

  final String label;
  final VoidCallback? onPressed;
  final bool loading;

  @override
  State<SellioButton> createState() => _SellioButtonState();
}

class _SellioButtonState extends State<SellioButton> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    final enabled = widget.onPressed != null && !widget.loading;
    final disabledLook = widget.onPressed == null || widget.loading;
    return Semantics(
      button: true,
      enabled: enabled,
      child: GestureDetector(
        onTapDown: enabled ? (_) => setState(() => _down = true) : null,
        onTapUp: enabled ? (_) => setState(() => _down = false) : null,
        onTapCancel: () => setState(() => _down = false),
        onTap: enabled ? widget.onPressed : null,
        child: AnimatedScale(
          scale: _down ? .985 : 1,
          duration: const Duration(milliseconds: 100),
          child: Container(
            height: 56,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16),
              color: disabledLook ? const Color(0xFF9D93AD) : null,
              gradient: disabledLook
                  ? null
                  : const LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: [AppColors.brand2, AppColors.brand],
                    ),
              boxShadow: disabledLook
                  ? const []
                  : const [
                      BoxShadow(color: Color(0x993A1266), offset: Offset(0, 12), blurRadius: 24, spreadRadius: -8),
                    ],
            ),
            child: Stack(
              alignment: Alignment.center,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    if (widget.loading) ...[
                      const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                          strokeWidth: 2.5,
                          color: Colors.white,
                          backgroundColor: Color(0x59FFFFFF),
                        ),
                      ),
                      const SizedBox(width: 10),
                    ],
                    Text(
                      widget.label,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 16,
                        fontWeight: FontWeight.w600,
                        letterSpacing: .16,
                        fontFeatures: [FontFeature.tabularFigures()],
                      ),
                    ),
                  ],
                ),
                if (!disabledLook)
                  Positioned(
                    right: 8,
                    top: 8,
                    child: Container(
                      width: 40,
                      height: 40,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(color: AppColors.accent, borderRadius: BorderRadius.circular(12)),
                      child: const AppIcon(AppIcons.arrow, color: AppColors.brandInk, strokeWidth: 2.4),
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

/// Thông báo đầu khung trắng. Đổi [shakeKey] để rung lại (mỗi lần sai một lần rung).
class LoginAlert extends StatefulWidget {
  const LoginAlert({
    super.key,
    required this.icon,
    required this.title,
    required this.body,
    required this.background,
    required this.foreground,
    this.shakeKey = 0,
  });

  final AppIcons icon;
  final String title;
  final Widget body;
  final Color background;
  final Color foreground;
  final int shakeKey;

  @override
  State<LoginAlert> createState() => _LoginAlertState();
}

class _LoginAlertState extends State<LoginAlert> with SingleTickerProviderStateMixin {
  late final _shake = AnimationController(vsync: this, duration: const Duration(milliseconds: 360));

  @override
  void initState() {
    super.initState();
    if (widget.shakeKey > 0) _rung();
  }

  @override
  void didUpdateWidget(LoginAlert old) {
    super.didUpdateWidget(old);
    if (widget.shakeKey != old.shakeKey) _rung();
  }

  void _rung() {
    if (WidgetsBinding.instance.platformDispatcher.accessibilityFeatures.disableAnimations) return;
    _shake.forward(from: 0);
  }

  @override
  void dispose() {
    _shake.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _shake,
      builder: (context, child) {
        // 20%/60% lệch trái, 40%/80% lệch phải — đúng nhịp keyframes "shake" của bản HTML.
        final dx = _shake.isAnimating ? -5 * math.sin(_shake.value * 2.5 * 2 * math.pi) : 0.0;
        return Transform.translate(offset: Offset(dx, 0), child: child);
      },
      child: Semantics(
        liveRegion: true,
        child: Container(
          margin: const EdgeInsets.only(bottom: 16),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          decoration: BoxDecoration(color: widget.background, borderRadius: BorderRadius.circular(14)),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              AppIcon(widget.icon, color: widget.foreground),
              const SizedBox(width: 10),
              Expanded(
                child: DefaultTextStyle.merge(
                  style: TextStyle(color: widget.foreground, fontSize: 13.5, height: 1.45),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(widget.title, style: const TextStyle(fontWeight: FontWeight.w600)),
                      widget.body,
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

/// Cửa hàng đã ghi nhớ — thẻ thay cho ô nhập mã.
class ShopChip extends StatelessWidget {
  const ShopChip({super.key, required this.code, required this.onChange});

  final String code;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    final viet = code.length <= 2 ? code : code.substring(0, 2);
    return Container(
      margin: const EdgeInsets.only(bottom: 18),
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(color: AppColors.brandSoft, borderRadius: BorderRadius.circular(16)),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(12),
              gradient: const LinearGradient(
                begin: Alignment(-.5, -1),
                end: Alignment(.5, 1),
                colors: [AppColors.brand2, AppColors.brand],
              ),
            ),
            child: Text(
              viet,
              style: const TextStyle(
                color: AppColors.accent,
                fontSize: 15,
                fontWeight: FontWeight.w700,
                letterSpacing: .3,
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Cửa hàng', style: TextStyle(color: AppColors.muted, fontSize: 12)),
                Text(
                  code,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: AppColors.ink,
                    fontSize: 16,
                    fontWeight: FontWeight.w600,
                    letterSpacing: .5,
                  ),
                ),
              ],
            ),
          ),
          Material(
            color: Colors.white,
            elevation: 0,
            shadowColor: const Color(0x14140028),
            borderRadius: BorderRadius.circular(12),
            child: InkWell(
              borderRadius: BorderRadius.circular(12),
              onTap: onChange,
              child: const SizedBox(
                height: 40,
                child: Padding(
                  padding: EdgeInsets.symmetric(horizontal: 14),
                  child: Center(
                    child: Text(
                      'Đổi',
                      semanticsLabel: 'Đổi cửa hàng',
                      style: TextStyle(color: AppColors.brand2, fontSize: 13.5, fontWeight: FontWeight.w600),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
