import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { Link, Outlet, useLocation } from 'react-router-dom';
import { api } from '../lib/api';
import { pickBranch } from '../lib/branch';

const ShopContext = createContext(null);

/** {shop: {code, name}, branch: 'CN1' | ''} của shop đang xem. */
export const useShop = () => useContext(ShopContext);

// Khung chung của mọi trang khách: chỉ hiện nội dung khi đã biết chắc đang ở shop nào.
export default function ShopLayout() {
  const { search } = useLocation();
  const branch = useMemo(() => pickBranch(search), [search]);
  const [state, setState] = useState({ status: 'loading' });

  useEffect(() => {
    let cancelled = false;
    api('/shop')
      .then(({ data }) => {
        if (cancelled) return;
        document.title = data.name;
        setState({ status: 'ready', shop: data });
      })
      .catch((e) => !cancelled && setState({ status: 'error', message: e.message }));
    return () => {
      cancelled = true;
    };
  }, []);

  if (state.status === 'loading') {
    return <main className="page notice">Đang tải cửa hàng…</main>;
  }
  if (state.status === 'error') {
    return (
      <main className="page notice">
        <h1>{state.message}</h1>
        <p>Kiểm tra lại địa chỉ web của cửa hàng hoặc quét lại mã QR tại cửa hàng.</p>
      </main>
    );
  }

  return (
    <ShopContext.Provider value={{ shop: state.shop, branch }}>
      <header className="shop-header">
        <div className="page shop-header__inner">
          <Link to="/" className="shop-header__name">
            {state.shop.name}
          </Link>
          {branch && <span className="shop-header__branch">Chi nhánh {branch}</span>}
        </div>
      </header>
      <Outlet />
    </ShopContext.Provider>
  );
}
