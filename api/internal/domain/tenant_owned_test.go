package domain

import (
	"go/ast"
	"go/parser"
	"go/token"
	"strings"
	"testing"
)

// Bài kiểm tra này tồn tại vì QUÊN là kiểu lỗi chính của phần đa khách hàng.
//
// Thêm một bảng mới rồi khai entity cho nó là việc bình thường; nhớ nhúng
// TenantOwned vào entity đó thì lại là việc phải nhớ. Không ai quên ở bảng thứ
// nhất, và ai cũng có thể quên ở bảng thứ bốn mươi — mà hậu quả thì y hệt nhau:
// cột tenant_id không nằm trong câu INSERT, dòng dữ liệu mới thuộc về cửa hàng
// nào là do database tự quyết.
//
// Vì vậy việc nhớ được giao cho máy: mọi entity GORM trong gói này (nhận biết
// qua trường khoá chính) đều PHẢI nhúng TenantOwned, trừ đúng những bảng toàn
// cục khai tường minh dưới đây.
//
// Lưới thứ hai nằm ở tầng chạy: bộ lọc trong repository/tenant_scope.go từ chối
// INSERT vào bảng thuộc tenant khi entity thiếu trường tenant_id. Hai lưới bắt
// hai lúc khác nhau — bài này bắt lúc biên dịch test, lưới kia bắt cả những
// entity khai ở gói khác.

// bangToanCuc — các entity KHÔNG thuộc về khách hàng nào.
//
// Danh sách này phải khớp với globalTables trong repository/tenant_scope.go.
// Thêm tên vào đây là tuyên bố "bảng này mọi khách hàng cùng đọc", nên mỗi dòng
// cần một lý do đứng vững được.
var bangToanCuc = map[string]string{
	"Role":   "bốn vai trò RBAC cố định, code tham chiếu thẳng bằng id",
	"Tenant": "chính là sổ đăng ký cửa hàng — bảng cha của mọi tenant_id",
}

func TestMoiEntityDeuNhungTenantOwned(t *testing.T) {
	fset := token.NewFileSet()
	tep, err := parser.ParseFile(fset, "entities.go", nil, 0)
	if err != nil {
		t.Fatalf("không đọc được entities.go: %v", err)
	}

	var daKiem int
	ast.Inspect(tep, func(n ast.Node) bool {
		ts, ok := n.(*ast.TypeSpec)
		if !ok {
			return true
		}
		st, ok := ts.Type.(*ast.StructType)
		if !ok {
			return true
		}
		// Chỉ xét entity GORM. Kiểu chỉ dùng để truyền dữ liệu trong bộ nhớ
		// (VoucherClaim, StockMove...) không có khoá chính và cũng không có bảng.
		if !coKhoaChinh(st) {
			return true
		}

		daKiem++
		ten := ts.Name.Name
		nhung := nhungTenantOwned(st)

		if lyDo, toanCuc := bangToanCuc[ten]; toanCuc {
			if nhung {
				t.Errorf("%s khai là bảng toàn cục (%s) mà vẫn nhúng TenantOwned — bỏ một trong hai", ten, lyDo)
			}

			return true
		}
		if !nhung {
			t.Errorf("entity %s thiếu TenantOwned.\n"+
				"  Bảng của nó có cột tenant_id nên INSERT phải ghi cột đó, nếu không dòng mới "+
				"rơi vào cửa hàng nào là do database tự quyết.\n"+
				"  Nhúng `TenantOwned` ngay dưới trường ID, hoặc khai vào bangToanCuc nếu bảng "+
				"này thật sự dùng chung cho mọi khách hàng.", ten)
		}

		return true
	})

	// Chốt chặn cho chính bài kiểm tra: đổi cách khai khoá chính mà coKhoaChinh
	// không nhận ra nữa thì bài này lặng lẽ không kiểm gì cả và vẫn xanh.
	if daKiem < 30 {
		t.Fatalf("chỉ nhận ra %d entity — bộ nhận biết khoá chính có vẻ đã hỏng", daKiem)
	}
}

func coKhoaChinh(st *ast.StructType) bool {
	for _, f := range st.Fields.List {
		if f.Tag != nil && strings.Contains(f.Tag.Value, "primaryKey") {
			return true
		}
	}

	return false
}

func nhungTenantOwned(st *ast.StructType) bool {
	for _, f := range st.Fields.List {
		// Trường nhúng: không có tên, chỉ có kiểu.
		if len(f.Names) > 0 {
			continue
		}
		if id, ok := f.Type.(*ast.Ident); ok && id.Name == "TenantOwned" {
			return true
		}
	}

	return false
}
