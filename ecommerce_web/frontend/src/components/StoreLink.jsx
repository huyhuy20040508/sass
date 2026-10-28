import { Link } from 'react-router-dom';
import { withStore } from '../lib/store';
import { useShop } from './ShopLayout';

// Link trong trang khách luôn mang store_id: khách chép link một sản phẩm gửi
// người khác thì người nhận vẫn mở đúng tiệm, đúng chi nhánh.
export default function StoreLink({ to, ...props }) {
  const { shop, branch } = useShop();

  return <Link to={withStore(to, { store: shop.code, branch })} {...props} />;
}
