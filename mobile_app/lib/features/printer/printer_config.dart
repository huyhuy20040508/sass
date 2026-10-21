import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

enum PrinterKind { bluetooth, lan }

/// Máy in hoá đơn ĐIỆN THOẠI NÀY in ra. Lưu trên máy chứ không lên máy chủ:
/// mỗi điện thoại ghép với một máy in Bluetooth riêng, hai thu ngân hai máy là
/// hai cấu hình khác nhau.
class PrinterConfig {
  const PrinterConfig({
    required this.kind,
    required this.address,
    this.name = '',
    this.port = 9100,
    this.paper = 80,
    this.cut = true,
  });

  final PrinterKind kind;

  /// Bluetooth: địa chỉ MAC; LAN: địa chỉ IP.
  final String address;
  final String name;
  final int port;

  /// Khổ cuộn giấy 58 hoặc 80mm.
  final int paper;

  /// Máy có dao cắt thì cắt sau mỗi phiếu.
  final bool cut;

  /// Số chấm một hàng ở 203 dpi: cuộn 80mm in được 72mm = 576 chấm, cuộn 58mm in được 48mm = 384 chấm.
  int get dots => paper == 58 ? 384 : 576;

  String get label => name.isNotEmpty ? name : (kind == PrinterKind.lan ? '$address:$port' : address);

  PrinterConfig copyWith({int? paper, bool? cut}) => PrinterConfig(
    kind: kind,
    address: address,
    name: name,
    port: port,
    paper: paper ?? this.paper,
    cut: cut ?? this.cut,
  );

  Map<String, dynamic> toJson() => {
    'kind': kind.name,
    'address': address,
    'name': name,
    'port': port,
    'paper': paper,
    'cut': cut,
  };

  static PrinterConfig? fromJson(Object? j) {
    if (j is! Map) return null;
    final kind = PrinterKind.values.where((k) => k.name == j['kind']).firstOrNull;
    final address = '${j['address'] ?? ''}'.trim();
    if (kind == null || address.isEmpty) return null;
    return PrinterConfig(
      kind: kind,
      address: address,
      name: '${j['name'] ?? ''}',
      port: (j['port'] as num?)?.toInt() ?? 9100,
      paper: (j['paper'] as num?)?.toInt() == 58 ? 58 : 80,
      cut: j['cut'] != false,
    );
  }
}

class PrinterConfigNotifier extends AsyncNotifier<PrinterConfig?> {
  static const key = 'printer.config';

  @override
  Future<PrinterConfig?> build() async {
    final raw = (await SharedPreferences.getInstance()).getString(key);
    if (raw == null) return null;
    try {
      return PrinterConfig.fromJson(jsonDecode(raw));
    } on FormatException {
      return null;
    }
  }

  Future<void> save(PrinterConfig? c) async {
    final p = await SharedPreferences.getInstance();
    if (c == null) {
      await p.remove(key);
    } else {
      await p.setString(key, jsonEncode(c.toJson()));
    }
    state = AsyncData(c);
  }
}

final printerConfigProvider = AsyncNotifierProvider<PrinterConfigNotifier, PrinterConfig?>(PrinterConfigNotifier.new);
