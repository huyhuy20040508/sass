import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app/router.dart';
import 'app/theme.dart';

void main() {
  runApp(const ProviderScope(child: SelliotechApp()));
}

class SelliotechApp extends ConsumerWidget {
  const SelliotechApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return MaterialApp.router(
      title: 'Sellio Shop',
      debugShowCheckedModeBanner: false,
      // Theme của khu làm việc là MẶC ĐỊNH, màn đăng nhập tự bọc theme tím
      // của nó (xem router) — màn mở bằng Navigator.push không thừa hưởng
      // Theme bọc trong cây widget, nên khu nào nhiều màn thì khu ấy làm gốc.
      theme: buildAdminTheme(),
      routerConfig: ref.watch(routerProvider),
      locale: const Locale('vi'),
      supportedLocales: const [Locale('vi'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
    );
  }
}
