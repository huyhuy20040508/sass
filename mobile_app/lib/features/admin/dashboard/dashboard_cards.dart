import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../../app/theme.dart';
import '../../../core/format/vn_number.dart';
import '../../../core/ui/app_icon.dart';
import 'charts.dart';
import 'dashboard_data.dart';

const _tnum = [FontFeature.tabularFigures()];

class DashCard extends StatelessWidget {
  const DashCard({super.key, required this.child, this.padding = const EdgeInsets.all(16), this.radius = 18});

  final Widget child;
  final EdgeInsets padding;
  final double radius;

  @override
  Widget build(BuildContext context) => Container(
    padding: padding,
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(radius),
      boxShadow: DashColors.cardShadow,
    ),
    child: child,
  );
}

class SectionLabel extends StatelessWidget {
  const SectionLabel(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(4, 8, 4, 0),
    child: Text(
      text.toUpperCase(),
      style: const TextStyle(color: DashColors.muted, fontSize: 11.5, fontWeight: FontWeight.w700, letterSpacing: .9),
    ),
  );
}

class CardHead extends StatelessWidget {
  const CardHead(this.title, {super.key, this.meta, this.trailing});

  final String title;
  final String? meta;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: Row(
      children: [
        Expanded(
          child: Text(
            title,
            style: const TextStyle(color: DashColors.ink, fontSize: 14.5, fontWeight: FontWeight.w700),
          ),
        ),
        if (meta != null) Text(meta!, style: const TextStyle(color: DashColors.muted, fontSize: 12)),
        if (trailing != null) ...[const SizedBox(width: 8), trailing!],
      ],
    ),
  );
}

/// ▲ 12,5% / ▼ 3% / Mới / — so với kỳ trước.
class DeltaChip extends StatelessWidget {
  const DeltaChip(this.delta, {super.key});

  final Delta? delta;

  @override
  Widget build(BuildContext context) {
    final d = delta;
    final (String text, Color bg, Color fg) = d == null
        ? ('—', DashColors.page, DashColors.muted)
        : d.isNew
        ? ('Mới', DashColors.primarySoft, DashColors.primaryDeep)
        : d.pct >= 0
        ? ('▲ ${pct(d.pct.abs(), d.pct.abs() >= 100 ? 0 : 1)}', DashColors.upSoft, DashColors.up)
        : ('▼ ${pct(d.pct.abs(), d.pct.abs() >= 100 ? 0 : 1)}', DashColors.downSoft, DashColors.down);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(7)),
      child: Text(
        text,
        style: TextStyle(color: fg, fontSize: 12, fontWeight: FontWeight.w700, fontFeatures: _tnum),
      ),
    );
  }
}

class _Linkish extends StatelessWidget {
  const _Linkish(this.label, this.onTap);

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
    color: DashColors.page,
    borderRadius: BorderRadius.circular(9),
    child: InkWell(
      borderRadius: BorderRadius.circular(9),
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
        child: Text(
          label,
          style: const TextStyle(color: DashColors.primaryDeep, fontSize: 12, fontWeight: FontWeight.w600),
        ),
      ),
    ),
  );
}

class _Empty extends StatelessWidget {
  const _Empty(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => SizedBox(
    height: 110,
    child: Center(
      child: Text(
        text,
        textAlign: TextAlign.center,
        style: const TextStyle(color: DashColors.muted, fontSize: 13),
      ),
    ),
  );
}

class _Legend extends StatelessWidget {
  const _Legend(this.items);

  /// Màu null = ô chữ phụ không chấm màu (như "3 mã hàng" ở thẻ Tồn kho).
  final List<(String, Color?, num?)> items;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, c) => Wrap(
      spacing: 14,
      runSpacing: 8,
      children: [
        for (final (name, color, n) in items)
          SizedBox(
            width: (c.maxWidth - 14) / 2,
            child: Row(
              children: [
                if (color != null) ...[
                  Container(
                    width: 10,
                    height: 10,
                    decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(3)),
                  ),
                  const SizedBox(width: 8),
                ],
                Expanded(
                  child: Text(
                    name,
                    style: TextStyle(color: color == null ? DashColors.muted : DashColors.text, fontSize: 12.5),
                  ),
                ),
                if (n != null)
                  Text(
                    dot(n),
                    style: const TextStyle(
                      color: DashColors.ink,
                      fontSize: 12.5,
                      fontWeight: FontWeight.w700,
                      fontFeatures: _tnum,
                    ),
                  ),
              ],
            ),
          ),
      ],
    ),
  );
}

// ===================== Doanh thu thuần =====================

class RevenueCard extends StatelessWidget {
  const RevenueCard({super.key, required this.data, required this.onTable});

  final DashboardData data;
  final VoidCallback onTable;

  /// Chuỗi vẽ biểu đồ: theo giờ nếu kỳ 1 ngày, theo ngày nếu dài hơn.
  static (List<num>, List<num>, List<String>) series(DashboardData d) {
    if (d.oneDay) {
      final cur = d.hourly('revenue');
      return (cur, const [], [for (var i = 0; i < cur.length; i++) '${7 + i}h']);
    }
    final cur = [for (final b in d.buckets) Money.of(b).net];
    final prev = [for (final b in d.prevBuckets.take(cur.length)) Money.of(b).net];
    return (cur, prev, [for (final b in d.buckets) dm('${b['label']}')]);
  }

  @override
  Widget build(BuildContext context) {
    final nay = data.now$, truoc = data.prev$;
    final txt = compact(nay.net);
    final m = RegExp(r'^(.*) (tỷ|tr)$').firstMatch(txt);
    final (cur, prev, labels) = series(data);
    return DashCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Expanded(
                child: Text(
                  'Doanh thu thuần',
                  style: TextStyle(color: DashColors.muted, fontSize: 13, fontWeight: FontWeight.w500),
                ),
              ),
              _Linkish('Bảng số', onTable),
            ],
          ),
          const SizedBox(height: 2),
          Text.rich(
            TextSpan(
              text: m?.group(1) ?? txt,
              children: [
                if (m != null)
                  TextSpan(
                    text: ' ${m.group(2)}',
                    style: const TextStyle(
                      color: DashColors.muted,
                      fontSize: 22,
                      fontWeight: FontWeight.w600,
                      letterSpacing: -.2,
                    ),
                  ),
              ],
            ),
            key: const Key('hero_num'),
            style: const TextStyle(
              color: DashColors.ink,
              fontSize: 46,
              fontWeight: FontWeight.w700,
              letterSpacing: -1.6,
              height: 1.05,
              fontFeatures: _tnum,
            ),
          ),
          const SizedBox(height: 6),
          Wrap(
            spacing: 6,
            runSpacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              DeltaChip(Delta.of(nay.net, truoc.net)),
              Text(
                'kỳ trước ${compact(truoc.net)} · ${dot(nay.net)} đ',
                style: const TextStyle(color: DashColors.muted, fontSize: 12.5, fontFeatures: _tnum),
              ),
            ],
          ),
          const SizedBox(height: 12),
          RevenueChart(
            current: cur,
            previous: prev,
            labels: labels,
            emptyText: data.oneDay ? 'Chưa có đơn trong ngày' : 'Chưa có đơn trong kỳ',
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              _line(DashColors.s1),
              const Text('Kỳ này', style: TextStyle(color: DashColors.muted, fontSize: 12)),
              const SizedBox(width: 14),
              if (data.oneDay)
                const Text('theo giờ', style: TextStyle(color: DashColors.muted, fontSize: 12))
              else ...[
                _line(DashColors.seriesOther),
                const Text('Kỳ trước', style: TextStyle(color: DashColors.muted, fontSize: 12)),
              ],
            ],
          ),
        ],
      ),
    );
  }

  Widget _line(Color c) => Container(
    width: 14,
    height: 2,
    margin: const EdgeInsets.only(right: 6),
    decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(1)),
  );
}

/// Bảng số của biểu đồ doanh thu (ngăn kéo "Bảng số").
class RevenueTable extends StatelessWidget {
  const RevenueTable({super.key, required this.data});

  final DashboardData data;

  @override
  Widget build(BuildContext context) {
    const th = TextStyle(color: DashColors.muted, fontSize: 11.5, fontWeight: FontWeight.w600);
    const td = TextStyle(color: DashColors.ink, fontSize: 13, fontFeatures: _tnum);
    final rows = <List<String>>[];
    if (data.oneDay) {
      final h = data.hourly('revenue');
      for (var i = 0; i < h.length; i++) {
        rows.add(['${7 + i}h', dot(h[i])]);
      }
    } else {
      for (var i = 0; i < data.buckets.length; i++) {
        final b = data.buckets[i];
        final p = i < data.prevBuckets.length ? data.prevBuckets[i] : null;
        rows.add([
          dm('${b['label']}'),
          dot(Money.of(b).net),
          p == null ? '—' : dot(Money.of(p).net),
          '${b['orders'] ?? 0}',
        ]);
      }
    }
    final head = data.oneDay ? ['Giờ', 'Kỳ này'] : ['Ngày', 'Kỳ này', 'Kỳ trước', 'Đơn'];
    Widget cell(String s, TextStyle st, int col) => Padding(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 8),
      child: Text(
        s,
        textAlign: col == 0 ? TextAlign.left : TextAlign.right,
        style: col == 2 ? st.copyWith(color: DashColors.muted) : st,
      ),
    );
    return Table(
      columnWidths: const {0: IntrinsicColumnWidth()},
      children: [
        TableRow(
          decoration: const BoxDecoration(
            border: Border(bottom: BorderSide(color: DashColors.line)),
          ),
          children: [for (var i = 0; i < head.length; i++) cell(head[i], th, i == 2 ? -1 : i)],
        ),
        for (final r in rows)
          TableRow(
            decoration: const BoxDecoration(
              border: Border(bottom: BorderSide(color: DashColors.grid)),
            ),
            children: [for (var i = 0; i < r.length; i++) cell(r[i], td, i)],
          ),
      ],
    );
  }
}

// ===================== 4 ô số liệu =====================

class MetricTiles extends StatelessWidget {
  const MetricTiles({super.key, required this.data});

  final DashboardData data;

  @override
  Widget build(BuildContext context) {
    final nay = data.now$, truoc = data.prev$;
    final mua = data.purchases, muaT = data.purchasesPrev;
    final margin = nay.net > 0 ? nay.profit / nay.net * 100 : null;
    final aov = nay.orders > 0 ? nay.net / nay.orders : 0;
    final aovT = truoc.orders > 0 ? truoc.net / truoc.orders : 0;
    List<num>? sp(num Function(Map) f) => data.oneDay ? null : [for (final b in data.buckets) f(b)];

    final tiles = [
      (
        'Lợi nhuận gộp',
        compact(nay.profit),
        Delta.of(nay.profit, truoc.profit),
        margin != null ? 'biên ${pct(margin, 0)}' : null,
        sp((b) => (b['profit'] as num?) ?? 0),
      ),
      (
        'Đơn bán',
        dot(nay.orders),
        Delta.of(nay.orders, truoc.orders),
        null,
        data.oneDay ? data.hourly('orders') : sp((b) => (b['orders'] as num?) ?? 0),
      ),
      (
        'Giá trị TB / đơn',
        compact(aov),
        Delta.of(aov, aovT),
        null,
        sp((b) {
          final t = Money.of(b);
          return t.orders > 0 ? t.net / t.orders : 0;
        }),
      ),
      (
        'Tiền nhập hàng',
        compact(mua.total),
        Delta.of(mua.total, muaT.total),
        '${mua.count} phiếu',
        sp((b) => mua.byDay['${b['label']}'] ?? 0),
      ),
    ];
    return LayoutBuilder(
      builder: (context, c) {
        final w = (c.maxWidth - 10) / 2;
        return Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            for (final (label, value, delta, extra, spark) in tiles)
              SizedBox(
                width: w,
                child: DashCard(
                  radius: 16,
                  padding: const EdgeInsets.fromLTRB(13, 13, 13, 10),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        label,
                        style: const TextStyle(color: DashColors.muted, fontSize: 12, fontWeight: FontWeight.w500),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        value,
                        style: const TextStyle(
                          color: DashColors.ink,
                          fontSize: 21,
                          fontWeight: FontWeight.w700,
                          letterSpacing: -.4,
                          fontFeatures: _tnum,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Row(
                        children: [
                          DeltaChip(delta),
                          if (extra != null) ...[
                            const SizedBox(width: 6),
                            Flexible(
                              child: Text(
                                extra,
                                maxLines: 1,
                                overflow: TextOverflow.clip,
                                softWrap: false,
                                style: const TextStyle(color: DashColors.muted, fontSize: 11.5),
                              ),
                            ),
                          ],
                        ],
                      ),
                      const SizedBox(height: 8),
                      Sparkline(spark),
                    ],
                  ),
                ),
              ),
          ],
        );
      },
    );
  }
}

// ===================== Mục tiêu tháng =====================

class GoalCard extends StatelessWidget {
  const GoalCard({super.key, required this.data, required this.onSetGoal});

  final DashboardData data;
  final VoidCallback onSetGoal;

  @override
  Widget build(BuildContext context) {
    final now = data.now;
    final goal = data.setting('monthly_revenue_goal', 0);
    final dat = data.monthNet;
    final soNgay = DateTime(now.year, now.month + 1, 0).day;
    return DashCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CardHead('Mục tiêu tháng ${now.month}'),
          if (goal > 0)
            ...() {
              final p = dat / goal * 100, can = now.day / soNgay * 100, con = soNgay - now.day + 1;
              final kip = p >= can;
              return [
                Row(
                  children: [
                    Expanded(
                      child: Text.rich(
                        TextSpan(
                          children: [
                            TextSpan(
                              text: compact(dat),
                              style: const TextStyle(color: DashColors.ink, fontSize: 18, fontWeight: FontWeight.w700),
                            ),
                            TextSpan(text: ' / ${compact(goal)}'),
                          ],
                        ),
                        style: const TextStyle(color: DashColors.muted, fontSize: 12),
                      ),
                    ),
                    Text(
                      pct(p, 0),
                      style: TextStyle(
                        color: kip ? DashColors.up : DashColors.down,
                        fontSize: 18,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Semantics(
                  label: 'Đạt ${p.round()}% mục tiêu',
                  child: SizedBox(
                    height: 18,
                    child: LayoutBuilder(
                      builder: (context, c) => Stack(
                        clipBehavior: Clip.none,
                        children: [
                          Positioned(
                            left: 0,
                            right: 0,
                            top: 4,
                            height: 10,
                            child: Container(
                              decoration: BoxDecoration(
                                color: DashColors.s1Soft,
                                borderRadius: BorderRadius.circular(5),
                              ),
                            ),
                          ),
                          Positioned(
                            left: 0,
                            top: 4,
                            height: 10,
                            width: c.maxWidth * math.min(100, p) / 100,
                            child: Container(
                              decoration: BoxDecoration(color: DashColors.s1, borderRadius: BorderRadius.circular(5)),
                            ),
                          ),
                          Positioned(
                            left: c.maxWidth * can / 100 - 1,
                            top: 0,
                            bottom: 0,
                            width: 2,
                            child: Container(
                              decoration: BoxDecoration(color: DashColors.ink, borderRadius: BorderRadius.circular(1)),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 4),
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        '${kip ? 'Vượt nhịp' : 'Chậm nhịp'} · vạch đen: lẽ ra đạt ${pct(can, 0)}',
                        style: const TextStyle(color: DashColors.muted, fontSize: 12),
                      ),
                    ),
                    Text(
                      'còn $con ngày · cần ${compact(math.max(0, goal - dat) / con)}/ngày',
                      style: const TextStyle(color: DashColors.muted, fontSize: 12),
                    ),
                  ],
                ),
              ];
            }()
          else
            Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: DashColors.primarySoft, borderRadius: BorderRadius.circular(12)),
                  child: const AppIcon(AppIcons.target, color: DashColors.primaryDeep),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text.rich(
                    TextSpan(
                      children: [
                        const TextSpan(
                          text: 'Chưa đặt mục tiêu\n',
                          style: TextStyle(color: DashColors.ink, fontSize: 14, fontWeight: FontWeight.w700),
                        ),
                        TextSpan(text: 'Tháng này đã đạt ${compact(dat)} thuần.'),
                      ],
                    ),
                    style: const TextStyle(color: DashColors.muted, fontSize: 12.5, height: 1.4),
                  ),
                ),
                const SizedBox(width: 12),
                FilledButton(
                  onPressed: onSetGoal,
                  style: FilledButton.styleFrom(
                    backgroundColor: DashColors.primary,
                    minimumSize: const Size(0, 36),
                    padding: const EdgeInsets.symmetric(horizontal: 14),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    textStyle: const TextStyle(fontFamily: appFont, fontSize: 13, fontWeight: FontWeight.w600),
                  ),
                  child: const Text('Đặt mục tiêu'),
                ),
              ],
            ),
        ],
      ),
    );
  }
}

// ===================== Cần chú ý =====================

class AlertsCard extends StatelessWidget {
  const AlertsCard({super.key, required this.alerts, required this.onTap});

  final List<DashAlert> alerts;
  final ValueChanged<DashAlert> onTap;

  static AppIcons iconOf(AlertKind k) => switch (k) {
    AlertKind.shift => AppIcons.clock,
    AlertKind.stock => AppIcons.box,
    AlertKind.einvoice => AppIcons.doc,
    AlertKind.unpaid => AppIcons.wallet,
    AlertKind.cashDiff => AppIcons.cash,
  };

  @override
  Widget build(BuildContext context) {
    return DashCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          CardHead('Cần chú ý', meta: alerts.isEmpty ? null : '${alerts.length} việc'),
          if (alerts.isEmpty)
            const Row(
              children: [
                AppIcon(AppIcons.check, color: DashColors.up),
                SizedBox(width: 10),
                Text(
                  'Không có gì cần xử lý',
                  style: TextStyle(color: DashColors.up, fontSize: 13.5, fontWeight: FontWeight.w600),
                ),
              ],
            )
          else
            for (var i = 0; i < alerts.length; i++)
              DecoratedBox(
                decoration: BoxDecoration(
                  border: i > 0 ? const Border(top: BorderSide(color: DashColors.grid)) : null,
                ),
                child: InkWell(
                  borderRadius: BorderRadius.circular(12),
                  onTap: () => onTap(alerts[i]),
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(minHeight: 48),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6),
                      child: Row(
                        children: [
                          _alertIcon(alerts[i]),
                          const SizedBox(width: 12),
                          Expanded(child: _alertText(alerts[i])),
                          const AppIcon(AppIcons.right, size: 16, color: DashColors.faint),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
        ],
      ),
    );
  }

  Widget _alertIcon(DashAlert a) {
    final (Color bg, Color fg) = switch (a.level) {
      AlertLevel.critical => (const Color(0xFFFBE9E9), DashColors.critical),
      AlertLevel.warning => (const Color(0xFFFFF4D9), const Color(0xFF9A6400)),
      AlertLevel.info => (DashColors.primarySoft, DashColors.primaryDeep),
    };
    return Container(
      width: 32,
      height: 32,
      alignment: Alignment.center,
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(10)),
      child: AppIcon(iconOf(a.kind), size: 17, color: fg),
    );
  }

  Widget _alertText(DashAlert a) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text.rich(
        TextSpan(
          children: [
            for (var i = 0; i < a.parts.length; i++)
              TextSpan(
                text: a.parts[i],
                style: i.isOdd ? const TextStyle(color: DashColors.ink, fontWeight: FontWeight.w700) : null,
              ),
          ],
        ),
        style: const TextStyle(color: DashColors.text, fontSize: 13.5, height: 1.35),
      ),
      if (a.sub.isNotEmpty) Text(a.sub, style: const TextStyle(color: DashColors.muted, fontSize: 12)),
    ],
  );
}

// ===================== Trạng thái đơn =====================

class StatusCard extends StatelessWidget {
  const StatusCard({super.key, required this.data});

  final DashboardData data;

  static const groups = [
    (['completed', 'delivered'], 'Hoàn thành', DashColors.good),
    (['pending', 'confirmed', 'processing', 'shipping'], 'Đang xử lý', DashColors.warning),
    (['returned'], 'Trả hàng', DashColors.serious),
    (['cancelled'], 'Đã huỷ', DashColors.critical),
  ];

  @override
  Widget build(BuildContext context) {
    final bs = data.byStatus;
    final parts = [
      for (final (keys, name, color) in groups) (name, color, keys.fold<int>(0, (a, k) => a + (bs[k] ?? 0))),
    ];
    final tong = parts.fold<int>(0, (a, p) => a + p.$3);
    final t = data.orderTotals;
    return DashCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const CardHead('Trạng thái đơn'),
          if (tong == 0)
            const _Empty('Chưa có đơn trong kỳ')
          else ...[
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.baseline,
                textBaseline: TextBaseline.alphabetic,
                children: [
                  Text(
                    pct((t['dead_rate'] as num?) ?? 0, 0),
                    style: const TextStyle(
                      color: DashColors.ink,
                      fontSize: 26,
                      fontWeight: FontWeight.w700,
                      letterSpacing: -.5,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'đơn huỷ / trả · ${t['cancelled'] ?? 0} huỷ, ${t['returned'] ?? 0} trả trên ${t['total'] ?? 0} đơn',
                      style: const TextStyle(color: DashColors.muted, fontSize: 12.5),
                    ),
                  ),
                ],
              ),
            ),
            StackBar([for (final p in parts) (p.$3, p.$2)]),
            const SizedBox(height: 12),
            _Legend([for (final p in parts) (p.$1, p.$2, p.$3)]),
          ],
        ],
      ),
    );
  }
}

// ===================== Cơ cấu doanh thu =====================

enum MixKind { pay, src, shop, promo }

class MixCard extends StatefulWidget {
  const MixCard({super.key, required this.data});

  final DashboardData data;

  @override
  State<MixCard> createState() => _MixCardState();
}

class _MixCardState extends State<MixCard> {
  MixKind _kind = MixKind.pay;

  static const _pay = {
    'cash': 'Tiền mặt',
    'bank_transfer': 'Chuyển khoản',
    'card': 'Quẹt thẻ',
    'sepay': 'QR tự động',
    'payos': 'QR tự động',
    'mpos_qr': 'QR tự động',
  };
  static const _src = {'web': 'Đơn giao hàng', 'pos': 'Bán tại quầy'};

  List<(String, num, int, bool)> _rows() {
    final d = widget.data;
    var list = <(String, num, int, bool)>[];
    List<Map> rows(Map src, String k) => ((src[k] as List?) ?? const []).whereType<Map>().toList();
    num v(Map r, String k) => (r[k] as num?) ?? 0;
    switch (_kind) {
      case MixKind.pay:
        final gop = <String, (num, int)>{};
        for (final r in rows(d.revenue, 'by_payment_method')) {
          final t = _pay[r['key']] ?? 'Khác';
          final o = gop[t] ?? (0, 0);
          gop[t] = (o.$1 + v(r, 'revenue'), o.$2 + v(r, 'orders').toInt());
        }
        list = [for (final e in gop.entries) (e.key, e.value.$1, e.value.$2, false)];
      case MixKind.src:
        list = [
          for (final r in rows(d.orders, 'by_source'))
            if (v(r, 'orders') > 0) (_src[r['key']] ?? '${r['key']}', v(r, 'revenue'), v(r, 'orders').toInt(), false),
        ];
      case MixKind.shop:
        list = [
          for (final r in rows(d.revenue, 'by_shop')) ('${r['label']}', v(r, 'revenue'), v(r, 'orders').toInt(), false),
        ];
      case MixKind.promo:
        for (final tab in d.promotions.values) {
          for (final r in tab) {
            if (v(r, 'order_count') > 0) {
              list.add(('${r['name']}', v(r, 'discount'), v(r, 'order_count').toInt(), false));
            }
          }
        }
    }
    list.sort((a, b) => b.$2.compareTo(a.$2));
    // Quá 5 phần thì gộp "Khác" — vòng tròn chỉ đọc được ≤ 6 phần.
    if (list.length > 5) {
      final rest = list.sublist(4);
      list = [
        ...list.take(4),
        ('Khác', rest.fold<num>(0, (a, r) => a + r.$2), rest.fold<int>(0, (a, r) => a + r.$3), true),
      ];
    }
    return list;
  }

  @override
  Widget build(BuildContext context) {
    final list = _rows();
    final tong = list.fold<num>(0, (a, r) => a + r.$2);
    final what = switch (_kind) {
      MixKind.pay || MixKind.src => 'Tiền khách trả (gồm VAT)',
      MixKind.shop => 'Tiền hàng',
      MixKind.promo => 'Tiền giảm',
    };
    Color colorOf(int i, bool other) => other ? DashColors.seriesOther : DashColors.series[i % 5];
    return DashCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const CardHead('Cơ cấu doanh thu'),
          Container(
            margin: const EdgeInsets.only(bottom: 14),
            padding: const EdgeInsets.all(3),
            decoration: BoxDecoration(color: DashColors.page, borderRadius: BorderRadius.circular(11)),
            child: Row(
              children: [
                for (final (k, name) in const [
                  (MixKind.pay, 'Thanh toán'),
                  (MixKind.src, 'Kênh'),
                  (MixKind.shop, 'Chi nhánh'),
                  (MixKind.promo, 'Khuyến mại'),
                ])
                  Expanded(
                    child: GestureDetector(
                      onTap: () => setState(() => _kind = k),
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 150),
                        height: 30,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: _kind == k ? Colors.white : Colors.transparent,
                          borderRadius: BorderRadius.circular(8),
                          boxShadow: _kind == k
                              ? const [BoxShadow(color: Color(0x1A0F172A), blurRadius: 3, offset: Offset(0, 1))]
                              : null,
                        ),
                        child: Text(
                          name,
                          maxLines: 1,
                          style: TextStyle(
                            color: _kind == k ? DashColors.navy : DashColors.muted,
                            fontSize: 12,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
          if (list.isEmpty || tong <= 0)
            const _Empty('Chưa có số liệu trong kỳ')
          else ...[
            Row(
              children: [
                Donut(
                  parts: [for (var i = 0; i < list.length; i++) (list[i].$2, colorOf(i, list[i].$4))],
                  center: compact(tong),
                  caption: _kind == MixKind.promo ? 'tiền giảm' : 'tổng',
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    children: [
                      for (var i = 0; i < list.length; i++)
                        Container(
                          padding: const EdgeInsets.symmetric(vertical: 6),
                          decoration: BoxDecoration(
                            border: i > 0 ? const Border(top: BorderSide(color: DashColors.grid)) : null,
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                children: [
                                  Container(
                                    width: 10,
                                    height: 10,
                                    decoration: BoxDecoration(
                                      color: colorOf(i, list[i].$4),
                                      borderRadius: BorderRadius.circular(3),
                                    ),
                                  ),
                                  const SizedBox(width: 8),
                                  Expanded(
                                    child: Text(
                                      list[i].$1,
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(color: DashColors.text, fontSize: 12.5),
                                    ),
                                  ),
                                  Text(
                                    pct(list[i].$2 / tong * 100, 0),
                                    style: const TextStyle(
                                      color: DashColors.ink,
                                      fontSize: 12.5,
                                      fontWeight: FontWeight.w700,
                                      fontFeatures: _tnum,
                                    ),
                                  ),
                                ],
                              ),
                              Padding(
                                padding: const EdgeInsets.only(left: 18),
                                child: Text(
                                  '${compact(list[i].$2)} · ${list[i].$3}${_kind == MixKind.promo ? ' lượt' : ' đơn'}',
                                  style: const TextStyle(color: DashColors.muted, fontSize: 11.5, fontFeatures: _tnum),
                                ),
                              ),
                            ],
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Text.rich(
              TextSpan(
                text: '$what: ',
                children: [
                  TextSpan(
                    text: '${dot(tong)} đ',
                    style: const TextStyle(color: DashColors.ink, fontWeight: FontWeight.w700),
                  ),
                ],
              ),
              style: const TextStyle(color: DashColors.muted, fontSize: 12),
            ),
          ],
        ],
      ),
    );
  }
}

// ===================== Giờ cao điểm =====================

class HoursCard extends StatelessWidget {
  const HoursCard({super.key, required this.data});

  final DashboardData data;

  static const _ramp = [
    Color(0xFFF1F5FB),
    Color(0xFFCDE2FB),
    Color(0xFF9EC5F4),
    Color(0xFF6DA7EC),
    Color(0xFF3987E5),
    Color(0xFF256ABF),
  ];

  @override
  Widget build(BuildContext context) {
    final byH = <int, int>{};
    for (final r in ((data.orders['by_hour'] as List?) ?? const []).whereType<Map>()) {
      byH[int.tryParse('${r['key']}') ?? -1] = ((r['orders'] as num?) ?? 0).toInt();
    }
    final co = byH.entries.where((e) => e.value > 0).map((e) => e.key).toList();
    return DashCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const CardHead('Giờ cao điểm'),
          if (co.isEmpty)
            const _Empty('Chưa có đơn trong kỳ')
          else
            ...() {
              final dau = [7, ...co].reduce(math.min), cuoi = [22, ...co].reduce(math.max);
              final dinh = co.reduce((a, b) => byH[b]! > byH[a]! ? b : a);
              final byW = <int, int>{};
              for (final r in ((data.orders['by_weekday'] as List?) ?? const []).whereType<Map>()) {
                byW[int.tryParse('${r['key']}') ?? 0] = ((r['orders'] as num?) ?? 0).toInt();
              }
              final mW = [1, ...byW.values].reduce(math.max);
              return [
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text.rich(
                    TextSpan(
                      text: 'Đông nhất ',
                      children: [
                        TextSpan(
                          text: '${dinh}h – ${dinh + 1}h',
                          style: const TextStyle(color: DashColors.ink, fontWeight: FontWeight.w700),
                        ),
                        TextSpan(text: ' · ${byH[dinh]} đơn'),
                      ],
                    ),
                    style: const TextStyle(color: DashColors.muted, fontSize: 12.5),
                  ),
                ),
                HourBars(byHour: byH, first: dau, last: cuoi, peak: dinh),
                if (!data.oneDay) ...[
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      for (final (i, nm) in const ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'].indexed) ...[
                        if (i > 0) const SizedBox(width: 4),
                        Expanded(
                          child: Column(
                            children: [
                              Container(
                                height: 26,
                                decoration: BoxDecoration(
                                  color: _ramp[(byW[i + 1] ?? 0) == 0 ? 0 : 1 + ((byW[i + 1]! / mW) * 4).round()],
                                  borderRadius: BorderRadius.circular(6),
                                ),
                              ),
                              const SizedBox(height: 4),
                              Text(nm, style: const TextStyle(color: DashColors.muted, fontSize: 11)),
                              Text(
                                '${byW[i + 1] ?? 0}',
                                style: const TextStyle(
                                  color: DashColors.ink,
                                  fontSize: 12,
                                  fontWeight: FontWeight.w700,
                                  fontFeatures: _tnum,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ],
                  ),
                ],
              ];
            }(),
        ],
      ),
    );
  }
}

// ===================== Bán chạy =====================

class TopProductsCard extends StatefulWidget {
  const TopProductsCard({super.key, required this.data});

  final DashboardData data;

  @override
  State<TopProductsCard> createState() => _TopProductsCardState();
}

class _TopProductsCardState extends State<TopProductsCard> {
  bool _byRevenue = false;

  @override
  Widget build(BuildContext context) {
    var items = ((widget.data.products['items'] as List?) ?? const [])
        .whereType<Map>()
        .where((x) => ((x['units'] as num?) ?? 0) > 0)
        .toList();
    num v(Map x) => ((_byRevenue ? x['revenue'] : x['units']) as num?) ?? 0;
    if (_byRevenue) items.sort((a, b) => v(b).compareTo(v(a)));
    items = items.take(5).toList();
    final mx = items.isEmpty ? 1 : items.map(v).reduce(math.max);

    return DashCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CardHead(
            'Bán chạy',
            trailing: Container(
              padding: const EdgeInsets.all(2),
              decoration: BoxDecoration(color: DashColors.page, borderRadius: BorderRadius.circular(9)),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [_toggle('Số lượng', !_byRevenue, false), _toggle('Doanh thu', _byRevenue, true)],
              ),
            ),
          ),
          if (items.isEmpty)
            const _Empty('Chưa bán được mặt hàng nào')
          else
            for (var i = 0; i < items.length; i++)
              Container(
                padding: const EdgeInsets.symmetric(vertical: 8),
                decoration: BoxDecoration(
                  border: i > 0 ? const Border(top: BorderSide(color: DashColors.grid)) : null,
                ),
                child: Column(
                  children: [
                    Row(
                      children: [
                        _thumb(items[i]),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            '${items[i]['name'] ?? ''}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(color: DashColors.ink, fontSize: 13, fontWeight: FontWeight.w600),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Text(
                          _byRevenue ? compact(v(items[i])) : dot(v(items[i])),
                          style: const TextStyle(
                            color: DashColors.ink,
                            fontSize: 13,
                            fontWeight: FontWeight.w700,
                            fontFeatures: _tnum,
                          ),
                        ),
                      ],
                    ),
                    Padding(
                      padding: const EdgeInsets.only(left: 44, top: 6),
                      child: LayoutBuilder(
                        builder: (context, c) => Row(
                          children: [
                            Container(
                              width: math.max(2, (c.maxWidth - 70) * v(items[i]) / mx),
                              height: 8,
                              decoration: const BoxDecoration(
                                color: DashColors.s1,
                                borderRadius: BorderRadius.horizontal(right: Radius.circular(4)),
                              ),
                            ),
                            const SizedBox(width: 8),
                            Text(
                              _byRevenue
                                  ? '${dot((items[i]['units'] as num?) ?? 0)} cái'
                                  : compact((items[i]['revenue'] as num?) ?? 0),
                              style: const TextStyle(color: DashColors.muted, fontSize: 11.5),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
        ],
      ),
    );
  }

  Widget _toggle(String label, bool on, bool revenue) => GestureDetector(
    onTap: () => setState(() => _byRevenue = revenue),
    child: Container(
      height: 26,
      padding: const EdgeInsets.symmetric(horizontal: 9),
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: on ? Colors.white : Colors.transparent,
        borderRadius: BorderRadius.circular(7),
        boxShadow: on ? const [BoxShadow(color: Color(0x1A0F172A), blurRadius: 2, offset: Offset(0, 1))] : null,
      ),
      child: Text(
        label,
        style: TextStyle(color: on ? DashColors.navy : DashColors.muted, fontSize: 12, fontWeight: FontWeight.w600),
      ),
    ),
  );

  Widget _thumb(Map x) {
    final name = '${x['name'] ?? '?'}';
    final url = '${x['thumbnail'] ?? ''}';
    final letter = Center(
      child: Text(
        name.isEmpty ? '?' : name[0].toUpperCase(),
        style: const TextStyle(color: DashColors.primaryDeep, fontSize: 13, fontWeight: FontWeight.w700),
      ),
    );
    return Container(
      width: 34,
      height: 34,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(color: DashColors.primarySoft, borderRadius: BorderRadius.circular(9)),
      child: url.startsWith('http') ? Image.network(url, fit: BoxFit.cover, errorBuilder: (_, _, _) => letter) : letter,
    );
  }
}

// ===================== Tồn kho =====================

class StockCard extends StatelessWidget {
  const StockCard({super.key, required this.data});

  final DashboardData data;

  @override
  Widget build(BuildContext context) {
    final st = data.inventory;
    int n(String k) => ((st[k] as num?) ?? 0).toInt();
    final con = math.max(0, n('in_stock') - n('low_stock'));
    final thieuVon = n('missing_cost_in_stock');
    return DashCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const CardHead('Tồn kho', meta: 'toàn kho'),
          const Text('Giá trị tồn', style: TextStyle(color: DashColors.muted, fontSize: 12)),
          Text(
            '${dot((st['stock_value'] as num?) ?? 0)} đ',
            style: const TextStyle(
              color: DashColors.ink,
              fontSize: 22,
              fontWeight: FontWeight.w700,
              letterSpacing: -.4,
              fontFeatures: _tnum,
            ),
          ),
          const SizedBox(height: 12),
          StackBar([
            (con, DashColors.s1),
            (n('low_stock'), DashColors.warning),
            (n('out_of_stock'), DashColors.critical),
          ]),
          const SizedBox(height: 12),
          _Legend([
            ('Còn hàng', DashColors.s1, con),
            ('Sắp hết', DashColors.warning, n('low_stock')),
            ('Hết hàng', DashColors.critical, n('out_of_stock')),
            ('${n('in_stock') + n('out_of_stock')} mã hàng', null, null),
          ]),
          if (thieuVon > 0)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Text(
                '$thieuVon mặt hàng còn tồn chưa có giá vốn — giá trị tồn đang thiếu phần này.',
                style: const TextStyle(color: DashColors.muted, fontSize: 12, height: 1.45),
              ),
            ),
        ],
      ),
    );
  }
}
