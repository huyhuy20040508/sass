import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../features/auth/auth_controller.dart';
import '../features/auth/login_screen.dart';
import '../features/home/home_screen.dart';
import '../features/workspace/choose_workspace_screen.dart';
import '../features/workspace/workspace.dart';

const chooseWorkspacePath = '/chon-noi-lam-viec';

final routerProvider = Provider<GoRouter>((ref) {
  // go_router chỉ nghe Listenable: đổi phiên hay đổi khu vực đều phải tính lại đường.
  final doi = ValueNotifier(0);
  ref.listen(authProvider, (_, _) => doi.value++);
  ref.listen(currentAreaProvider, (_, _) => doi.value++);
  ref.onDispose(doi.dispose);

  return GoRouter(
    initialLocation: chooseWorkspacePath,
    refreshListenable: doi,
    redirect: (context, state) {
      final auth = ref.read(authProvider);
      if (auth.isLoading && !auth.hasValue) return '/splash';
      final o = state.matchedLocation;
      if (auth.value == null) return o == '/login' ? null : '/login';

      // Đã đăng nhập: chưa chọn khu thì ở màn chọn nơi làm việc, chọn rồi thì ở đúng khu đó.
      final area = ref.read(currentAreaProvider);
      final dich = area?.route ?? chooseWorkspacePath;
      return o == dich ? null : dich;
    },
    routes: [
      GoRoute(path: '/splash', builder: (_, _) => const _Splash()),
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(path: chooseWorkspacePath, builder: (_, _) => const ChooseWorkspaceScreen()),
      for (final a in WorkspaceArea.values)
        GoRoute(
          path: a.route,
          builder: (_, _) => HomeScreen(area: a),
        ),
    ],
  );
});

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) => const Scaffold(body: Center(child: CircularProgressIndicator()));
}
