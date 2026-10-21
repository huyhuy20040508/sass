import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_exception.dart';
import '../../core/ui/app_icon.dart';
import '../admin/goods/goods_widgets.dart' show GoodsBtn;
import '../admin/shell/shell_widgets.dart' show showDashToast;
import '../auth/auth_controller.dart';
import 'print_service.dart';
import 'printer_config.dart';
import 'printer_link.dart';
import 'printer_settings_screen.dart';

/// Bấm "In hoá đơn" / "In phiếu trả" trên điện thoại: chưa cài máy in thì mở màn
/// Máy in trước; có rồi thì hiện đúng tờ sẽ in để xem, chọn số bản, in.
/// Truyền [order] (chi tiết đơn đã tải) hoặc [returnId].
Future<void> openPrintSheet(
  BuildContext context,
  WidgetRef ref, {
  required String code,
  Map<String, dynamic>? order,
  int? returnId,
}) async {
  var c = await ref.read(printerConfigProvider.future);
  if (c == null) {
    if (!context.mounted) return;
    showDashToast(context, 'Chọn máy in cho điện thoại này trước');
    await Navigator.of(context).push(MaterialPageRoute<bool>(builder: (_) => const PrinterSettingsScreen()));
    c = await ref.read(printerConfigProvider.future);
    if (c == null || !context.mounted) return;
  }
  if (!context.mounted) return;
  await showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.white,
    shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
    builder: (_) => _PrintSheet(code: code, order: order, returnId: returnId),
  );
}

class _PrintSheet extends ConsumerStatefulWidget {
  const _PrintSheet({required this.code, this.order, this.returnId});

  final String code;
  final Map<String, dynamic>? order;
  final int? returnId;

  @override
  ConsumerState<_PrintSheet> createState() => _PrintSheetState();
}

class _PrintSheetState extends ConsumerState<_PrintSheet> {
  Future<Slip>? _slip;
  int? _dots;

  /// Luôn 1 bản: mỗi lần bấm in ra đúng một tờ, muốn thêm thì bấm "+" ngay lúc in.
  int _copies = 1;
  bool _busy = false;

  Future<Slip> _dung(PrinterConfig c) {
    final svc = ref.read(slipServiceProvider);
    final tenant = ref.read(authProvider).value?.tenant.name ?? '';
    return widget.order != null
        ? svc.receipt(widget.order!, dots: c.dots, tenantName: tenant)
        : svc.returnSlip(widget.returnId!, dots: c.dots, tenantName: tenant);
  }

  Future<void> _in(PrinterConfig c, Slip s) async {
    setState(() => _busy = true);
    try {
      await ref.read(slipServiceProvider).send(c, s, copies: _copies);
      if (!mounted) return;
      final nav = Navigator.of(context);
      showDashToast(context, 'Đã gửi ${_copies > 1 ? '$_copies bản ' : ''}phiếu ${widget.code} ra ${c.label}');
      nav.pop();
    } on PrinterException catch (e) {
      if (mounted) showDashToast(context, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = ref.watch(printerConfigProvider).value;
    if (c == null) {
      return const SizedBox(
        height: 120,
        child: Center(child: CircularProgressIndicator(color: OrderColors.primary)),
      );
    }
    // Đổi khổ ở màn Máy in thì dựng lại phiếu theo bề ngang mới.
    if (_slip == null || _dots != c.dots) {
      _dots = c.dots;
      _slip = _dung(c);
    }
    final h = MediaQuery.sizeOf(context).height;
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 8, 16, MediaQuery.viewPaddingOf(context).bottom + 12),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 40,
              height: 4,
              margin: const EdgeInsets.only(bottom: 10),
              decoration: BoxDecoration(color: OrderColors.line, borderRadius: BorderRadius.circular(2)),
            ),
          ),
          Text(
            widget.order != null ? 'In hoá đơn ${widget.code}' : 'In phiếu trả ${widget.code}',
            style: const TextStyle(color: OrderColors.ink, fontSize: 16.5, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 4),
          Row(
            children: [
              AppIcon(
                c.kind == PrinterKind.lan ? AppIcons.wifi : AppIcons.bluetooth,
                size: 16,
                color: OrderColors.muted,
              ),
              const SizedBox(width: 4),
              Expanded(
                child: Text(
                  '${c.label} · khổ ${c.paper}mm',
                  style: const TextStyle(color: OrderColors.muted, fontSize: 13),
                ),
              ),
              TextButton(
                key: const Key('print_change_printer'),
                onPressed: () =>
                    Navigator.of(context).push(MaterialPageRoute<bool>(builder: (_) => const PrinterSettingsScreen())),
                child: const Text('Đổi máy in'),
              ),
            ],
          ),
          const SizedBox(height: 8),
          ConstrainedBox(
            constraints: BoxConstraints(maxHeight: h * .5),
            child: Container(
              decoration: BoxDecoration(color: OrderColors.page, borderRadius: BorderRadius.circular(12)),
              padding: const EdgeInsets.all(12),
              child: FutureBuilder<Slip>(
                future: _slip,
                builder: (_, snap) {
                  if (snap.hasError) {
                    final e = snap.error;
                    return Center(
                      child: Text(
                        e is ApiException ? e.message : 'Không dựng được phiếu: $e',
                        textAlign: TextAlign.center,
                        style: TextStyle(color: OrderColors.red.$2),
                      ),
                    );
                  }
                  if (!snap.hasData) return const Center(child: CircularProgressIndicator(color: OrderColors.primary));
                  final img = snap.data!.preview;
                  return SingleChildScrollView(
                    child: Center(
                      child: Container(
                        decoration: const BoxDecoration(
                          boxShadow: [BoxShadow(color: Color(0x22000000), blurRadius: 6, offset: Offset(0, 2))],
                        ),
                        child: RawImage(key: const Key('print_preview'), image: img, width: 260, fit: BoxFit.fitWidth),
                      ),
                    ),
                  );
                },
              ),
            ),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              const Expanded(
                child: Text(
                  'Số bản',
                  style: TextStyle(color: OrderColors.ink, fontSize: 14.5, fontWeight: FontWeight.w600),
                ),
              ),
              IconButton(
                key: const Key('print_copies_minus'),
                onPressed: _copies > 1 ? () => setState(() => _copies--) : null,
                icon: const AppIcon(AppIcons.minus, size: 20, color: OrderColors.ink),
              ),
              SizedBox(
                width: 28,
                child: Text(
                  '$_copies',
                  key: const Key('print_copies'),
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                ),
              ),
              IconButton(
                key: const Key('print_copies_plus'),
                onPressed: _copies < 9 ? () => setState(() => _copies++) : null,
                icon: const AppIcon(AppIcons.plus, size: 20, color: OrderColors.ink),
              ),
            ],
          ),
          const SizedBox(height: 8),
          FutureBuilder<Slip>(
            future: _slip,
            builder: (_, snap) => GoodsBtn(
              key: const Key('print_go'),
              label: _busy ? 'Đang gửi ra máy in…' : (snap.hasData ? 'In $_copies bản' : 'Đang dựng phiếu…'),
              icon: AppIcons.print,
              primary: true,
              onTap: !snap.hasData || _busy ? () {} : () => _in(c, snap.data!),
            ),
          ),
        ],
      ),
    );
  }
}
