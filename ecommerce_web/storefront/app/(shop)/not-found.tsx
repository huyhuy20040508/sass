// Đường dẫn sai TRONG một shop (vd hàng đã ẩn): vẫn giữ đầu/chân trang của shop để khách đi tiếp.
export default function KhongCoTrang() {
  return (
    <div className="sf-wrap shop-notice">
      <h1>Không tìm thấy trang</h1>
      <p>
        Trang này không có hoặc đã bị gỡ. <a href="/">Về trang chủ</a>
      </p>
    </div>
  );
}
