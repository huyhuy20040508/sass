# Selliotech mobile

App Flutter cho chủ shop, quản lý, thu ngân. Gọi thẳng API Go (`api/`), cùng cách `web_Shop/app/Services/ApiClient.php`: Bearer token + header `X-Chi-Nhanh`, gặp 401 thì tự làm mới token.

Flutter SDK trên máy chủ: `/opt/flutter` (3.47.7). Chưa có Android SDK nên chưa build được APK.

```bash
flutter pub get
flutter analyze
flutter test   # test/flutter_test_config.dart nạp font thật để đo tràn khung cho đúng
# flutter run (debug/profile) đọc API dev https://huyshop.selliotech.store/api/v1 (dữ liệu như web huyshop);
# flutter build apk --release đọc API prod https://api.selliotech.store/api/v1. Ghi đè:
flutter run --dart-define=API_BASE_URL=https://<máy-chủ>/api/v1
```

| Thư mục | Nội dung |
|---|---|
| `lib/core/api/` | `ApiClient` (dio, tự làm mới token), `ApiException` (đọc `message`, `errors.ma`) |
| `lib/core/storage/` | Lưu phiên vào Keychain/Keystore |
| `lib/features/auth/` | Phiên, màn đăng nhập theo `design/html/dang-nhap-mobile.html` (v2), các phần con ở `login/` |
| `lib/features/home/` | Màn chính tạm, chờ thiết kế |
| `lib/core/ui/` | Bộ icon nét chép từ bản thiết kế |
| `lib/app/` | Theme (màu Sellio, font Be Vietnam Pro — `assets/fonts`, giấy phép OFL), router (go_router) |
