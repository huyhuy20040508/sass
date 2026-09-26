package repository

import (
	"errors"
	"testing"

	"sass-api/internal/domain"
)

// Hai mũi tên lên/xuống trên bảng danh sách hàng hoá.
//
// Đổi CHỖ hai giá trị sort chứ không đánh số lại cả bảng: đánh số lại thì mỗi
// lần bấm là một lượt UPDATE quét toàn bộ mặt hàng của cửa hàng.
//
// Danh sách sắp theo sort GIẢM DẦN nên "lên trên" = nhận số lớn hơn.
func TestDoiChoThuTuHangHoa(t *testing.T) {
	db := testDB(t)
	repo := NewProductRepository(db)
	ctx := ctxTest()

	// Ba mặt hàng, thứ tự trên bảng: C (30) · B (20) · A (10).
	a := newProduct(t, db, "sp-thu-tu-a", "TEST-TT-A")
	b := newProduct(t, db, "sp-thu-tu-b", "TEST-TT-B")
	c := newProduct(t, db, "sp-thu-tu-c", "TEST-TT-C")
	for id, sort := range map[uint]int{a.ID: 10, b.ID: 20, c.ID: 30} {
		if err := db.WithContext(ctxTest()).Model(&domain.Product{}).
			Where("id = ?", id).UpdateColumn("sort", sort).Error; err != nil {
			t.Fatalf("không đặt được thứ tự ban đầu: %v", err)
		}
	}

	docSort := func(id uint) int {
		var p domain.Product
		if err := db.WithContext(ctxTest()).First(&p, id).Error; err != nil {
			t.Fatalf("không đọc lại được mặt hàng: %v", err)
		}
		return p.Sort
	}

	// B đi lên: đổi chỗ với C.
	if err := repo.DoiChoThuTu(ctx, b.ID, "up"); err != nil {
		t.Fatalf("đưa lên trên lỗi: %v", err)
	}
	if got := docSort(b.ID); got != 30 {
		t.Fatalf("B phải nhận thứ tự 30, nhận %d", got)
	}
	if got := docSort(c.ID); got != 20 {
		t.Fatalf("C phải nhận thứ tự 20, nhận %d", got)
	}
	// A không được đụng tới — chỉ hai dòng đổi chỗ, không phải cả bảng.
	if got := docSort(a.ID); got != 10 {
		t.Fatalf("A không được đổi, nhận %d", got)
	}

	// B (giờ đứng đầu) đi lên nữa: hết đường, và đó KHÔNG phải lỗi hệ thống.
	err := repo.DoiChoThuTu(ctx, b.ID, "up")
	if !errors.Is(err, domain.ErrDaODau) {
		t.Fatalf("bấm lên khi đã ở đầu phải trả ErrDaODau, nhận %v", err)
	}

	// A (đứng cuối) đi xuống: cũng hết đường.
	err = repo.DoiChoThuTu(ctx, a.ID, "down")
	if !errors.Is(err, domain.ErrDaOCuoi) {
		t.Fatalf("bấm xuống khi đã ở cuối phải trả ErrDaOCuoi, nhận %v", err)
	}
}

// Hai mặt hàng cùng số thứ tự (dữ liệu cũ, hoặc vừa nhập hàng loạt) vẫn phải đổi
// chỗ được — đổi chỗ hai số bằng nhau thì bảng đứng im, người bấm tưởng nút hỏng.
func TestDoiChoThuTuKhiTrungSo(t *testing.T) {
	db := testDB(t)
	repo := NewProductRepository(db)
	ctx := ctxTest()

	a := newProduct(t, db, "sp-thu-tu-bang-a", "TEST-TTB-A")
	b := newProduct(t, db, "sp-thu-tu-bang-b", "TEST-TTB-B")
	for _, id := range []uint{a.ID, b.ID} {
		if err := db.WithContext(ctxTest()).Model(&domain.Product{}).
			Where("id = ?", id).UpdateColumn("sort", 5).Error; err != nil {
			t.Fatalf("không đặt được thứ tự ban đầu: %v", err)
		}
	}

	// Cùng sort thì thứ tự trên bảng do id quyết (id lớn nằm trên) — a đứng dưới.
	if err := repo.DoiChoThuTu(ctx, a.ID, "up"); err != nil {
		t.Fatalf("đưa lên trên lỗi: %v", err)
	}

	var sauA, sauB domain.Product
	db.WithContext(ctxTest()).First(&sauA, a.ID)
	db.WithContext(ctxTest()).First(&sauB, b.ID)
	if sauA.Sort <= sauB.Sort {
		t.Fatalf("sau khi lên trên, A phải có thứ tự lớn hơn B: A=%d B=%d", sauA.Sort, sauB.Sort)
	}
}

// Kéo thả: một dòng nhảy thẳng tới chỗ bất kỳ, không nhích từng bậc.
//
// Điều đáng canh nhất KHÔNG phải là ba dòng có đổi chỗ hay không, mà là TẬP giá
// trị sort phải giữ nguyên. Bảng đang phân trang: đánh số lại 1..n theo trang thì
// cả trang nhảy lên đầu (hoặc rơi xuống đáy) so với những trang chưa đụng tới.
func TestSapXepLaiHangHoa(t *testing.T) {
	db := testDB(t)
	repo := NewProductRepository(db)
	ctx := ctxTest()

	// Trên bảng: C (30) · B (20) · A (10). Thêm D (5) đứng NGOÀI lượt kéo thả.
	a := newProduct(t, db, "sp-keo-tha-a", "TEST-KT-A")
	b := newProduct(t, db, "sp-keo-tha-b", "TEST-KT-B")
	c := newProduct(t, db, "sp-keo-tha-c", "TEST-KT-C")
	d := newProduct(t, db, "sp-keo-tha-d", "TEST-KT-D")
	for id, sort := range map[uint]int{a.ID: 10, b.ID: 20, c.ID: 30, d.ID: 5} {
		if err := db.WithContext(ctxTest()).Model(&domain.Product{}).
			Where("id = ?", id).UpdateColumn("sort", sort).Error; err != nil {
			t.Fatalf("không đặt được thứ tự ban đầu: %v", err)
		}
	}
	docSort := func(id uint) int {
		var p domain.Product
		if err := db.WithContext(ctxTest()).First(&p, id).Error; err != nil {
			t.Fatalf("không đọc lại được mặt hàng: %v", err)
		}
		return p.Sort
	}

	// Kéo A từ cuối lên đầu: trình tự mới A · C · B.
	// PHÁ THỬ: nếu đổi sang đánh số 1..n thì A nhận 1 chứ không phải 30, và D
	// (đang giữ 5) đột nhiên vượt lên trên cả ba — đúng cái bẫy phân trang.
	if err := repo.SapXepLai(ctx, []uint{a.ID, c.ID, b.ID}); err != nil {
		t.Fatalf("sắp xếp lại lỗi: %v", err)
	}
	if got := docSort(a.ID); got != 30 {
		t.Fatalf("A đứng đầu phải nhận 30, nhận %d", got)
	}
	if got := docSort(c.ID); got != 20 {
		t.Fatalf("C đứng giữa phải nhận 20, nhận %d", got)
	}
	if got := docSort(b.ID); got != 10 {
		t.Fatalf("B đứng cuối phải nhận 10, nhận %d", got)
	}
	// D nằm ngoài danh sách gửi lên: không được xê dịch, và vẫn phải đứng SAU cả ba.
	if got := docSort(d.ID); got != 5 {
		t.Fatalf("D ngoài lượt kéo thả không được đổi, nhận %d", got)
	}

	// Một id không còn (vừa bị xoá ở tab khác): TỪ CHỐI cả lượt. Nhận bừa thì số
	// thứ tự phát lệch một nhịp cho mọi dòng phía sau nó.
	err := repo.SapXepLai(ctx, []uint{a.ID, 999999999})
	if !errors.Is(err, domain.ErrNotFound) {
		t.Fatalf("id không có phải trả ErrNotFound, nhận %v", err)
	}
	if got := docSort(a.ID); got != 30 {
		t.Fatalf("lượt bị từ chối không được ghi gì: A = %d", got)
	}
}
