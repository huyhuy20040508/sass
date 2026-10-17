import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_exception.dart';
import '../../core/ui/app_icon.dart';
import '../../core/ui/brand_splash.dart';
import '../auth/auth_controller.dart';
import 'workspace.dart';
import 'workspace_widgets.dart';

/// Màn chọn nơi làm việc theo design/html/chon-noi-lam-viec-mobile.html (v3, bộ màu navy).
///
/// Bản mobile của /choose-workspace bên web_Shop: hỏi đúng hai câu — hôm nay
/// đứng ở CHI NHÁNH nào, vào QUẢN TRỊ hay THU NGÂN. Không còn gì để chọn thì
/// vào thẳng như bản PC.
class ChooseWorkspaceScreen extends ConsumerStatefulWidget {
  const ChooseWorkspaceScreen({super.key, this.now = DateTime.now});

  /// Đồng hồ — test truyền giờ cố định.
  final DateTime Function() now;

  /// Màn "Đang vào …" hiện bao lâu trước khi mở khu vực.
  static const goingDelay = Duration(milliseconds: 900);

  /// Phần thẻ chi nhánh đè lên đáy đầu navy.
  static const branchOverlap = 36.0;

  @override
  ConsumerState<ChooseWorkspaceScreen> createState() => _ChooseWorkspaceScreenState();
}

class _ChooseWorkspaceScreenState extends ConsumerState<ChooseWorkspaceScreen> with SingleTickerProviderStateMixin {
  int? _picked;
  WorkspaceArea? _going;
  bool _autoEntered = false;
  late DateTime _now = widget.now();
  Timer? _clock;
  late final _flash = AnimationController(vsync: this, duration: const Duration(milliseconds: 600));

  @override
  void initState() {
    super.initState();
    _clock = Timer.periodic(const Duration(seconds: 15), (_) => setState(() => _now = widget.now()));
  }

  @override
  void dispose() {
    _clock?.cancel();
    _flash.dispose();
    super.dispose();
  }

  Branch? _pickedBranch(WorkspaceOptions o) {
    if (o.branches.isEmpty) return null;
    return o.branches.firstWhere((b) => b.id == _picked, orElse: () => o.branches.first);
  }

  void _initPicked(WorkspaceOptions o) {
    if (_picked != null || o.branches.isEmpty) return;
    // Giữ chi nhánh đang làm nếu còn trong danh sách, không thì trụ sở (đứng đầu).
    final cur = ref.read(authProvider).value?.chiNhanhId;
    _picked = o.branches.any((b) => b.id == cur) ? cur : o.branches.first.id;
  }

  /// Một lượt cho cả hai câu: ghi chi nhánh đang chọn rồi vào khu vừa chạm (như PC).
  Future<void> _enter(WorkspaceOptions o, WorkspaceArea area, {bool splash = true}) async {
    if (_going != null) return;
    final branch = o.branches.length > 1 ? _pickedBranch(o) : null;
    if (branch != null) await ref.read(authProvider.notifier).setChiNhanh(branch.id);
    if (!splash) {
      ref.read(currentAreaProvider.notifier).enter(area);
      return;
    }
    setState(() => _going = area);
    await Future<void>.delayed(ChooseWorkspaceScreen.goingDelay);
    if (mounted) ref.read(currentAreaProvider.notifier).enter(area);
  }

  Future<void> _pickBranch(WorkspaceOptions o) async {
    final chosen = await showNavySheet<int>(
      context,
      title: 'Chọn chi nhánh',
      subtitle: 'Hàng nhập, hàng bán trong ca ghi vào chi nhánh này.',
      body: (ctx) => _BranchList(branches: o.branches, initial: _pickedBranch(o)?.id),
    );
    if (chosen == null || !mounted) return;
    setState(() => _picked = chosen);
    if (!MediaQuery.disableAnimationsOf(context)) {
      _flash
        ..value = 0
        ..animateTo(1, duration: const Duration(milliseconds: 240)).then((_) => _flash.animateBack(0));
    }
  }

  Future<void> _confirmLogout() async {
    final yes = await showNavySheet<bool>(
      context,
      title: 'Đăng xuất?',
      subtitle: 'Sai tài khoản hay sai tiệm thì đăng xuất rồi vào lại.',
      body: (ctx) => Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SheetButton(
            label: 'Đăng xuất',
            icon: AppIcons.logout,
            danger: true,
            onTap: () => Navigator.of(ctx).pop(true),
          ),
          SheetButton(label: 'Ở lại', onTap: () => Navigator.of(ctx).pop(false)),
        ],
      ),
    );
    if (yes == true) await ref.read(authProvider.notifier).logout();
  }

  @override
  Widget build(BuildContext context) {
    final session = ref.watch(authProvider).value;
    if (session == null) return const SizedBox.shrink();
    final optsAsync = ref.watch(workspaceOptionsProvider);
    final opts = optsAsync.value;
    if (opts != null) {
      _initPicked(opts);
      if (!_autoEntered && !opts.locked && !opts.needsChoice && opts.areas.length == 1) {
        _autoEntered = true;
        WidgetsBinding.instance.addPostFrameCallback((_) => _enter(opts, opts.areas.single, splash: false));
      }
    }

    final branch = opts == null || opts.branches.length < 2 ? null : _pickedBranch(opts);
    final name = session.user.tenHienThi;

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        backgroundColor: NavyColors.page,
        body: Stack(
          children: [
            LayoutBuilder(
              builder: (context, box) => SingleChildScrollView(
                child: ConstrainedBox(
                  constraints: BoxConstraints(minHeight: box.maxHeight),
                  child: IntrinsicHeight(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        WorkspaceHeader(
                          greeting: '${greetingFor(_now)}, $name',
                          initials: initialsOf(name),
                          shopName: session.tenant.name.isNotEmpty ? session.tenant.name : session.tenant.code,
                          clock: clockFor(_now),
                          onLogout: _confirmLogout,
                          overlap: branch != null ? ChooseWorkspaceScreen.branchOverlap : 0,
                        ),
                        if (branch != null)
                          Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 16),
                            child: BranchCard(
                              key: const Key('branch_card'),
                              branch: branch,
                              flash: _flash,
                              onTap: () => _pickBranch(opts!),
                            ),
                          ),
                        Expanded(
                          child: ConstrainedBox(
                            constraints: const BoxConstraints(minHeight: 330),
                            child: Padding(
                              padding: EdgeInsets.fromLTRB(16, 22, 16, MediaQuery.viewPaddingOf(context).bottom + 14),
                              child: _areas(optsAsync),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
            if (_going != null)
              Positioned.fill(
                child: BrandSplash(
                  gradient: NavyColors.header,
                  icon: _going!.icon,
                  iconColor: NavyColors.hd1,
                  title: 'Đang vào ${_going!.name}',
                  subtitle: branch?.name ?? session.tenant.name,
                  boxSize: 72,
                  boxRadius: 22,
                  iconSize: 34,
                  iconStroke: 2.2,
                  titleGap: 16,
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _areas(AsyncValue<WorkspaceOptions> optsAsync) {
    const heading = Padding(
      padding: EdgeInsets.fromLTRB(4, 0, 4, 10),
      child: Text(
        'Vào khu vực',
        style: TextStyle(color: NavyColors.ink, fontSize: 15, fontWeight: FontWeight.w700),
      ),
    );

    return optsAsync.when(
      loading: () => const Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          heading,
          Expanded(
            child: Center(child: CircularProgressIndicator(color: NavyColors.pick)),
          ),
        ],
      ),
      error: (e, _) => _Notice(
        icon: AppIcons.wifiOff,
        text: e is ApiException ? e.message : 'Không tải được nơi làm việc.',
        action: 'Thử lại',
        onAction: () => ref.invalidate(workspaceOptionsProvider),
      ),
      data: (o) {
        if (o.locked) {
          return _Notice(
            icon: AppIcons.alert,
            text: '${o.lockedMessage ?? 'Cửa hàng đã hết hạn sử dụng.'}\nGia hạn trên web rồi đăng nhập lại.',
            action: 'Đăng xuất',
            onAction: _confirmLogout,
          );
        }
        if (o.areas.isEmpty) {
          return _Notice(
            icon: AppIcons.alert,
            text: 'Tài khoản chưa được giao khu vực nào. Nhờ quản lý cửa hàng cấp quyền rồi đăng nhập lại.',
            action: 'Đăng xuất',
            onAction: _confirmLogout,
          );
        }
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            heading,
            for (final (i, a) in o.areas.indexed) ...[
              if (i > 0) const SizedBox(height: 12),
              Flexible(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(minHeight: 140, maxHeight: 260),
                  child: AreaCard(key: Key('area_${a.cua}'), area: a, index: i, onTap: () => _enter(o, a)),
                ),
              ),
            ],
            const SizedBox(height: 14),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              child: Text(
                o.areas.length > 1
                    ? 'Chọn nhầm cũng không sao — đổi lại bất cứ lúc nào ở góc phải thanh trên cùng.'
                    : 'Sai tài khoản hay sai tiệm thì đăng xuất rồi vào lại.',
                textAlign: TextAlign.center,
                style: const TextStyle(color: NavyColors.muted, fontSize: 12.5),
              ),
            ),
          ],
        );
      },
    );
  }
}

class _BranchList extends StatefulWidget {
  const _BranchList({required this.branches, required this.initial});

  final List<Branch> branches;
  final int? initial;

  @override
  State<_BranchList> createState() => _BranchListState();
}

class _BranchListState extends State<_BranchList> {
  late int? _sel = widget.initial;

  // Chọn là đóng luôn — chờ một nhịp cho thấy dấu tích đã đổi.
  Future<void> _choose(int id) async {
    setState(() => _sel = id);
    await Future<void>.delayed(const Duration(milliseconds: 160));
    if (mounted) Navigator.of(context).pop(id);
  }

  @override
  Widget build(BuildContext context) => ListView.separated(
    shrinkWrap: true,
    padding: const EdgeInsets.all(2),
    itemCount: widget.branches.length,
    separatorBuilder: (_, _) => const SizedBox(height: 6),
    itemBuilder: (_, i) {
      final b = widget.branches[i];
      return BranchOption(key: Key('branch_${b.id}'), branch: b, selected: b.id == _sel, onTap: () => _choose(b.id));
    },
  );
}

class _Notice extends StatelessWidget {
  const _Notice({required this.icon, required this.text, required this.action, required this.onAction});

  final AppIcons icon;
  final String text;
  final String action;
  final VoidCallback onAction;

  @override
  Widget build(BuildContext context) => Center(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 56,
          height: 56,
          alignment: Alignment.center,
          decoration: BoxDecoration(color: NavyColors.pickSoft, borderRadius: BorderRadius.circular(18)),
          child: AppIcon(icon, size: 26, color: NavyColors.pick),
        ),
        const SizedBox(height: 14),
        Text(
          text,
          textAlign: TextAlign.center,
          style: const TextStyle(color: NavyColors.text, fontSize: 14, height: 1.5),
        ),
        const SizedBox(height: 6),
        TextButton(
          onPressed: onAction,
          style: TextButton.styleFrom(
            foregroundColor: NavyColors.pick,
            textStyle: const TextStyle(fontFamily: appFont, fontSize: 14.5, fontWeight: FontWeight.w600),
          ),
          child: Text(action),
        ),
      ],
    ),
  );
}
