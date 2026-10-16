import 'dart:async';
import 'dart:io';

import 'package:flutter/services.dart';

/// Nạp font thật của app cho mọi bài test: font giả mặc định rộng hơn nhiều,
/// đo tràn khung theo nó thì báo sai.
Future<void> testExecutable(FutureOr<void> Function() testMain) async {
  final loader = FontLoader('BeVietnamPro');
  for (final w in ['Regular', 'Medium', 'SemiBold', 'Bold']) {
    final bytes = File('assets/fonts/BeVietnamPro-$w.ttf').readAsBytesSync();
    loader.addFont(Future.value(ByteData.sublistView(bytes)));
  }
  await loader.load();
  await testMain();
}
