import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';

import 'printer_config.dart';

/// Lỗi in nói được cho thu ngân biết phải làm gì — không phải mã lỗi hệ thống.
class PrinterException implements Exception {
  const PrinterException(this.message);

  final String message;

  @override
  String toString() => message;
}

class PairedPrinter {
  const PairedPrinter(this.name, this.address);

  final String name;
  final String address;
}

/// Đường gửi lệnh tới máy in. Tách thành provider để test thay bằng bản ghi lại.
abstract interface class PrinterLink {
  Future<void> send(PrinterConfig c, List<int> bytes);

  /// Máy in Bluetooth ĐÃ GHÉP ĐÔI trong Cài đặt điện thoại (Android chỉ cho
  /// app đọc danh sách này, không tự dò máy lạ khi chưa xin quyền vị trí).
  Future<List<PairedPrinter>> paired();
}

class DeviceLink implements PrinterLink {
  const DeviceLink();

  @override
  Future<void> send(PrinterConfig c, List<int> bytes) =>
      c.kind == PrinterKind.lan ? _lan(c, bytes) : _bluetooth(c, bytes);

  /// Máy in mạng nhận ESC/POS thô ở cổng 9100 (RAW / JetDirect) — không cần driver.
  Future<void> _lan(PrinterConfig c, List<int> bytes) async {
    Socket? s;
    try {
      s = await Socket.connect(c.address, c.port, timeout: const Duration(seconds: 5));
      s.add(bytes);
      await s.flush();
    } on SocketException {
      throw PrinterException(
        'Không kết nối được máy in ${c.address}:${c.port}. Kiểm tra máy in đang bật và cùng mạng Wi-Fi với điện thoại.',
      );
    } finally {
      await s?.close();
      s?.destroy();
    }
  }

  Future<void> _bluetooth(PrinterConfig c, List<int> bytes) async {
    await _canDung();
    final dangNoi = await PrintBluetoothThermal.connectionStatus;
    if (!dangNoi && !await PrintBluetoothThermal.connect(macPrinterAddress: c.address)) {
      throw PrinterException('Không kết nối được máy in ${c.label}. Bật máy in, để gần điện thoại rồi thử lại.');
    }
    if (!await PrintBluetoothThermal.writeBytes(bytes)) {
      // Kết nối cũ có thể đã rơi (máy in tắt rồi bật lại): nối lại một lần.
      await PrintBluetoothThermal.disconnect;
      if (!await PrintBluetoothThermal.connect(macPrinterAddress: c.address) ||
          !await PrintBluetoothThermal.writeBytes(bytes)) {
        throw PrinterException('Gửi phiếu sang máy in ${c.label} không được. Tắt máy in, bật lại rồi thử lại.');
      }
    }
  }

  Future<void> _canDung() async {
    if (!await PrintBluetoothThermal.isPermissionBluetoothGranted) {
      throw const PrinterException('App chưa được phép dùng Bluetooth. Cho phép "Thiết bị ở gần" rồi bấm lại.');
    }
    if (!await PrintBluetoothThermal.bluetoothEnabled) {
      throw const PrinterException('Bluetooth của điện thoại đang tắt. Bật Bluetooth rồi bấm lại.');
    }
  }

  @override
  Future<List<PairedPrinter>> paired() async {
    await _canDung();
    final ds = await PrintBluetoothThermal.pairedBluetooths;
    return [for (final b in ds) PairedPrinter(b.name, b.macAdress)];
  }
}

final printerLinkProvider = Provider<PrinterLink>((_) => const DeviceLink());
