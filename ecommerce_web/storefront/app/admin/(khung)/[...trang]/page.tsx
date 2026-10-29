import { notFound } from 'next/navigation';
import { MOI_MUC, timMuc } from '@/lib/menu';

// Màn chưa làm: hiện đúng nội dung khai trong lib/menu.ts. Làm xong màn nào thì tạo
// trang thật cùng đường dẫn — Next ưu tiên trang cụ thể hơn trang bắt-tất-cả này.
export function generateStaticParams() {
  return MOI_MUC.filter((m) => m.giaiDoan).map((m) => ({ trang: m.duongDan.split('/') }));
}

export const dynamicParams = false;

export default async function GiuCho({ params }: { params: Promise<{ trang: string[] }> }) {
  const muc = timMuc((await params).trang.join('/'));
  if (!muc?.giaiDoan) notFound();

  return (
    <section className="card">
      <div className="ph">
        <div className="ico">
          <i className={`fa-solid ${muc.icon}`} />
        </div>
        <h2>{muc.ten}</h2>
        <p>{muc.moTa}</p>
        <ul>
          {muc.seCo?.map((c) => <li key={c}>{c}</li>)}
        </ul>
        <div>
          <span className="chip mute">
            <i className="fa-regular fa-clock" />
            Làm ở giai đoạn {muc.giaiDoan}
          </span>
        </div>
      </div>
    </section>
  );
}
