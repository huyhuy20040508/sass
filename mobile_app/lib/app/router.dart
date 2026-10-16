import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../features/auth/auth_controller.dart';
import '../features/auth/login_screen.dart';
import '../features/home/home_screen.dart';

final routerProvider = Provider<GoRouter>((ref) {
  // go_router chỉ nghe Listenable, nên chuyển thay đổi phiên sang ValueNotifier.
  final phien = ValueNotifier<AsyncValue<Object?>>(ref.read(authProvider));
  ref.listen(authProvider, (_, next) => phien.value = next);
  ref.onDispose(phien.dispose);

  return GoRouter(
    initialLocation: '/',
    refreshListenable: phien,
    redirect: (context, state) {
      final auth = phien.value;
      if (auth.isLoading && !auth.hasValue) return '/splash';
      final daDangNhap = auth.value != null;
      final o = state.matchedLocation;
      if (!daDangNhap) return o == '/login' ? null : '/login';
      if (o == '/login' || o == '/splash') return '/';
      return null;
    },
    routes: [
      GoRoute(path: '/splash', builder: (_, _) => const _Splash()),
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(path: '/', builder: (_, _) => const HomeScreen()),
    ],
  );
});

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) => const Scaffold(body: Center(child: CircularProgressIndicator()));
}
