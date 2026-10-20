import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/ui/app_icon.dart';
import '../shell/shell_widgets.dart' show showDashToast;
import 'goods_data.dart';
import 'goods_widgets.dart';

/// Kéo thả đổi thứ tự hàng hoá — cùng việc với hai mũi tên lên/xuống trên bảng PC.
/// Thả tay là lưu ngay (như PC), hỏng thì trả dòng về chỗ cũ.
class GoodsReorderScreen extends ConsumerStatefulWidget {
  const GoodsReorderScreen({super.key, required this.items, this.onSaved});

  /// Đã xếp theo [GoodsSort.manualOrder].
  final List<GoodsItem> items;

  /// Gọi sau mỗi lần lưu được — màn danh sách biết để tải lại khi quay về.
  final VoidCallback? onSaved;

  @override
  ConsumerState<GoodsReorderScreen> createState() => _GoodsReorderScreenState();
}

class _GoodsReorderScreenState extends ConsumerState<GoodsReorderScreen> {
  late List<GoodsItem> _rows = [...widget.items];
  bool _saving = false;

  /// [to] đã tính sau khi rút dòng ra (onReorderItem).
  Future<void> _move(int from, int to) async {
    if (to == from || _saving) return;
    final before = _rows;
    final next = [...before];
    next.insert(to, next.removeAt(from));
    final lo = from < to ? from : to, hi = from < to ? to : from;
    setState(() {
      _rows = next;
      _saving = true;
    });
    try {
      await reorderProducts(ref, [for (final it in next.sublist(lo, hi + 1)) it.id]);
      widget.onSaved?.call();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _rows = before);
      showDashToast(context, e.message);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return Scaffold(
      backgroundColor: OrderColors.page,
      body: Column(
        children: [
          Container(
            padding: EdgeInsets.fromLTRB(8, top + 4, 8, 8),
            decoration: const BoxDecoration(
              color: Colors.white,
              boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, 1))],
            ),
            child: Row(
              children: [
                IconButton(
                  tooltip: 'Quay lại',
                  onPressed: () => Navigator.of(context).maybePop(),
                  icon: const AppIcon(AppIcons.back, size: 22, color: OrderColors.ink),
                ),
                const Expanded(
                  child: Text(
                    'Đổi thứ tự hiển thị',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: OrderColors.ink, fontSize: 16.5, fontWeight: FontWeight.w700),
                  ),
                ),
                SizedBox(
                  width: 48,
                  child: _saving
                      ? const Center(
                          key: Key('reorder_saving'),
                          child: SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2, color: OrderColors.primary),
                          ),
                        )
                      : null,
                ),
              ],
            ),
          ),
          const Padding(
            padding: EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: Text(
              'Kéo biểu tượng ≡ để đổi chỗ, thả tay là lưu. Thứ tự này dùng ở quầy bán hàng '
              'và ở danh sách khi xếp theo “Thứ tự tự xếp”.',
              style: TextStyle(color: OrderColors.muted, fontSize: 12.5, height: 1.4),
            ),
          ),
          Expanded(
            child: ReorderableListView.builder(
              buildDefaultDragHandles: false,
              padding: EdgeInsets.only(bottom: MediaQuery.viewPaddingOf(context).bottom + 16),
              itemCount: _rows.length,
              onReorderItem: _move,
              itemBuilder: (_, i) {
                final it = _rows[i];
                return _ReorderRow(key: ValueKey(it.id), item: it, index: i, locked: _saving);
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _ReorderRow extends StatelessWidget {
  const _ReorderRow({super.key, required this.item, required this.index, required this.locked});

  final GoodsItem item;
  final int index;
  final bool locked;

  @override
  Widget build(BuildContext context) {
    final dim = item.active ? 1.0 : .55;
    final handle = Padding(
      padding: const EdgeInsets.all(10),
      child: AppIcon(AppIcons.menu, size: 22, color: locked ? OrderColors.line : OrderColors.muted),
    );
    return Material(
      color: Colors.white,
      child: Container(
        decoration: const BoxDecoration(
          border: Border(bottom: BorderSide(color: OrderColors.line)),
        ),
        padding: const EdgeInsets.fromLTRB(12, 8, 6, 8),
        child: Row(
          children: [
            SizedBox(
              width: 28,
              child: Text(
                '${index + 1}',
                style: const TextStyle(color: OrderColors.faint, fontSize: 12.5, fontFeatures: tnum),
              ),
            ),
            Opacity(
              opacity: dim,
              child: GoodsImage(url: item.thumb, size: 40, radius: 9),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Opacity(
                opacity: dim,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: OrderColors.ink, fontSize: 14, fontWeight: FontWeight.w600),
                    ),
                    Text(
                      [item.sku, if (!item.active) 'Ngừng bán'].join(' · '),
                      maxLines: 1,
                      style: const TextStyle(color: OrderColors.muted, fontSize: 12),
                    ),
                  ],
                ),
              ),
            ),
            if (locked)
              handle
            else
              ReorderableDragStartListener(key: Key('reorder_handle_${item.id}'), index: index, child: handle),
          ],
        ),
      ),
    );
  }
}
