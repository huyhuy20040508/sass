import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { api, setApiStore } from '../lib/api';
import { clearStore, pickStore, saveStore } from '../lib/store';
import StoreLink from './StoreLink';

const ShopContext = createContext(null);

/** {shop: {code, name}, branch: 'CN1' | ''} của shop đang xem. */
export const useShop = () => useContext(ShopContext);

// Khung chung của mọi trang khách: chỉ hiện nội dung khi đã biết chắc đang ở shop nào.
export default function ShopLayout() {
  const { search } = useLocation();
  const target = useMemo(() => pickStore(search), [search]);
  const [state, setState] = useState({ status: 'loading' });

  useEffect(() => {
    if (!target.store) {
      setState({ status: 'no-store' });
      return;
    }
    if (target.validFormat === false) {
      setState({ status: 'error', message: 'Không tìm thấy cửa hàng' });
      return;
    }

    let cancelled = false;
    setState({ status: 'loading' });
    api('/shop', { store: target.store })
      .then(({ data }) => {
        if (cancelled) return;
        // Chỉ nhớ shop backend đã xác nhận: mã gõ sai không được kẹt lại cho các lần sau.
        saveStore({ store: data.code, branch: target.branch });
        setApiStore(data.code);
        document.title = data.name;
        setState({ status: 'ready', shop: data, branch: target.branch });
      })
      .catch((e) => {
        if (cancelled) return;
        // Shop đã nhớ nay không còn (tiệm tắt web) thì quên đi, lần sau hiện lời nhắc quét QR.
        if (e.status === 404 && target.fromSaved) clearStore();
        setState({ status: 'error', message: e.message });
      });
    return () => {
      cancelled = true;
    };
  }, [target.store, target.branch, target.fromSaved, target.validFormat]);

  if (state.status === 'loading') {
    return <main className="page notice">Đang tải cửa hàng…</main>;
  }
  if (state.status === 'no-store') {
    return (
      <main className="page notice">
        <h1>Chào bạn</h1>
        <p>Vui lòng quét mã QR của cửa hàng để bắt đầu mua sắm.</p>
      </main>
    );
  }
  if (state.status === 'error') {
    return (
      <main className="page notice">
        <h1>{state.message}</h1>
        <p>Kiểm tra lại link hoặc quét lại mã QR tại cửa hàng.</p>
      </main>
    );
  }

  return (
    <ShopContext.Provider value={{ shop: state.shop, branch: state.branch }}>
      <header className="shop-header">
        <div className="page shop-header__inner">
          <StoreLink to="/" className="shop-header__name">
            {state.shop.name}
          </StoreLink>
          {state.branch && <span className="shop-header__branch">Chi nhánh {state.branch}</span>}
        </div>
      </header>
      <Outlet />
    </ShopContext.Provider>
  );
}
