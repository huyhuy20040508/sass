import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/ui/app_icon.dart';
import '../admin/goods/goods_widgets.dart' show GoodsBtn;
import '../admin/shell/more_menu.dart' show MoreHost;
import '../admin/shell/shell_widgets.dart' show showDashToast;
import '../auth/auth_controller.dart';
import 'print_service.dart';
import 'printer_config.dart';
import 'printer_link.dart';

/// Mở từ menu Thêm → Cài đặt → Cài đặt thiết bị.
Widget printerSettingsView(MoreHost host) => PrinterSettingsScreen(host: host);

/// Cài đặt thiết bị → Máy in của điện thoại: chọn máy in Bluetooth đã ghép đôi
/// hoặc máy in mạng (IP), khổ giấy, dao cắt; in thử trước khi lưu.
///
/// Là VIEW nằm trong khung app như Nhóm hàng hoá / Đơn vị tính / Thuế: thanh
/// dưới vẫn hiện, tab Thêm vẫn sáng. Mở chồng bằng Navigator.push thì màn đứng
/// một mình, mất thanh dưới — khác hẳn bốn màn cùng menu.
class PrinterSettingsScreen extends ConsumerStatefulWidget {
  const PrinterSettingsScreen({super.key, this.host});

  /// Có host = đang nằm trong khung app (mở từ menu Thêm). Null = mở chồng từ
  /// hộp In phiếu ("Đổi máy in") — lúc ấy lùi bằng Navigator như mọi màn chồng.
  final MoreHost? host;

  @override
  ConsumerState<PrinterSettingsScreen> createState() => _PrinterSettingsScreenState();
}

class _PrinterSettingsScreenState extends ConsumerState<PrinterSettingsScreen> {
  PrinterKind _kind = PrinterKind.bluetooth;
  PairedPrinter? _bt;
  List<PairedPrinter>? _paired;
  String? _btLoi;
  final _ip = TextEditingController();
  final _port = TextEditingController(text: '9100');
  int _paper = 80;
  bool _cut = true;
  bool _busy = false;
  bool _napDau = false;

  @override
  void dispose() {
    _ip.dispose();
    _port.dispose();
    super.dispose();
  }

  void _nap(PrinterConfig? c) {
    if (_napDau) return;
    _napDau = true;
    if (c == null) return;
    _kind = c.kind;
    _paper = c.paper;
    _cut = c.cut;
    if (c.kind == PrinterKind.lan) {
      _ip.text = c.address;
      _port.text = '${c.port}';
    } else {
      _bt = PairedPrinter(c.name, c.address);
    }
  }

  Future<void> _timBt() async {
    setState(() => _btLoi = null);
    try {
      final ds = await ref.read(printerLinkProvider).paired();
      if (mounted) setState(() => _paired = ds);
    } on PrinterException catch (e) {
      if (mounted) setState(() => _btLoi = e.message);
    }
  }

  /// Cấu hình đang nhập — null kèm câu báo khi còn thiếu.
  (PrinterConfig?, String) _dang() {
    if (_kind == PrinterKind.bluetooth) {
      if (_bt == null) return (null, 'Chọn một máy in Bluetooth trong danh sách.');
      return (PrinterConfig(kind: _kind, address: _bt!.address, name: _bt!.name, paper: _paper, cut: _cut), '');
    }
    final ip = _ip.text.trim();
    if (!RegExp(r'^\d{1,3}(\.\d{1,3}){3}$').hasMatch(ip)) {
      return (null, 'Nhập địa chỉ IP của máy in, ví dụ 192.168.1.100.');
    }
    final port = int.tryParse(_port.text.trim()) ?? 0;
    if (port <= 0 || port > 65535) return (null, 'Cổng không hợp lệ — máy in mạng thường dùng 9100.');
    return (PrinterConfig(kind: _kind, address: ip, port: port, paper: _paper, cut: _cut), '');
  }

  Future<void> _inThu() async {
    final (c, loi) = _dang();
    if (c == null) return showDashToast(context, loi);
    setState(() => _busy = true);
    try {
      final svc = ref.read(slipServiceProvider);
      final slip = await svc.testSlip(c, tenantName: ref.read(authProvider).value?.tenant.name ?? '');
      await svc.send(c, slip);
      if (mounted) showDashToast(context, 'Đã gửi phiếu in thử tới ${c.label}');
    } on PrinterException catch (e) {
      if (mounted) showDashToast(context, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _luu() async {
    final (c, loi) = _dang();
    if (c == null) return showDashToast(context, loi);
    await ref.read(printerConfigProvider.notifier).save(c);
    if (mounted) {
      showDashToast(context, 'Đã lưu máy in ${c.label}');
      Navigator.of(context).maybePop(true);
    }
  }

  Future<void> _go() async {
    await ref.read(printerConfigProvider.notifier).save(null);
    if (mounted) {
      setState(() {
        _bt = null;
        _ip.clear();
      });
      showDashToast(context, 'Đã gỡ máy in khỏi điện thoại này');
    }
  }

  @override
  Widget build(BuildContext context) {
    final cfg = ref.watch(printerConfigProvider);
    if (cfg.hasValue) _nap(cfg.value);
    final top = MediaQuery.paddingOf(context).top;
    final host = widget.host;
    // Trong khung app thì thanh tab đã choán mép dưới; mở chồng thì tự chừa.
    final duoi = host == null ? MediaQuery.viewPaddingOf(context).bottom : 0.0;
    final thanhNut = Container(
      padding: EdgeInsets.fromLTRB(12, 10, 12, duoi + 10),
      decoration: const BoxDecoration(
        color: Colors.white,
        boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, -1))],
      ),
      child: Row(
        children: [
          Expanded(
            child: GoodsBtn(
              key: const Key('printer_test'),
              label: _busy ? 'Đang in thử…' : 'In thử',
              icon: AppIcons.print,
              onTap: _busy ? () {} : _inThu,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: GoodsBtn(
              key: const Key('printer_save'),
              label: 'Lưu máy in',
              icon: AppIcons.check,
              primary: true,
              onTap: _luu,
            ),
          ),
        ],
      ),
    );

    return PopScope(
      canPop: host == null,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) host?.close();
      },
      // Scaffold LỒNG (không phải ColoredBox) dù màn nằm trong khung app: thông
      // báo nổi bám theo Scaffold gần nhất, không có nó thì câu báo đậu đè lên
      // hai nút In thử / Lưu máy in và nuốt luôn lượt chạm.
      child: Scaffold(
        backgroundColor: OrderColors.page,
        bottomNavigationBar: thanhNut,
        body: Column(
          children: [
            Container(
              padding: EdgeInsets.fromLTRB(8, top + 4, 8, 8),
              decoration: const BoxDecoration(
                color: Colors.white,
                boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, 1))],
              ),
              child: Row(
                children: [
                  IconButton(
                    tooltip: 'Quay lại',
                    onPressed: () => host == null ? Navigator.of(context).maybePop() : host.close(),
                    icon: const AppIcon(AppIcons.back, size: 22, color: OrderColors.ink),
                  ),
                  const Expanded(
                    child: Text(
                      'Máy in hoá đơn',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: OrderColors.ink, fontSize: 16.5, fontWeight: FontWeight.w700),
                    ),
                  ),
                  const SizedBox(width: 48),
                ],
              ),
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(12, 12, 12, 16),
                children: [
                  if (cfg.value case final c?) _dangDung(c),
                  _the('Kết nối', [
                    _chon<PrinterKind>(
                      const [
                        (PrinterKind.bluetooth, 'Bluetooth', AppIcons.bluetooth),
                        (PrinterKind.lan, 'Wi-Fi / LAN', AppIcons.wifi),
                      ],
                      _kind,
                      (k) => setState(() => _kind = k),
                      keyOf: (k) => 'printer_kind_${k.name}',
                    ),
                    const SizedBox(height: 12),
                    if (_kind == PrinterKind.bluetooth) ..._phanBt() else ..._phanLan(),
                  ]),
                  _the('Khổ giấy', [
                    _chon<int>(
                      const [(80, '80mm', null), (58, '58mm', null)],
                      _paper,
                      (v) => setState(() => _paper = v),
                      keyOf: (v) => 'printer_paper_$v',
                    ),
                    const SizedBox(height: 6),
                    const Text(
                      'Chọn đúng cuộn giấy đang lắp — sai khổ thì chữ tràn mép hoặc phiếu hẹp một nửa.',
                      style: TextStyle(color: OrderColors.muted, fontSize: 12.5),
                    ),
                  ]),
                  _the('Sau khi in', [
                    SwitchListTile(
                      key: const Key('printer_cut'),
                      contentPadding: EdgeInsets.zero,
                      value: _cut,
                      activeThumbColor: OrderColors.primary,
                      onChanged: (v) => setState(() => _cut = v),
                      title: const Text(
                        'Tự cắt giấy',
                        style: TextStyle(color: OrderColors.ink, fontSize: 14.5, fontWeight: FontWeight.w600),
                      ),
                      subtitle: const Text(
                        'Tắt nếu máy in không có dao cắt.',
                        style: TextStyle(color: OrderColors.muted, fontSize: 12.5),
                      ),
                    ),
                  ]),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _dangDung(PrinterConfig c) => Container(
    margin: const EdgeInsets.only(bottom: 12),
    padding: const EdgeInsets.fromLTRB(14, 12, 8, 12),
    decoration: BoxDecoration(color: OrderColors.green.$1, borderRadius: BorderRadius.circular(14)),
    child: Row(
      children: [
        AppIcon(c.kind == PrinterKind.lan ? AppIcons.wifi : AppIcons.bluetooth, size: 22, color: OrderColors.green.$2),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Đang dùng: ${c.label}',
                style: TextStyle(color: OrderColors.green.$2, fontSize: 14.5, fontWeight: FontWeight.w700),
              ),
              Text(
                '${c.kind == PrinterKind.lan ? 'Wi-Fi / LAN' : 'Bluetooth'} · khổ ${c.paper}mm${c.cut ? ' · tự cắt' : ''}',
                style: const TextStyle(color: OrderColors.text, fontSize: 12.5),
              ),
            ],
          ),
        ),
        TextButton(
          key: const Key('printer_remove'),
          onPressed: _go,
          child: const Text('Gỡ', style: TextStyle(color: OrderColors.muted)),
        ),
      ],
    ),
  );

  List<Widget> _phanBt() => [
    const Text(
      'Ghép đôi máy in trong Cài đặt → Bluetooth của điện thoại trước (mã PIN thường là 0000 hoặc 1234), rồi chọn ở đây.',
      style: TextStyle(color: OrderColors.muted, fontSize: 12.5),
    ),
    const SizedBox(height: 10),
    GoodsBtn(
      key: const Key('printer_bt_scan'),
      label: _paired == null ? 'Xem máy in đã ghép đôi' : 'Tải lại danh sách',
      icon: AppIcons.refresh,
      onTap: _timBt,
    ),
    if (_btLoi != null)
      Padding(
        padding: const EdgeInsets.only(top: 10),
        child: Text(_btLoi!, style: TextStyle(color: OrderColors.red.$2, fontSize: 13)),
      ),
    if (_paired case final ds?) ...[
      const SizedBox(height: 8),
      if (ds.isEmpty)
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 8),
          child: Text(
            'Điện thoại chưa ghép đôi máy Bluetooth nào.',
            style: TextStyle(color: OrderColors.text, fontSize: 13.5),
          ),
        ),
      for (final p in ds)
        _dongChon(
          key: Key('printer_bt_${p.address}'),
          chon: _bt?.address == p.address,
          title: p.name.isEmpty ? 'Không tên' : p.name,
          sub: p.address,
          onTap: () => setState(() => _bt = p),
        ),
    ] else if (_bt case final b?)
      _dongChon(
        key: const Key('printer_bt_saved'),
        chon: true,
        title: b.name.isEmpty ? 'Máy đã lưu' : b.name,
        sub: b.address,
        onTap: () {},
      ),
  ];

  List<Widget> _phanLan() => [
    Row(
      children: [
        Expanded(flex: 3, child: _o(_ip, 'Địa chỉ IP', '192.168.1.100', const Key('printer_ip'))),
        const SizedBox(width: 10),
        Expanded(flex: 2, child: _o(_port, 'Cổng', '9100', const Key('printer_port'))),
      ],
    ),
    const SizedBox(height: 8),
    const Text(
      'Điện thoại và máy in phải cùng mạng Wi-Fi. Chưa biết IP: tắt máy in, giữ nút FEED rồi bật lại — tờ tự kiểm in ra có dòng IP Address.',
      style: TextStyle(color: OrderColors.muted, fontSize: 12.5),
    ),
  ];

  Widget _o(TextEditingController c, String nhan, String goiY, Key key) => TextField(
    key: key,
    controller: c,
    keyboardType: const TextInputType.numberWithOptions(decimal: true),
    decoration: InputDecoration(
      labelText: nhan,
      hintText: goiY,
      isDense: true,
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
    ),
  );

  // Nền trắng là Material (không phải Container tô màu): công tắc, dòng chọn bên trong vẽ hiệu ứng chạm lên đó.
  Widget _the(String tieuDe, List<Widget> con) => Container(
    margin: const EdgeInsets.only(bottom: 12),
    decoration: BoxDecoration(borderRadius: BorderRadius.circular(14), boxShadow: OrderColors.cardShadow),
    child: Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(14),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              tieuDe,
              style: const TextStyle(color: OrderColors.ink, fontSize: 14.5, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 10),
            ...con,
          ],
        ),
      ),
    ),
  );

  Widget _chon<T>(List<(T, String, AppIcons?)> ds, T dang, ValueChanged<T> doi, {required String Function(T) keyOf}) =>
      Row(
        children: [
          for (final (i, (v, nhan, icon)) in ds.indexed) ...[
            if (i > 0) const SizedBox(width: 8),
            Expanded(
              child: Material(
                color: v == dang ? OrderColors.primarySoft : Colors.white,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(10),
                  side: BorderSide(
                    color: v == dang ? OrderColors.primary : OrderColors.line,
                    width: v == dang ? 1.5 : 1,
                  ),
                ),
                child: InkWell(
                  key: Key(keyOf(v)),
                  borderRadius: BorderRadius.circular(10),
                  onTap: () => doi(v),
                  child: SizedBox(
                    height: 44,
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        if (icon != null) ...[
                          AppIcon(icon, size: 18, color: v == dang ? OrderColors.primaryInk : OrderColors.text),
                          const SizedBox(width: 6),
                        ],
                        Flexible(
                          child: Text(
                            nhan,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              color: v == dang ? OrderColors.primaryInk : OrderColors.text,
                              fontSize: 14,
                              fontWeight: v == dang ? FontWeight.w700 : FontWeight.w500,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ],
        ],
      );

  Widget _dongChon({
    required Key key,
    required bool chon,
    required String title,
    required String sub,
    required VoidCallback onTap,
  }) => InkWell(
    key: key,
    onTap: onTap,
    borderRadius: BorderRadius.circular(10),
    child: Padding(
      padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 4),
      child: Row(
        children: [
          Icon(
            chon ? Icons.radio_button_checked : Icons.radio_button_off,
            color: chon ? OrderColors.primary : OrderColors.faint,
            size: 22,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(color: OrderColors.ink, fontSize: 14.5, fontWeight: FontWeight.w600),
                ),
                Text(sub, style: const TextStyle(color: OrderColors.muted, fontSize: 12)),
              ],
            ),
          ),
        ],
      ),
    ),
  );
}
