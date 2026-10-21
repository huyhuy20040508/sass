import 'package:flutter/material.dart';

import '../../../app/theme.dart';
import '../../../core/ui/app_icon.dart';
import '../../printer/printer_settings_screen.dart';
import '../categories/categories_screen.dart';
import 'shell_widgets.dart' show AdminTab;

/// Một mục con trong module; [tab] là tab app đã có màn, [page] là màn mở chồng
/// lên (không phải tab), [view] là màn nằm TRONG khung app (thanh dưới vẫn hiện, tab
/// Thêm sáng) — cả ba null thì chưa làm.
class MoreItem {
  const MoreItem(this.name, [this.tab, this.page, this.view]);

  final String name;
  final AdminTab? tab;
  final Widget Function()? page;
  final Widget Function(MoreHost host)? view;

  bool get ready => tab != null || page != null || view != null;
}

/// Khung app cho màn [MoreItem.view]: sang một tab, hoặc rời màn (nút lùi ở màn đầu).
class MoreHost {
  const MoreHost({required this.goTab, required this.close});

  final void Function(AdminTab tab) goTab;
  final VoidCallback close;
}

Widget _categories(MoreHost host) => CategoriesScreen(host: host);

class MoreModule {
  const MoreModule(this.code, this.title, this.icon, this.items);

  final String code;
  final String title;
  final AppIcons icon;
  final List<MoreItem> items;
}

/// Đúng thứ tự module và các tab con của Quản trị PC v2 (v2/layouts/header.blade.php),
/// chỉ lấy tab PC đã dựng — người dùng quen menu bên máy tính, sang app tìm cùng chỗ.
const moreModules = [
  MoreModule('thong_ke', 'Thống kê', AppIcons.chart, [
    MoreItem('Tổng quan', AdminTab.home),
    MoreItem('Khách hàng'),
    MoreItem('Quản lý đơn hàng', AdminTab.orders),
    MoreItem('Hoá đơn điện tử'),
  ]),
  MoreModule('hang_hoa', 'Hàng hoá', AppIcons.box, [
    MoreItem('Hàng hoá', AdminTab.goods),
    MoreItem('Nhóm hàng hoá', null, null, _categories),
    MoreItem('Thuộc tính'),
    MoreItem('Đơn vị tính'),
    MoreItem('Quản lý thuế'),
  ]),
  MoreModule('kho', 'Kho', AppIcons.store, [
    MoreItem('Tồn kho chi nhánh'),
    MoreItem('Nhà cung cấp'),
    MoreItem('Phiếu mua hàng'),
    MoreItem('Trả hàng nhà cung cấp'),
    MoreItem('Phiếu điều chuyển'),
    MoreItem('Điều chỉnh tồn kho'),
  ]),
  MoreModule('thu_chi', 'Thu chi', AppIcons.wallet, [
    MoreItem('Quản lý thu chi'),
    MoreItem('Loại thu chi'),
    MoreItem('Công nợ'),
  ]),
  MoreModule('nhan_su', 'Nhân sự', AppIcons.users, [MoreItem('Danh sách nhân sự')]),
  MoreModule('crm', 'CRM', AppIcons.target, [
    MoreItem('Tổng quan CRM'),
    MoreItem('Danh sách khách hàng'),
    MoreItem('Danh sách đơn hàng'),
    MoreItem('Chương trình khuyến mãi'),
    MoreItem('Thẻ thành viên'),
  ]),
  MoreModule('bao_cao', 'Báo cáo', AppIcons.doc, [
    MoreItem('Báo cáo tổng hợp'),
    MoreItem('Báo cáo cuối ngày'),
    MoreItem('Báo cáo kết ca'),
    MoreItem('Báo cáo chương trình khuyến mại'),
  ]),
  MoreModule('cai_dat', 'Cài đặt', AppIcons.gear, [
    MoreItem('Chi nhánh'),
    MoreItem('Phương thức thanh toán'),
    MoreItem('Cài đặt thiết bị', null, printerSettingsPage),
  ]),
];

/// Danh sách module xổ ra/thu vào; mở sẵn module chứa tab đang xem.
class MoreModules extends StatefulWidget {
  const MoreModules({super.key, required this.current, required this.onPick});

  final AdminTab current;
  final ValueChanged<MoreItem> onPick;

  @override
  State<MoreModules> createState() => _MoreModulesState();
}

class _MoreModulesState extends State<MoreModules> {
  late final _open = {
    for (final m in moreModules)
      if (m.items.any((i) => i.tab == widget.current)) m.code,
  };

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [for (final m in moreModules) _module(m, _open.contains(m.code))],
  );

  Widget _module(MoreModule m, bool open) {
    final ready = m.items.where((i) => i.ready).length;
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        color: open ? DashColors.page : Colors.white,
        border: Border.all(color: open ? DashColors.page : DashColors.line),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InkWell(
            key: Key('more_mod_${m.code}'),
            borderRadius: BorderRadius.circular(16),
            onTap: () => setState(() => open ? _open.remove(m.code) : _open.add(m.code)),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
              child: Row(
                children: [
                  Container(
                    width: 40,
                    height: 40,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(color: DashColors.primarySoft, borderRadius: BorderRadius.circular(12)),
                    child: AppIcon(m.icon, size: 20, color: DashColors.primaryDeep),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          m.title,
                          style: const TextStyle(color: DashColors.ink, fontSize: 15, fontWeight: FontWeight.w700),
                        ),
                        Text(
                          ready > 0 ? '${m.items.length} mục · $ready có trên app' : '${m.items.length} mục',
                          style: const TextStyle(color: DashColors.muted, fontSize: 12.5),
                        ),
                      ],
                    ),
                  ),
                  AnimatedRotation(
                    turns: open ? .5 : 0,
                    duration: const Duration(milliseconds: 180),
                    child: const AppIcon(AppIcons.down, size: 18, color: DashColors.muted),
                  ),
                ],
              ),
            ),
          ),
          if (open)
            Padding(
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 8),
              child: Container(
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
                child: Column(
                  children: [
                    for (final (n, i) in m.items.indexed) ...[
                      if (n > 0) const Divider(height: 1, indent: 14, endIndent: 14, color: DashColors.line),
                      _item(i),
                    ],
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _item(MoreItem i) {
    final ready = i.ready;
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: () => widget.onPick(i),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 46),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  i.name,
                  style: TextStyle(
                    color: ready ? DashColors.ink : DashColors.text,
                    fontSize: 14,
                    fontWeight: ready ? FontWeight.w600 : FontWeight.w500,
                  ),
                ),
              ),
              if (ready)
                const AppIcon(AppIcons.right, size: 16, color: DashColors.primaryDeep)
              else
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(color: DashColors.page, borderRadius: BorderRadius.circular(20)),
                  child: const Text(
                    'Sắp có',
                    style: TextStyle(color: DashColors.muted, fontSize: 11.5, fontWeight: FontWeight.w600),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
