import { Navigate, Route, Routes } from 'react-router-dom';
import ShopLayout from './components/ShopLayout';
import Home from './pages/shop/Home';
import Dashboard from './pages/admin/Dashboard';

// Trang khách và trang quản trị chung một ứng dụng React; quản trị nằm dưới /admin.
export default function App() {
  return (
    <Routes>
      {/* Hai kiểu link vào shop: /?store_id=kimhana&branch_code=CN1 (QR chi nhánh) và /cua-hang?slug=kimhana */}
      <Route element={<ShopLayout />}>
        <Route index element={<Home />} />
        <Route path="cua-hang" element={<Home />} />
      </Route>

      <Route path="admin" element={<Dashboard />} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
