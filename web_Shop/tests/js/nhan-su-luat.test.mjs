/**
 * Bài kiểm cho luật JavaScript của màn hình Nhân sự.
 *
 *     cd admin && npm run test:js          (hoặc: node --test tests/js)
 *
 * KHÔNG phụ thuộc thư viện nào — chạy bằng trình kiểm sẵn trong node. Cố ý:
 * `node_modules` của dự án chưa cài, và một bộ kiểm phải `npm install` mới chạy
 * được thì trên thực tế là một bộ kiểm không ai chạy.
 *
 * Chỉ kiểm phần THUẦN (public/js/nhan-su-luat.js). Phần gắn sự kiện và sờ vào
 * DOM cần trình duyệt thật — muốn phủ tới đó thì phải dựng Dusk, và điều đó ghi
 * ở đây để lần sau khỏi tưởng chỗ này đã phủ hết.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import assert from 'node:assert/strict';

// Tệp luật là script thường (trình duyệt nạp bằng thẻ <script>), không phải ES
// module — nên đọc rồi chạy để nó gắn vào biến toàn cục, đúng như trên trang.
const goc = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
(0, eval)(readFileSync(join(goc, 'public', 'js', 'nhan-su-luat.js'), 'utf8'));

const Luat = globalThis.NhanSuLuat;

/** Dựng danh sách ô tick cho gọn. */
const o = (ds) => [
    { value: 'sang', checked: ds.includes('sang') },
    { value: 'chieu', checked: ds.includes('chieu') },
    { value: 'ca_ngay', checked: ds.includes('ca_ngay') },
];

test('luật nạp được và lộ ra đúng những gì trang cần', () => {
    assert.ok(Luat, 'public/js/nhan-su-luat.js phải gắn NhanSuLuat vào biến toàn cục');
    ['caLamBiKhoa', 'oQuyenNenTick', 'tachCa'].forEach((ten) => {
        assert.equal(typeof Luat[ten], 'function', `thiếu hàm ${ten}`);
    });
});

test('chưa tick gì thì không ô nào bị khoá', () => {
    const khoa = Luat.caLamBiKhoa(o([]));
    assert.deepEqual(khoa, { sang: false, chieu: false, ca_ngay: false });
});

test('tick Cả ngày thì khoá Sáng và Chiều', () => {
    const khoa = Luat.caLamBiKhoa(o(['ca_ngay']));
    assert.equal(khoa.sang, true);
    assert.equal(khoa.chieu, true);
    // Chính ô đang tick KHÔNG bị khoá — khoá nó thì người dùng không bỏ tick được
    // nữa, và ô ca kẹt vĩnh viễn ở "Cả ngày".
    assert.equal(khoa.ca_ngay, false);
});

test('tick Sáng thì khoá Cả ngày, còn Chiều vẫn chọn thêm được', () => {
    const khoa = Luat.caLamBiKhoa(o(['sang']));
    assert.equal(khoa.ca_ngay, true);
    assert.equal(khoa.chieu, false, 'trực cả sáng lẫn chiều là chuyện thường');
    assert.equal(khoa.sang, false);
});

test('tick cả Sáng lẫn Chiều vẫn chỉ khoá Cả ngày', () => {
    const khoa = Luat.caLamBiKhoa(o(['sang', 'chieu']));
    assert.deepEqual(khoa, { sang: false, chieu: false, ca_ngay: true });
});

test('danh sách rỗng hay thiếu phần tử thì không nổ', () => {
    assert.deepEqual(Luat.caLamBiKhoa([]), {});
    assert.deepEqual(Luat.caLamBiKhoa(null), {});
    assert.deepEqual(Luat.caLamBiKhoa([null, undefined]), {});
});

// --------------------------------------------------------------- ô tick quyền

// Khớp NhanSuController::CUA — giá trị ô tick là role_id, giá trị API là mã cửa.
const BAN_DO = { 2: 'quan_ly', 3: 'thu_ngan' };

test('tick lại ĐÚNG những cửa đã lưu, không suy từ vai trò', () => {
    assert.deepEqual(Luat.oQuyenNenTick(['quan_ly'], BAN_DO), { 2: true, 3: false },
        'chỉ tích Quản lý thì KHÔNG được tự tick thêm Thu ngân');
    assert.deepEqual(Luat.oQuyenNenTick(['thu_ngan'], BAN_DO), { 2: false, 3: true });
    assert.deepEqual(Luat.oQuyenNenTick(['quan_ly', 'thu_ngan'], BAN_DO), { 2: true, 3: true });
});

test('hồ sơ không có tài khoản thì không ô nào tick', () => {
    assert.deepEqual(Luat.oQuyenNenTick([], BAN_DO), { 2: false, 3: false });
    assert.deepEqual(Luat.oQuyenNenTick(null, BAN_DO), { 2: false, 3: false });
});

test('cửa lạ không làm tick nhầm ô nào', () => {
    assert.deepEqual(Luat.oQuyenNenTick(['kho'], BAN_DO), { 2: false, 3: false });
});

// ------------------------------------------------------------------ tách ca

test('tách chuỗi cột SET, bỏ phần rỗng', () => {
    assert.deepEqual(Luat.tachCa('sang,chieu'), ['sang', 'chieu']);
    assert.deepEqual(Luat.tachCa('ca_ngay'), ['ca_ngay']);
    // Cột NULL đọc lên thành rỗng — phải ra mảng rỗng, không phải mảng một phần
    // tử rỗng (thứ sẽ tick nhầm một ô có value === '').
    assert.deepEqual(Luat.tachCa(''), []);
    assert.deepEqual(Luat.tachCa(null), []);
    assert.deepEqual(Luat.tachCa(undefined), []);
});
