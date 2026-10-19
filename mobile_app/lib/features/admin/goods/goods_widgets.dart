import 'package:flutter/material.dart';

import '../../../app/theme.dart';
import '../../../core/format/vn_number.dart';
import '../../../core/ui/app_icon.dart';
import '../orders/order_widgets.dart' show mediaUrl;
import 'goods_data.dart';

const tnum = [FontFeature.tabularFigures()];

/// Màu số tồn: hết (≤0) đỏ, sắp hết (≤ [lowStock]) cam, còn lại xanh.
Color stockColor(num n) => n <= 0
    ? OrderColors.red.$2
    : n <= lowStock
    ? OrderColors.orange.$2
    : OrderColors.green.$2;

String priceText(GoodsItem it) =>
    it.minPrice == it.maxPrice ? dot(it.minPrice) : '${dot(it.minPrice)} – ${dot(it.maxPrice)}';

/// Ô ảnh hàng: viền mảnh, ảnh vừa khung (không cắt); không ảnh / lỗi ảnh thì icon ảnh.
class GoodsImage extends StatelessWidget {
  const GoodsImage({super.key, required this.url, this.size = 56, this.radius = 10});

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

/// Một dòng danh sách kiểu Sapo: ảnh · tên + SKU · giá + tồn.
class GoodsRow extends StatelessWidget {
  const GoodsRow({super.key, required this.item, required this.onTap});

  final GoodsItem item;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final it = item;
    final n = it.variants.length;
    return Material(
      color: Colors.white,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Row(
            children: [
              GoodsImage(url: it.thumb),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
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
                    const SizedBox(height: 3),
                    Text(
                      [it.sku, if (n > 1) '$n phiên bản'].join(' · '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: OrderColors.muted, fontSize: 12.5),
                    ),
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
                      color: OrderColors.ink,
                      fontSize: 14.5,
                      fontWeight: FontWeight.w700,
                      fontFeatures: tnum,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text.rich(
                    TextSpan(
                      text: 'Tồn: ',
                      children: [
                        TextSpan(
                          text: dot(it.stock),
                          style: TextStyle(color: stockColor(it.stock), fontWeight: FontWeight.w600),
                        ),
                      ],
                    ),
                    style: const TextStyle(color: OrderColors.muted, fontSize: 12.5, fontFeatures: tnum),
                  ),
                  if (!it.active) ...[
                    const SizedBox(height: 4),
                    const Text('Ngừng bán', style: TextStyle(color: OrderColors.faint, fontSize: 11.5)),
                  ],
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Vạch ngăn giữa hai dòng, bắt đầu từ mép chữ (16 + ảnh 56 + 12).
class GoodsDivider extends StatelessWidget {
  const GoodsDivider({super.key});

  @override
  Widget build(BuildContext context) => const ColoredBox(
    color: Colors.white,
    child: Padding(
      padding: EdgeInsets.only(left: 84),
      child: Divider(height: 1, thickness: 1, color: OrderColors.line),
    ),
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
              child: Text.rich(
                TextSpan(
                  text: label,
                  children: [
                    if (count != null)
                      TextSpan(
                        text: ' $count',
                        style: TextStyle(color: on ? OrderColors.primaryInk : OrderColors.faint),
                      ),
                  ],
                ),
                style: TextStyle(
                  color: on ? OrderColors.primaryInk : OrderColors.text,
                  fontSize: fontSize,
                  fontWeight: FontWeight.w600,
                  fontFeatures: tnum,
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
