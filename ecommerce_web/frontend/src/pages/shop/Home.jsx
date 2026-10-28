import { useShop } from '../../components/ShopLayout';

export default function Home() {
  const { shop, branch } = useShop();

  return (
    <main className="page">
      <h1>{shop.name}</h1>
      <p>{branch ? `Đang xem chi nhánh ${branch}` : 'Chưa chọn chi nhánh'}</p>
    </main>
  );
}
