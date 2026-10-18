import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../../app/theme.dart';
import '../../../core/format/vn_number.dart';

const _tick = TextStyle(fontFamily: appFont, color: DashColors.faint, fontSize: 10.5);
const _label = TextStyle(fontFamily: appFont, color: DashColors.ink, fontSize: 11, fontWeight: FontWeight.w700);

void _text(Canvas c, String s, Offset at, TextStyle st, {TextAlign align = TextAlign.left}) {
  final tp = TextPainter(
    text: TextSpan(text: s, style: st),
    textDirection: TextDirection.ltr,
  )..layout();
  final dx = switch (align) {
    TextAlign.right || TextAlign.end => -tp.width,
    TextAlign.center => -tp.width / 2,
    _ => 0.0,
  };
  tp.paint(c, at + Offset(dx, -tp.height / 2));
}

/// Vạch trục "tròn số": 3 vạch, bước 1/2/2,5/5/10 × 10ⁿ — đọc nhanh hơn số lẻ.
(double step, double max) niceTicks(double raw) {
  final r = math.max(raw, 1.0);
  final base = math.pow(10, (math.log(r / 3) / math.ln10).floor()).toDouble();
  final step = [1, 2, 2.5, 5, 10].map((k) => k * base).firstWhere((s) => s * 3 >= r, orElse: () => base * 10);
  return (step, step * 3);
}

/// Doanh thu thuần: vùng xanh kỳ này, đường xám kỳ trước, trục số trái, điểm cao nhất có ghi số;
/// chạm/kéo để xem từng mốc.
class RevenueChart extends StatefulWidget {
  const RevenueChart({
    super.key,
    required this.current,
    required this.previous,
    required this.labels,
    required this.emptyText,
  });

  final List<num> current;
  final List<num> previous;
  final List<String> labels;
  final String emptyText;

  static const height = 150.0;

  @override
  State<RevenueChart> createState() => _RevenueChartState();
}

class _RevenueChartState extends State<RevenueChart> {
  int? _sel;
  Timer? _hide;

  @override
  void dispose() {
    _hide?.cancel();
    super.dispose();
  }

  void _pick(Offset p, double w) {
    final n = widget.current.length;
    if (n == 0) return;
    final g = _RevenueGeom(w, n);
    final i = n == 1 ? 0 : ((p.dx - g.padL - 6) / g.stepX).round().clamp(0, n - 1);
    setState(() => _sel = i);
    _hide?.cancel();
    _hide = Timer(const Duration(milliseconds: 2500), () {
      if (mounted) setState(() => _sel = null);
    });
  }

  @override
  Widget build(BuildContext context) {
    final empty = !widget.current.any((v) => v > 0);
    return LayoutBuilder(
      builder: (context, box) {
        final w = box.maxWidth;
        final g = _RevenueGeom(w, widget.current.length);
        final i = _sel;
        return GestureDetector(
          behavior: HitTestBehavior.opaque,
          onPanDown: (d) => _pick(d.localPosition, w),
          onPanUpdate: (d) => _pick(d.localPosition, w),
          child: SizedBox(
            height: RevenueChart.height,
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                Positioned.fill(
                  child: CustomPaint(painter: _RevenuePainter(widget.current, widget.previous, widget.labels, i)),
                ),
                if (empty)
                  Positioned(
                    left: g.padL,
                    right: 0,
                    top: 0,
                    bottom: 18,
                    child: Center(
                      child: Text(widget.emptyText, style: const TextStyle(color: DashColors.muted, fontSize: 13)),
                    ),
                  ),
                if (i != null && !empty)
                  Positioned(
                    top: -6,
                    left: (g.x(i) - 70).clamp(0, math.max(0, w - 140)).toDouble(),
                    width: 140,
                    child: Center(
                      child: _Tip(
                        label: widget.labels[i],
                        cur: widget.current[i],
                        prev: i < widget.previous.length ? widget.previous[i] : null,
                      ),
                    ),
                  ),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _Tip extends StatelessWidget {
  const _Tip({required this.label, required this.cur, required this.prev});

  final String label;
  final num cur;
  final num? prev;

  @override
  Widget build(BuildContext context) {
    Widget row(Color c, String t) => Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 8,
          height: 8,
          decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(2)),
        ),
        const SizedBox(width: 5),
        Text(t),
      ],
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
      decoration: BoxDecoration(
        color: DashColors.ink,
        borderRadius: BorderRadius.circular(10),
        boxShadow: const [BoxShadow(color: Color(0x400F172A), offset: Offset(0, 6), blurRadius: 16)],
      ),
      child: DefaultTextStyle.merge(
        style: const TextStyle(color: Colors.white, fontSize: 12, height: 1.4),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: const TextStyle(fontWeight: FontWeight.w700)),
            row(DashColors.s1, '${dot(cur)} đ'),
            if (prev != null) row(DashColors.seriesOther, 'kỳ trước ${dot(prev!)} đ'),
          ],
        ),
      ),
    );
  }
}

class _RevenueGeom {
  _RevenueGeom(this.w, this.n);

  final double w;
  final int n;
  final padL = 34.0, padB = 20.0, padT = 8.0, h = RevenueChart.height;

  double get stepX => n <= 1 ? 0 : (w - padL - 12) / (n - 1);
  double x(int i) => n == 1 ? padL + (w - padL) / 2 : padL + 6 + i * stepX;
}

class _RevenuePainter extends CustomPainter {
  _RevenuePainter(this.cur, this.prev, this.labels, this.sel);

  final List<num> cur;
  final List<num> prev;
  final List<String> labels;
  final int? sel;

  @override
  void paint(Canvas c, Size size) {
    final n = cur.length;
    if (n == 0) return;
    final g = _RevenueGeom(size.width, n);
    final raw = [...cur, ...prev, 1].map((e) => e.toDouble()).reduce(math.max);
    final (step, max) = niceTicks(raw);
    double y(num v) => g.padT + (g.h - g.padT - g.padB) * (1 - v / max);
    final empty = ![...cur, ...prev].any((v) => v > 0);

    // Kỳ trống chỉ vẽ đường đáy — vạch 1, 2, 3 đồng của trục rỗng là số vô nghĩa.
    for (var t = 0; t <= (empty ? 0 : 3); t++) {
      final yy = y(step * t);
      c.drawLine(
        Offset(g.padL, yy),
        Offset(size.width, yy),
        Paint()..color = t == 0 ? const Color(0xFFDFE4EC) : DashColors.grid,
      );
      _text(
        c,
        t == 0 ? '0' : compact(step * t).replaceAll(' ', ''),
        Offset(g.padL - 6, yy),
        _tick,
        align: TextAlign.right,
      );
    }

    if (prev.length > 1) {
      final p = Path();
      for (var i = 0; i < math.min(prev.length, n); i++) {
        final o = Offset(g.x(i), y(prev[i]));
        i == 0 ? p.moveTo(o.dx, o.dy) : p.lineTo(o.dx, o.dy);
      }
      c.drawPath(
        p,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.5
          ..strokeJoin = StrokeJoin.round
          ..color = DashColors.seriesOther,
      );
    }

    final line = Path();
    for (var i = 0; i < n; i++) {
      final o = Offset(g.x(i), y(cur[i]));
      i == 0 ? line.moveTo(o.dx, o.dy) : line.lineTo(o.dx, o.dy);
    }
    final area = Path.from(line)
      ..lineTo(g.x(n - 1), y(0))
      ..lineTo(g.x(0), y(0))
      ..close();
    final top = g.padT, bottom = y(0);
    c.drawPath(
      area,
      Paint()
        ..shader = const LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Color(0x382A78D6), Color(0x002A78D6)],
        ).createShader(Rect.fromLTRB(0, top, size.width, bottom)),
    );
    c.drawPath(
      line,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeJoin = StrokeJoin.round
        ..strokeCap = StrokeCap.round
        ..color = DashColors.s1,
    );

    // Nhãn trục ngang: đầu, giữa, cuối.
    final marks = {0, (n - 1) ~/ 2, n - 1}.toList();
    for (var k = 0; k < marks.length; k++) {
      final i = marks[k];
      final align = marks.length == 1
          ? TextAlign.center
          : k == 0
          ? TextAlign.left
          : k == marks.length - 1
          ? TextAlign.right
          : TextAlign.center;
      _text(c, labels[i], Offset(g.x(i), g.h - 9), _tick, align: align);
    }

    // Điểm cao nhất được ghi số — nhãn chọn lọc, không ghi mọi điểm.
    var peak = 0;
    for (var i = 1; i < n; i++) {
      if (cur[i] > cur[peak]) peak = i;
    }
    if (cur[peak] > 0) {
      final o = Offset(g.x(peak), y(cur[peak]));
      _dot(c, o);
      final align = o.dx > size.width - 40
          ? TextAlign.right
          : o.dx < g.padL + 40
          ? TextAlign.left
          : TextAlign.center;
      _text(c, compact(cur[peak]), Offset(o.dx.clamp(g.padL + 4, size.width - 4), o.dy - 14), _label, align: align);
    }

    if (sel != null && !empty) {
      final x = g.x(sel!);
      c.drawLine(Offset(x, g.padT), Offset(x, g.h - g.padB), Paint()..color = DashColors.faint);
      _dot(c, Offset(x, y(cur[sel!])), r: 5);
    }
  }

  void _dot(Canvas c, Offset o, {double r = 4}) {
    c.drawCircle(o, r + 2, Paint()..color = Colors.white);
    c.drawCircle(o, r, Paint()..color = DashColors.s1);
  }

  @override
  bool shouldRepaint(_RevenuePainter old) =>
      old.sel != sel || old.cur != cur || old.prev != prev || old.labels != labels;
}

/// Đường xu hướng nhỏ trong ô số liệu: một màu nhạt, vùng dưới tô rất nhẹ.
class Sparkline extends StatelessWidget {
  const Sparkline(this.values, {super.key});

  final List<num>? values;

  @override
  Widget build(BuildContext context) => SizedBox(
    height: 30,
    width: double.infinity,
    child: CustomPaint(painter: _SparkPainter(values)),
  );
}

class _SparkPainter extends CustomPainter {
  _SparkPainter(this.v);

  final List<num>? v;

  @override
  void paint(Canvas c, Size s) {
    final vals = v;
    if (vals == null || vals.length < 2 || !vals.any((e) => e != 0)) {
      c.drawLine(
        Offset(0, s.height * .9),
        Offset(s.width, s.height * .9),
        Paint()
          ..color = DashColors.line
          ..strokeWidth = 2,
      );
      return;
    }
    final mx = vals.map((e) => e.toDouble()).reduce(math.max);
    final pts = [
      for (var i = 0; i < vals.length; i++)
        Offset(i / (vals.length - 1) * s.width, s.height * (27 - (mx > 0 ? vals[i] / mx : 0) * 24) / 30),
    ];
    final line = Path()..addPolygon(pts, false);
    final area = Path.from(line)
      ..lineTo(s.width, s.height)
      ..lineTo(0, s.height)
      ..close();
    c.drawPath(area, Paint()..color = const Color(0xFFE8F1FC));
    c.drawPath(
      line,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.6
        ..strokeJoin = StrokeJoin.round
        ..color = const Color(0xFF86B6EF),
    );
  }

  @override
  bool shouldRepaint(_SparkPainter old) => old.v != v;
}

/// Thanh chia phần: khe 2px giữa các đoạn, không viền.
class StackBar extends StatelessWidget {
  const StackBar(this.parts, {super.key});

  final List<(num, Color)> parts;

  @override
  Widget build(BuildContext context) {
    final shown = parts.where((p) => p.$1 > 0).toList();
    return ClipRRect(
      borderRadius: BorderRadius.circular(6),
      child: SizedBox(
        height: 12,
        child: Row(
          children: [
            for (var i = 0; i < shown.length; i++) ...[
              if (i > 0) const SizedBox(width: 2),
              Expanded(
                flex: math.max(1, (shown[i].$1 * 1000).round()),
                child: Container(constraints: const BoxConstraints(minWidth: 3), color: shown[i].$2),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Vòng tròn cơ cấu ≤ 5 phần, khe nhỏ giữa các phần; giữa vòng ghi tổng.
class Donut extends StatelessWidget {
  const Donut({super.key, required this.parts, required this.center, required this.caption});

  final List<(num, Color)> parts;
  final String center;
  final String caption;

  @override
  Widget build(BuildContext context) => SizedBox(
    width: 128,
    height: 128,
    child: CustomPaint(
      painter: _DonutPainter(parts),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              center,
              style: const TextStyle(color: DashColors.ink, fontSize: 15, fontWeight: FontWeight.w700),
            ),
            Text(caption, style: const TextStyle(color: DashColors.muted, fontSize: 10)),
          ],
        ),
      ),
    ),
  );
}

class _DonutPainter extends CustomPainter {
  _DonutPainter(this.parts);

  final List<(num, Color)> parts;

  @override
  void paint(Canvas c, Size s) {
    final stroke = s.width * 5 / 42;
    final rect = Rect.fromCircle(center: s.center(Offset.zero), radius: s.width * 15.915 / 42);
    final base = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke;
    c.drawArc(rect, 0, math.pi * 2, false, base..color = DashColors.grid);
    final total = parts.fold<num>(0, (a, p) => a + p.$1);
    if (total <= 0) return;
    final gap = parts.length > 1 ? 1.2 / 100 * math.pi * 2 : 0.0;
    var start = -math.pi / 2;
    for (final (v, color) in parts) {
      final sweep = v / total * math.pi * 2;
      c.drawArc(
        rect,
        start,
        math.max(0.02, sweep - gap),
        false,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = stroke
          ..color = color,
      );
      start += sweep;
    }
  }

  @override
  bool shouldRepaint(_DonutPainter old) => old.parts != parts;
}

/// Số đơn theo giờ: cột bo đầu, giờ đông nhất tô đậm và ghi số.
class HourBars extends StatelessWidget {
  const HourBars({super.key, required this.byHour, required this.first, required this.last, required this.peak});

  final Map<int, int> byHour;
  final int first;
  final int last;
  final int peak;

  @override
  Widget build(BuildContext context) => SizedBox(
    height: 110,
    width: double.infinity,
    child: CustomPaint(painter: _HourPainter(byHour, first, last, peak)),
  );
}

class _HourPainter extends CustomPainter {
  _HourPainter(this.byHour, this.first, this.last, this.peak);

  final Map<int, int> byHour;
  final int first, last, peak;

  @override
  void paint(Canvas c, Size s) {
    const padB = 18.0, gap = 2.0;
    final n = last - first + 1;
    final bw = (s.width - gap * (n - 1)) / n;
    final mx = byHour.values.fold(1, math.max);
    final base = s.height - padB;
    c.drawLine(Offset(0, base), Offset(s.width, base), Paint()..color = const Color(0xFFDFE4EC));
    for (var h = first; h <= last; h++) {
      final v = byHour[h] ?? 0;
      final x = (h - first) * (bw + gap);
      if (v > 0) {
        final bh = math.max(4.0, v / mx * (base - 16));
        c.drawRRect(
          RRect.fromRectAndCorners(
            Rect.fromLTWH(x, base - bh, bw, bh),
            topLeft: const Radius.circular(3),
            topRight: const Radius.circular(3),
          ),
          Paint()..color = h == peak ? DashColors.s1 : const Color(0xFFB7D3F6),
        );
        if (h == peak) _text(c, '$v', Offset(x + bw / 2, base - bh - 10), _label, align: TextAlign.center);
      }
    }
    final marks = [first, ((first + last) / 2).round(), last];
    for (var k = 0; k < 3; k++) {
      final hh = marks[k];
      _text(
        c,
        '${hh}h',
        Offset((hh - first) * (bw + gap) + bw / 2, s.height - 9),
        _tick,
        align: k == 0
            ? TextAlign.left
            : k == 2
            ? TextAlign.right
            : TextAlign.center,
      );
    }
  }

  @override
  bool shouldRepaint(_HourPainter old) => old.byHour != byHour || old.peak != peak;
}
