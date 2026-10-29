import { Baloo_2, Be_Vietnam_Pro, Inter, Lora, Montserrat, Nunito, Playfair_Display, Roboto } from 'next/font/google';

// Font của web (giao_dien.font từ /api/khung; bước 4 cho shop chọn ở màn Trang trí cửa hàng).
// Nạp sẵn cả bộ lúc build (tự host, không gọi Google lúc chạy); trình duyệt chỉ tải
// font trang thật sự dùng. preload: false vì preload là tải hết, kể cả font shop không chọn.
// next/font chỉ nhận object viết thẳng (đọc lúc build), nên mỗi font khai đủ tham số.
const beVietnamPro = Be_Vietnam_Pro({ subsets: ['latin', 'vietnamese'], display: 'swap', preload: false, weight: ['400', '500', '600', '700'], variable: '--f-be-vietnam-pro' });
const inter = Inter({ subsets: ['latin', 'vietnamese'], display: 'swap', preload: false, variable: '--f-inter' });
const roboto = Roboto({ subsets: ['latin', 'vietnamese'], display: 'swap', preload: false, weight: ['400', '500', '700'], variable: '--f-roboto' });
const montserrat = Montserrat({ subsets: ['latin', 'vietnamese'], display: 'swap', preload: false, variable: '--f-montserrat' });
const nunito = Nunito({ subsets: ['latin', 'vietnamese'], display: 'swap', preload: false, variable: '--f-nunito' });
const lora = Lora({ subsets: ['latin', 'vietnamese'], display: 'swap', preload: false, variable: '--f-lora' });
// Chữ tiêu đề theo theme (theme tự chọn trong CSS, shop không đổi): Mộc dùng Playfair, Mochi Pet dùng Baloo 2.
const playfair = Playfair_Display({ subsets: ['latin', 'vietnamese'], display: 'swap', preload: false, weight: ['500', '600', '700'], style: ['normal', 'italic'], variable: '--f-playfair' });
const baloo = Baloo_2({ subsets: ['latin', 'vietnamese'], display: 'swap', preload: false, weight: ['600', '700', '800'], variable: '--f-baloo' });

export const FONT_LOP = [beVietnamPro, inter, roboto, montserrat, nunito, lora, playfair, baloo].map((f) => f.variable).join(' ');

