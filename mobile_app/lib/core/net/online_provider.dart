import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// true khi máy còn Wi-Fi/4G. Lỗi đọc trạng thái mạng thì coi như còn mạng —
/// chặn nhầm người dùng tệ hơn để họ thử và nhận câu lỗi của lượt gọi API.
final onlineProvider = StreamProvider<bool>((ref) async* {
  final c = Connectivity();
  bool coMang(List<ConnectivityResult> r) => r.any((x) => x != ConnectivityResult.none);
  try {
    yield coMang(await c.checkConnectivity());
    yield* c.onConnectivityChanged.map(coMang);
  } catch (_) {
    yield true;
  }
});
