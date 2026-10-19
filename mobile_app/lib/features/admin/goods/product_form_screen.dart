import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_number.dart';
import '../../../core/ui/app_icon.dart';
import '../dashboard/dashboard_data.dart' show branchLabel, dashBranchesProvider, defaultShopProvider;
import '../shell/shell_widgets.dart';
import 'goods_data.dart';
import 'goods_widgets.dart';
import 'scanner_screen.dart';

/// Chọn ảnh từ camera / thư viện. Tách ra provider để test thay bằng ảnh giả.
final pickImageProvider = Provider<Future<XFile?> Function(ImageSource)>(
  // Ảnh điện thoại thường 3–8MB, API nhận tối đa 5MB: thu về 1600px là đủ nét cho tem và danh sách.
  (_) =>
      (src) => ImagePicker().pickImage(source: src, maxWidth: 1600, maxHeight: 1600, imageQuality: 85),
);

/// Gõ số tiền: chỉ nhận chữ số, tự chấm ngăn nghìn.
class _MoneyFormatter extends TextInputFormatter {
  const _MoneyFormatter();

  @override
  TextEditingValue formatEditUpdate(TextEditingValue old, TextEditingValue next) {
    final digits = next.text.replaceAll(RegExp(r'\D'), '');
    final t = digits.isEmpty ? '' : dot(int.parse(digits));
    return TextEditingValue(
      text: t,
      selection: TextSelection.collapsed(offset: t.length),
    );
  }
}

int _num(TextEditingController c) => int.tryParse(c.text.replaceAll(RegExp(r'\D'), '')) ?? 0;

/// Thêm sản phẩm (hàng một phiên bản): ảnh, tên, nhóm, SKU, mã vạch, đơn vị,
/// giá bán / giá vốn, tồn ban đầu. Lưu xong trả về [CreatedProduct].
class ProductFormScreen extends ConsumerStatefulWidget {
  const ProductFormScreen({super.key});

  @override
  ConsumerState<ProductFormScreen> createState() => _ProductFormScreenState();
}

class _ProductFormScreenState extends ConsumerState<ProductFormScreen> {
  final _name = TextEditingController();
  final _sku = TextEditingController();
  final _barcode = TextEditingController();
  final _price = TextEditingController();
  final _cost = TextEditingController();
  final _stock = TextEditingController();
  GoodsCategory? _cat;
  (int, String)? _unit;
  String _thumb = '';
  bool _uploading = false;
  bool _saving = false;
  Map<String, String> _err = const {};

  @override
  void dispose() {
    for (final c in [_name, _sku, _barcode, _price, _cost, _stock]) {
      c.dispose();
    }
    super.dispose();
  }

  void _clearErr(String k) {
    if (_err.containsKey(k)) setState(() => _err = {..._err}..remove(k));
  }

  Future<void> _pickImage() async {
    final src = await showDashSheet<ImageSource?>(
      context,
      title: 'Ảnh sản phẩm',
      body: (ctx) => Column(
        children: [
          SheetOption(
            label: 'Chụp ảnh',
            leading: AppIcons.scan,
            onTap: () => Navigator.of(ctx).pop(ImageSource.camera),
          ),
          SheetOption(
            key: const Key('pick_gallery'),
            label: 'Chọn từ thư viện',
            leading: AppIcons.image,
            onTap: () => Navigator.of(ctx).pop(ImageSource.gallery),
          ),
          if (_thumb.isNotEmpty)
            SheetOption(
              label: 'Bỏ ảnh',
              leading: AppIcons.close,
              danger: true,
              onTap: () {
                setState(() => _thumb = '');
                Navigator.of(ctx).pop();
              },
            ),
        ],
      ),
    );
    if (src == null || !mounted) return;
    final f = await ref.read(pickImageProvider)(src);
    if (f == null || !mounted) return;
    setState(() => _uploading = true);
    try {
      final url = await uploadProductImage(ref, await f.readAsBytes(), f.name);
      if (mounted) setState(() => _thumb = url);
    } on ApiException catch (e) {
      if (mounted) showDashToast(context, e.message);
    } finally {
      if (mounted) setState(() => _uploading = false);
    }
  }

  Future<void> _pickCategory() async {
    final c = await ref.read(goodsCatalogProvider.future);
    if (!mounted) return;
    final tree = categoryTree(c.categories);
    final picked = await showDashSheet<GoodsCategory>(
      context,
      title: 'Nhóm hàng',
      body: (ctx) => tree.isEmpty
          ? const Padding(
              padding: EdgeInsets.all(12),
              child: Text(
                'Cửa hàng chưa có nhóm hàng. Thêm nhóm ở web quản trị → Hàng hoá → Nhóm hàng.',
                style: TextStyle(color: OrderColors.muted),
              ),
            )
          : Column(
              children: [
                for (final (g, depth) in tree)
                  Padding(
                    // Thụt lề theo cấp để thấy nhóm con nằm trong nhóm nào.
                    padding: EdgeInsets.only(left: depth * 18.0),
                    child: SheetOption(
                      key: Key('cat_${g.id}'),
                      label: g.name,
                      selected: _cat?.id == g.id,
                      onTap: () => Navigator.of(ctx).pop(g),
                    ),
                  ),
              ],
            ),
    );
    if (picked != null) {
      setState(() => _cat = picked);
      _clearErr('category_id');
    }
  }

  Future<void> _pickUnit(List<(int, String)> units) async {
    final picked = await showDashSheet<(int, String)?>(
      context,
      title: 'Đơn vị tính',
      body: (ctx) => Column(
        children: [
          SheetOption(label: 'Không chọn', selected: _unit == null, onTap: () => Navigator.of(ctx).pop((0, ''))),
          for (final u in units)
            SheetOption(label: u.$2, selected: _unit?.$1 == u.$1, onTap: () => Navigator.of(ctx).pop(u)),
        ],
      ),
    );
    if (picked != null) setState(() => _unit = picked.$1 == 0 ? null : picked);
  }

  Future<void> _scanBarcode() async {
    await Navigator.of(context, rootNavigator: true).push(
      MaterialPageRoute<void>(
        fullscreenDialog: true,
        builder: (_) => ScannerScreen(
          onCode: (code) async {
            _barcode.text = code;
            _clearErr('barcode');
            return true;
          },
        ),
      ),
    );
  }

  Future<void> _save() async {
    final err = <String, String>{
      if (_name.text.trim().isEmpty) 'name': 'Nhập tên sản phẩm',
      if (_cat == null) 'category_id': 'Chọn nhóm hàng',
      if (_num(_price) <= 0) 'base_price': 'Nhập giá bán',
    };
    if (err.isNotEmpty) {
      setState(() => _err = err);
      return;
    }
    setState(() {
      _saving = true;
      _err = const {};
    });
    try {
      final res = await createProduct(
        ref,
        NewProduct(
          name: _name.text,
          categoryId: _cat!.id,
          price: _num(_price),
          sku: _sku.text,
          barcode: _barcode.text,
          cost: _cost.text.trim().isEmpty ? null : _num(_cost),
          unitId: _unit?.$1,
          thumbnail: _thumb,
          openingStock: _num(_stock),
        ),
      );
      if (mounted) Navigator.of(context).pop(res);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _err = e.fieldErrors);
      // Lỗi không gắn được vào ô nào (hết hạn mức, mất mạng…) thì báo nổi.
      if (e.fieldErrors.isEmpty) showDashToast(context, e.message);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final units = ref.watch(goodsUnitsProvider).value ?? const [];
    final shop = ref.watch(defaultShopProvider).value;
    final branches = ref.watch(dashBranchesProvider).value ?? const [];
    final price = _num(_price), cost = _cost.text.trim().isEmpty ? null : _num(_cost);
    final gain = cost == null || price <= 0 ? null : price - cost;
    final top = MediaQuery.paddingOf(context).top;

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.dark,
      child: Scaffold(
        backgroundColor: OrderColors.page,
        bottomNavigationBar: Container(
          padding: EdgeInsets.fromLTRB(12, 10, 12, MediaQuery.viewPaddingOf(context).bottom + 10),
          decoration: const BoxDecoration(
            color: Colors.white,
            boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, -1))],
          ),
          child: GoodsBtn(
            key: const Key('form_save'),
            label: _saving ? 'Đang lưu…' : 'Lưu sản phẩm',
            icon: AppIcons.check,
            primary: true,
            onTap: _saving || _uploading ? () {} : _save,
          ),
        ),
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
                    tooltip: 'Đóng',
                    constraints: const BoxConstraints.tightFor(width: 40, height: 40),
                    style: IconButton.styleFrom(tapTargetSize: MaterialTapTargetSize.shrinkWrap),
                    padding: EdgeInsets.zero,
                    onPressed: () => Navigator.of(context).maybePop(),
                    icon: const AppIcon(AppIcons.close, size: 22, color: OrderColors.ink),
                  ),
                  const Expanded(
                    child: Text(
                      'Thêm sản phẩm',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: OrderColors.ink, fontSize: 16.5, fontWeight: FontWeight.w700),
                    ),
                  ),
                  const SizedBox(width: 40),
                ],
              ),
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.only(bottom: 16),
                keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                children: [
                  _Block(
                    first: true,
                    child: Row(
                      children: [
                        InkWell(
                          key: const Key('form_image'),
                          borderRadius: BorderRadius.circular(14),
                          onTap: _uploading ? null : _pickImage,
                          child: Stack(
                            children: [
                              _thumb.isEmpty
                                  ? Container(
                                      width: 96,
                                      height: 96,
                                      decoration: BoxDecoration(
                                        color: OrderColors.primarySoft,
                                        borderRadius: BorderRadius.circular(14),
                                        border: Border.all(color: const Color(0xFFBFD7FF)),
                                      ),
                                      child: const Column(
                                        mainAxisAlignment: MainAxisAlignment.center,
                                        children: [
                                          AppIcon(AppIcons.image, size: 26, color: OrderColors.primaryInk),
                                          SizedBox(height: 4),
                                          Text(
                                            'Thêm ảnh',
                                            style: TextStyle(
                                              color: OrderColors.primaryInk,
                                              fontSize: 12,
                                              fontWeight: FontWeight.w600,
                                            ),
                                          ),
                                        ],
                                      ),
                                    )
                                  : GoodsImage(url: _thumb, size: 96, radius: 14),
                              if (_uploading)
                                const Positioned.fill(
                                  child: Center(
                                    child: SizedBox(
                                      width: 24,
                                      height: 24,
                                      child: CircularProgressIndicator(strokeWidth: 2.4, color: OrderColors.primary),
                                    ),
                                  ),
                                ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 14),
                        const Expanded(
                          child: Text(
                            'Chụp hoặc chọn ảnh rõ, nền sáng — thu ngân nhận ra hàng nhanh hơn ở quầy.',
                            style: TextStyle(color: OrderColors.muted, fontSize: 12.5, height: 1.5),
                          ),
                        ),
                      ],
                    ),
                  ),
                  _Block(
                    title: 'Thông tin',
                    child: Column(
                      children: [
                        _Field(
                          label: 'Tên sản phẩm',
                          required: true,
                          error: _err['name'],
                          child: _Input(
                            key: const Key('form_name'),
                            controller: _name,
                            hint: 'VD: Áo thun cổ tròn trắng',
                            capitalize: true,
                            onChanged: (_) => _clearErr('name'),
                          ),
                        ),
                        _Field(
                          label: 'Nhóm hàng',
                          required: true,
                          error: _err['category_id'],
                          child: _Picker(
                            key: const Key('form_category'),
                            value: _cat?.name,
                            hint: 'Chọn nhóm hàng',
                            onTap: _pickCategory,
                          ),
                        ),
                        Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Expanded(
                              child: _Field(
                                label: 'Mã SKU',
                                error: _err['sku'],
                                child: _Input(
                                  key: const Key('form_sku'),
                                  controller: _sku,
                                  hint: 'Tự sinh mã',
                                  upper: true,
                                  onChanged: (_) => _clearErr('sku'),
                                ),
                              ),
                            ),
                            if (units.isNotEmpty) ...[
                              const SizedBox(width: 10),
                              Expanded(
                                child: _Field(
                                  label: 'Đơn vị tính',
                                  child: _Picker(
                                    key: const Key('form_unit'),
                                    value: _unit?.$2,
                                    hint: 'Chọn',
                                    onTap: () => _pickUnit(units),
                                  ),
                                ),
                              ),
                            ],
                          ],
                        ),
                        _Field(
                          label: 'Mã vạch',
                          error: _err['barcode'],
                          last: true,
                          child: _Input(
                            key: const Key('form_barcode'),
                            controller: _barcode,
                            hint: 'Quét hoặc nhập mã in trên hàng',
                            keyboard: TextInputType.number,
                            onChanged: (_) => _clearErr('barcode'),
                            trailing: IconButton(
                              tooltip: 'Quét mã vạch',
                              onPressed: _scanBarcode,
                              icon: const AppIcon(AppIcons.scan, color: OrderColors.primaryInk),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  _Block(
                    title: 'Giá',
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Expanded(
                              child: _Field(
                                label: 'Giá bán',
                                required: true,
                                error: _err['base_price'],
                                last: true,
                                child: _Input(
                                  key: const Key('form_price'),
                                  controller: _price,
                                  hint: '0',
                                  money: true,
                                  onChanged: (_) {
                                    _clearErr('base_price');
                                    setState(() {});
                                  },
                                ),
                              ),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: _Field(
                                label: 'Giá vốn',
                                last: true,
                                child: _Input(
                                  key: const Key('form_cost'),
                                  controller: _cost,
                                  hint: 'Chưa khai',
                                  money: true,
                                  onChanged: (_) => setState(() {}),
                                ),
                              ),
                            ),
                          ],
                        ),
                        if (gain != null)
                          Padding(
                            padding: const EdgeInsets.only(top: 10),
                            child: Text.rich(
                              TextSpan(
                                text: 'Lãi mỗi sản phẩm: ',
                                children: [
                                  TextSpan(
                                    text: '${dot(gain)}đ · ${pct(gain / price * 100)}',
                                    style: TextStyle(
                                      color: gain > 0 ? OrderColors.green.$2 : OrderColors.red.$2,
                                      fontWeight: FontWeight.w700,
                                      fontFeatures: tnum,
                                    ),
                                  ),
                                ],
                              ),
                              key: const Key('form_gain'),
                              style: const TextStyle(color: OrderColors.muted, fontSize: 12.5),
                            ),
                          ),
                      ],
                    ),
                  ),
                  _Block(
                    title: 'Tồn kho',
                    child: _Field(
                      label: 'Tồn ban đầu',
                      last: true,
                      note:
                          'Ghi vào kho ${shop == null ? 'chi nhánh đang làm việc' : branchLabel(shop, branches)} '
                          'như một lần nhập hàng, theo giá vốn ở trên.',
                      child: _Input(
                        key: const Key('form_stock'),
                        controller: _stock,
                        hint: '0',
                        money: true,
                        suffix: _unit?.$2 ?? '',
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Block extends StatelessWidget {
  const _Block({required this.child, this.title, this.first = false});

  final Widget child;
  final String? title;
  final bool first;

  @override
  Widget build(BuildContext context) => Container(
    margin: EdgeInsets.only(top: first ? 0 : 8),
    padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
    color: Colors.white,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (title != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(
              title!,
              style: const TextStyle(color: OrderColors.ink, fontSize: 14.5, fontWeight: FontWeight.w700),
            ),
          ),
        child,
      ],
    ),
  );
}

/// Nhãn + ô + dòng lỗi / ghi chú.
class _Field extends StatelessWidget {
  const _Field({
    required this.label,
    required this.child,
    this.required = false,
    this.error,
    this.note,
    this.last = false,
  });

  final String label;
  final Widget child;
  final bool required;
  final String? error;
  final String? note;
  final bool last;

  @override
  Widget build(BuildContext context) => Padding(
    padding: EdgeInsets.only(bottom: last ? 0 : 14),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text.rich(
          TextSpan(
            text: label,
            children: [
              if (required)
                TextSpan(
                  text: ' *',
                  style: TextStyle(color: OrderColors.red.$2),
                ),
            ],
          ),
          style: const TextStyle(color: OrderColors.muted, fontSize: 12.5, fontWeight: FontWeight.w500),
        ),
        const SizedBox(height: 6),
        // Viền lỗi vẽ ĐÈ lên ô (nền xám của ô sẽ che mất nếu vẽ phía sau).
        DecoratedBox(
          position: DecorationPosition.foreground,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: error == null ? Colors.transparent : OrderColors.red.$2, width: 1.5),
          ),
          child: child,
        ),
        if (error != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(error!, style: TextStyle(color: OrderColors.red.$2, fontSize: 12.5)),
          )
        else if (note != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(note!, style: const TextStyle(color: OrderColors.muted, fontSize: 12, height: 1.45)),
          ),
      ],
    ),
  );
}

class _Input extends StatelessWidget {
  const _Input({
    super.key,
    required this.controller,
    required this.hint,
    this.onChanged,
    this.money = false,
    this.upper = false,
    this.capitalize = false,
    this.keyboard,
    this.trailing,
    this.suffix = 'đ',
  });

  final TextEditingController controller;
  final String hint;
  final ValueChanged<String>? onChanged;
  final bool money;
  final bool upper;
  final bool capitalize;
  final TextInputType? keyboard;
  final Widget? trailing;

  /// Chữ sau số (ô kiểu số): "đ" cho tiền, đơn vị tính cho số lượng.
  final String suffix;

  @override
  Widget build(BuildContext context) => Container(
    height: 46,
    padding: EdgeInsets.only(left: 12, right: trailing == null ? 12 : 0),
    decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(10)),
    child: Row(
      children: [
        Expanded(
          child: TextField(
            controller: controller,
            onChanged: onChanged,
            keyboardType: money ? TextInputType.number : keyboard,
            textCapitalization: upper
                ? TextCapitalization.characters
                : capitalize
                ? TextCapitalization.sentences
                : TextCapitalization.none,
            inputFormatters: money ? const [_MoneyFormatter()] : null,
            style: TextStyle(
              color: OrderColors.ink,
              fontSize: 15.5,
              fontWeight: money ? FontWeight.w600 : FontWeight.w400,
              fontFeatures: money ? tnum : null,
            ),
            decoration: InputDecoration(
              isCollapsed: true,
              filled: false,
              border: InputBorder.none,
              enabledBorder: InputBorder.none,
              focusedBorder: InputBorder.none,
              hintText: hint,
              hintStyle: const TextStyle(color: OrderColors.faint, fontSize: 15, fontWeight: FontWeight.w400),
            ),
          ),
        ),
        if (money && suffix.isNotEmpty)
          Text(
            suffix,
            style: const TextStyle(color: OrderColors.muted, fontSize: 14, fontWeight: FontWeight.w600),
          ),
        ?trailing,
      ],
    ),
  );
}

class _Picker extends StatelessWidget {
  const _Picker({super.key, required this.value, required this.hint, required this.onTap});

  final String? value;
  final String hint;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
    color: OrderColors.grid,
    borderRadius: BorderRadius.circular(10),
    child: InkWell(
      borderRadius: BorderRadius.circular(10),
      onTap: onTap,
      child: Container(
        height: 46,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        child: Row(
          children: [
            Expanded(
              child: Text(
                value ?? hint,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(color: value == null ? OrderColors.faint : OrderColors.ink, fontSize: 15),
              ),
            ),
            const AppIcon(AppIcons.down, size: 18, color: OrderColors.muted),
          ],
        ),
      ),
    ),
  );
}
