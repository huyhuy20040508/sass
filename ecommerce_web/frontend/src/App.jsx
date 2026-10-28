import { Navigate, Route, Routes } from 'react-router-dom';
import AdminLayout from './components/AdminLayout';
import ShopLayout from './components/ShopLayout';
import Home from './pages/shop/Home';
import Dashboard from './pages/admin/Dashboard';
import Login from './pages/admin/Login';

// Trang khách và trang quản trị chung một ứng dụng React; quản trị nằm dưới /admin của địa chỉ shop.
export default function App() {
  return (
    <Routes>
      {/* Shop nhận theo tên miền (kimhana.huyweb.selliotech.store); QR chi nhánh thêm ?branch_code=CN1 */}
      <Route element={<ShopLayout />}>
        <Route index element={<Home />} />
      </Route>

      <Route path="admin/dang-nhap" element={<Login />} />
      <Route path="admin" element={<AdminLayout />}>
        <Route index element={<Dashboard />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
