import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/config.dart';
import '../../../core/format/vn_number.dart';
import '../../../core/ui/app_icon.dart';
import '../../workspace/workspace_widgets.dart' show initialsOf;
import 'orders_data.dart';

const _tnum = [FontFeature.tabularFigures()];

/// Nhãn + màu trạng thái xử lý.
(String, (Color, Color)) statusTag(String s) => switch (s) {
  'pending' => ('Chờ xác nhận', OrderColors.orange),
  'confirmed' => ('Đã xác nhận', OrderColors.blue),
  'processing' => ('Đang chuẩn bị', OrderColors.blue),
  'shipping' => ('Đang giao', OrderColors.blue),
  'delivered' => ('Đã giao', OrderColors.green),
  'completed' => ('Hoàn tất', OrderColors.green),
  'cancelled' => ('Đã huỷ', OrderColors.neutral),
  'returned' => ('Trả hàng', OrderColors.purple),
  _ => (s, OrderColors.neutral),
};

/// Nhãn + màu trạng thái thanh toán của sổ.
(String, (Color, Color))? payTag(String s, {bool short = false}) => switch (s) {
  'paid' => ('Đã thanh toán', OrderColors.green),
  'unpaid' => ('Chưa thanh toán', OrderColors.orange),
  'partial' => (short ? 'TT một phần' : 'Thanh toán một phần', OrderColors.blue),
  _ => null,
};

/// Ảnh sản phẩm: đường dẫn tương đối (/uploads/…) ghép với máy chủ API.
String mediaUrl(String u) {
  if (u.isEmpty || u.startsWith('http')) return u;
  final host = AppConfig.apiBaseUrl.replaceFirst(RegExp(r'/api/v\d+/?$'), '');
  return '$host${u.startsWith('/') ? '' : '/'}$u';
}

class Tag extends StatelessWidget {
  const Tag(this.text, this.colors, {super.key});

  final String text;
  final (Color, Color) colors;

  @override
  Widget build(BuildContext context) => Container(
    height: 22,
    padding: const EdgeInsets.symmetric(horizontal: 8),
    decoration: BoxDecoration(color: colors.$1, borderRadius: BorderRadius.circular(6)),
    // widthFactor 1: ôm theo chữ — Container có alignment thì giãn hết chỗ trong Wrap.
    child: Center(
      widthFactor: 1,
      child: Text(
        text,
        style: TextStyle(color: colors.$2, fontSize: 11.5, fontWeight: FontWeight.w600),
      ),
    ),
  );
}

/// Ô ảnh sản phẩm: ảnh thật nếu có, không thì chữ cái đầu tên.
class ProductThumb extends StatelessWidget {
  const ProductThumb({super.key, required this.name, required this.url, this.size = 34, this.radius = 9, this.border});

  final String name;
  final String url;
  final double size;
  final double radius;
  final BoxBorder? border;

  @override
  Widget build(BuildContext context) {
    final letter = Center(
      child: Text(
        name.isEmpty ? '?' : name[0].toUpperCase(),
        style: TextStyle(color: OrderColors.muted, fontSize: size > 40 ? 15 : 11, fontWeight: FontWeight.w700),
      ),
    );
    return Container(
      width: size,
      height: size,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(radius), border: border),
      child: url.isEmpty
          ? letter
          : Image.network(
              mediaUrl(url),
              fit: BoxFit.cover,
              // Bản web: ảnh ở tên miền khác không có CORS — lùi về thẻ <img> thay vì báo lỗi.
              webHtmlElementStrategy: WebHtmlElementStrategy.fallback,
              errorBuilder: (_, _, _) => letter,
            ),
    );
  }
}

class _Dashed extends CustomPainter {
  const _Dashed();

  @override
  void paint(Canvas c, Size s) {
    final p = Paint()
      ..color = OrderColors.line
      ..strokeWidth = 1;
    for (double x = 0; x < s.width; x += 6) {
      c.drawLine(Offset(x, 0), Offset(math.min(x + 3, s.width), 0), p);
    }
  }

  @override
  bool shouldRepaint(_Dashed old) => false;
}

/// Thẻ một chứng từ trong sổ.
class OrderCard extends ConsumerWidget {
  const OrderCard({super.key, required this.row, required this.onTap});

  final SoDonRow row;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Dòng sổ chưa có món / trạng thái xử lý thì đọc từ chi tiết đơn (giữ trong bộ nhớ).
    final needDetail = !row.isReturn && (row.procStatus == null || row.thumbs == null);
    final detail = needDetail ? ref.watch(orderDetailProvider(row.id)).value : null;
    final items = ((detail?['items'] as List?) ?? const []).whereType<Map>().toList();

    final proc = row.cancelled ? 'cancelled' : row.procStatus ?? detail?['status'] as String?;
    final names = row.thumbs != null
        ? <(String, String)>[for (final t in row.thumbs!) (row.firstItem ?? '', t)]
        : [for (final x in items.take(3)) ('${x['product_name'] ?? ''}', '${x['thumbnail'] ?? ''}')];
    final first = row.firstItem ?? (items.isNotEmpty ? '${items.first['product_name'] ?? ''}' : null);
    final count = row.itemCount ?? items.length;
    final qty = row.qty ?? items.fold<int>(0, (a, x) => a + ((x['quantity'] as num?) ?? 0).toInt());
    final name = row.customer.isNotEmpty ? row.customer : '${detail?['recipient_name'] ?? ''}';
    final pay = !row.isReturn && !row.cancelled ? payTag(row.ledgerStatus, short: true) : null;
    final st = proc == null ? null : statusTag(proc);
    final t = row.createdAt;
    final hm = '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(borderRadius: BorderRadius.circular(14), boxShadow: OrderColors.cardShadow),
      child: Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          key: Key('order_${row.isReturn ? 'tra' : 'don'}_${row.id}'),
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      width: 26,
                      height: 26,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(8)),
                      child: AppIcon(
                        row.isReturn
                            ? AppIcons.returns
                            : row.channel == 'web'
                            ? AppIcons.globe
                            : AppIcons.store,
                        size: 15,
                        color: OrderColors.muted,
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      row.code,
                      style: const TextStyle(
                        color: OrderColors.primaryInk,
                        fontSize: 14.5,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const Spacer(),
                    Text(
                      '${row.isReturn ? '−' : ''}${dot(row.total)}đ',
                      style: TextStyle(
                        color: row.isReturn
                            ? OrderColors.purple.$2
                            : row.cancelled
                            ? OrderColors.faint
                            : OrderColors.ink,
                        decoration: row.cancelled ? TextDecoration.lineThrough : null,
                        fontSize: 15,
                        fontWeight: FontWeight.w700,
                        fontFeatures: _tnum,
                      ),
                    ),
                  ],
                ),
                Padding(
                  padding: const EdgeInsets.only(left: 34, top: 3),
                  child: Text(
                    '$hm · ${row.creator} · ${methodLabel(row.method)}',
                    style: const TextStyle(color: OrderColors.muted, fontSize: 12),
                  ),
                ),
                if (first != null && first.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(left: 34, top: 10),
                    child: Row(
                      children: [
                        SizedBox(
                          width: 34 + (math.max(1, names.length) - 1) * 24.0,
                          height: 34,
                          child: Stack(
                            children: [
                              for (var i = 0; i < math.max(1, names.length); i++)
                                Positioned(
                                  left: i * 24.0,
                                  child: ProductThumb(
                                    name: names.isEmpty ? first : names[i].$1,
                                    url: names.isEmpty ? '' : names[i].$2,
                                    border: Border.all(color: Colors.white, width: 2),
                                  ),
                                ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                first,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                  color: OrderColors.ink,
                                  fontSize: 13,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              Text(
                                '${count > 1 ? 'và ${count - 1} món khác · ' : ''}$qty sản phẩm',
                                style: const TextStyle(color: OrderColors.muted, fontSize: 12),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                Padding(
                  padding: const EdgeInsets.only(left: 34, top: 10),
                  child: Column(
                    children: [
                      const SizedBox(
                        height: 1,
                        width: double.infinity,
                        child: CustomPaint(painter: _Dashed()),
                      ),
                      const SizedBox(height: 10),
                      Row(
                        children: [
                          _MiniAvatar(name: name),
                          const SizedBox(width: 6),
                          Expanded(
                            child: Text(
                              name.isEmpty ? 'Khách lẻ' : name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                color: OrderColors.text,
                                fontSize: 12.5,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                          if (st != null) ...[const SizedBox(width: 6), Tag(st.$1, st.$2)],
                          if (pay != null) ...[const SizedBox(width: 6), Tag(pay.$1, pay.$2)],
                        ],
                      ),
                    ],
                  ),
                ),
                if (row.debt > 0 && !row.cancelled)
                  Container(
                    width: double.infinity,
                    margin: const EdgeInsets.only(left: 34, top: 8),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
                    decoration: BoxDecoration(color: OrderColors.orange.$1, borderRadius: BorderRadius.circular(8)),
                    child: Text(
                      'Còn nợ ${dot(row.debt)} đ',
                      style: TextStyle(
                        color: OrderColors.orange.$2,
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        fontFeatures: _tnum,
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _MiniAvatar extends StatelessWidget {
  const _MiniAvatar({required this.name});

  final String name;

  @override
  Widget build(BuildContext context) => Container(
    width: 20,
    height: 20,
    alignment: Alignment.center,
    decoration: BoxDecoration(shape: BoxShape.circle, color: name.isEmpty ? OrderColors.grid : OrderColors.blue.$1),
    child: name.isEmpty
        ? const AppIcon(AppIcons.user, size: 12, color: OrderColors.faint)
        : Text(
            initialsOf(name),
            style: TextStyle(color: OrderColors.blue.$2, fontSize: 9.5, fontWeight: FontWeight.w700),
          ),
  );
}

/// "Hôm nay, 18/10" · "Hôm qua, 17/10" · "Thứ năm, 16/10".
String dayLabel(DateTime d, DateTime now) {
  final a = DateTime(d.year, d.month, d.day), b = DateTime(now.year, now.month, now.day);
  final diff = b.difference(a).inDays;
  final dmy = '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}';
  const thu = ['Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy', 'Chủ nhật'];
  return diff == 0
      ? 'Hôm nay, $dmy'
      : diff == 1
      ? 'Hôm qua, $dmy'
      : '${thu[d.weekday - 1]}, $dmy';
}

class DayHeader extends StatelessWidget {
  const DayHeader({super.key, required this.label, required this.count});

  final String label;
  final int count;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(4, 14, 4, 8),
    child: Row(
      children: [
        Expanded(
          child: Text(
            label,
            style: const TextStyle(color: OrderColors.ink, fontSize: 12.5, fontWeight: FontWeight.w700),
          ),
        ),
        Text(
          '$count đơn',
          style: const TextStyle(color: OrderColors.muted, fontSize: 12, fontWeight: FontWeight.w600),
        ),
      ],
    ),
  );
}

/// Thẻ tổng: doanh thu kỳ + cột theo ngày + 3 chỉ số chạm được.
class OrdersSummaryCard extends StatelessWidget {
  const OrdersSummaryCard({super.key, required this.s, required this.timeLabel, required this.onKpi});

  final OrdersSummary s;
  final String timeLabel;

  /// 'unpaid' | 'cancelled' | 'returned'
  final ValueChanged<String> onKpi;

  @override
  Widget build(BuildContext context) {
    final mx = s.perDay.fold<num>(1, (a, b) => math.max(a, b));
    String dd(DateTime d) => '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}';
    Widget kpi(String key, String label, Color dotColor, String value, String unit, {bool first = false}) => Expanded(
      child: InkWell(
        key: Key('kpi_$key'),
        onTap: () => onKpi(key),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(
            border: first ? null : const Border(left: BorderSide(color: OrderColors.grid)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    width: 7,
                    height: 7,
                    decoration: BoxDecoration(color: dotColor, shape: BoxShape.circle),
                  ),
                  const SizedBox(width: 5),
                  Text(label, style: const TextStyle(color: OrderColors.muted, fontSize: 11.5)),
                ],
              ),
              const SizedBox(height: 2),
              Text(
                value,
                style: const TextStyle(
                  color: OrderColors.ink,
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                  fontFeatures: _tnum,
                ),
              ),
              Text(unit, style: const TextStyle(color: OrderColors.muted, fontSize: 11.5)),
            ],
          ),
        ),
      ),
    );

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        boxShadow: OrderColors.cardShadow,
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 14, 14, 12),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Doanh thu · ${timeLabel.toLowerCase()}',
                      style: const TextStyle(color: OrderColors.muted, fontSize: 12.5),
                    ),
                    Text.rich(
                      TextSpan(
                        text: compact(s.revenue),
                        children: [
                          TextSpan(
                            text: ' · ${s.sales} đơn',
                            style: const TextStyle(color: OrderColors.muted, fontSize: 13, fontWeight: FontWeight.w500),
                          ),
                        ],
                      ),
                      key: const Key('orders_revenue'),
                      style: const TextStyle(
                        color: OrderColors.ink,
                        fontSize: 22,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -.4,
                        height: 1.2,
                        fontFeatures: _tnum,
                      ),
                    ),
                  ],
                ),
                if (s.days.length > 1) ...[
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      children: [
                        SizedBox(
                          height: 46,
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              for (var i = 0; i < s.perDay.length; i++) ...[
                                if (i > 0) const SizedBox(width: 2),
                                Expanded(
                                  child: FractionallySizedBox(
                                    heightFactor: math.max(.04, s.perDay[i] / mx).toDouble(),
                                    child: Container(
                                      decoration: BoxDecoration(
                                        color: s.perDay[i] > 0 ? OrderColors.primary : const Color(0xFFDBE6F7),
                                        borderRadius: const BorderRadius.vertical(
                                          top: Radius.circular(2),
                                          bottom: Radius.circular(1),
                                        ),
                                      ),
                                    ),
                                  ),
                                ),
                              ],
                            ],
                          ),
                        ),
                        const SizedBox(height: 4),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text(dd(s.days.first), style: const TextStyle(color: OrderColors.faint, fontSize: 10.5)),
                            Text(dd(s.days.last), style: const TextStyle(color: OrderColors.faint, fontSize: 10.5)),
                          ],
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
          Container(
            decoration: const BoxDecoration(
              border: Border(top: BorderSide(color: OrderColors.grid)),
            ),
            child: IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  kpi('unpaid', 'Chưa thu', OrderColors.orange.$2, compact(s.debt), '${s.debtOrders} đơn', first: true),
                  kpi('cancelled', 'Đã huỷ', OrderColors.neutral.$2, '${s.cancelled}', 'đơn'),
                  kpi('returned', 'Trả hàng', OrderColors.purple.$2, '${s.returned}', 'phiếu'),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
