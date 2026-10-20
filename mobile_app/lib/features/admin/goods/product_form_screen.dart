import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_number.dart';
import '../../../core/ui/app_icon.dart';
import '../../workspace/workspace.dart' show Branch;
import '../../workspace/workspace_widgets.dart' show SheetButton;
import '../dashboard/dashboard_data.dart' show dashBranchesProvider;
import '../orders/order_widgets.dart' show mediaUrl;
import '../shell/shell_widgets.dart';
import 'goods_data.dart';
import 'goods_widgets.dart';
import 'product_form_data.dart';
import 'scanner_screen.dart';

/// Chụp một ảnh / chọn một ảnh. Tách ra provider để test thay bằng ảnh giả.
final pickImageProvider = Provider<Future<XFile?> Function(ImageSource)>(
  // Ảnh điện thoại thường 3–8MB, API nhận tối đa 5MB: thu về 1600px là đủ nét cho tem và danh sách.
  (_) =>
      (src) => ImagePicker().pickImage(source: src, maxWidth: 1600, maxHeight: 1600, imageQuality: 85),
);

/// Chọn nhiều ảnh trong thư viện (tối đa [limit]).
final pickImagesProvider = Provider<Future<List<XFile>> Function(int limit)>(
  (_) =>
      (limit) => limit <= 1
      ? ImagePicker()
            .pickImage(source: ImageSource.gallery, maxWidth: 1600, maxHeight: 1600, imageQuality: 85)
            .then((f) => f == null ? const <XFile>[] : [f])
      : ImagePicker().pickMultiImage(limit: limit, maxWidth: 1600, maxHeight: 1600, imageQuality: 85),
);

const _maxPics = 8;
const _err = Color(0xFFC62828);

/// Gõ số: chỉ nhận chữ số, tự chấm ngăn nghìn.
class _DotFormatter extends TextInputFormatter {
  const _DotFormatter();

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

num? _numOf(String s) {
  final d = s.replaceAll(RegExp(r'\D'), '');
  return d.isEmpty ? null : int.parse(d);
}

/// Thêm sản phẩm theo design/html/them-hang-mobile.html (v4): màn đầu chỉ ảnh · tên ·
/// nhóm · giá; phiên bản bật bằng công tắc; mọi trường còn lại nằm trong "Thêm chi tiết".
/// Thêm: đóng màn trả về id sản phẩm cần mở chi tiết (null = không mở).
/// Sửa ([detail] = GET /admin/products/{id}): trả về 'saved' / 'deleted'.
class ProductFormScreen extends ConsumerStatefulWidget {
  const ProductFormScreen({super.key, this.detail});

  final Map<String, dynamic>? detail;

  @override
  ConsumerState<ProductFormScreen> createState() => _ProductFormScreenState();
}

class _ProductFormScreenState extends ConsumerState<ProductFormScreen> {
  var _d = ProductDraft();
  final _scroll = ScrollController();
  final _name = TextEditingController();
  final _price = TextEditingController();
  final _cost = TextEditingController();
  final _sale = TextEditingController();
  final _weight = TextEditingController();
  final _sku = TextEditingController();
  final _barcode = TextEditingController();
  final _desc = TextEditingController();
  final _tag = TextEditingController();
  final _nameFocus = FocusNode();

  /// Ô nhập của từng phiên bản / dòng quy đổi / giá cộng thêm, theo khoá.
  final _ctl = <String, TextEditingController>{};
  final _keys = {
    for (final k in ['name', 'category_id', 'base_price', 'sku', 'attrs', 'convs', 'sale']) k: GlobalKey(),
  };
  int _uploading = 0;
  final _uploadingVariant = <String>{};
  bool _saving = false;
  bool _savingMore = false;
  bool _moreOpen = false;
  bool _dirty = false;
  String _taxKey = '';
  Map<String, String> _bad = const {};

  bool get _edit => widget.detail != null;

  /// Form sửa chờ nạp nhóm hàng + danh mục chọn để dựng lại thuộc tính / đơn vị.
  bool _loading = false;
  bool _deleting = false;

  @override
  void initState() {
    super.initState();
    if (_edit) {
      _loading = true;
      _load();
    }
  }

  Future<void> _load() async {
    final d = widget.detail!;
    List<GoodsCategory> cats = const [];
    var src = const FormSources();
    try {
      cats = (await ref.read(goodsCatalogProvider.future)).categories;
      src = await ref.read(productFormSourcesProvider.future);
    } on ApiException {
      // Thiếu danh mục chọn vẫn sửa được — tên thuộc tính / đơn vị lấy từ chính mặt hàng.
    }
    if (!mounted) return;
    final x = ProductDraft.fromDetail(d, categories: cats, src: src);
    String money(num? v) => v == null ? '' : dot(v);
    _name.text = x.name;
    _price.text = money(x.price);
    _cost.text = money(x.cost);
    _sale.text = money(x.sale);
    _weight.text = x.weight > 0 ? dot(x.weight) : '';
    _sku.text = x.sku;
    _barcode.text = x.barcode;
    _desc.text = x.description;
    for (final MapEntry(key: k, value: st) in x.states.entries) {
      _c('p:$k', dot(st.price));
      _c('b:$k', st.barcode);
      _c('s:$k', st.sku);
      _c('c:$k', st.cost == null ? '' : dot(st.cost!));
    }
    for (final (i, (_, q)) in x.convs.indexed) {
      _c('q:$i', q > 0 ? '$q' : '');
    }
    setState(() {
      _d = x;
      // Mặt hàng chỉ lưu số VAT; loại thuế là loại đầu tiên có mức ấy (PC cũng suy như vậy).
      _taxKey = src.taxes.where((t) => t.levels.contains(x.vat)).firstOrNull?.key ?? '';
      _loading = false;
    });
  }

  @override
  void dispose() {
    for (final c in [_name, _price, _cost, _sale, _weight, _sku, _barcode, _desc, _tag, ..._ctl.values]) {
      c.dispose();
    }
    _scroll.dispose();
    _nameFocus.dispose();
    super.dispose();
  }

  TextEditingController _c(String key, [String init = '']) =>
      _ctl.putIfAbsent(key, () => TextEditingController(text: init));

  void _touch([VoidCallback? f]) => setState(() {
    f?.call();
    _dirty = true;
  });

  void _clearBad(String k) {
    if (_bad.containsKey(k)) setState(() => _bad = {..._bad}..remove(k));
  }

  void _toast(String t) => showDashToast(context, t);

  // ---------- Ảnh ----------

  Future<List<int>> _bytes(XFile f) => f.readAsBytes();

  Future<void> _addPics() async {
    final src = await showDashSheet<ImageSource>(
      context,
      title: 'Thêm ảnh',
      body: (ctx) => Column(
        children: [
          SheetOption(
            label: 'Chụp ảnh',
            leading: AppIcons.camera,
            onTap: () => Navigator.of(ctx).pop(ImageSource.camera),
          ),
          SheetOption(
            key: const Key('pick_gallery'),
            label: 'Chọn từ thư viện',
            leading: AppIcons.image,
            onTap: () => Navigator.of(ctx).pop(ImageSource.gallery),
          ),
        ],
      ),
    );
    if (src == null || !mounted) return;
    final room = _maxPics - _d.images.length - _uploading;
    final files = src == ImageSource.camera
        ? [?await ref.read(pickImageProvider)(ImageSource.camera)]
        : await ref.read(pickImagesProvider)(room);
    if (!mounted || files.isEmpty) return;
    setState(() => _uploading += files.take(room).length);
    for (final f in files.take(room)) {
      try {
        final url = await uploadProductImage(ref, await _bytes(f), f.name);
        if (mounted) {
          _touch(() {
            _d.images.add(url);
            _d.imagesTouched = true;
          });
        }
      } on ApiException catch (e) {
        if (mounted) _toast(e.message);
      } finally {
        if (mounted) setState(() => _uploading--);
      }
    }
  }

  Future<void> _variantPic(String key) async {
    final f = (await ref.read(pickImagesProvider)(1)).firstOrNull;
    if (f == null || !mounted) return;
    setState(() => _uploadingVariant.add(key));
    try {
      final url = await uploadProductImage(ref, await _bytes(f), f.name);
      if (mounted) _touch(() => _d.states[key]?.image = url);
    } on ApiException catch (e) {
      if (mounted) _toast(e.message);
    } finally {
      if (mounted) setState(() => _uploadingVariant.remove(key));
    }
  }

  // ---------- Ngăn chọn ----------

  Future<void> _pickCategory() async {
    final all = (await ref.read(goodsCatalogProvider.future)).categories;
    if (!mounted) return;
    final leaves = leafCategories(all);
    final picked = await showDashSheet<GoodsCategory>(
      context,
      title: 'Chọn nhóm hàng',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (leaves.isEmpty)
            const Padding(
              padding: EdgeInsets.all(12),
              child: Text(
                'Cửa hàng chưa có nhóm hàng. Thêm nhóm ở web quản trị → Hàng hoá → Nhóm hàng.',
                style: TextStyle(color: OrderColors.muted),
              ),
            ),
          for (final c in leaves)
            _Radio(
              key: Key('cat_${c.id}'),
              label: c.name,
              sub: categoryParents(c, all).join(' › '),
              on: _d.category?.id == c.id,
              onTap: () => Navigator.of(ctx).pop(c),
            ),
          if (leaves.isNotEmpty)
            const Padding(
              padding: EdgeInsets.fromLTRB(12, 4, 12, 0),
              child: Text(
                'Chỉ chọn được nhóm nhỏ nhất, giống PC.',
                style: TextStyle(color: OrderColors.faint, fontSize: 12),
              ),
            ),
        ],
      ),
    );
    if (picked == null) return;
    _touch(() {
      _d.category = picked;
      // VAT theo nhóm hàng — trừ khi người dùng đã tự chọn.
      if (!_d.vatTouched) _d.vat = picked.vat;
    });
    _clearBad('category_id');
  }

  String _categoryText(List<GoodsCategory> all) {
    final c = _d.category;
    return c == null ? '' : [...categoryParents(c, all), c.name].join(' › ');
  }

  Future<(int, String)?> _pickUnit(FormSources src, {required bool main, (int, String)? cur}) async {
    final used = {_d.unit?.$1, for (final (u, _) in _d.convs) u?.$1}..remove(cur?.$1);
    return showDashSheet<(int, String)>(
      context,
      title: main ? 'Đơn vị chính' : 'Đơn vị quy đổi',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (main) _Radio(label: 'Không đặt', on: cur == null, onTap: () => Navigator.of(ctx).pop((0, ''))),
          for (final u in src.units)
            if (!used.contains(u.$1))
              _Radio(key: Key('unit_${u.$1}'), label: u.$2, on: cur?.$1 == u.$1, onTap: () => Navigator.of(ctx).pop(u)),
          if (src.units.isEmpty)
            const Padding(
              padding: EdgeInsets.all(12),
              child: Text('Cần thêm đơn vị ở Cài đặt → Đơn vị tính.', style: TextStyle(color: OrderColors.muted)),
            ),
        ],
      ),
    );
  }

  Future<void> _pickBranches(List<Branch> branches) async {
    await showDashSheet<void>(
      context,
      title: 'Chi nhánh quản lý',
      body: (ctx) => StatefulBuilder(
        builder: (ctx, set) => Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _Radio(
              label: 'Tất cả chi nhánh',
              on: _d.shopIds.isEmpty,
              onTap: () {
                _touch(_d.shopIds.clear);
                set(() {});
              },
            ),
            for (final b in branches)
              _Radio(
                label: b.name,
                on: _d.shopIds.contains(b.id),
                onTap: () {
                  _touch(() {
                    _d.shopIds.contains(b.id) ? _d.shopIds.remove(b.id) : _d.shopIds.add(b.id);
                    // Chọn đủ mọi chi nhánh = "Tất cả" (gửi rỗng: hàng dùng chung mọi chi nhánh).
                    if (_d.shopIds.length == branches.length) _d.shopIds.clear();
                  });
                  set(() {});
                },
              ),
            const SizedBox(height: 10),
            GoodsBtn(label: 'Xong', primary: true, onTap: () => Navigator.of(ctx).pop()),
          ],
        ),
      ),
    );
  }

  Future<void> _addAttr(FormSources src) async {
    if (_d.picks.length >= 3) return _toast('Tối đa 3 thuộc tính cho một sản phẩm');
    final used = {for (final p in _d.picks) p.attr.id};
    final a = await showDashSheet<AttrDef>(
      context,
      title: 'Chọn thuộc tính',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final a in src.attrs)
            if (!used.contains(a.id))
              _Radio(
                key: Key('attr_${a.id}'),
                label: a.name,
                sub: '${a.values.length} giá trị',
                on: false,
                onTap: () => Navigator.of(ctx).pop(a),
              ),
          if (src.attrs.isEmpty)
            const Padding(
              padding: EdgeInsets.all(12),
              child: Text(
                'Cửa hàng chưa có thuộc tính. Thêm ở web quản trị → Hàng hoá → Thuộc tính.',
                style: TextStyle(color: OrderColors.muted),
              ),
            ),
        ],
      ),
    );
    if (a != null) {
      _touch(() => _d.picks.add(AttrPick(a)));
      _clearBad('attrs');
    }
  }

  Future<void> _scanInto(void Function(String) set) async {
    await Navigator.of(context, rootNavigator: true).push(
      MaterialPageRoute<void>(
        fullscreenDialog: true,
        builder: (_) => ScannerScreen(
          onCode: (code) async {
            _touch(() => set(code));
            return true;
          },
        ),
      ),
    );
  }

  // ---------- Lưu ----------

  void _readFields() {
    _d
      ..name = _name.text
      ..price = _numOf(_price.text)
      ..cost = _numOf(_cost.text)
      ..sale = _numOf(_sale.text)
      ..weight = _numOf(_weight.text) ?? 0
      ..sku = _sku.text
      ..barcode = _barcode.text
      ..description = _desc.text;
    _addTag();
  }

  bool _validate(FormSources src) {
    _readFields();
    final bad = <String, String>{
      if (_d.name.trim().isEmpty) 'name': 'Nhập tên sản phẩm',
      if (_d.category == null) 'category_id': 'Chọn nhóm hàng',
      // Như PC: giá bán không được để trống (0 vẫn hợp lệ); mã hàng bắt buộc khi shop tắt tự sinh mã.
      if (_d.price == null) 'base_price': 'Nhập giá bán',
      if (!src.autoSku && _d.sku.trim().isEmpty) 'sku': 'Nhập mã hàng',
    };
    String? msg;
    if (_d.multi && _d.live.isEmpty) {
      bad['attrs'] = '';
      msg = 'Chọn giá trị thuộc tính để tạo ít nhất một phiên bản';
    }
    if (_d.convs.any((c) => c.$1 == null || c.$2 <= 0)) {
      bad['convs'] = '';
      msg ??= 'Quy đổi đơn vị: chọn đơn vị và nhập số lượng';
    }
    if (_d.sale != null && _d.sale! > 0 && _d.sale! >= (_d.price ?? 0)) {
      bad['sale'] = 'Giá khuyến mãi phải thấp hơn giá bán';
      msg ??= 'Giá khuyến mãi phải thấp hơn giá bán';
    }
    setState(() {
      _bad = bad;
      if (bad.keys.any((k) => const {'sku', 'convs', 'sale'}.contains(k))) _moreOpen = true;
    });
    if (msg != null) _toast(msg);
    if (bad.isNotEmpty) {
      final first = _keys.entries.firstWhere((e) => bad.containsKey(e.key), orElse: () => _keys.entries.first);
      WidgetsBinding.instance.addPostFrameCallback((_) {
        final ctx = first.value.currentContext;
        if (ctx != null && ctx.mounted) {
          Scrollable.ensureVisible(ctx, alignment: .1, duration: const Duration(milliseconds: 250));
        }
      });
    }
    return bad.isEmpty;
  }

  Future<void> _save(FormSources src, {bool again = false}) async {
    if (_saving || _savingMore || _loading) return;
    if (_uploading > 0 || _uploadingVariant.isNotEmpty) return _toast('Đang tải ảnh lên, chờ chút rồi lưu');
    if (!_validate(src)) return;
    setState(() => again ? _savingMore = true : _saving = true);
    try {
      if (_edit) {
        await updateProduct(ref, _d.editId!, _d.payload());
        if (!mounted) return;
        _toast('Đã lưu thay đổi');
        Navigator.of(context).pop('saved');
        return;
      }
      final d = await createProduct(ref, _d.payload());
      if (!mounted) return;
      final name = '${d['name'] ?? _d.name}', sku = '${d['sku'] ?? ''}';
      final variants = ((d['variants'] as List?) ?? const []).whereType<Map>().toList();
      if (again) {
        _toast('Đã thêm “$name” ($sku). Nhập sản phẩm tiếp theo.');
        _reset(keepCategory: true);
      } else {
        _dirty = false;
        await _done(d, name, sku, variants);
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      final f = e.fieldErrors;
      setState(() {
        _bad = {
          for (final k in ['name', 'sku', 'category_id', 'base_price'])
            if (f[k] != null) k: f[k]!,
        };
        if (_bad.containsKey('sku')) _moreOpen = true;
      });
      if (_bad.isEmpty) _toast(e.message);
    } finally {
      if (mounted) {
        setState(() {
          _saving = false;
          _savingMore = false;
        });
      }
    }
  }

  void _reset({bool keepCategory = false}) {
    final cat = keepCategory ? _d.category : null;
    for (final c in [_name, _price, _cost, _sale, _weight, _sku, _barcode, _desc, _tag, ..._ctl.values]) {
      c.clear();
    }
    setState(() {
      _d = ProductDraft()
        ..category = cat
        ..vat = cat?.vat ?? 0;
      _bad = const {};
      _dirty = false;
    });
    if (_scroll.hasClients) _scroll.jumpTo(0);
    _nameFocus.requestFocus();
  }

  /// Ngăn "Đã thêm sản phẩm": bước tiếp theo hay làm ngay sau khi thêm hàng.
  Future<void> _done(Map<String, dynamic> d, String name, String sku, List<Map> variants) async {
    final id = (d['id'] as num).toInt();
    final pick = await showModalBottomSheet<String>(
      context: context,
      useRootNavigator: true,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (ctx) => _DoneSheet(name: name, sku: sku, variants: variants.length),
    );
    if (!mounted) return;
    switch (pick) {
      case 'stock':
        await _openingStock(variants);
        if (mounted) await _done(d, name, sku, variants);
      case 'label':
        _toast('In tem mã vạch trên mobile — sắp có');
        if (mounted) await _done(d, name, sku, variants);
      case 'view':
        Navigator.of(context).pop(id);
      case 'again':
        _reset();
      default:
        Navigator.of(context).pop();
    }
  }

  Future<void> _openingStock(List<Map> variants) async {
    final qty = await showModalBottomSheet<Map<int, int>>(
      context: context,
      useRootNavigator: true,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (ctx) => _StockSheet(variants: variants, cost: _d.cost),
    );
    if (qty == null || qty.values.every((q) => q <= 0) || !mounted) return;
    try {
      await addOpeningStock(ref, qty, unitCost: _d.cost);
      if (mounted) _toast('Đã nhập ${dot(qty.values.fold<int>(0, (a, b) => a + b))} vào kho');
    } on ApiException catch (e) {
      if (mounted) _toast(e.message);
    }
  }

  Future<void> _delete() async {
    final stock = ref.read(goodsCatalogProvider).value?.byId(_d.editId!)?.stock ?? 0;
    final ok = await showDashSheet<bool>(
      context,
      title: 'Xoá sản phẩm?',
      subtitle: [
        '“${_d.name}” sẽ không còn ở danh sách, quầy và web bán hàng. Đơn và sổ kho cũ vẫn giữ.',
        if (stock > 0) 'Sản phẩm còn tồn ${dot(stock)} ở chi nhánh này.',
      ].join(' '),
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SheetButton(
            key: const Key('form_delete_yes'),
            label: 'Xoá sản phẩm',
            icon: AppIcons.trash,
            danger: true,
            onTap: () => Navigator.of(ctx).pop(true),
          ),
          SheetButton(label: 'Thôi', onTap: () => Navigator.of(ctx).pop(false)),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    setState(() => _deleting = true);
    try {
      await deleteProduct(ref, _d.editId!);
      if (!mounted) return;
      _toast('Đã xoá ${_d.name}');
      Navigator.of(context).pop('deleted');
    } on ApiException catch (e) {
      if (mounted) _toast(e.message);
    } finally {
      if (mounted) setState(() => _deleting = false);
    }
  }

  Future<void> _close() async {
    _readFields();
    if (!_dirty) return Navigator.of(context).pop();
    final drop = await showDashSheet<bool>(
      context,
      title: 'Bỏ sản phẩm đang nhập?',
      subtitle: 'Thông tin đã nhập sẽ mất.',
      body: (ctx) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SheetButton(
            key: const Key('form_discard'),
            label: 'Bỏ',
            icon: AppIcons.close,
            danger: true,
            onTap: () => Navigator.of(ctx).pop(true),
          ),
          SheetButton(label: 'Nhập tiếp', onTap: () => Navigator.of(ctx).pop(false)),
        ],
      ),
    );
    if (drop == true && mounted) Navigator.of(context).pop();
  }

  void _addTag() {
    final v = _tag.text.replaceAll(',', '').trim();
    if (v.isNotEmpty && !_d.tags.any((t) => t.toLowerCase() == v.toLowerCase())) _d.tags.add(v);
    _tag.clear();
  }

  // ---------- Dựng màn ----------

  @override
  Widget build(BuildContext context) {
    final src = ref.watch(productFormSourcesProvider).value ?? const FormSources();
    final cats = ref.watch(goodsCatalogProvider).value?.categories ?? const [];
    final branches = ref.watch(dashBranchesProvider).value ?? const [];
    final tax = src.taxes.firstWhere((t) => t.key == _taxKey, orElse: () => src.taxes.first);
    final top = MediaQuery.paddingOf(context).top;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _close();
      },
      child: AnnotatedRegion<SystemUiOverlayStyle>(
        value: SystemUiOverlayStyle.dark,
        child: Scaffold(
          backgroundColor: OrderColors.page,
          bottomNavigationBar: Container(
            padding: EdgeInsets.fromLTRB(12, 10, 12, MediaQuery.viewPaddingOf(context).bottom + 10),
            decoration: const BoxDecoration(
              color: Colors.white,
              boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, -1))],
            ),
            child: Row(
              children: [
                Expanded(
                  child: _edit
                      ? _BigBtn(
                          key: const Key('form_delete'),
                          label: 'Xoá',
                          danger: true,
                          busy: _deleting,
                          onTap: _delete,
                        )
                      : _BigBtn(
                          key: const Key('form_save_more'),
                          label: 'Lưu & thêm tiếp',
                          busy: _savingMore,
                          onTap: () => _save(src, again: true),
                        ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _BigBtn(
                    key: const Key('form_save'),
                    label: _edit ? 'Lưu thay đổi' : 'Lưu sản phẩm',
                    primary: true,
                    busy: _saving,
                    onTap: () => _save(src),
                  ),
                ),
              ],
            ),
          ),
          body: Column(
            children: [
              Container(
                height: top + 52,
                padding: EdgeInsets.fromLTRB(6, top, 6, 0),
                decoration: const BoxDecoration(
                  color: Colors.white,
                  boxShadow: [BoxShadow(color: OrderColors.line, offset: Offset(0, 1))],
                ),
                child: Row(
                  children: [
                    IconButton(
                      tooltip: 'Đóng',
                      onPressed: _close,
                      icon: const AppIcon(AppIcons.close, size: 22, color: OrderColors.ink),
                    ),
                    Expanded(
                      child: Text(
                        _edit ? 'Sửa sản phẩm' : 'Thêm sản phẩm',
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: OrderColors.ink, fontSize: 17, fontWeight: FontWeight.w700),
                      ),
                    ),
                    TextButton(
                      key: const Key('form_save_top'),
                      onPressed: () => _save(src),
                      style: TextButton.styleFrom(
                        foregroundColor: OrderColors.primaryInk,
                        minimumSize: const Size(56, 36),
                        textStyle: const TextStyle(fontFamily: appFont, fontSize: 15, fontWeight: FontWeight.w700),
                      ),
                      child: const Text('Lưu'),
                    ),
                  ],
                ),
              ),
              if (_loading)
                const Expanded(
                  child: Center(child: CircularProgressIndicator(color: OrderColors.primary)),
                )
              else
                Expanded(
                  child: ListView(
                    controller: _scroll,
                    padding: const EdgeInsets.only(bottom: 12),
                    keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                    children: [_basics(cats), _variants(src), _details(src, tax, branches)],
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _basics(List<GoodsCategory> cats) {
    final price = _numOf(_price.text), cost = _numOf(_cost.text);
    return _Blk(
      first: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            height: 72,
            child: ListView(
              scrollDirection: Axis.horizontal,
              children: [
                if (_d.images.length + _uploading < _maxPics)
                  _AddPic(
                    key: const Key('form_image'),
                    label: _d.images.isEmpty ? 'Thêm ảnh' : '${_d.images.length}/$_maxPics',
                    onTap: _addPics,
                  ),
                for (final (i, u) in _d.images.indexed)
                  _Pic(
                    url: u,
                    main: i == 0,
                    onRemove: () => _touch(() {
                      _d.images.removeAt(i);
                      _d.imagesTouched = true;
                    }),
                  ),
                for (var i = 0; i < _uploading; i++) const _Pic(url: ''),
              ],
            ),
          ),
          const SizedBox(height: 14),
          _Fld(
            key: _keys['name'],
            label: 'Tên sản phẩm',
            req: true,
            error: _bad['name'],
            child: _In(
              key: const Key('form_name'),
              controller: _name,
              focus: _nameFocus,
              hint: 'VD: iPhone 15 Pro Max 256GB',
              capitalize: true,
              bad: _bad.containsKey('name'),
              onChanged: (_) {
                _dirty = true;
                _clearBad('name');
              },
            ),
          ),
          _Fld(
            key: _keys['category_id'],
            label: 'Nhóm hàng',
            req: true,
            error: _bad['category_id'],
            child: _Pick(
              key: const Key('form_category'),
              value: _d.category == null ? null : _categoryText(cats),
              hint: 'Chọn nhóm hàng',
              bad: _bad.containsKey('category_id'),
              onTap: _pickCategory,
            ),
          ),
          _Two(
            _Fld(
              key: _keys['base_price'],
              label: 'Giá bán',
              req: true,
              error: _bad['base_price'],
              last: true,
              child: _In(
                key: const Key('form_price'),
                controller: _price,
                hint: '0',
                number: true,
                suffix: 'đ',
                bad: _bad.containsKey('base_price'),
                onChanged: (_) {
                  _clearBad('base_price');
                  _touch(() => _d.price = _numOf(_price.text));
                },
              ),
            ),
            _Fld(
              label: 'Giá vốn',
              last: true,
              child: _In(
                key: const Key('form_cost'),
                controller: _cost,
                hint: '0',
                number: true,
                suffix: 'đ',
                onChanged: (_) => _touch(() => _d.cost = _numOf(_cost.text)),
              ),
            ),
          ),
          if (price != null && price > 0 && cost != null) _Gain(price: price, cost: cost),
        ],
      ),
    );
  }

  Widget _variants(FormSources src) {
    final live = _d.multi ? _d.live : const <(Combo, ComboState)>[];
    if (_d.rawVariants != null && !_d.multi) {
      return _Blk(
        child: Text(
          'Sản phẩm có ${_d.rawVariants!.length} phiên bản chưa gắn thuộc tính — giữ nguyên khi lưu. '
          'Sửa từng phiên bản ở web quản trị.',
          key: const Key('form_raw_variants'),
          style: const TextStyle(color: OrderColors.muted, fontSize: 12.5, height: 1.45),
        ),
      );
    }
    return _Blk(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _Switch(
            key: const Key('form_multi'),
            title: 'Có nhiều phiên bản',
            sub: 'Màu, dung lượng, size… mỗi tổ hợp có giá, mã vạch, ảnh riêng',
            on: _d.multi,
            flush: true,
            onChanged: (v) {
              _touch(() => _d.multi = v);
              if (v && _d.picks.isEmpty) _addAttr(src);
            },
          ),
          if (_d.multi) ...[
            for (final (i, p) in _d.picks.indexed) _attrCard(i, p),
            if (_d.picks.length < 3)
              _DashedBtn(
                key: _keys['attrs'],
                label: 'Thêm thuộc tính',
                bad: _bad.containsKey('attrs'),
                onTap: () => _addAttr(src),
              ),
            if (live.isEmpty)
              const Padding(
                padding: EdgeInsets.only(top: 10),
                child: Text(
                  'Chọn giá trị cho từng thuộc tính để tạo phiên bản.',
                  style: TextStyle(color: OrderColors.faint, fontSize: 12),
                ),
              )
            else ...[
              Padding(
                padding: const EdgeInsets.only(top: 14, bottom: 4),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        '${live.length} PHIÊN BẢN',
                        style: const TextStyle(
                          color: OrderColors.muted,
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          letterSpacing: .5,
                        ),
                      ),
                    ),
                    GestureDetector(
                      key: const Key('combo_recalc'),
                      onTap: () => _touch(() {
                        for (final s in _d.states.values) {
                          s.edited = false;
                        }
                        for (final (c, st) in _d.live) {
                          _c('p:${c.key}').text = dot(st.price);
                        }
                      }),
                      child: const Text(
                        'Tính lại giá',
                        style: TextStyle(color: OrderColors.primaryInk, fontSize: 12, fontWeight: FontWeight.w600),
                      ),
                    ),
                  ],
                ),
              ),
              for (final (c, st) in live) _comboRow(c, st),
            ],
          ],
        ],
      ),
    );
  }

  Widget _attrCard(int i, AttrPick p) => Container(
    margin: const EdgeInsets.only(top: 12),
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      border: Border.all(color: OrderColors.line),
      borderRadius: BorderRadius.circular(12),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                p.attr.name,
                style: const TextStyle(color: OrderColors.ink, fontSize: 13.5, fontWeight: FontWeight.w700),
              ),
            ),
            _SquareBtn(icon: AppIcons.trash, tooltip: 'Bỏ thuộc tính', onTap: () => _touch(() => _d.picks.removeAt(i))),
          ],
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final (id, name) in p.attr.values)
              GoodsChip(
                key: Key('val_$id'),
                label: name,
                on: p.values.contains(id),
                fontSize: 12.5,
                onTap: () {
                  _touch(() {
                    p.toggle(id);
                    _d.defaultTouched = true;
                  });
                  _clearBad('attrs');
                },
              ),
          ],
        ),
        if (p.values.isNotEmpty) ...[
          const SizedBox(height: 10),
          const Row(
            children: [
              Expanded(
                child: Text('Mặc định · giá trị', style: TextStyle(color: OrderColors.faint, fontSize: 11.5)),
              ),
              Text('Cộng thêm vào giá', style: TextStyle(color: OrderColors.faint, fontSize: 11.5)),
            ],
          ),
          for (final v in p.values)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                children: [
                  _Dot(
                    key: Key('def_$v'),
                    on: p.def == v,
                    onTap: () => _touch(() {
                      p.def = v;
                      _d.defaultTouched = true;
                    }),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text.rich(
                      TextSpan(
                        text: p.attr.valueName(v),
                        children: [
                          if (p.def == v)
                            const TextSpan(
                              text: '  mặc định',
                              style: TextStyle(
                                color: OrderColors.primaryInk,
                                fontSize: 11,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                        ],
                      ),
                      style: const TextStyle(color: OrderColors.ink, fontSize: 13.5, fontWeight: FontWeight.w600),
                    ),
                  ),
                  const Text('+ ', style: TextStyle(color: OrderColors.muted)),
                  SizedBox(
                    width: 112,
                    child: _In(
                      key: Key('extra_$v'),
                      controller: _c('x:$v'),
                      hint: '0',
                      number: true,
                      dense: true,
                      onChanged: (t) => _touch(() => p.extra[v] = _numOf(t) ?? 0),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ],
    ),
  );

  Widget _comboRow(Combo c, ComboState st) {
    final pc = _c('p:${c.key}', dot(st.price));
    // Giá tự tính (chưa sửa tay) đi theo giá gốc + cộng thêm.
    if (!st.edited && _numOf(pc.text) != st.price) pc.text = dot(st.price);
    return Container(
      key: Key('combo_${c.key}'),
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: const BoxDecoration(
        border: Border(top: BorderSide(color: OrderColors.line)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              InkWell(
                borderRadius: BorderRadius.circular(10),
                onTap: () => _variantPic(c.key),
                child: _uploadingVariant.contains(c.key)
                    ? const SizedBox(
                        width: 40,
                        height: 40,
                        child: Center(
                          child: SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2, color: OrderColors.primary),
                          ),
                        ),
                      )
                    : st.image.isEmpty
                    ? Container(
                        width: 40,
                        height: 40,
                        decoration: BoxDecoration(
                          color: const Color(0xFFF8FAFC),
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: const Color(0xFFC7D2E0)),
                        ),
                        child: const Center(child: AppIcon(AppIcons.image, size: 18, color: OrderColors.faint)),
                      )
                    : GoodsImage(url: st.image, size: 40, radius: 10),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text.rich(
                  TextSpan(
                    text: c.name,
                    children: [
                      if (c.isDefault)
                        const WidgetSpan(
                          alignment: PlaceholderAlignment.middle,
                          child: Padding(
                            padding: EdgeInsets.only(left: 6),
                            child: SmallTag('Mặc định', (OrderColors.primarySoft, OrderColors.primaryInk)),
                          ),
                        ),
                    ],
                  ),
                  style: const TextStyle(color: OrderColors.ink, fontSize: 13.5, fontWeight: FontWeight.w600),
                ),
              ),
              const SizedBox(width: 8),
              SizedBox(
                width: 112,
                child: _In(
                  key: Key('cprice_${c.key}'),
                  controller: pc,
                  hint: 'Giá',
                  number: true,
                  dense: true,
                  onChanged: (t) => _touch(() {
                    st.price = _numOf(t) ?? 0;
                    st.edited = true;
                  }),
                ),
              ),
              _SquareBtn(
                icon: AppIcons.close,
                tooltip: 'Bỏ phiên bản',
                plain: true,
                onTap: () => _touch(() => st.on = false),
              ),
            ],
          ),
          Padding(
            padding: const EdgeInsets.only(left: 50, top: 8),
            child: Row(
              children: [
                Expanded(
                  child: _In(
                    key: Key('cbar_${c.key}'),
                    controller: _c('b:${c.key}', st.barcode),
                    hint: 'Mã vạch',
                    dense: true,
                    keyboard: TextInputType.number,
                    lead: const AppIcon(AppIcons.barcode, size: 16, color: OrderColors.faint),
                    onChanged: (t) => st.barcode = t.trim(),
                    trailing: _SquareBtn(
                      icon: AppIcons.scan,
                      tooltip: 'Quét mã vạch',
                      onTap: () => _scanInto((code) {
                        st.barcode = code;
                        _c('b:${c.key}').text = code;
                      }),
                    ),
                  ),
                ),
                const SizedBox(width: 6),
                _SquareBtn(
                  key: Key('copen_${c.key}'),
                  icon: AppIcons.filter,
                  tooltip: 'SKU và giá vốn riêng',
                  boxed: true,
                  on: st.open,
                  onTap: () => setState(() => st.open = !st.open),
                ),
              ],
            ),
          ),
          if (st.open)
            Padding(
              padding: const EdgeInsets.only(left: 50, top: 8),
              child: _Two(
                _Fld(
                  label: 'SKU',
                  small: true,
                  last: true,
                  child: _In(
                    controller: _c('s:${c.key}', st.sku),
                    hint: 'Tự sinh',
                    dense: true,
                    upper: true,
                    onChanged: (t) => st.sku = t.trim(),
                  ),
                ),
                _Fld(
                  label: 'Giá vốn riêng',
                  small: true,
                  last: true,
                  child: _In(
                    controller: _c('c:${c.key}', st.cost == null ? '' : dot(st.cost!)),
                    hint: _cost.text.isEmpty ? 'Theo SP' : _cost.text,
                    dense: true,
                    number: true,
                    onChanged: (t) => st.cost = _numOf(t),
                  ),
                ),
                gap: 8,
              ),
            ),
        ],
      ),
    );
  }

  String _summary(FormSources src) {
    final sku = _sku.text.trim();
    return [
      sku.isNotEmpty
          ? 'SKU $sku'
          : src.autoSku
          ? 'SKU tự sinh'
          : 'Chưa có SKU',
      'VAT ${vatName(_d.vat)}',
      if (_d.unit != null) '${_d.unit!.$2}${_d.convs.isEmpty ? '' : ' +${_d.convs.length} quy đổi'}',
      if (!_d.active) 'Lưu nháp',
      if (!_d.deduct) 'Không trừ kho',
      if (_d.serial) 'IMEI',
      if (_d.shopIds.isNotEmpty) '${_d.shopIds.length} chi nhánh',
      if (_d.tags.isNotEmpty) '${_d.tags.length} thẻ',
    ].join(' · ');
  }

  Widget _details(FormSources src, TaxType tax, List<Branch> branches) {
    final price = _numOf(_price.text) ?? 0;
    final levels = [...tax.levels, if (!tax.levels.contains(_d.vat)) _d.vat];
    return _Blk(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InkWell(
            key: const Key('form_more'),
            onTap: () => setState(() => _moreOpen = !_moreOpen),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Thêm chi tiết',
                        style: TextStyle(color: OrderColors.ink, fontSize: 14.5, fontWeight: FontWeight.w700),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        _summary(src),
                        key: const Key('form_more_sum'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(color: OrderColors.muted, fontSize: 12),
                      ),
                    ],
                  ),
                ),
                AnimatedRotation(
                  turns: _moreOpen ? .5 : 0,
                  duration: const Duration(milliseconds: 200),
                  child: const AppIcon(AppIcons.down, size: 18, color: OrderColors.muted),
                ),
              ],
            ),
          ),
          if (_moreOpen) ...[
            _Grp('Mã hàng', [
              _Two(
                _Fld(
                  key: _keys['sku'],
                  label: 'Mã SKU',
                  error: _bad['sku'],
                  last: true,
                  child: _In(
                    key: const Key('form_sku'),
                    controller: _sku,
                    hint: src.autoSku ? (src.skuPrefix.isEmpty ? 'Tự sinh' : '${src.skuPrefix}…') : 'Bắt buộc',
                    upper: true,
                    bad: _bad.containsKey('sku'),
                    suffix: src.autoSku && _sku.text.isEmpty ? 'tự sinh' : '',
                    onChanged: (_) {
                      _clearBad('sku');
                      _touch();
                    },
                  ),
                ),
                _Fld(
                  label: 'Mã vạch',
                  last: true,
                  child: _In(
                    key: const Key('form_barcode'),
                    controller: _barcode,
                    hint: 'Quét / nhập',
                    keyboard: TextInputType.number,
                    onChanged: (_) => _dirty = true,
                    trailing: _SquareBtn(
                      icon: AppIcons.scan,
                      tooltip: 'Quét mã vạch',
                      onTap: () => _scanInto((code) => _barcode.text = code),
                    ),
                  ),
                ),
              ),
              if (_d.multi)
                const Padding(
                  padding: EdgeInsets.only(top: 6),
                  child: Text(
                    'Hàng nhiều phiên bản: mã vạch này gắn cho phiên bản mặc định (như PC); phiên bản khác nhập mã riêng ở trên.',
                    style: TextStyle(color: OrderColors.faint, fontSize: 12, height: 1.45),
                  ),
                ),
            ]),
            _Grp('Đơn vị tính', [
              _Pick(
                key: const Key('form_unit'),
                value: _d.unit?.$2,
                hint: 'Chọn đơn vị chính (cái, chai…)',
                onTap: () async {
                  final u = await _pickUnit(src, main: true, cur: _d.unit);
                  if (u == null) return;
                  _touch(() {
                    _d.unit = u.$1 == 0 ? null : u;
                    if (_d.unit == null) _d.convs.clear();
                  });
                },
              ),
              Container(
                key: _keys['convs'],
                child: Column(
                  children: [
                    for (final (i, (u, q)) in _d.convs.indexed)
                      Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: Row(
                          children: [
                            const Text('1 ', style: TextStyle(fontSize: 13.5)),
                            Expanded(
                              child: _Pick(
                                key: Key('conv_unit_$i'),
                                value: u?.$2,
                                hint: 'Đơn vị',
                                bad: _bad.containsKey('convs') && u == null,
                                dense: true,
                                onTap: () async {
                                  final nu = await _pickUnit(src, main: false, cur: u);
                                  if (nu != null) _touch(() => _d.convs[i] = (nu, _d.convs[i].$2));
                                },
                              ),
                            ),
                            const Text(' = ', style: TextStyle(fontSize: 13.5)),
                            SizedBox(
                              width: 84,
                              child: _In(
                                key: Key('conv_q_$i'),
                                controller: _c('q:$i', q > 0 ? '$q' : ''),
                                hint: 'SL',
                                number: true,
                                dense: true,
                                bad: _bad.containsKey('convs') && q <= 0,
                                onChanged: (t) =>
                                    _touch(() => _d.convs[i] = (_d.convs[i].$1, (_numOf(t) ?? 0).toInt())),
                              ),
                            ),
                            Text(' ${_d.unit?.$2 ?? ''}', style: const TextStyle(fontSize: 13.5)),
                            _SquareBtn(
                              icon: AppIcons.close,
                              tooltip: 'Bỏ quy đổi',
                              plain: true,
                              onTap: () => _touch(() {
                                _d.convs.removeAt(i);
                                // Ô số lượng theo vị trí dòng — xoá dòng thì dựng lại các ô.
                                _ctl.removeWhere((k, _) => k.startsWith('q:'));
                              }),
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
              ),
              if (_d.unit != null && _d.convs.length < src.units.length - 1)
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    key: const Key('form_add_conv'),
                    onPressed: () async {
                      _touch(() => _d.convs.add((null, 0)));
                      final i = _d.convs.length - 1;
                      final nu = await _pickUnit(src, main: false);
                      if (nu != null) _touch(() => _d.convs[i] = (nu, _d.convs[i].$2));
                    },
                    style: TextButton.styleFrom(
                      foregroundColor: OrderColors.primaryInk,
                      padding: EdgeInsets.zero,
                      textStyle: const TextStyle(fontFamily: appFont, fontSize: 13.5, fontWeight: FontWeight.w600),
                    ),
                    icon: const AppIcon(AppIcons.plus, size: 16, color: OrderColors.primaryInk),
                    label: const Text('Thêm đơn vị quy đổi'),
                  ),
                ),
            ]),
            _Grp('Giá & thuế', [
              _Two(
                _Fld(
                  key: _keys['sale'],
                  label: 'Giá khuyến mãi',
                  error: _bad['sale'],
                  last: true,
                  child: _In(
                    key: const Key('form_sale'),
                    controller: _sale,
                    hint: 'Không có',
                    number: true,
                    suffix: 'đ',
                    bad: _bad.containsKey('sale'),
                    onChanged: (_) {
                      _clearBad('sale');
                      _dirty = true;
                    },
                  ),
                ),
                _Fld(
                  label: 'Cân nặng',
                  last: true,
                  child: _In(
                    key: const Key('form_weight'),
                    controller: _weight,
                    hint: '0',
                    number: true,
                    suffix: 'g',
                    onChanged: (_) {
                      _dirty = true;
                      _d.weightTouched = true;
                    },
                  ),
                ),
              ),
              const SizedBox(height: 14),
              _Fld(
                label: 'Loại thuế',
                child: _Pick(
                  key: const Key('form_tax'),
                  value: tax.name,
                  hint: '',
                  onTap: () async {
                    final t = await showDashSheet<TaxType>(
                      context,
                      title: 'Loại thuế',
                      body: (ctx) => Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          for (final t in src.taxes)
                            _Radio(
                              key: Key('tax_${t.key}'),
                              label: t.name,
                              sub: t.levels.map(vatName).join(', '),
                              on: t.key == tax.key,
                              onTap: () => Navigator.of(ctx).pop(t),
                            ),
                        ],
                      ),
                    );
                    if (t != null) {
                      _touch(() {
                        _taxKey = t.key;
                        _d.vat = t.levels.first;
                        _d.vatTouched = true;
                      });
                    }
                  },
                ),
              ),
              _Fld(
                label: '% VAT',
                note: _d.category != null && !_d.vatTouched ? 'theo nhóm ${_d.category!.name}' : null,
                last: true,
                child: Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    for (final v in levels)
                      GoodsChip(
                        key: Key('vat_$v'),
                        label: vatName(v),
                        on: _d.vat == v,
                        height: 34,
                        fontSize: 13,
                        onTap: () => _touch(() {
                          _d.vat = v;
                          _d.vatTouched = true;
                        }),
                      ),
                  ],
                ),
              ),
              // Giá sau thuế: chỉ khi có giá và mức VAT > 0 (KCT/KKKNT không cộng thuế) — như ô khoá sẵn bên PC.
              if (price > 0 && _d.vat > 0)
                Container(
                  margin: const EdgeInsets.only(top: 10),
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                  decoration: BoxDecoration(color: OrderColors.grid, borderRadius: BorderRadius.circular(10)),
                  child: Row(
                    children: [
                      const Expanded(
                        child: Text('Giá sau thuế', style: TextStyle(color: OrderColors.muted, fontSize: 13)),
                      ),
                      Text(
                        '${dot(price * (1 + _d.vat / 100))}đ',
                        key: const Key('form_after_tax'),
                        style: const TextStyle(
                          color: OrderColors.ink,
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          fontFeatures: tnum,
                        ),
                      ),
                    ],
                  ),
                ),
            ]),
            _Grp('Kho & bán hàng', [
              _Switch(
                key: const Key('sw_active'),
                title: 'Đang bán',
                sub: 'Tắt để lưu nháp, chưa bày ra quầy',
                on: _d.active,
                first: true,
                onChanged: (v) => _touch(() => _d.active = v),
              ),
              _Switch(
                key: const Key('sw_deduct'),
                title: 'Trừ kho khi bán',
                sub: 'Tắt với dịch vụ, combo',
                on: _d.deduct,
                onChanged: (v) => _touch(() => _d.deduct = v),
              ),
              _Switch(
                key: const Key('sw_serial'),
                title: 'Quản lý IMEI / serial',
                sub: 'Nhập và bán theo từng số máy',
                on: _d.serial,
                onChanged: (v) => _touch(() => _d.serial = v),
              ),
              _Switch(
                key: const Key('sw_label'),
                title: 'In tem mã vạch',
                sub: 'Có trong danh sách in tem',
                on: _d.label,
                onChanged: (v) => _touch(() => _d.label = v),
              ),
              if (branches.length > 1) ...[
                const SizedBox(height: 8),
                _Fld(
                  label: 'Chi nhánh quản lý',
                  last: src.locations.isEmpty,
                  child: _Pick(
                    key: const Key('form_branches'),
                    value: _d.shopIds.isEmpty
                        ? 'Tất cả chi nhánh'
                        : branches.where((b) => _d.shopIds.contains(b.id)).map((b) => b.name).join(', '),
                    hint: '',
                    onTap: () => _pickBranches(branches),
                  ),
                ),
              ],
              // Vị trí kho chỉ hiện khi cửa hàng đã khai vị trí.
              if (src.locations.isNotEmpty)
                _Fld(
                  label: 'Vị trí kho',
                  last: true,
                  child: _Pick(
                    value: _d.location?.$2,
                    hint: 'Chọn vị trí',
                    onTap: () async {
                      final l = await showDashSheet<(int, String)>(
                        context,
                        title: 'Vị trí kho',
                        body: (ctx) => Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            _Radio(
                              label: 'Không đặt',
                              on: _d.location == null,
                              onTap: () => Navigator.of(ctx).pop((0, '')),
                            ),
                            for (final l in src.locations)
                              _Radio(label: l.$2, on: _d.location?.$1 == l.$1, onTap: () => Navigator.of(ctx).pop(l)),
                          ],
                        ),
                      );
                      if (l != null) _touch(() => _d.location = l.$1 == 0 ? null : l);
                    },
                  ),
                ),
            ]),
            _Grp('Thẻ & mô tả', [
              _Fld(
                label: 'Thẻ hàng hoá',
                aside: 'Gõ rồi bấm Enter',
                child: Container(
                  constraints: const BoxConstraints(minHeight: 48),
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: OrderColors.line, width: 1.5),
                  ),
                  child: Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      for (final (i, t) in _d.tags.indexed)
                        Container(
                          height: 30,
                          padding: const EdgeInsets.only(left: 10, right: 2),
                          decoration: BoxDecoration(
                            color: OrderColors.primarySoft,
                            borderRadius: BorderRadius.circular(15),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                t,
                                style: const TextStyle(
                                  color: OrderColors.primaryInk,
                                  fontSize: 13,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              IconButton(
                                tooltip: 'Bỏ thẻ $t',
                                visualDensity: VisualDensity.compact,
                                constraints: const BoxConstraints.tightFor(width: 26, height: 26),
                                padding: EdgeInsets.zero,
                                onPressed: () => _touch(() => _d.tags.removeAt(i)),
                                icon: const AppIcon(AppIcons.close, size: 13, color: OrderColors.primaryInk),
                              ),
                            ],
                          ),
                        ),
                      ConstrainedBox(
                        constraints: const BoxConstraints(minWidth: 120, maxWidth: 260),
                        child: IntrinsicWidth(
                          child: TextField(
                            key: const Key('form_tag'),
                            controller: _tag,
                            textInputAction: TextInputAction.done,
                            style: const TextStyle(color: OrderColors.ink, fontSize: 15),
                            onChanged: (t) {
                              if (t.contains(',')) _touch(_addTag);
                            },
                            onSubmitted: (_) => _touch(_addTag),
                            decoration: const InputDecoration(
                              isCollapsed: true,
                              filled: false,
                              border: InputBorder.none,
                              enabledBorder: InputBorder.none,
                              focusedBorder: InputBorder.none,
                              hintText: 'VD: hàng mới, bán chạy',
                              hintStyle: TextStyle(color: OrderColors.faint, fontSize: 15),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              _Fld(
                label: 'Mô tả',
                last: true,
                child: _In(
                  key: const Key('form_desc'),
                  controller: _desc,
                  hint: 'Hiển thị ở cửa hàng online',
                  lines: 3,
                  onChanged: (_) => _dirty = true,
                ),
              ),
            ]),
          ],
        ],
      ),
    );
  }
}

// ---------- Ngăn sau khi lưu ----------

class _DoneSheet extends StatelessWidget {
  const _DoneSheet({required this.name, required this.sku, required this.variants});

  final String name;
  final String sku;
  final int variants;

  @override
  Widget build(BuildContext context) {
    Widget next(String key, AppIcons icon, String title, String sub) => InkWell(
      key: Key('done_$key'),
      onTap: () => Navigator.of(context).pop(key),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: OrderColors.line)),
        ),
        child: Row(
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(color: OrderColors.primarySoft, borderRadius: BorderRadius.circular(12)),
              child: Center(child: AppIcon(icon, color: OrderColors.primaryInk)),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: const TextStyle(color: OrderColors.ink, fontSize: 14, fontWeight: FontWeight.w700),
                  ),
                  Text(sub, style: const TextStyle(color: OrderColors.muted, fontSize: 12)),
                ],
              ),
            ),
            const AppIcon(AppIcons.right, size: 18, color: OrderColors.faint),
          ],
        ),
      ),
    );
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 10, 16, MediaQuery.viewPaddingOf(context).bottom + 12),
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
          Center(
            child: Container(
              width: 64,
              height: 64,
              margin: const EdgeInsets.only(top: 8, bottom: 12),
              decoration: BoxDecoration(color: OrderColors.green.$1, shape: BoxShape.circle),
              child: Center(child: AppIcon(AppIcons.check, size: 32, strokeWidth: 2.4, color: OrderColors.green.$2)),
            ),
          ),
          const Text(
            'Đã thêm sản phẩm',
            textAlign: TextAlign.center,
            style: TextStyle(color: OrderColors.ink, fontSize: 17, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 4),
          Text(
            [name, sku, if (variants > 1) '$variants phiên bản'].where((x) => x.isNotEmpty).join(' · '),
            key: const Key('done_sub'),
            textAlign: TextAlign.center,
            style: const TextStyle(color: OrderColors.muted, fontSize: 13),
          ),
          const SizedBox(height: 16),
          next('stock', AppIcons.truck, 'Nhập hàng ban đầu', 'Sản phẩm mới đang tồn 0'),
          next('label', AppIcons.barcode, 'In tem mã vạch', 'Dán lên sản phẩm để quét ở quầy'),
          next('view', AppIcons.eye, 'Xem sản phẩm', 'Mở chi tiết vừa tạo'),
          Container(
            padding: const EdgeInsets.only(top: 12),
            decoration: const BoxDecoration(
              border: Border(top: BorderSide(color: OrderColors.line)),
            ),
            child: Row(
              children: [
                Expanded(
                  child: GoodsBtn(
                    key: const Key('done_again'),
                    label: 'Thêm sản phẩm khác',
                    onTap: () => Navigator.of(context).pop('again'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: GoodsBtn(
                    key: const Key('done_list'),
                    label: 'Về danh sách',
                    primary: true,
                    onTap: () => Navigator.of(context).pop('list'),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Nhập tồn ban đầu cho từng phiên bản vừa tạo.
class _StockSheet extends StatefulWidget {
  const _StockSheet({required this.variants, required this.cost});

  final List<Map> variants;
  final num? cost;

  @override
  State<_StockSheet> createState() => _StockSheetState();
}

class _StockSheetState extends State<_StockSheet> {
  late final _c = [for (final _ in widget.variants) TextEditingController()];

  @override
  void dispose() {
    for (final c in _c) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    return Padding(
      padding: EdgeInsets.only(bottom: mq.viewInsets.bottom),
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: mq.size.height * .86),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: Container(
                width: 40,
                height: 5,
                margin: const EdgeInsets.only(top: 10, bottom: 12),
                decoration: BoxDecoration(color: const Color(0xFFD6DBE3), borderRadius: BorderRadius.circular(3)),
              ),
            ),
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 20),
              child: Text(
                'Nhập hàng ban đầu',
                style: TextStyle(color: OrderColors.ink, fontSize: 18, fontWeight: FontWeight.w700),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
              child: Text(
                'Ghi vào kho chi nhánh đang làm việc như một lần nhập hàng'
                '${widget.cost == null ? '' : ', giá vốn ${dot(widget.cost!)}đ'}.',
                style: const TextStyle(color: OrderColors.muted, fontSize: 12.5, height: 1.45),
              ),
            ),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                padding: const EdgeInsets.symmetric(horizontal: 20),
                children: [
                  for (final (i, v) in widget.variants.indexed)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              '${v['name'] ?? ''}'.isEmpty ? 'Số lượng' : '${v['name']}',
                              style: const TextStyle(color: OrderColors.ink, fontSize: 14, fontWeight: FontWeight.w600),
                            ),
                          ),
                          SizedBox(
                            width: 110,
                            child: _In(key: Key('stock_$i'), controller: _c[i], hint: '0', number: true, dense: true),
                          ),
                        ],
                      ),
                    ),
                ],
              ),
            ),
            Container(
              margin: const EdgeInsets.only(top: 8),
              padding: EdgeInsets.fromLTRB(16, 12, 16, mq.viewPadding.bottom + 12),
              decoration: const BoxDecoration(
                border: Border(top: BorderSide(color: OrderColors.line)),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: GoodsBtn(label: 'Để sau', onTap: () => Navigator.of(context).pop()),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: GoodsBtn(
                      key: const Key('stock_save'),
                      label: 'Nhập kho',
                      primary: true,
                      onTap: () => Navigator.of(context).pop({
                        for (final (i, v) in widget.variants.indexed)
                          (v['id'] as num).toInt(): (_numOf(_c[i].text) ?? 0).toInt(),
                      }),
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

// ---------- Mảnh giao diện ----------

class _Blk extends StatelessWidget {
  const _Blk({required this.child, this.first = false});

  final Widget child;
  final bool first;

  @override
  Widget build(BuildContext context) => Container(
    margin: EdgeInsets.only(top: first ? 0 : 8),
    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    color: Colors.white,
    child: child,
  );
}

class _Grp extends StatelessWidget {
  const _Grp(this.title, this.children);

  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(top: 18),
    padding: const EdgeInsets.only(top: 14),
    decoration: const BoxDecoration(
      border: Border(top: BorderSide(color: OrderColors.line)),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Text(
            title.toUpperCase(),
            style: const TextStyle(
              color: OrderColors.muted,
              fontSize: 11.5,
              fontWeight: FontWeight.w700,
              letterSpacing: .6,
            ),
          ),
        ),
        ...children,
      ],
    ),
  );
}

class _Two extends StatelessWidget {
  const _Two(this.a, this.b, {this.gap = 10});

  final Widget a;
  final Widget b;
  final double gap;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 0),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(child: a),
        SizedBox(width: gap),
        Expanded(child: b),
      ],
    ),
  );
}

/// Nhãn + ô + dòng lỗi.
class _Fld extends StatelessWidget {
  const _Fld({
    super.key,
    required this.label,
    required this.child,
    this.req = false,
    this.error,
    this.note,
    this.aside,
    this.last = false,
    this.small = false,
  });

  final String label;
  final Widget child;
  final bool req;
  final String? error;
  final String? note;
  final String? aside;
  final bool last;
  final bool small;

  @override
  Widget build(BuildContext context) => Padding(
    padding: EdgeInsets.only(bottom: last ? 0 : 14),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.only(bottom: small ? 4 : 6),
          child: Row(
            children: [
              Text.rich(
                TextSpan(
                  text: label,
                  children: [
                    if (req)
                      const TextSpan(
                        text: ' *',
                        style: TextStyle(color: _err),
                      ),
                  ],
                ),
                style: small
                    ? const TextStyle(color: OrderColors.muted, fontSize: 11.5)
                    : const TextStyle(color: OrderColors.text, fontSize: 13, fontWeight: FontWeight.w600),
              ),
              const SizedBox(width: 8),
              if (note != null || aside != null)
                Expanded(
                  child: Text(
                    note ?? aside!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.right,
                    style: const TextStyle(color: OrderColors.faint, fontSize: 12, fontWeight: FontWeight.w500),
                  ),
                ),
            ],
          ),
        ),
        child,
        if (error != null && error!.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(error!, style: const TextStyle(color: _err, fontSize: 12.5)),
          ),
      ],
    ),
  );
}

/// Ô nhập viền mảnh, viền xanh khi gõ, đỏ khi lỗi.
class _In extends StatefulWidget {
  const _In({
    super.key,
    required this.controller,
    required this.hint,
    this.onChanged,
    this.focus,
    this.number = false,
    this.upper = false,
    this.capitalize = false,
    this.dense = false,
    this.bad = false,
    this.lines = 1,
    this.keyboard,
    this.suffix = '',
    this.lead,
    this.trailing,
  });

  final TextEditingController controller;
  final String hint;
  final ValueChanged<String>? onChanged;
  final FocusNode? focus;
  final bool number;
  final bool upper;
  final bool capitalize;
  final bool dense;
  final bool bad;
  final int lines;
  final TextInputType? keyboard;
  final String suffix;
  final Widget? lead;
  final Widget? trailing;

  @override
  State<_In> createState() => _InState();
}

class _InState extends State<_In> {
  late final FocusNode _f = widget.focus ?? FocusNode();

  @override
  void initState() {
    super.initState();
    _f.addListener(_onFocus);
  }

  void _onFocus() => setState(() {});

  @override
  void dispose() {
    _f.removeListener(_onFocus);
    if (widget.focus == null) _f.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final w = widget;
    final on = _f.hasFocus;
    return Container(
      constraints: BoxConstraints(minHeight: w.dense ? 36 : 48),
      padding: EdgeInsets.only(left: w.dense ? 10 : 12, right: w.trailing == null ? (w.dense ? 10 : 12) : 4),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(w.dense ? 10 : 12),
        border: Border.all(
          color: w.bad
              ? _err
              : on
              ? OrderColors.primary
              : OrderColors.line,
          width: 1.5,
        ),
        boxShadow: on && !w.bad ? const [BoxShadow(color: Color(0x241677FF), spreadRadius: 3)] : null,
      ),
      child: Row(
        crossAxisAlignment: w.lines > 1 ? CrossAxisAlignment.start : CrossAxisAlignment.center,
        children: [
          if (w.lead != null) ...[w.lead!, const SizedBox(width: 6)],
          Expanded(
            child: Padding(
              padding: EdgeInsets.symmetric(vertical: w.lines > 1 ? 12 : 0),
              child: TextField(
                controller: w.controller,
                focusNode: _f,
                onChanged: w.onChanged,
                minLines: w.lines,
                maxLines: w.lines,
                textAlign: w.number && w.dense ? TextAlign.right : TextAlign.start,
                keyboardType: w.number ? TextInputType.number : (w.lines > 1 ? TextInputType.multiline : w.keyboard),
                textCapitalization: w.upper
                    ? TextCapitalization.characters
                    : w.capitalize
                    ? TextCapitalization.sentences
                    : TextCapitalization.none,
                inputFormatters: w.number ? const [_DotFormatter()] : null,
                style: TextStyle(
                  color: OrderColors.ink,
                  fontSize: w.dense ? 14 : 16,
                  fontWeight: w.number ? FontWeight.w600 : FontWeight.w400,
                  fontFeatures: w.number ? tnum : null,
                  height: 1.45,
                ),
                decoration: InputDecoration(
                  isCollapsed: true,
                  filled: false,
                  border: InputBorder.none,
                  enabledBorder: InputBorder.none,
                  focusedBorder: InputBorder.none,
                  hintText: w.hint,
                  hintStyle: TextStyle(
                    color: OrderColors.faint,
                    fontSize: w.dense ? 14 : 16,
                    fontWeight: FontWeight.w400,
                  ),
                ),
              ),
            ),
          ),
          if (w.suffix.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(left: 6),
              child: Text(
                w.suffix,
                style: TextStyle(
                  color: OrderColors.muted,
                  fontSize: w.suffix.length > 2 ? 11.5 : 14,
                  fontWeight: w.suffix.length > 2 ? FontWeight.w500 : FontWeight.w600,
                ),
              ),
            ),
          ?w.trailing,
        ],
      ),
    );
  }
}

class _Pick extends StatelessWidget {
  const _Pick({
    super.key,
    required this.value,
    required this.hint,
    required this.onTap,
    this.bad = false,
    this.dense = false,
  });

  final String? value;
  final String hint;
  final VoidCallback onTap;
  final bool bad;
  final bool dense;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.white,
    shape: RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(dense ? 10 : 12),
      side: BorderSide(color: bad ? _err : OrderColors.line, width: 1.5),
    ),
    child: InkWell(
      borderRadius: BorderRadius.circular(dense ? 10 : 12),
      onTap: onTap,
      child: Container(
        constraints: BoxConstraints(minHeight: dense ? 42 : 48),
        padding: const EdgeInsets.symmetric(horizontal: 12),
        child: Row(
          children: [
            Expanded(
              child: Text(
                value ?? hint,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(color: value == null ? OrderColors.faint : OrderColors.ink, fontSize: dense ? 14 : 15),
              ),
            ),
            const AppIcon(AppIcons.down, size: 18, color: OrderColors.muted),
          ],
        ),
      ),
    ),
  );
}

class _Switch extends StatelessWidget {
  const _Switch({
    super.key,
    required this.title,
    required this.sub,
    required this.on,
    required this.onChanged,
    this.first = false,
    this.flush = false,
  });

  final String title;
  final String sub;
  final bool on;
  final ValueChanged<bool> onChanged;

  /// Dòng đầu nhóm: không kẻ vạch trên.
  final bool first;

  /// Không đệm trên dưới (công tắc đứng một mình trong khối).
  final bool flush;

  @override
  Widget build(BuildContext context) => Semantics(
    toggled: on,
    label: title,
    child: InkWell(
      // Chạm cả dòng là bật/tắt — công tắc nhỏ khó trúng trên điện thoại.
      onTap: () => onChanged(!on),
      child: Container(
        padding: EdgeInsets.symmetric(vertical: flush ? 0 : 10),
        decoration: first || flush
            ? null
            : const BoxDecoration(
                border: Border(top: BorderSide(color: OrderColors.line)),
              ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: const TextStyle(color: OrderColors.ink, fontSize: 14, fontWeight: FontWeight.w600),
                  ),
                  const SizedBox(height: 2),
                  Text(sub, style: const TextStyle(color: OrderColors.muted, fontSize: 12, height: 1.4)),
                ],
              ),
            ),
            const SizedBox(width: 12),
            // Công tắc 46×28 kiểu iOS như bản thiết kế (Switch của Material to và thấp hơn).
            AnimatedContainer(
              duration: const Duration(milliseconds: 200),
              width: 46,
              height: 28,
              padding: const EdgeInsets.all(3),
              alignment: on ? Alignment.centerRight : Alignment.centerLeft,
              decoration: BoxDecoration(
                color: on ? OrderColors.primary : const Color(0xFFD3D8E0),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Container(
                width: 22,
                height: 22,
                decoration: const BoxDecoration(
                  color: Colors.white,
                  shape: BoxShape.circle,
                  boxShadow: [BoxShadow(color: Color(0x40000000), offset: Offset(0, 1), blurRadius: 3)],
                ),
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

/// Dòng chọn trong ngăn kéo (chọn một / nhiều): nền xanh nhạt + dấu tick khi chọn.
class _Radio extends StatelessWidget {
  const _Radio({super.key, required this.label, required this.on, required this.onTap, this.sub = ''});

  final String label;
  final String sub;
  final bool on;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
    color: on ? OrderColors.primarySoft : Colors.transparent,
    borderRadius: BorderRadius.circular(12),
    child: InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: Container(
        constraints: const BoxConstraints(minHeight: 50),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    label,
                    style: TextStyle(
                      color: on ? OrderColors.primaryInk : OrderColors.ink,
                      fontSize: 15,
                      fontWeight: on ? FontWeight.w600 : FontWeight.w400,
                    ),
                  ),
                  if (sub.isNotEmpty) Text(sub, style: const TextStyle(color: OrderColors.faint, fontSize: 12)),
                ],
              ),
            ),
            if (on) const AppIcon(AppIcons.check, size: 18, color: OrderColors.primaryInk),
          ],
        ),
      ),
    ),
  );
}

class _Dot extends StatelessWidget {
  const _Dot({super.key, required this.on, required this.onTap});

  final bool on;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
    checked: on,
    label: 'Đặt mặc định',
    child: GestureDetector(
      onTap: onTap,
      child: Container(
        width: 22,
        height: 22,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: Colors.white,
          border: Border.all(color: on ? OrderColors.primary : const Color(0xFFC9CED8), width: 1.5),
        ),
        child: on
            ? Center(
                child: Container(
                  width: 10,
                  height: 10,
                  decoration: const BoxDecoration(color: OrderColors.primary, shape: BoxShape.circle),
                ),
              )
            : null,
      ),
    ),
  );
}

class _SquareBtn extends StatelessWidget {
  const _SquareBtn({
    super.key,
    required this.icon,
    required this.tooltip,
    required this.onTap,
    this.plain = false,
    this.boxed = false,
    this.on = false,
  });

  final AppIcons icon;
  final String tooltip;
  final VoidCallback onTap;

  /// Không nền (nút X bỏ dòng).
  final bool plain;

  /// Viền như ô nhập (nút mở SKU / giá vốn riêng).
  final bool boxed;
  final bool on;

  @override
  Widget build(BuildContext context) => Tooltip(
    message: tooltip,
    child: Material(
      color: plain
          ? Colors.transparent
          : boxed
          ? (on ? OrderColors.primarySoft : Colors.white)
          : OrderColors.grid,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(boxed ? 10 : 8),
        side: boxed ? BorderSide(color: on ? OrderColors.primary : OrderColors.line, width: 1.5) : BorderSide.none,
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(boxed ? 10 : 8),
        onTap: onTap,
        child: SizedBox(
          width: boxed ? 34 : 30,
          height: boxed ? 36 : 30,
          child: Center(
            child: AppIcon(
              icon,
              size: 16,
              color: plain
                  ? OrderColors.faint
                  : on
                  ? OrderColors.primaryInk
                  : OrderColors.navy,
            ),
          ),
        ),
      ),
    ),
  );
}

class _DashedBtn extends StatelessWidget {
  const _DashedBtn({super.key, required this.label, required this.onTap, this.bad = false});

  final String label;
  final VoidCallback onTap;
  final bool bad;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(top: 12),
    child: Material(
      color: const Color(0xFFF8FAFC),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: bad ? _err : const Color(0xFFC7D2E0), width: 1.5),
      ),
      child: InkWell(
        key: const Key('form_add_attr'),
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: SizedBox(
          height: 44,
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const AppIcon(AppIcons.plus, size: 18, color: OrderColors.primaryInk),
              const SizedBox(width: 6),
              Text(
                label,
                style: const TextStyle(color: OrderColors.primaryInk, fontSize: 13.5, fontWeight: FontWeight.w600),
              ),
            ],
          ),
        ),
      ),
    ),
  );
}

class _AddPic extends StatelessWidget {
  const _AddPic({super.key, required this.label, required this.onTap});

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(right: 8),
    child: Material(
      color: const Color(0xFFF8FAFC),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: Color(0xFFC7D2E0), width: 1.5),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: SizedBox(
          width: 72,
          height: 72,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const AppIcon(AppIcons.camera, size: 22, color: OrderColors.primaryInk),
              const SizedBox(height: 3),
              Text(
                label,
                style: const TextStyle(color: OrderColors.primaryInk, fontSize: 11, fontWeight: FontWeight.w600),
              ),
            ],
          ),
        ),
      ),
    ),
  );
}

/// Ô ảnh đã tải (url rỗng = đang tải lên).
class _Pic extends StatelessWidget {
  const _Pic({required this.url, this.main = false, this.onRemove});

  final String url;
  final bool main;
  final VoidCallback? onRemove;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(right: 8),
    child: Container(
      width: 72,
      height: 72,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: const Color(0xFFF7F8FA),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: OrderColors.line),
      ),
      child: url.isEmpty
          ? const Center(
              child: SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2, color: OrderColors.primary),
              ),
            )
          : Stack(
              fit: StackFit.expand,
              children: [
                Image.network(
                  mediaUrl(url),
                  fit: BoxFit.cover,
                  webHtmlElementStrategy: WebHtmlElementStrategy.fallback,
                  errorBuilder: (_, _, _) =>
                      const Center(child: AppIcon(AppIcons.image, size: 22, color: OrderColors.faint)),
                ),
                if (main)
                  const Positioned(
                    left: 0,
                    right: 0,
                    bottom: 0,
                    child: ColoredBox(
                      color: Color(0xA6111827),
                      child: Padding(
                        padding: EdgeInsets.symmetric(vertical: 1),
                        child: Text(
                          'Đại diện',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w600),
                        ),
                      ),
                    ),
                  ),
                Positioned(
                  top: 3,
                  right: 3,
                  child: Semantics(
                    button: true,
                    label: 'Xoá ảnh',
                    child: GestureDetector(
                      onTap: onRemove,
                      child: Container(
                        width: 20,
                        height: 20,
                        decoration: const BoxDecoration(color: Color(0x99111827), shape: BoxShape.circle),
                        child: const Center(
                          child: AppIcon(AppIcons.close, size: 12, strokeWidth: 2.4, color: Colors.white),
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
    ),
  );
}

class _Gain extends StatelessWidget {
  const _Gain({required this.price, required this.cost});

  final num price;
  final num cost;

  @override
  Widget build(BuildContext context) {
    final lai = price - cost;
    final neg = lai < 0;
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Row(
        children: [
          const Text('Lãi', style: TextStyle(color: OrderColors.muted, fontSize: 12.5)),
          const SizedBox(width: 10),
          // Thanh vốn | lãi theo tỉ lệ trên giá bán.
          Expanded(
            child: ClipRRect(
              borderRadius: BorderRadius.circular(3),
              child: SizedBox(
                height: 6,
                child: Row(
                  children: [
                    Expanded(
                      flex: (cost.clamp(0, double.infinity) * 1000 / price).round().clamp(0, 1 << 30),
                      child: const ColoredBox(color: Color(0xFFB8C7DD), child: SizedBox.expand()),
                    ),
                    Expanded(
                      flex: (lai.clamp(0, double.infinity) * 1000 / price).round().clamp(0, 1 << 30),
                      child: const ColoredBox(color: Color(0xFF12A150), child: SizedBox.expand()),
                    ),
                    if (cost <= 0 && lai <= 0)
                      const Expanded(
                        child: ColoredBox(color: Color(0xFFE2E6EE), child: SizedBox.expand()),
                      ),
                  ],
                ),
              ),
            ),
          ),
          const SizedBox(width: 10),
          Text(
            '${dot(lai)}đ · ${pct(lai / price * 100)}',
            key: const Key('form_gain'),
            style: TextStyle(
              color: neg ? _err : OrderColors.green.$2,
              fontSize: 12.5,
              fontWeight: FontWeight.w700,
              fontFeatures: tnum,
            ),
          ),
        ],
      ),
    );
  }
}

class _BigBtn extends StatelessWidget {
  const _BigBtn({
    super.key,
    required this.label,
    required this.onTap,
    this.primary = false,
    this.busy = false,
    this.danger = false,
  });

  final String label;
  final VoidCallback onTap;
  final bool primary;
  final bool busy;

  /// Nút phụ chữ đỏ (Xoá).
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final fg = primary
        ? Colors.white
        : danger
        ? _err
        : OrderColors.ink;
    return Material(
      color: primary ? OrderColors.primary : Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: primary ? BorderSide.none : const BorderSide(color: OrderColors.line),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: busy ? null : onTap,
        child: SizedBox(
          height: 50,
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (busy) ...[
                SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.5, color: fg)),
                const SizedBox(width: 8),
              ],
              Flexible(
                child: Text(
                  busy ? (danger ? 'Đang xoá' : 'Đang lưu') : label,
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
