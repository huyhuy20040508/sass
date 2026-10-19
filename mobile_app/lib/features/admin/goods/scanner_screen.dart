import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../../app/theme.dart';
import '../../../core/ui/app_icon.dart';

/// Có dùng camera thật không — test widget tắt đi (không có kênh nền tảng của camera).
final scannerCameraProvider = Provider<bool>((_) => true);

/// Màn quét mã vạch toàn màn hình: camera + khung ngắm, đèn flash, nhập mã tay.
/// [onCode] tra mã; trả true là đã tìm thấy → màn tự đóng, false → báo rồi quét tiếp.
class ScannerScreen extends ConsumerStatefulWidget {
  const ScannerScreen({super.key, required this.onCode});

  final Future<bool> Function(String code) onCode;

  @override
  ConsumerState<ScannerScreen> createState() => _ScannerScreenState();
}

class _ScannerScreenState extends ConsumerState<ScannerScreen> with SingleTickerProviderStateMixin {
  late final _laser = AnimationController(vsync: this, duration: const Duration(milliseconds: 900));
  final _manual = TextEditingController();
  MobileScannerController? _cam;
  bool _torch = false;
  bool _busy = false;
  bool _flash = false;
  String? _last;
  DateTime _lastAt = DateTime(2000);

  /// Báo "không tìm thấy" ngay trong khung camera — SnackBar nổi sẽ che ô nhập mã tay.
  String? _miss;
  Timer? _missT;

  @override
  void initState() {
    super.initState();
    if (ref.read(scannerCameraProvider)) {
      _cam = MobileScannerController(detectionSpeed: DetectionSpeed.noDuplicates);
    }
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (!MediaQuery.disableAnimationsOf(context) && !_laser.isAnimating) _laser.repeat(reverse: true);
  }

  @override
  void dispose() {
    _laser.dispose();
    _missT?.cancel();
    _manual.dispose();
    _cam?.dispose();
    super.dispose();
  }

  Future<void> _handle(String raw) async {
    final code = raw.trim();
    // Camera đọc cùng một mã nhiều lần mỗi giây — bỏ lượt lặp trong 2 giây.
    final now = DateTime.now();
    if (code.isEmpty || _busy || (code == _last && now.difference(_lastAt).inSeconds < 2)) return;
    _last = code;
    _lastAt = now;
    setState(() {
      _busy = true;
      _flash = true;
    });
    HapticFeedback.mediumImpact();
    Future.delayed(const Duration(milliseconds: 180), () {
      if (mounted) setState(() => _flash = false);
    });
    final found = await widget.onCode(code);
    if (!mounted) return;
    setState(() => _busy = false);
    if (found) {
      Navigator.of(context).pop();
      return;
    }
    setState(() => _miss = 'Không tìm thấy sản phẩm có mã “$code”');
    _missT?.cancel();
    _missT = Timer(const Duration(milliseconds: 2600), () {
      if (mounted) setState(() => _miss = null);
    });
  }

  Future<void> _toggleTorch() async {
    await _cam?.toggleTorch();
    if (mounted) setState(() => _torch = !_torch);
  }

  @override
  Widget build(BuildContext context) {
    final pad = MediaQuery.paddingOf(context);
    Widget roundBtn(AppIcons i, String tip, VoidCallback onTap, {bool on = false, Key? key}) => Tooltip(
      message: tip,
      child: Material(
        key: key,
        color: on ? const Color(0xFFFFC20F) : const Color(0x24FFFFFF),
        shape: const CircleBorder(),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: onTap,
          child: SizedBox(
            width: 42,
            height: 42,
            child: Center(child: AppIcon(i, size: 21, color: on ? const Color(0xFF111111) : Colors.white)),
          ),
        ),
      ),
    );

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        backgroundColor: const Color(0xFF05070C),
        resizeToAvoidBottomInset: true,
        body: Column(
          children: [
            Padding(
              padding: EdgeInsets.fromLTRB(10, pad.top + 6, 10, 6),
              child: Row(
                children: [
                  roundBtn(AppIcons.close, 'Đóng', () => Navigator.of(context).pop(), key: const Key('scan_close')),
                  const Expanded(
                    child: Text(
                      'Quét mã vạch',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w700),
                    ),
                  ),
                  roundBtn(AppIcons.bolt, 'Đèn flash', _toggleTorch, on: _torch),
                ],
              ),
            ),
            Expanded(
              child: LayoutBuilder(
                builder: (context, c) {
                  final w = c.maxWidth * .74, h = w / 1.6;
                  final box = Rect.fromCenter(center: Offset(c.maxWidth / 2, c.maxHeight * .45), width: w, height: h);
                  return Stack(
                    children: [
                      Positioned.fill(
                        child: _cam == null
                            ? const DecoratedBox(
                                decoration: BoxDecoration(
                                  gradient: RadialGradient(
                                    center: Alignment(0, -.1),
                                    radius: .8,
                                    colors: [Color(0xFF1B2333), Color(0xFF05070C)],
                                  ),
                                ),
                              )
                            : MobileScanner(
                                controller: _cam,
                                // Chỉ đọc mã nằm trong khung ngắm, không bắt nhầm mã khác trên kệ.
                                scanWindow: box,
                                onDetect: (cap) {
                                  final v = cap.barcodes.map((b) => b.rawValue).whereType<String>().firstOrNull;
                                  if (v != null) _handle(v);
                                },
                                errorBuilder: (_, e) => _CameraError(e),
                              ),
                      ),
                      Positioned.fill(
                        child: IgnorePointer(child: CustomPaint(painter: _Frame(box))),
                      ),
                      AnimatedBuilder(
                        animation: _laser,
                        builder: (_, _) => Positioned(
                          left: box.left + w * .08,
                          width: w * .84,
                          top: box.center.dy - 36 + 72 * Curves.easeInOut.transform(_laser.value),
                          height: 2,
                          child: Container(
                            decoration: const BoxDecoration(
                              color: Color(0xFFFF4D4F),
                              boxShadow: [BoxShadow(color: Color(0xB3FF4D4F), blurRadius: 12, spreadRadius: 2)],
                            ),
                          ),
                        ),
                      ),
                      Positioned(
                        left: 0,
                        right: 0,
                        top: box.bottom + 20,
                        child: const Text(
                          'Đưa mã vạch hoặc SKU vào khung',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: Color(0xCCFFFFFF), fontSize: 13.5),
                        ),
                      ),
                      if (_miss != null)
                        Positioned(
                          left: 16,
                          right: 16,
                          top: box.bottom + 56,
                          child: Center(
                            child: Container(
                              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                              decoration: BoxDecoration(
                                color: const Color(0xE6111827),
                                borderRadius: BorderRadius.circular(12),
                              ),
                              child: Text(
                                _miss!,
                                textAlign: TextAlign.center,
                                style: const TextStyle(color: Colors.white, fontSize: 13),
                              ),
                            ),
                          ),
                        ),
                      if (_busy)
                        const Positioned(
                          left: 0,
                          right: 0,
                          bottom: 20,
                          child: Center(
                            child: SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                            ),
                          ),
                        ),
                      IgnorePointer(
                        child: AnimatedOpacity(
                          opacity: _flash ? .5 : 0,
                          duration: const Duration(milliseconds: 120),
                          child: const ColoredBox(color: Colors.white, child: SizedBox.expand()),
                        ),
                      ),
                    ],
                  );
                },
              ),
            ),
            Container(
              color: const Color(0xFF0B0F17),
              padding: EdgeInsets.fromLTRB(16, 14, 16, pad.bottom + 16),
              child: Row(
                children: [
                  Expanded(
                    child: Container(
                      height: 46,
                      padding: const EdgeInsets.symmetric(horizontal: 14),
                      decoration: BoxDecoration(
                        color: const Color(0x1AFFFFFF),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      alignment: Alignment.centerLeft,
                      child: TextField(
                        key: const Key('scan_manual'),
                        controller: _manual,
                        textCapitalization: TextCapitalization.characters,
                        textInputAction: TextInputAction.search,
                        onSubmitted: _handle,
                        style: const TextStyle(color: Colors.white, fontSize: 16),
                        cursorColor: Colors.white,
                        decoration: const InputDecoration(
                          isCollapsed: true,
                          filled: false,
                          border: InputBorder.none,
                          enabledBorder: InputBorder.none,
                          focusedBorder: InputBorder.none,
                          hintText: 'Hoặc nhập mã tay',
                          hintStyle: TextStyle(color: Color(0x73FFFFFF), fontSize: 16),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Material(
                    color: OrderColors.primary,
                    borderRadius: BorderRadius.circular(12),
                    child: InkWell(
                      key: const Key('scan_submit'),
                      borderRadius: BorderRadius.circular(12),
                      onTap: () => _handle(_manual.text),
                      child: const SizedBox(
                        height: 46,
                        child: Padding(
                          padding: EdgeInsets.symmetric(horizontal: 16),
                          child: Center(
                            widthFactor: 1,
                            child: Text(
                              'Tìm',
                              style: TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w600),
                            ),
                          ),
                        ),
                      ),
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

/// Phủ tối ngoài khung ngắm + bốn góc trắng.
class _Frame extends CustomPainter {
  const _Frame(this.box);

  final Rect box;

  @override
  void paint(Canvas c, Size s) {
    final r = RRect.fromRectAndRadius(box, const Radius.circular(16));
    c.drawPath(
      Path.combine(PathOperation.difference, Path()..addRect(Offset.zero & s), Path()..addRRect(r)),
      Paint()..color = const Color(0x8C000000),
    );
    final p = Paint()
      ..color = Colors.white
      ..strokeWidth = 3
      ..style = PaintingStyle.stroke;
    const k = 26.0;
    for (final (x, y, dx, dy) in [
      (box.left, box.top, 1.0, 1.0),
      (box.right, box.top, -1.0, 1.0),
      (box.left, box.bottom, 1.0, -1.0),
      (box.right, box.bottom, -1.0, -1.0),
    ]) {
      c.drawPath(
        Path()
          ..moveTo(x, y + dy * k)
          ..lineTo(x, y + dy * 10)
          ..quadraticBezierTo(x, y, x + dx * 10, y)
          ..lineTo(x + dx * k, y),
        p,
      );
    }
  }

  @override
  bool shouldRepaint(_Frame old) => old.box != box;
}

class _CameraError extends StatelessWidget {
  const _CameraError(this.e);

  final MobileScannerException e;

  @override
  Widget build(BuildContext context) {
    final denied = e.errorCode == MobileScannerErrorCode.permissionDenied;
    return ColoredBox(
      color: const Color(0xFF05070C),
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Text(
            denied
                ? 'Chưa cho phép dùng camera. Mở Cài đặt của máy để cấp quyền, hoặc nhập mã tay bên dưới.'
                : 'Không mở được camera. Nhập mã tay bên dưới.',
            textAlign: TextAlign.center,
            style: const TextStyle(color: Color(0xCCFFFFFF), fontSize: 13.5, height: 1.5),
          ),
        ),
      ),
    );
  }
}
