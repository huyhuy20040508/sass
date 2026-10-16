import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/theme.dart';
import '../../../core/ui/app_icon.dart';

const supportEmail = 'nhuy08052004@gmail.com';

Future<void> openSupportEmail() => launchUrl(Uri(scheme: 'mailto', path: supportEmail));

Future<void> showForgotPasswordSheet(BuildContext context) => showModalBottomSheet<void>(
  context: context,
  isScrollControlled: true,
  backgroundColor: Colors.white,
  barrierColor: const Color(0x80140628),
  shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
  builder: (_) => const ForgotPasswordSheet(),
);

/// Mật khẩu nhân viên do Super Admin của cửa hàng cấp lại — không có đường tự khôi phục.
class ForgotPasswordSheet extends StatelessWidget {
  const ForgotPasswordSheet({super.key});

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.viewPaddingOf(context).bottom;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 10, 20, bottom + 18),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 40,
              height: 5,
              margin: const EdgeInsets.only(bottom: 16),
              decoration: BoxDecoration(color: const Color(0xFFDCDAE3), borderRadius: BorderRadius.circular(3)),
            ),
          ),
          Semantics(
            header: true,
            child: const Text(
              'Quên mật khẩu?',
              style: TextStyle(color: AppColors.ink, fontSize: 19, fontWeight: FontWeight.w700),
            ),
          ),
          const SizedBox(height: 14),
          const _Tip(
            icon: AppIcons.shield,
            title: 'Mật khẩu',
            body: TextSpan(
              children: [
                TextSpan(text: 'Vì lý do bảo mật, mật khẩu tài khoản quản trị được cấp lại thủ công. Liên hệ '),
                TextSpan(
                  text: 'quản trị viên (Super Admin)',
                  style: TextStyle(fontWeight: FontWeight.w700),
                ),
                TextSpan(text: ' của cửa hàng.'),
              ],
            ),
          ),
          const _Tip(
            icon: AppIcons.store,
            title: 'Mã cửa hàng',
            body: TextSpan(
              text: 'Là mã được cấp khi bàn giao phần mềm — hỏi lại quản lý cửa hàng, hoặc liên hệ hỗ trợ.',
            ),
            divider: true,
          ),
          _SheetButton(primary: true, icon: AppIcons.mail, label: 'Gửi email hỗ trợ', onTap: openSupportEmail),
          _SheetButton(label: 'Đóng', onTap: () => Navigator.of(context).pop()),
        ],
      ),
    );
  }
}

class _Tip extends StatelessWidget {
  const _Tip({required this.icon, required this.title, required this.body, this.divider = false});

  final AppIcons icon;
  final String title;
  final InlineSpan body;
  final bool divider;

  @override
  Widget build(BuildContext context) => Container(
    padding: EdgeInsets.only(top: divider ? 12 : 0, bottom: 12),
    decoration: divider
        ? const BoxDecoration(
            border: Border(top: BorderSide(color: Color(0xFFF0EEF4))),
          )
        : null,
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 38,
          height: 38,
          alignment: Alignment.center,
          decoration: BoxDecoration(color: AppColors.brandSoft, borderRadius: BorderRadius.circular(12)),
          child: AppIcon(icon, color: AppColors.brand2),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Text.rich(
            TextSpan(
              children: [
                TextSpan(
                  text: '$title\n',
                  style: const TextStyle(color: AppColors.ink, fontWeight: FontWeight.w600),
                ),
                body,
              ],
            ),
            style: const TextStyle(color: AppColors.text, fontSize: 14, height: 1.5),
          ),
        ),
      ],
    ),
  );
}

class _SheetButton extends StatelessWidget {
  const _SheetButton({required this.label, required this.onTap, this.icon, this.primary = false});

  final String label;
  final VoidCallback onTap;
  final AppIcons? icon;
  final bool primary;

  @override
  Widget build(BuildContext context) {
    final fg = primary ? AppColors.brand : AppColors.ink;
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Material(
        color: primary ? AppColors.brandSoft : Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: primary ? BorderSide.none : const BorderSide(color: AppColors.line, width: 1.5),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: SizedBox(
            height: 50,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (icon != null) ...[AppIcon(icon!, color: fg), const SizedBox(width: 8)],
                Text(
                  label,
                  style: TextStyle(color: fg, fontSize: 15, fontWeight: FontWeight.w600),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
