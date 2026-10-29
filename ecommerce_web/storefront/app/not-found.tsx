// Ngoài mọi shop: tên miền không thuộc shop nào (hoặc shop đã tắt web). Trả 404 để Google không lưu.
export default function KhongCoShop() {
  return (
    <main style={{ textAlign: 'center', padding: '20vh 16px 0', color: '#6b7280' }}>
      <h1 style={{ color: '#1e2a4a' }}>Không tìm thấy cửa hàng</h1>
      <p>Kiểm tra lại địa chỉ web của cửa hàng hoặc quét lại mã QR tại cửa hàng.</p>
    </main>
  );
}
