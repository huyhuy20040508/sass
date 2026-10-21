import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_number.dart';
import '../../../core/ui/app_icon.dart';
import '../../../core/ui/money_input.dart';
import '../../auth/auth_controller.dart';
import '../../printer/print_sheet.dart';
import '../../workspace/workspace_widgets.dart' show initialsOf;
import '../shell/shell_widgets.dart';
import 'order_widgets.dart';
import 'orders_data.dart';

const _tnum = [FontFeature.tabularFigures()];

String _hm(DateTime t) => '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';
String _dmy(DateTime t) => '${t.day.toString().padLeft(2, '0')}/${t.month.toString().padLeft(2, '0')}/${t.year}';
DateTime? _time(Object? v) => v == null ? null : DateTime.tryParse('$v')?.toLocal();

/// Chi tiết một chứng từ: đơn bán (GET /admin/orders/{id}) hoặc phiếu trả (chỉ từ dòng sổ).
class OrderDetailScreen extends ConsumerWidget {
  const OrderDetailScreen({super.key, required this.row});

  final SoDonRow row;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = row.isReturn ? null : ref.watch(orderDetailProvider(row.id));
    final o = detail?.value;
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.dark,
      child: Scaffold(
        backgroundColor: OrderColors.page,
        // Thanh thao tác ở bottomNavigationBar: thông báo nổi lên TRÊN nó, không che nút.
        bottomNavigationBar: row.isReturn
            ? _Foot(
                children: [
                  _ActBtn(
                    label: 'In phiếu trả',
                    icon: AppIcons.print,
                    onTap: () => openPrintSheet(context, ref, code: row.code, returnId: row.id),
                  ),
                ],
              )
            : o == null
            ? null
            : _Actions(row: row, o: o),
        body: Column(
          children: [
            _Head(
              code: row.code,
              sub: '${_dmy(row.createdAt)} · ${_hm(row.createdAt)}',
              onShare: () {
                Clipboard.setData(ClipboardData(text: '${row.code} · ${dot(row.total)} đ'));
                showDashToast(context, 'Đã sao chép mã đơn và số tiền');
              },
            ),
            Expanded(
              child: row.isReturn
                  ? ListView(
                      padding: const EdgeInsets.all(10),
                      children: [_ReturnCard(row: row)],
                    )
                  : detail!.when(
                      loading: () => const Center(child: CircularProgressIndicator(color: OrderColors.primary)),
                      error: (e, _) => Center(
                        child: Padding(
                          padding: const EdgeInsets.all(24),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(e is ApiException ? e.message : 'Không tải được đơn.', textAlign: TextAlign.center),
                              TextButton(
                                onPressed: () => ref.invalidate(orderDetailProvider(row.id)),
                                child: const Text('Thử lại'),
                              ),
                            ],
                          ),
                        ),
                      ),
                      data: (o) => RefreshIndicator(
                        onRefresh: () async {
                          ref.invalidate(orderDetailProvider(row.id));
                          await ref.read(orderDetailProvider(row.id).future);
                        },
                        child: ListView(
                          padding: const EdgeInsets.fromLTRB(10, 10, 10, 16),
                          children: _cards(context, o),
                        ),
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  List<Widget> _cards(BuildContext context, Map<String, dynamic> o) {
    final status = '${o['status'] ?? ''}';
    final st = statusTag(status);
    final pay = payTag(row.ledgerStatus);
    final items = ((o['items'] as List?) ?? const []).whereType<Map>().toList();
    final qty = items.fold<num>(0, (a, x) => a + ((x['quantity'] as num?) ?? 0));
    num n(String k) => (o[k] as num?) ?? 0;
    final debt = n('con_no');
    final cancelled = status == 'cancelled';
    final name = '${o['recipient_name'] ?? ''}'.isNotEmpty ? '${o['recipient_name']}' : row.customer;
    final phone = '${o['recipient_phone'] ?? ''}'.isNotEmpty ? '${o['recipient_phone']}' : row.phone;
    final company = '${o['buyer_company'] ?? ''}';
    final luot = ((o['luot_thu'] as List?) ?? const []).whereType<Map>().toList();
    final channel = '${o['channel'] ?? row.channel}';

    return [
      _Card(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                Tag(st.$1, st.$2),
                if (!cancelled && pay != null) Tag(pay.$1, pay.$2),
                Tag(channelLabels[channel] ?? channel, OrderColors.neutral),
              ],
            ),
            const SizedBox(height: 16),
            _Stepper(o: o),
          ],
        ),
      ),
      _Card(
        title: 'Khách hàng',
        child: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: name.isEmpty ? OrderColors.grid : OrderColors.blue.$1,
              ),
              child: name.isEmpty
                  ? const AppIcon(AppIcons.user, color: OrderColors.faint)
                  : Text(
                      initialsOf(name),
                      style: TextStyle(color: OrderColors.blue.$2, fontSize: 15, fontWeight: FontWeight.w700),
                    ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    name.isEmpty ? 'Khách lẻ' : name,
                    style: const TextStyle(color: OrderColors.ink, fontSize: 14.5, fontWeight: FontWeight.w700),
                  ),
                  Text(
                    [phone.isEmpty ? 'Không lưu số điện thoại' : phone, if (company.isNotEmpty) company].join(' · '),
                    style: const TextStyle(color: OrderColors.muted, fontSize: 12.5),
                  ),
                ],
              ),
            ),
            if (phone.isNotEmpty)
              Tooltip(
                message: 'Gọi khách',
                child: Material(
                  color: OrderColors.green.$1,
                  shape: const CircleBorder(),
                  child: InkWell(
                    customBorder: const CircleBorder(),
                    onTap: () => launchUrl(Uri(scheme: 'tel', path: phone.replaceAll(' ', ''))),
                    child: SizedBox(
                      width: 38,
                      height: 38,
                      child: Center(child: AppIcon(AppIcons.phone, size: 18, color: OrderColors.green.$2)),
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
      _Card(
        title: 'Sản phẩm',
        meta: '${dot(qty)} sản phẩm',
        child: Column(
          children: [
            for (var i = 0; i < items.length; i++)
              Container(
                padding: const EdgeInsets.symmetric(vertical: 10),
                decoration: BoxDecoration(
                  border: i > 0 ? const Border(top: BorderSide(color: OrderColors.grid)) : null,
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    ProductThumb(
                      name: '${items[i]['product_name'] ?? ''}',
                      url: '${items[i]['thumbnail'] ?? ''}',
                      size: 52,
                      radius: 10,
                      border: Border.all(color: OrderColors.line),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            '${items[i]['product_name'] ?? ''}',
                            style: const TextStyle(
                              color: OrderColors.ink,
                              fontSize: 13.5,
                              fontWeight: FontWeight.w600,
                              height: 1.35,
                            ),
                          ),
                          if ('${items[i]['variant_name'] ?? ''}'.isNotEmpty)
                            Text(
                              '${items[i]['variant_name']}',
                              style: const TextStyle(color: OrderColors.muted, fontSize: 12),
                            ),
                          if (items[i]['is_gift'] == true)
                            Text(
                              'Quà tặng',
                              style: TextStyle(color: OrderColors.green.$2, fontSize: 12, fontWeight: FontWeight.w600),
                            ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(
                          dot((items[i]['total_price'] as num?) ?? 0),
                          style: const TextStyle(
                            color: OrderColors.ink,
                            fontSize: 13.5,
                            fontWeight: FontWeight.w700,
                            fontFeatures: _tnum,
                          ),
                        ),
                        Text(
                          '${dot((items[i]['unit_price'] as num?) ?? 0)} × ${items[i]['quantity'] ?? 0}',
                          style: const TextStyle(color: OrderColors.muted, fontSize: 12, fontFeatures: _tnum),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
      _Card(
        title: 'Thanh toán',
        child: Column(
          children: [
            _Kv('Tổng tiền hàng', dot(n('subtotal_amount'))),
            for (final (label, key) in const [
              ('Khuyến mại', 'promotion_discount'),
              ('Giảm theo hạng', 'rank_discount'),
              ('Dùng điểm', 'points_amount'),
              ('Chiết khấu đơn', 'order_discount_amount'),
            ])
              if (n(key) > 0) _Kv(label, '−${dot(n(key))}', color: OrderColors.green.$2),
            if (n('shipping_fee') > 0) _Kv('Phí giao hàng', dot(n('shipping_fee'))),
            if (n('surcharge_amount') > 0) _Kv('Phụ thu', dot(n('surcharge_amount'))),
            if (n('vat_amount') > 0) _Kv('Thuế VAT', dot(n('vat_amount'))),
            _Kv('Khách phải trả', dot(n('total_amount')), total: true),
            if (!cancelled) ...[
              _Kv('Đã thanh toán', dot(n('da_thu'))),
              if (debt > 0) _Kv('Còn phải thu', dot(debt), color: OrderColors.orange.$2),
            ],
            if (luot.isNotEmpty)
              for (final l in luot)
                _PayRow(
                  method: methodLabel('${l['payment_method'] ?? ''}'),
                  sub: [
                    if (_time(l['paid_at']) case final t?) '${_dmy(t)} ${_hm(t)}',
                    if ('${l['note'] ?? ''}'.isNotEmpty) '${l['note']}',
                  ].join(' · '),
                  amount: (l['amount'] as num?) ?? 0,
                )
            else if (!cancelled && n('da_thu') > 0)
              _PayRow(method: methodLabel('${o['payment_method'] ?? ''}'), sub: 'Thu khi tạo đơn', amount: n('da_thu')),
            if (n('amount_tendered') > 0)
              _Kv('Khách đưa · tiền thừa', '${dot(n('amount_tendered'))} · ${dot(n('change_amount'))}'),
          ],
        ),
      ),
      _Card(
        title: 'Thông tin khác',
        child: Column(
          children: [
            _Info('Nhân viên tạo', '${o['created_by_name'] ?? row.creator}', first: true),
            _Info('Kênh bán', channelLabels[channel] ?? channel),
            _Info('Hình thức', methodLabel('${o['payment_method'] ?? ''}')),
            _Info('Hoá đơn điện tử', row.canEInvoice ? 'Xuất được' : 'Không'),
            if ('${o['cancel_reason'] ?? ''}'.isNotEmpty) _Info('Lý do huỷ', '${o['cancel_reason']}'),
            if ('${o['note'] ?? ''}'.isNotEmpty)
              Container(
                width: double.infinity,
                margin: const EdgeInsets.only(top: 10),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(10)),
                child: Text('${o['note']}', style: const TextStyle(color: OrderColors.text, fontSize: 13)),
              ),
          ],
        ),
      ),
    ];
  }
}

class _Head extends StatelessWidget {
  const _Head({required this.code, required this.sub, required this.onShare});

  final String code;
  final String sub;
  final VoidCallback onShare;

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    Widget btn(AppIcons i, String tip, VoidCallback onTap) => IconButton(
      tooltip: tip,
      onPressed: onTap,
      icon: AppIcon(i, color: OrderColors.ink),
    );
    return Container(
      padding: EdgeInsets.fromLTRB(8, top + 4, 8, 8),
      decoration: const BoxDecoration(
        color: Colors.white,
        boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, 1))],
      ),
      child: Row(
        children: [
          btn(AppIcons.back, 'Quay lại', () => Navigator.of(context).maybePop()),
          Expanded(
            child: Column(
              children: [
                Text(
                  code,
                  style: const TextStyle(color: OrderColors.ink, fontSize: 16.5, fontWeight: FontWeight.w700),
                ),
                Text(sub, style: const TextStyle(color: OrderColors.muted, fontSize: 12)),
              ],
            ),
          ),
          btn(AppIcons.share, 'Chia sẻ hoá đơn', onShare),
        ],
      ),
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({required this.child, this.title, this.meta});

  final Widget child;
  final String? title;
  final String? meta;

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(bottom: 10),
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(14),
      boxShadow: OrderColors.cardShadow,
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (title != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    title!,
                    style: const TextStyle(color: OrderColors.ink, fontSize: 14.5, fontWeight: FontWeight.w700),
                  ),
                ),
                if (meta != null) Text(meta!, style: const TextStyle(color: OrderColors.muted, fontSize: 12)),
              ],
            ),
          ),
        child,
      ],
    ),
  );
}

class _Kv extends StatelessWidget {
  const _Kv(this.label, this.value, {this.color, this.total = false});

  final String label;
  final String value;
  final Color? color;
  final bool total;

  @override
  Widget build(BuildContext context) {
    final row = Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: TextStyle(
                color: total ? OrderColors.ink : OrderColors.muted,
                fontSize: total ? 15.5 : 13.5,
                fontWeight: total ? FontWeight.w700 : FontWeight.w400,
              ),
            ),
          ),
          Text(
            value,
            style: TextStyle(
              color: color ?? OrderColors.ink,
              fontSize: total ? 15.5 : 13.5,
              fontWeight: total ? FontWeight.w700 : FontWeight.w600,
              fontFeatures: _tnum,
            ),
          ),
        ],
      ),
    );
    if (!total) return row;
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Column(
        children: [
          const Divider(height: 1, color: OrderColors.line),
          const SizedBox(height: 5),
          row,
        ],
      ),
    );
  }
}

class _PayRow extends StatelessWidget {
  const _PayRow({required this.method, required this.sub, required this.amount});

  final String method;
  final String sub;
  final num amount;

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(top: 10),
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
    decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(10)),
    child: Row(
      children: [
        Container(
          width: 30,
          height: 30,
          alignment: Alignment.center,
          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8)),
          child: AppIcon(AppIcons.cash, size: 16, color: OrderColors.green.$2),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                method,
                style: const TextStyle(color: OrderColors.ink, fontSize: 13, fontWeight: FontWeight.w700),
              ),
              Text(sub, style: const TextStyle(color: OrderColors.muted, fontSize: 12.5)),
            ],
          ),
        ),
        Text(
          dot(amount),
          style: const TextStyle(color: OrderColors.ink, fontWeight: FontWeight.w700, fontFeatures: _tnum),
        ),
      ],
    ),
  );
}

class _Info extends StatelessWidget {
  const _Info(this.label, this.value, {this.first = false});

  final String label;
  final String value;
  final bool first;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(vertical: 7),
    decoration: BoxDecoration(
      border: first ? null : const Border(top: BorderSide(color: OrderColors.grid)),
    ),
    child: Row(
      children: [
        Text(label, style: const TextStyle(color: OrderColors.muted, fontSize: 13)),
        const SizedBox(width: 12),
        Expanded(
          child: Text(
            value,
            textAlign: TextAlign.right,
            style: const TextStyle(color: OrderColors.ink, fontSize: 13, fontWeight: FontWeight.w600),
          ),
        ),
      ],
    ),
  );
}

/// Tiến trình ngang: mốc đã qua tô xanh, mốc hiện tại có vòng sáng; huỷ / trả tô đỏ.
class _Stepper extends StatelessWidget {
  const _Stepper({required this.o});

  final Map<String, dynamic> o;

  @override
  Widget build(BuildContext context) {
    final h = <String, DateTime?>{
      for (final x in ((o['histories'] as List?) ?? const []).whereType<Map>())
        '${x['to_status']}': _time(x['created_at']),
    };
    final status = '${o['status'] ?? ''}';
    final placed = _time(o['placed_at']);
    List<(String, String, DateTime?)> flow;
    if (status == 'cancelled') {
      flow = [('pending', 'Tạo đơn', placed), ('cancelled', 'Đã huỷ', _time(o['cancelled_at']) ?? h['cancelled'])];
    } else if (status == 'returned') {
      flow = [
        ('pending', 'Tạo đơn', placed),
        ('completed', 'Hoàn tất', _time(o['delivered_at']) ?? h['completed']),
        ('returned', 'Trả hàng', h['returned']),
      ];
    } else if (o['channel'] == 'web') {
      flow = [
        ('pending', 'Đặt hàng', placed),
        ('confirmed', 'Xác nhận', h['confirmed'] ?? _time(o['confirmed_at'])),
        ('processing', 'Đóng gói', h['processing']),
        ('shipping', 'Đang giao', h['shipping']),
        ('completed', 'Hoàn tất', h['completed'] ?? _time(o['delivered_at'])),
      ];
    } else {
      flow = [
        ('pending', 'Tạo đơn', placed),
        (
          'paid',
          'Thanh toán',
          o['payment_status'] == 'paid' ? (_time(o['confirmed_at']) ?? _time(o['delivered_at'])) : null,
        ),
        ('completed', 'Hoàn tất', _time(o['delivered_at']) ?? h['completed']),
      ];
    }
    var last = -1;
    for (var i = 0; i < flow.length; i++) {
      if (flow[i].$3 != null) last = i;
    }
    // Đường nối từ tâm bước i-1 tới tâm bước i: tô màu khi đã tới bước i (đỏ nếu bước đó là huỷ/trả).
    Color link(int i) => i > last
        ? OrderColors.line
        : _bad(flow[i].$1)
        ? OrderColors.red.$2
        : OrderColors.primary;
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (var i = 0; i < flow.length; i++)
          Expanded(
            child: _Step(
              flow[i],
              i,
              last,
              bad: _bad(flow[i].$1),
              inColor: i == 0 ? null : link(i),
              outColor: i == flow.length - 1 ? null : link(i + 1),
            ),
          ),
      ],
    );
  }
}

bool _bad(String k) => k == 'cancelled' || k == 'returned';

class _Step extends StatelessWidget {
  const _Step(this.s, this.i, this.last, {required this.bad, this.inColor, this.outColor});

  final (String, String, DateTime?) s;
  final int i;
  final int last;
  final bool bad;

  /// Nửa trái (đoạn đi vào) và nửa phải (đoạn đi ra) của đường nối; null = không có.
  final Color? inColor;
  final Color? outColor;

  @override
  Widget build(BuildContext context) {
    final done = i < last, cur = i == last, todo = i > last;
    final color = bad && cur ? OrderColors.red.$2 : OrderColors.primary;
    final t = s.$3;
    return Column(
      children: [
        // Rộng hết ô: hai nửa đường nối nằm hai bên vòng tròn.
        SizedBox(
          width: double.infinity,
          height: 28,
          child: Stack(
            alignment: Alignment.center,
            children: [
              if (inColor != null)
                Positioned(
                  left: 0,
                  right: 0,
                  child: FractionallySizedBox(
                    alignment: Alignment.centerLeft,
                    widthFactor: .5,
                    child: Container(height: 2, color: inColor),
                  ),
                ),
              if (outColor != null)
                Positioned(
                  left: 0,
                  right: 0,
                  child: FractionallySizedBox(
                    alignment: Alignment.centerRight,
                    widthFactor: .5,
                    child: Container(height: 2, color: outColor),
                  ),
                ),
              Container(
                width: 28,
                height: 28,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: todo ? Colors.white : color,
                  border: Border.all(color: todo ? OrderColors.line : color, width: 2),
                  boxShadow: cur
                      ? [BoxShadow(color: bad ? OrderColors.red.$1 : OrderColors.primarySoft, spreadRadius: 4)]
                      : null,
                ),
                child: todo
                    ? null
                    : AppIcon(
                        bad && cur ? AppIcons.close : AppIcons.check,
                        size: 15,
                        strokeWidth: 2.6,
                        color: Colors.white,
                      ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 5),
        Text(
          s.$2,
          textAlign: TextAlign.center,
          style: TextStyle(
            color: todo ? OrderColors.faint : OrderColors.ink,
            fontSize: 11.5,
            fontWeight: todo ? FontWeight.w500 : FontWeight.w600,
            height: 1.25,
          ),
        ),
        Text(
          t == null ? 'Chưa' : '${_hm(t)} · ${_dmy(t).substring(0, 5)}',
          style: const TextStyle(color: OrderColors.muted, fontSize: 10.5),
        ),
        if (done) const SizedBox.shrink(),
      ],
    );
  }
}

class _ReturnCard extends StatelessWidget {
  const _ReturnCard({required this.row});

  final SoDonRow row;

  @override
  Widget build(BuildContext context) => _Card(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Tag('Phiếu trả hàng', OrderColors.purple),
        const SizedBox(height: 16),
        Row(
          children: [
            const Expanded(
              child: Text(
                'Tiền trả khách',
                style: TextStyle(color: OrderColors.ink, fontSize: 15.5, fontWeight: FontWeight.w700),
              ),
            ),
            Text(
              '${dot(row.total)} đ',
              style: TextStyle(
                color: OrderColors.purple.$2,
                fontSize: 15.5,
                fontWeight: FontWeight.w700,
                fontFeatures: _tnum,
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        _Info('Hoàn bằng', methodLabel(row.method), first: true),
        _Info('Người lập', row.creator),
        Container(
          width: double.infinity,
          margin: const EdgeInsets.only(top: 10),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(10)),
          child: const Text(
            'Chi tiết món trả xem ở màn Trả hàng — chưa có trên mobile.',
            style: TextStyle(color: OrderColors.text, fontSize: 13),
          ),
        ),
      ],
    ),
  );
}

class _Foot extends StatelessWidget {
  const _Foot({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Container(
    padding: EdgeInsets.fromLTRB(12, 10, 12, MediaQuery.viewPaddingOf(context).bottom + 10),
    decoration: const BoxDecoration(
      color: Colors.white,
      boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, -1))],
    ),
    child: Row(children: children),
  );
}

class _ActBtn extends StatelessWidget {
  const _ActBtn({
    super.key,
    required this.label,
    required this.icon,
    required this.onTap,
    this.primary = false,
    this.square = false,
  });

  final String label;
  final AppIcons icon;
  final VoidCallback onTap;
  final bool primary;
  final bool square;

  @override
  Widget build(BuildContext context) {
    final btn = Material(
      color: primary ? OrderColors.primary : OrderColors.grid,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: SizedBox(
          height: 48,
          width: square ? 48 : null,
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              AppIcon(icon, size: 19, color: primary ? Colors.white : OrderColors.ink),
              if (!square) ...[
                const SizedBox(width: 6),
                // Co chữ chứ không cắt: nhãn nút Thu mang số tiền ("Thu
                // 24.200.000") nên dấu … cắt mất đuôi số, đọc thành số khác.
                Flexible(
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: Text(
                      label,
                      maxLines: 1,
                      style: TextStyle(
                        color: primary ? Colors.white : OrderColors.ink,
                        fontSize: 15,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
    return square ? Tooltip(message: label, child: btn) : Expanded(child: btn);
  }
}

/// Thanh thao tác đáy: hai nút lớn (việc chính tô xanh), phần còn lại vào "…".
class _Actions extends ConsumerWidget {
  const _Actions({required this.row, required this.o});

  final SoDonRow row;
  final Map<String, dynamic> o;

  int get _id => row.id;

  void _refresh(WidgetRef ref) {
    ref.invalidate(orderDetailProvider(_id));
    ref.invalidate(ordersListProvider);
    ref.invalidate(ordersSummaryProvider);
  }

  Future<void> _setStatus(BuildContext context, WidgetRef ref, String status, {String note = ''}) async {
    try {
      await ref
          .read(apiClientProvider)
          .put<Object?>('/admin/orders/$_id/status', body: {'status': status, if (note.isNotEmpty) 'note': note});
      _refresh(ref);
      if (context.mounted) showDashToast(context, 'Đơn đã chuyển sang "${statusTag(status).$1}"');
    } on ApiException catch (e) {
      if (context.mounted) showDashToast(context, e.message);
    }
  }

  Future<void> _pay(BuildContext context, WidgetRef ref, num debt) async {
    final r = await showModalBottomSheet<(num, String)>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => _PaySheet(code: row.code, debt: debt),
    );
    if (r == null || !context.mounted) return;
    try {
      await ref
          .read(apiClientProvider)
          .post<Object?>('/admin/orders/$_id/payments', body: {'amount': r.$1, 'payment_method': r.$2});
      _refresh(ref);
      if (context.mounted) showDashToast(context, 'Đã ghi thu ${dot(r.$1)} đ');
    } on ApiException catch (e) {
      if (context.mounted) showDashToast(context, e.message);
    }
  }

  Future<void> _cancel(BuildContext context, WidgetRef ref) async {
    final reason = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => const _CancelSheet(),
    );
    if (reason != null && context.mounted) await _setStatus(context, ref, 'cancelled', note: reason);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final status = '${o['status'] ?? ''}';
    final debt = (o['con_no'] as num?) ?? 0;
    final next = ((o['next_statuses'] as List?) ?? const []).map((e) => '$e').toList();
    final acts = <(String, String, AppIcons, VoidCallback)>[];
    if (debt > 0 && status != 'cancelled') {
      acts.add(('pay', 'Thu ${dot(debt)}', AppIcons.cash, () => _pay(context, ref, debt)));
    }
    for (final s in next.where((s) => s != 'cancelled' && s != 'returned').take(1)) {
      acts.add(('st', statusTag(s).$1, AppIcons.check, () => _setStatus(context, ref, s)));
    }
    void soon(String t) => showDashToast(context, t);
    acts.add(('print', 'In hoá đơn', AppIcons.print, () => openPrintSheet(context, ref, code: row.code, order: o)));
    if (row.canEInvoice && row.ledgerStatus == 'paid') {
      acts.add(('etax', 'Xuất HĐĐT', AppIcons.doc, () => soon('Xuất HĐĐT trên mobile — sắp có')));
    }
    if (next.contains('returned')) {
      acts.add(('return', 'Trả hàng', AppIcons.returns, () => soon('Màn Trả hàng — chưa thiết kế')));
    }
    acts.add(('copy', 'Sao chép đơn', AppIcons.copy, () => soon('Tạo đơn mới cùng sản phẩm → màn Bán hàng — sắp có')));

    final main = acts.take(2).toList(), rest = acts.skip(2).toList();
    // can_cancel của API là cờ KHÁCH tự huỷ trên web bán hàng (đường quản trị luôn
    // false) — quản trị huỷ được hay không theo next_statuses, như bản PC.
    final canCancel = next.contains('cancelled');
    bool isPrimary(int i) =>
        (i == 0 && (main[0].$1 == 'pay' || main[0].$1 == 'st')) || (i == 1 && main[0].$1 == 'print');

    return _Foot(
      children: [
        for (var i = 0; i < main.length; i++) ...[
          if (i > 0) const SizedBox(width: 10),
          _ActBtn(
            key: Key('act_${main[i].$1}'),
            label: main[i].$2,
            icon: main[i].$3,
            onTap: main[i].$4,
            primary: isPrimary(i),
          ),
        ],
        const SizedBox(width: 10),
        _ActBtn(
          key: const Key('act_more'),
          label: 'Thao tác khác',
          icon: AppIcons.dots,
          square: true,
          onTap: () async {
            final pick = await showDashSheet<String>(
              context,
              title: 'Thao tác',
              body: (ctx) => Column(
                children: [
                  for (final a in rest)
                    SheetOption(label: a.$2, leading: a.$3, onTap: () => Navigator.of(ctx).pop(a.$1)),
                  if (canCancel)
                    SheetOption(
                      key: const Key('act_cancel'),
                      label: 'Huỷ đơn',
                      leading: AppIcons.ban,
                      danger: true,
                      onTap: () => Navigator.of(ctx).pop('cancel'),
                    ),
                ],
              ),
            );
            if (!context.mounted || pick == null) return;
            if (pick == 'cancel') return _cancel(context, ref);
            rest.firstWhere((a) => a.$1 == pick).$4();
          },
        ),
      ],
    );
  }
}

class _PaySheet extends StatefulWidget {
  const _PaySheet({required this.code, required this.debt});

  final String code;
  final num debt;

  @override
  State<_PaySheet> createState() => _PaySheetState();
}

class _PaySheetState extends State<_PaySheet> {
  late final _ctl = TextEditingController(text: dot(widget.debt));
  String _method = 'cash';
  String? _err;

  @override
  void dispose() {
    _ctl.dispose();
    super.dispose();
  }

  void _ok() {
    final v = undot(_ctl.text);
    if (v == null || v <= 0) return setState(() => _err = 'Nhập số tiền thu');
    // Thu quá phần còn nợ thì API cũng từ chối (422) — báo ngay ở đây cho khỏi chờ.
    if (v > widget.debt) return setState(() => _err = 'Không thu quá ${dot(widget.debt)} đ còn nợ');
    Navigator.of(context).pop((v, _method));
  }

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 10, 20, mq.viewInsets.bottom + mq.viewPadding.bottom + 16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 40,
              height: 5,
              margin: const EdgeInsets.only(bottom: 12),
              decoration: BoxDecoration(color: const Color(0xFFD6DBE3), borderRadius: BorderRadius.circular(3)),
            ),
          ),
          Text(
            'Thu tiền ${widget.code}',
            style: const TextStyle(color: OrderColors.ink, fontSize: 18, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 14),
          const Text(
            'SỐ TIỀN THU',
            style: TextStyle(color: OrderColors.muted, fontSize: 11.5, fontWeight: FontWeight.w700, letterSpacing: .7),
          ),
          TextField(
            key: const Key('pay_amount'),
            controller: _ctl,
            keyboardType: TextInputType.number,
            inputFormatters: const [MoneyInputFormatter()],
            style: const TextStyle(
              color: OrderColors.ink,
              fontSize: 26,
              fontWeight: FontWeight.w700,
              fontFeatures: _tnum,
            ),
            // Viền + con trỏ theo màu của khu Quản trị: theme chung vẫn mang
            // màu tím của màn đăng nhập, để nguyên thì ô đang gõ viền tím giữa
            // một màn navy–xanh.
            cursorColor: OrderColors.primary,
            decoration: InputDecoration(
              suffixText: 'đ',
              errorText: _err,
              helperText: 'Còn phải thu ${dot(widget.debt)} đ',
              focusedBorder: const OutlineInputBorder(
                borderRadius: BorderRadius.all(Radius.circular(12)),
                borderSide: BorderSide(color: OrderColors.primary, width: 2),
              ),
            ),
          ),
          const SizedBox(height: 14),
          const Text(
            'HÌNH THỨC',
            style: TextStyle(color: OrderColors.muted, fontSize: 11.5, fontWeight: FontWeight.w700, letterSpacing: .7),
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final (k, v) in const [
                ('cash', 'Tiền mặt'),
                ('bank_transfer', 'Chuyển khoản'),
                ('card', 'Quẹt thẻ'),
              ])
                _MethodChip(label: v, on: _method == k, onTap: () => setState(() => _method = k)),
            ],
          ),
          const SizedBox(height: 16),
          FilledButton(
            key: const Key('pay_ok'),
            onPressed: _ok,
            style: FilledButton.styleFrom(backgroundColor: OrderColors.primary, minimumSize: const Size.fromHeight(48)),
            child: const Text('Xác nhận thu'),
          ),
        ],
      ),
    );
  }
}

/// Chip hình thức thu tiền. Tự vẽ chứ không dùng ChoiceChip: chip của Material 3
/// khi chọn thì nền tím nhạt (secondaryContainer) — lạc hẳn khỏi bảng màu navy
/// + xanh của app. Hình dáng giống dải chip kỳ ở màn Đơn hàng.
class _MethodChip extends StatelessWidget {
  const _MethodChip({required this.label, required this.on, required this.onTap});

  final String label;
  final bool on;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
    color: on ? OrderColors.primary : Colors.white,
    shape: RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(16),
      side: BorderSide(color: on ? OrderColors.primary : OrderColors.line),
    ),
    child: InkWell(
      borderRadius: BorderRadius.circular(16),
      onTap: onTap,
      // Center(widthFactor: 1) chứ không Container(alignment:): đặt alignment là
      // Container nở hết bề ngang Wrap cho sẵn, ba chip thành ba hàng.
      child: SizedBox(
        height: 34,
        child: Center(
          widthFactor: 1,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14),
            child: Text(
              label,
              style: TextStyle(color: on ? Colors.white : OrderColors.text, fontSize: 13, fontWeight: FontWeight.w600),
            ),
          ),
        ),
      ),
    ),
  );
}

class _CancelSheet extends StatefulWidget {
  const _CancelSheet();

  @override
  State<_CancelSheet> createState() => _CancelSheetState();
}

class _CancelSheetState extends State<_CancelSheet> {
  final _ctl = TextEditingController();

  @override
  void dispose() {
    _ctl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 10, 20, mq.viewInsets.bottom + mq.viewPadding.bottom + 16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 40,
              height: 5,
              margin: const EdgeInsets.only(bottom: 12),
              decoration: BoxDecoration(color: const Color(0xFFD6DBE3), borderRadius: BorderRadius.circular(3)),
            ),
          ),
          const Text(
            'Huỷ đơn?',
            style: TextStyle(color: OrderColors.ink, fontSize: 18, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 4),
          const Text(
            'Hàng đã lấy khỏi kho sẽ được trả về kho.',
            style: TextStyle(color: OrderColors.muted, fontSize: 13),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('cancel_reason'),
            controller: _ctl,
            maxLength: 255,
            decoration: const InputDecoration(hintText: 'Lý do huỷ (không bắt buộc)'),
          ),
          const SizedBox(height: 8),
          FilledButton(
            key: const Key('cancel_ok'),
            onPressed: () => Navigator.of(context).pop(_ctl.text.trim()),
            style: FilledButton.styleFrom(backgroundColor: OrderColors.red.$2, minimumSize: const Size.fromHeight(48)),
            child: const Text('Huỷ đơn'),
          ),
          TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Không huỷ')),
        ],
      ),
    );
  }
}
