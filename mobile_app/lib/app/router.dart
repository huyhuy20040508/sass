import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../features/admin/admin_shell.dart';
import '../features/auth/auth_controller.dart';
import '../features/auth/login_screen.dart';
import '../features/cashier/cashier_screen.dart';
import '../features/workspace/choose_workspace_screen.dart';
import '../features/workspace/workspace.dart';
import 'theme.dart';

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
      // Màn đăng nhập giữ bộ màu tím + vàng của logo; mọi màn sau khi vào
      // khu làm việc dùng theme navy mặc định.
      GoRoute(path: '/login', builder: (_, _) => Theme(data: buildTheme(), child: const LoginScreen())),
      GoRoute(path: chooseWorkspacePath, builder: (_, _) => const ChooseWorkspaceScreen()),
      GoRoute(path: WorkspaceArea.quanTri.route, builder: (_, _) => const AdminShell()),
      // Khu Thu ngân chưa có thiết kế mobile — màn chờ nói rõ đang thiếu gì.
      GoRoute(path: WorkspaceArea.thuNgan.route, builder: (_, _) => const CashierScreen()),
    ],
  );
});

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) => const Scaffold(body: Center(child: CircularProgressIndicator()));
}
