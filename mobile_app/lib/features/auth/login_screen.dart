import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_exception.dart';
import '../../core/net/online_provider.dart';
import '../../core/storage/session_store.dart';
import '../../core/ui/app_icon.dart';
import 'auth_controller.dart';
import 'login/forgot_password_sheet.dart';
import 'login/login_hero.dart';
import 'login/login_success.dart';
import 'login/login_widgets.dart';

/// Màn đăng nhập theo design/html/dang-nhap-mobile.html (v2): đầu tím phía
/// trên, form dồn xuống đáy cho ngón cái với tới.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  /// Màn thấp hơn mức này thì bỏ minh hoạ, rồi bỏ dòng phụ — form luôn trọn trong màn đầu.
  static const decoMinHeight = 780.0;
  static const subtitleMinHeight = 700.0;

  /// Thời gian màn "Đăng nhập thành công" hiện trước khi vào app.
  static const successDelay = Duration(milliseconds: 1400);

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> with WidgetsBindingObserver {
  final _shop = TextEditingController();
  final _user = TextEditingController();
  final _pass = TextEditingController();
  final _shopFocus = FocusNode();
  final _userFocus = FocusNode();
  final _passFocus = FocusNode();

  RememberedLogin? _remembered;
  bool _rememberedApplied = false;
  bool _remember = true;
  bool _showPass = false;

  bool _errShop = false;
  bool _errUser = false;
  bool _errPass = false;

  bool _loading = false;
  bool _success = false;
  String? _error;
  int _shakeKey = 0;

  Timer? _lockTimer;
  int _lockLeft = 0;

  double _keyboardInset = 0;
  bool _capsLock = false;

  @override
  void initState() {
    super.initState();
    for (final f in [_shopFocus, _userFocus, _passFocus]) {
      f.addListener(_onFocusChange);
    }
    HardwareKeyboard.instance.addHandler(_onKey);
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    HardwareKeyboard.instance.removeHandler(_onKey);
    WidgetsBinding.instance.removeObserver(this);
    _lockTimer?.cancel();
    for (final x in [_shop, _user, _pass]) {
      x.dispose();
    }
    for (final f in [_shopFocus, _userFocus, _passFocus]) {
      f.dispose();
    }
    super.dispose();
  }

  bool get _locked => _lockLeft > 0;
  bool get _chipShown => _remembered != null;

  bool get _anyFocus => _shopFocus.hasFocus || _userFocus.hasFocus || _passFocus.hasFocus;

  void _onFocusChange() {
    setState(() {});
    _syncCaps();
  }

  // Android tắt bàn phím (nút Back, vuốt) mà ô vẫn giữ focus — bỏ focus luôn
  // để màn về đúng trạng thái thường, không còn viền tím của ô đang gõ.
  @override
  void didChangeMetrics() {
    final inset = View.of(context).viewInsets.bottom;
    if (_keyboardInset > 0 && inset == 0) FocusManager.instance.primaryFocus?.unfocus();
    _keyboardInset = inset;
  }

  bool _onKey(KeyEvent _) {
    _syncCaps();
    return false;
  }

  void _syncCaps() {
    final on = _passFocus.hasFocus && HardwareKeyboard.instance.lockModesEnabled.contains(KeyboardLockMode.capsLock);
    if (on != _capsLock && mounted) setState(() => _capsLock = on);
  }

  void _applyRemembered(RememberedLogin? r) {
    if (_rememberedApplied) return;
    _rememberedApplied = true;
    if (r == null || r.shopCode.isEmpty) return;
    _remembered = r;
    _shop.text = r.shopCode.toUpperCase();
    if (_user.text.isEmpty) _user.text = r.username;
  }

  void _changeShop() {
    setState(() {
      _remembered = null;
      _shop.clear();
      _errShop = false;
    });
    _shopFocus.requestFocus();
  }

  bool _validate() {
    setState(() {
      _errShop = !_chipShown && _shop.text.trim().isEmpty;
      _errUser = _user.text.trim().isEmpty;
      _errPass = _pass.text.isEmpty;
    });
    final first = _errShop
        ? _shopFocus
        : _errUser
        ? _userFocus
        : _errPass
        ? _passFocus
        : null;
    first?.requestFocus();
    return first == null;
  }

  void _showError(String text) => setState(() {
    _error = text;
    _shakeKey++;
  });

  Future<void> _submit() async {
    if (_loading || _locked || _success) return;
    setState(() => _error = null);
    if (!_validate()) return;
    if (!(ref.read(onlineProvider).value ?? true)) {
      _showError('Chưa có kết nối mạng. Kết nối lại rồi thử lần nữa.');
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() => _loading = true);
    final auth = ref.read(authProvider.notifier);
    try {
      final session = await auth.login(
        shopCode: _shop.text.trim(),
        username: _user.text.trim(),
        password: _pass.text,
        remember: _remember,
      );
      if (!mounted) return;
      setState(() {
        _loading = false;
        _success = true;
      });
      await Future<void>.delayed(LoginScreen.successDelay);
      auth.publish(session);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _loading = false);
      if (e.biChanTam) {
        _startLock(e.retryAfter ?? const Duration(minutes: 5));
        return;
      }
      _showError(e.message);
      if (!e.laMatMang) {
        _pass.clear();
        _passFocus.requestFocus();
      }
    }
  }

  void _startLock(Duration d) {
    _lockTimer?.cancel();
    setState(() {
      _error = null;
      _lockLeft = d.inSeconds;
    });
    _lockTimer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) return t.cancel();
      setState(() => _lockLeft--);
      if (_lockLeft <= 0) t.cancel();
    });
  }

  static String _mmss(int s) => '${(s ~/ 60).toString().padLeft(2, '0')}:${(s % 60).toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    final rememberedAsync = ref.watch(rememberedLoginProvider);
    if (rememberedAsync.hasValue && !_rememberedApplied) _applyRemembered(rememberedAsync.value);

    final online = ref.watch(onlineProvider).value ?? true;
    final mq = MediaQuery.of(context);
    final screenH = mq.size.height;
    final safeTop = mq.padding.top;
    final hasAlert = _error != null || _locked;
    // Thu đầu tím theo bàn phím thật chứ không theo focus: tắt bàn phím mà ô
    // còn focus thì vẫn phải về bố cục thường, không chừa khoảng trống.
    final typing = mq.viewInsets.bottom > 0 && _anyFocus;

    final name = _remembered?.displayName ?? '';
    final title = _chipShown && name.isNotEmpty ? 'Chào $name,' : 'Chào mừng trở lại';
    final subtitle = _chipShown && name.isNotEmpty
        ? 'Mừng trở lại — nhập mật khẩu để tiếp tục'
        : 'Đăng nhập để quản lý cửa hàng của bạn';

    final hero = LoginHero(
      compact: typing,
      showDeco: screenH >= LoginScreen.decoMinHeight && !hasAlert,
      showSubtitle: screenH >= LoginScreen.subtitleMinHeight,
      title: title,
      subtitle: subtitle,
    );

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        backgroundColor: Colors.white,
        body: Stack(
          children: [
            LayoutBuilder(
              builder: (context, box) => SingleChildScrollView(
                physics: const ClampingScrollPhysics(),
                child: ConstrainedBox(
                  constraints: BoxConstraints(minHeight: box.maxHeight),
                  child: IntrinsicHeight(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        // Cấu trúc cây giữ nguyên khi đang gõ, chỉ đổi hệ số co giãn —
                        // bọc/bỏ Expanded sẽ dựng lại ô nhập và làm mất chữ vừa gõ.
                        Flexible(
                          flex: typing ? 0 : 1,
                          child: ConstrainedBox(
                            constraints: BoxConstraints(minHeight: typing ? 0 : safeTop + 150 - LoginHero.overlap),
                            child: hero,
                          ),
                        ),
                        Flexible(flex: typing ? 1 : 0, child: _sheet(context)),
                      ],
                    ),
                  ),
                ),
              ),
            ),
            if (!online) Positioned(left: 12, right: 12, top: safeTop + 6, child: const _OfflineBanner()),
            if (_success) const Positioned.fill(child: LoginSuccess()),
          ],
        ),
      ),
    );
  }

  Widget _sheet(BuildContext context) {
    final safeBottom = MediaQuery.viewPaddingOf(context).bottom;
    final submitLabel = _locked
        ? 'Thử lại sau ${_mmss(_lockLeft)}'
        : _loading
        ? 'Đang đăng nhập…'
        : 'Đăng nhập';

    return TweenAnimationBuilder<double>(
      tween: Tween(begin: MediaQuery.disableAnimationsOf(context) ? 1 : 0, end: 1),
      duration: const Duration(milliseconds: 500),
      curve: const Cubic(.2, .8, .2, 1),
      builder: (context, t, child) => Opacity(
        opacity: t,
        child: Transform.translate(offset: Offset(0, 40 * (1 - t)), child: child),
      ),
      child: Container(
        padding: EdgeInsets.fromLTRB(20, 26, 20, safeBottom + 14),
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
          boxShadow: [BoxShadow(color: Color(0x2E140028), offset: Offset(0, -10), blurRadius: 30)],
        ),
        child: AutofillGroup(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (_error != null)
                LoginAlert(
                  key: const Key('alert_error'),
                  icon: AppIcons.alert,
                  title: 'Không đăng nhập được',
                  body: Text(_error!),
                  background: AppColors.dangerSoft,
                  foreground: const Color(0xFF8E1C1C),
                  shakeKey: _shakeKey,
                ),
              if (_locked)
                LoginAlert(
                  key: const Key('alert_lock'),
                  icon: AppIcons.timer,
                  title: 'Thử sai quá nhiều lần',
                  body: Text.rich(
                    TextSpan(
                      text: 'Vui lòng thử lại sau ',
                      children: [
                        TextSpan(
                          text: _mmss(_lockLeft),
                          style: const TextStyle(
                            fontWeight: FontWeight.w700,
                            fontFeatures: [FontFeature.tabularFigures()],
                          ),
                        ),
                        const TextSpan(text: '.'),
                      ],
                    ),
                  ),
                  background: AppColors.warnSoft,
                  foreground: AppColors.warn,
                ),
              if (_chipShown)
                ShopChip(code: _shop.text, onChange: _changeShop)
              else
                LoginField(
                  key: const Key('shop_code'),
                  label: 'Mã cửa hàng',
                  icon: AppIcons.store,
                  controller: _shop,
                  focusNode: _shopFocus,
                  placeholder: 'Mã được cấp khi bàn giao',
                  errorText: 'Nhập mã cửa hàng',
                  hasError: _errShop,
                  readOnly: _loading,
                  textCapitalization: TextCapitalization.characters,
                  inputFormatters: [
                    FilteringTextInputFormatter.deny(RegExp(r'\s')),
                    LengthLimitingTextInputFormatter(30),
                    _UpperCaseFormatter(),
                  ],
                  onChanged: (_) {
                    if (_errShop) setState(() => _errShop = false);
                  },
                  onSubmitted: (_) => _userFocus.requestFocus(),
                ),
              LoginField(
                key: const Key('username'),
                label: 'Tên đăng nhập',
                icon: AppIcons.user,
                controller: _user,
                focusNode: _userFocus,
                placeholder: 'Nhập tên đăng nhập',
                errorText: 'Nhập tên đăng nhập',
                hasError: _errUser,
                readOnly: _loading,
                autofillHints: const [AutofillHints.username],
                inputFormatters: [LengthLimitingTextInputFormatter(50)],
                onChanged: (_) {
                  if (_errUser) setState(() => _errUser = false);
                },
                onSubmitted: (_) => _passFocus.requestFocus(),
              ),
              LoginField(
                key: const Key('password'),
                label: 'Mật khẩu',
                icon: AppIcons.lock,
                controller: _pass,
                focusNode: _passFocus,
                placeholder: 'Nhập mật khẩu',
                errorText: 'Nhập mật khẩu',
                hasError: _errPass,
                readOnly: _loading,
                obscure: !_showPass,
                onToggleObscure: () => setState(() => _showPass = !_showPass),
                autofillHints: const [AutofillHints.password],
                textInputAction: TextInputAction.go,
                showCapsLock: _capsLock,
                onChanged: (_) {
                  if (_errPass) setState(() => _errPass = false);
                },
                onSubmitted: (_) => _submit(),
              ),
              Padding(
                padding: const EdgeInsets.only(top: 2, bottom: 18),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Flexible(
                      child: LoginToggle(
                        label: 'Ghi nhớ đăng nhập',
                        value: _remember,
                        onChanged: (v) => setState(() => _remember = v),
                      ),
                    ),
                    TextButton(
                      onPressed: () => showForgotPasswordSheet(context),
                      style: TextButton.styleFrom(
                        foregroundColor: AppColors.brand2,
                        minimumSize: const Size(0, 44),
                        padding: const EdgeInsets.symmetric(horizontal: 2),
                        textStyle: const TextStyle(fontFamily: appFont, fontSize: 14, fontWeight: FontWeight.w600),
                      ),
                      child: const Text('Quên mật khẩu?'),
                    ),
                  ],
                ),
              ),
              SellioButton(
                key: const Key('submit'),
                label: submitLabel,
                loading: _loading,
                onPressed: _locked ? null : _submit,
              ),
              const SizedBox(height: 16),
              Center(
                child: Wrap(
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    const Text('Cần hỗ trợ? ', style: TextStyle(color: AppColors.muted, fontSize: 12.5)),
                    GestureDetector(
                      onTap: openSupportEmail,
                      child: const Text(
                        'Liên hệ Sellio',
                        style: TextStyle(color: AppColors.text, fontSize: 12.5, fontWeight: FontWeight.w600),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _OfflineBanner extends StatelessWidget {
  const _OfflineBanner();

  @override
  Widget build(BuildContext context) => Semantics(
    liveRegion: true,
    child: Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
      decoration: BoxDecoration(
        color: AppColors.accent,
        borderRadius: BorderRadius.circular(14),
        boxShadow: const [BoxShadow(color: Color(0x40000000), offset: Offset(0, 10), blurRadius: 24)],
      ),
      child: const Row(
        children: [
          AppIcon(AppIcons.wifiOff, color: Color(0xFF3A2A00)),
          SizedBox(width: 10),
          Expanded(
            child: Text(
              'Mất kết nối — kiểm tra Wi-Fi hoặc 4G',
              style: TextStyle(color: Color(0xFF3A2A00), fontSize: 13.5, fontWeight: FontWeight.w600),
            ),
          ),
        ],
      ),
    ),
  );
}

/// Mã cửa hàng hiển thị chữ hoa — gõ chữ thường cũng tự nâng lên (API so khớp không phân biệt hoa thường).
class _UpperCaseFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) =>
      newValue.copyWith(text: newValue.text.toUpperCase());
}
