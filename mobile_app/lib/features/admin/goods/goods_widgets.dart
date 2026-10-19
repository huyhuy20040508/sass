import 'package:flutter/material.dart';

import '../../../app/theme.dart';
import '../../../core/format/vn_number.dart';
import '../../../core/ui/app_icon.dart';
import '../orders/order_widgets.dart' show mediaUrl;
import 'goods_data.dart';

const tnum = [FontFeature.tabularFigures()];

/// Viên tồn kho: (nền, chữ, nhãn) — ngừng bán xám, hết đỏ, sắp hết (≤ [lowStock]) cam, còn xanh.
(Color, Color, String) stockPill(GoodsItem it) {
  if (!it.active) return (OrderColors.grid, OrderColors.muted, 'Ngừng bán');
  if (it.stock <= 0) return (OrderColors.red.$1, OrderColors.red.$2, 'Hết hàng');
  if (it.stock <= lowStock) return (OrderColors.orange.$1, OrderColors.orange.$2, 'Sắp hết · ${dot(it.stock)}');
  return (OrderColors.green.$1, OrderColors.green.$2, 'Tồn ${dot(it.stock)}');
}

String priceText(GoodsItem it) =>
    it.minPrice == it.maxPrice ? dot(it.minPrice) : '${dot(it.minPrice)} – ${dot(it.maxPrice)}';

/// Ô ảnh hàng: viền mảnh, ảnh vừa khung (không cắt); không ảnh / lỗi ảnh thì icon ảnh.
class GoodsImage extends StatelessWidget {
  const GoodsImage({super.key, required this.url, this.size = 60, this.radius = 12});

  final String url;
  final double size;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final ph = Center(
      child: AppIcon(AppIcons.image, size: size >= 90 ? 36 : 24, color: OrderColors.faint),
    );
    return Container(
      width: size,
      height: size,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: const Color(0xFFF7F8FA),
        borderRadius: BorderRadius.circular(radius),
        border: Border.all(color: OrderColors.line),
      ),
      child: url.isEmpty
          ? ph
          : Padding(
              padding: const EdgeInsets.all(3),
              child: Image.network(
                mediaUrl(url),
                fit: BoxFit.contain,
                webHtmlElementStrategy: WebHtmlElementStrategy.fallback,
                errorBuilder: (_, _, _) => ph,
              ),
            ),
    );
  }
}

/// Một dòng danh sách kiểu Sapo: ảnh · tên + SKU · nhóm + thẻ · giá + viên tồn.
class GoodsRow extends StatelessWidget {
  const GoodsRow({super.key, required this.item, required this.onTap});

  final GoodsItem item;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final it = item;
    final n = it.variants.length;
    final (bg, fg, label) = stockPill(it);
    // Hàng ngừng bán: làm mờ ảnh + tên, giá và viên trạng thái vẫn rõ.
    final dim = it.active ? 1.0 : .55;
    return Material(
      color: Colors.white,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Row(
            children: [
              Opacity(
                opacity: dim,
                child: GoodsImage(url: it.thumb),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Opacity(
                      opacity: dim,
                      child: Text(
                        it.name,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: OrderColors.ink,
                          fontSize: 14.5,
                          fontWeight: FontWeight.w600,
                          height: 1.35,
                        ),
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      [it.sku, if (it.categoryName.isNotEmpty) it.categoryName].join(' · '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: OrderColors.muted, fontSize: 12.5),
                    ),
                    if (n > 1 || it.isSerial) ...[
                      const SizedBox(height: 5),
                      Wrap(
                        spacing: 4,
                        runSpacing: 4,
                        children: [
                          if (n > 1) SmallTag('$n phiên bản', (OrderColors.primarySoft, OrderColors.primaryInk)),
                          if (it.isSerial) const SmallTag('IMEI', OrderColors.purple),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    priceText(it),
                    style: const TextStyle(
                      color: OrderColors.primaryInk,
                      fontSize: 15,
                      fontWeight: FontWeight.w700,
                      fontFeatures: tnum,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Container(
                    key: const Key('stock_pill'),
                    height: 22,
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(11)),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Container(
                          width: 6,
                          height: 6,
                          decoration: BoxDecoration(color: fg, shape: BoxShape.circle),
                        ),
                        const SizedBox(width: 5),
                        Text(
                          label,
                          style: TextStyle(color: fg, fontSize: 12, fontWeight: FontWeight.w700, fontFeatures: tnum),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Thẻ nhỏ cạnh tên hàng: "2 phiên bản", "IMEI".
class SmallTag extends StatelessWidget {
  const SmallTag(this.text, this.colors, {super.key});

  final String text;
  final (Color, Color) colors;

  @override
  Widget build(BuildContext context) => Container(
    height: 20,
    padding: const EdgeInsets.symmetric(horizontal: 7),
    decoration: BoxDecoration(color: colors.$1, borderRadius: BorderRadius.circular(6)),
    child: Center(
      widthFactor: 1,
      child: Text(
        text,
        style: TextStyle(color: colors.$2, fontSize: 11, fontWeight: FontWeight.w600),
      ),
    ),
  );
}

/// Vạch ngăn giữa hai dòng, bắt đầu từ mép chữ (16 + ảnh 60 + 12).
class GoodsDivider extends StatelessWidget {
  const GoodsDivider({super.key});

  @override
  Widget build(BuildContext context) => const ColoredBox(
    color: Colors.white,
    child: Padding(
      padding: EdgeInsets.only(left: 88),
      child: Divider(height: 1, thickness: 1, color: OrderColors.line),
    ),
  );
}

/// Khung xương lúc tải danh sách: đúng dáng dòng hàng, lấp lánh nhẹ.
class GoodsSkeleton extends StatefulWidget {
  const GoodsSkeleton({super.key, this.rows = 6});

  final int rows;

  @override
  State<GoodsSkeleton> createState() => _GoodsSkeletonState();
}

class _GoodsSkeletonState extends State<GoodsSkeleton> with SingleTickerProviderStateMixin {
  late final _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1200));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (!MediaQuery.disableAnimationsOf(context) && !_c.isAnimating) _c.repeat();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  Widget _sk(double w, double h, {double r = 6}) => AnimatedBuilder(
    animation: _c,
    builder: (_, _) {
      final t = _c.value * 4 - 2;
      return Container(
        width: w,
        height: h,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(r),
          gradient: LinearGradient(
            begin: Alignment(t - 1, 0),
            end: Alignment(t + 1, 0),
            colors: const [Color(0xFFECEFF4), Color(0xFFF6F7F9), Color(0xFFECEFF4)],
          ),
        ),
      );
    },
  );

  @override
  Widget build(BuildContext context) => Column(
    key: const Key('goods_skeleton'),
    children: [
      for (var i = 0; i < widget.rows; i++)
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          decoration: BoxDecoration(
            color: Colors.white,
            border: i == 0 ? null : const Border(top: BorderSide(color: OrderColors.line)),
          ),
          child: Row(
            children: [
              _sk(60, 60, r: 12),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    FractionallySizedBox(
                      alignment: Alignment.centerLeft,
                      widthFactor: .72,
                      child: _sk(double.infinity, 13),
                    ),
                    const SizedBox(height: 8),
                    FractionallySizedBox(
                      alignment: Alignment.centerLeft,
                      widthFactor: .46,
                      child: _sk(double.infinity, 11),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [_sk(70, 13), const SizedBox(height: 8), _sk(56, 18, r: 9)],
              ),
            ],
          ),
        ),
    ],
  );
}

/// Nút to của chân màn / ngăn kéo: chính (xanh) hoặc phụ (trắng viền).
class GoodsBtn extends StatelessWidget {
  const GoodsBtn({super.key, required this.label, required this.onTap, this.icon, this.primary = false});

  final String label;
  final VoidCallback onTap;
  final AppIcons? icon;
  final bool primary;

  @override
  Widget build(BuildContext context) {
    final fg = primary ? Colors.white : OrderColors.ink;
    return Material(
      color: primary ? OrderColors.primary : Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: primary ? BorderSide.none : const BorderSide(color: OrderColors.line),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: onTap,
        child: SizedBox(
          height: 46,
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (icon != null) ...[AppIcon(icon!, size: 19, color: fg), const SizedBox(width: 6)],
              Flexible(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(color: fg, fontSize: 15, fontWeight: FontWeight.w600),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Chip chọn (trạng thái / nhóm hàng): chọn thì nền xanh nhạt viền xanh.
class GoodsChip extends StatelessWidget {
  const GoodsChip({
    super.key,
    required this.label,
    required this.on,
    required this.onTap,
    this.count,
    this.height = 30,
    this.fontSize = 12.5,
  });

  final String label;
  final bool on;
  final VoidCallback onTap;
  final int? count;
  final double height;
  final double fontSize;

  @override
  Widget build(BuildContext context) {
    final r = BorderRadius.circular(height / 2);
    return Semantics(
      selected: on,
      button: true,
      child: Material(
        color: on ? OrderColors.primarySoft : Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: r,
          side: BorderSide(color: on ? OrderColors.primary : OrderColors.line),
        ),
        child: InkWell(
          borderRadius: r,
          onTap: onTap,
          child: Container(
            height: height,
            padding: EdgeInsets.symmetric(horizontal: height > 32 ? 13 : 12),
            // widthFactor 1: chip ôm theo chữ, không giãn hết dòng trong Wrap.
            child: Center(
              widthFactor: 1,
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    label,
                    style: TextStyle(
                      color: on ? OrderColors.primaryInk : OrderColors.text,
                      fontSize: fontSize,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  if (count != null) ...[
                    const SizedBox(width: 4),
                    // Số đếm dạng huy hiệu: chip đang chọn thì nền xanh chữ trắng.
                    Container(
                      key: const Key('chip_count'),
                      constraints: const BoxConstraints(minWidth: 18),
                      height: 17,
                      padding: const EdgeInsets.symmetric(horizontal: 5),
                      decoration: BoxDecoration(
                        color: on ? OrderColors.primary : OrderColors.grid,
                        borderRadius: BorderRadius.circular(9),
                      ),
                      child: Center(
                        widthFactor: 1,
                        child: Text(
                          '$count',
                          style: TextStyle(
                            color: on ? Colors.white : OrderColors.faint,
                            fontSize: 11,
                            fontWeight: FontWeight.w600,
                            fontFeatures: tnum,
                          ),
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
