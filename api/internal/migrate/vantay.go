package migrate

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"sort"
	"strings"
)

// VanTayLuocDo — "ảnh chụp" lược đồ thật của một database, rút gọn thành một
// chuỗi so sánh được.
//
// Dùng để trả lời câu hỏi mà trước đây không ai trả lời được: lược đồ của bản
// THỬ và bản THẬT có còn giống nhau không? Chạy `migrate van-tay` trên hai máy,
// so hai chuỗi; giống nhau là yên tâm, khác nhau thì `--chi-tiet` chỉ ra khác
// chỗ nào.
//
// Cố tình BỎ QUA những thứ khác nhau một cách hợp lệ giữa hai môi trường:
// AUTO_INCREMENT hiện tại, số dòng, dung lượng, ngày tạo bảng, và chính bảng
// schema_migrations.
type VanTayLuocDo struct {
	Bang   []BangLuocDo
	VanTay string
}

// BangLuocDo là phần lược đồ của một bảng đã chuẩn hoá.
type BangLuocDo struct {
	Ten  string
	Cot  []string // "ten kieu NULL|NOT NULL mac-dinh"
	Khoa []string // "ten UNIQUE cot1,cot2"
}

// LayVanTay đọc information_schema và dựng vân tay cho database đang kết nối.
func LayVanTay(db DB, tenDB string) (VanTayLuocDo, error) {
	cot, err := layCot(db, tenDB)
	if err != nil {
		return VanTayLuocDo{}, err
	}
	khoa, err := layKhoa(db, tenDB)
	if err != nil {
		return VanTayLuocDo{}, err
	}

	tenBang := make([]string, 0, len(cot))
	for t := range cot {
		tenBang = append(tenBang, t)
	}
	sort.Strings(tenBang)

	var vt VanTayLuocDo
	var b strings.Builder
	for _, t := range tenBang {
		bang := BangLuocDo{Ten: t, Cot: cot[t], Khoa: khoa[t]}
		vt.Bang = append(vt.Bang, bang)

		b.WriteString("BANG " + t + "\n")
		for _, c := range bang.Cot {
			b.WriteString("  C " + c + "\n")
		}
		for _, k := range bang.Khoa {
			b.WriteString("  K " + k + "\n")
		}
	}

	tong := sha256.Sum256([]byte(b.String()))
	vt.VanTay = hex.EncodeToString(tong[:])

	return vt, nil
}

func layCot(db DB, tenDB string) (map[string][]string, error) {
	rows, err := db.Query(`
SELECT table_name, column_name, column_type, is_nullable, COALESCE(column_default, ''), extra
  FROM information_schema.columns
 WHERE table_schema = ? AND table_name <> ?
 ORDER BY table_name, ordinal_position`, tenDB, TenBang)
	if err != nil {
		return nil, fmt.Errorf("không đọc được information_schema.columns: %w", err)
	}
	defer rows.Close()

	ra := map[string][]string{}
	for rows.Next() {
		var bang, ten, kieu, nullDuoc, macDinh, them string
		if err := rows.Scan(&bang, &ten, &kieu, &nullDuoc, &macDinh, &them); err != nil {
			return nil, err
		}
		ra[bang] = append(ra[bang], strings.TrimSpace(fmt.Sprintf("%s %s %s %s %s", ten, kieu, nullDuoc, macDinh, them)))
	}

	return ra, rows.Err()
}

func layKhoa(db DB, tenDB string) (map[string][]string, error) {
	rows, err := db.Query(`
SELECT table_name, index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index)
  FROM information_schema.statistics
 WHERE table_schema = ? AND table_name <> ?
 GROUP BY table_name, index_name, non_unique
 ORDER BY table_name, index_name`, tenDB, TenBang)
	if err != nil {
		return nil, fmt.Errorf("không đọc được information_schema.statistics: %w", err)
	}
	defer rows.Close()

	ra := map[string][]string{}
	for rows.Next() {
		var bang, ten, cot string
		var khongDuyNhat int
		if err := rows.Scan(&bang, &ten, &khongDuyNhat, &cot); err != nil {
			return nil, err
		}
		loai := "UNIQUE"
		if khongDuyNhat == 1 {
			loai = "INDEX"
		}
		ra[bang] = append(ra[bang], fmt.Sprintf("%s %s %s", ten, loai, cot))
	}

	return ra, rows.Err()
}

// SoVanTay liệt kê khác biệt giữa hai lược đồ, dạng câu người đọc được.
// Trả về danh sách rỗng khi hai bên giống hệt nhau.
func SoVanTay(a, b VanTayLuocDo, tenA, tenB string) []string {
	bangA := map[string]BangLuocDo{}
	for _, t := range a.Bang {
		bangA[t.Ten] = t
	}
	bangB := map[string]BangLuocDo{}
	for _, t := range b.Bang {
		bangB[t.Ten] = t
	}

	var khac []string

	for _, t := range a.Bang {
		if _, co := bangB[t.Ten]; !co {
			khac = append(khac, fmt.Sprintf("bảng %s: có ở %s, KHÔNG có ở %s", t.Ten, tenA, tenB))
		}
	}
	for _, t := range b.Bang {
		if _, co := bangA[t.Ten]; !co {
			khac = append(khac, fmt.Sprintf("bảng %s: có ở %s, KHÔNG có ở %s", t.Ten, tenB, tenA))
		}
	}

	for _, t := range a.Bang {
		u, co := bangB[t.Ten]
		if !co {
			continue
		}
		khac = append(khac, soDanhSach(t.Ten, "cột", t.Cot, u.Cot, tenA, tenB)...)
		khac = append(khac, soDanhSach(t.Ten, "khoá", t.Khoa, u.Khoa, tenA, tenB)...)
	}

	sort.Strings(khac)

	return khac
}

func soDanhSach(bang, loai string, a, b []string, tenA, tenB string) []string {
	// So theo TÊN (từ đầu tiên) để phân biệt "thiếu hẳn" với "khác kiểu dữ liệu".
	mucA := map[string]string{}
	for _, s := range a {
		mucA[dauTien(s)] = s
	}
	mucB := map[string]string{}
	for _, s := range b {
		mucB[dauTien(s)] = s
	}

	var khac []string
	for ten, ca := range mucA {
		cb, co := mucB[ten]
		if !co {
			khac = append(khac, fmt.Sprintf("%s.%s: %s có ở %s, KHÔNG có ở %s", bang, ten, loai, tenA, tenB))

			continue
		}
		if ca != cb {
			khac = append(khac, fmt.Sprintf("%s.%s: %s khác nhau — %s: %q | %s: %q", bang, ten, loai, tenA, ca, tenB, cb))
		}
	}
	for ten := range mucB {
		if _, co := mucA[ten]; !co {
			khac = append(khac, fmt.Sprintf("%s.%s: %s có ở %s, KHÔNG có ở %s", bang, ten, loai, tenB, tenA))
		}
	}

	return khac
}

func dauTien(s string) string {
	if i := strings.IndexByte(s, ' '); i > 0 {
		return s[:i]
	}

	return s
}
