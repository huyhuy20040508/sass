package repository

import (
	"errors"
	"fmt"
	"reflect"
	"strings"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/tenant"
)

// Bộ lọc tenant ở tầng GORM — RANH GIỚI BẢO MẬT GIỮA CÁC KHÁCH HÀNG.
//
// Vì sao nó nằm ở đây chứ không nằm trong từng repository: một hệ thống nhiều
// khách hàng rò dữ liệu KHÔNG phải vì ai đó viết sai câu truy vấn, mà vì ai đó
// QUÊN viết thêm một điều kiện vào câu thứ hai trăm. Đường đi thường ngày vẫn
// chạy đúng — mỗi khách vẫn thấy dữ liệu của mình — nên không có bài kiểm tra
// nào, không có người dùng nào báo lỗi. Cái sai chỉ lộ ra khi có người đi lệch:
// sửa URL cho id của người khác, gọi thẳng API bằng số tự đoán. Lúc đó nó không
// còn là lỗi phần mềm nữa mà là sự cố dữ liệu giữa hai khách hàng trả tiền.
//
// Vì vậy chỗ chèn điều kiện phải là chỗ mà KHÔNG câu truy vấn nào đi vòng qua
// được: callback của GORM, nơi mọi SELECT/UPDATE/DELETE/INSERT đều phải chạy qua.
//
// BA NGUYÊN TẮC, đọc kỹ trước khi sửa bất cứ dòng nào dưới đây:
//
//  1. MẶC ĐỊNH LÀ CÓ LỌC. Bảng không nằm trong globalTables đều bị coi là của
//     khách hàng. Thêm bảng mới mà quên khai báo gì cả thì nó được lọc — cùng
//     lắm là báo lỗi "unknown column tenant_id" ngay ở lượt chạy đầu tiên. Làm
//     ngược lại (mặc định không lọc, phải khai bảng nào cần lọc) thì bảng quên
//     khai sẽ phơi dữ liệu của mọi khách, im lặng, mãi mãi.
//
//  2. THIẾU TENANT LÀ LỖI, không phải là "lấy tất cả". Câu truy vấn chạy bằng
//     một ctx chưa xác định được khách hàng sẽ HỎNG kèm thông báo rõ ràng. Đây
//     là điều khác biệt quan trọng nhất của tệp này: một chỗ sót biến thành lỗi
//     500 nhìn thấy ngay trong lúc phát triển, thay vì thành một trang lặng lẽ
//     trả về dữ liệu của khách khác.
//
//  3. KHÔNG BAO GIỜ ĐOÁN. Không xác định được câu truy vấn đang chạm bảng nào
//     thì báo lỗi, chứ không bỏ qua. "Không rõ" và "an toàn" là hai chuyện khác
//     nhau.
//
// Đường đi vòng duy nhất là tenant.WithoutScope — xem chú thích ở gói đó.

// Lỗi của bộ lọc. Đều là lỗi LẬP TRÌNH, không phải lỗi người dùng: chúng nổi lên
// thành 500 và phải được sửa ở chỗ gọi, không phải bắt rồi bỏ qua.
var (
	// ErrNoTenant — câu truy vấn chạm bảng của khách hàng nhưng ctx không nói
	// được là khách nào.
	ErrNoTenant = errors.New("chưa xác định được cửa hàng cho câu truy vấn này")

	// ErrUnknownTable — không đọc ra được tên bảng thật để quyết định có lọc hay
	// không.
	ErrUnknownTable = errors.New("không xác định được bảng của câu truy vấn")

	// ErrRawSQL — câu lệnh SQL viết tay, không có chỗ nào để chèn điều kiện vào.
	ErrRawSQL = errors.New("SQL viết tay không tự lọc được theo cửa hàng")

	// ErrNoTenantColumn — bảng thuộc tenant nhưng entity Go không mang trường
	// tenant_id, nên INSERT sẽ không ghi cột đó.
	ErrNoTenantColumn = errors.New("entity thiếu trường tenant_id")
)

// tenantColumn là tên cột ở mọi bảng dữ liệu (xem migration 0002).
const tenantColumn = "tenant_id"

// globalTables — các bảng KHÔNG thuộc về khách hàng nào.
//
// Đây là danh sách ngoại lệ và nó phải luôn ngắn. Mỗi dòng thêm vào đây là một
// bảng mà mọi khách hàng cùng đọc, nên chỉ được thêm khi bảng đó thật sự không
// chứa dữ liệu của ai.
var globalTables = map[string]bool{
	// Bốn vai trò RBAC cố định, code tham chiếu thẳng bằng id (domain.AdminRoleID
	// = 2...). Cắt theo tenant thì mỗi khách mới phải sinh 4 dòng vai trò với id
	// không đoán trước được và bộ hằng số kia sai ngay từ khách thứ hai — xem
	// migration 0002.
	"roles": true,

	// Sổ đăng ký cửa hàng. Chính nó là bảng cha của mọi tenant_id nên không có
	// cột đó; lọc theo `id` cũng không được vì luồng đăng nhập PHẢI tra được cửa
	// hàng theo mã khách gõ TRƯỚC khi biết mình đang phục vụ khách nào.
	"tenants": true,

	// Lịch sử migration — của database, không của khách. Công cụ migrate dùng
	// database/sql chứ không qua GORM, nhưng để đây cho đủ nghĩa.
	"schema_migrations": true,
}

// tenantScope là plugin GORM cài bộ lọc vào vòng đời câu truy vấn.
type tenantScope struct{}

func (tenantScope) Name() string { return "selliotech:tenant" }

func (p tenantScope) Initialize(db *gorm.DB) error {
	// Đăng ký ở nhánh Before của từng loại lệnh: SQL được dựng bên trong callback
	// "gorm:*", nên chèn điều kiện phải xong trước lúc đó.
	//
	// Lỗi đăng ký được trả ra chứ không nuốt: plugin không gắn được nghĩa là toàn
	// bộ hệ thống chạy KHÔNG có bộ lọc, và đó là thứ phải làm sập lúc khởi động.
	reg := []struct {
		name string
		add  func() error
	}{
		{"query", func() error {
			return db.Callback().Query().Before("gorm:query").Register("tenant:query", p.filterRead)
		}},
		{"row", func() error {
			return db.Callback().Row().Before("gorm:row").Register("tenant:row", p.filterRead)
		}},
		{"update", func() error {
			return db.Callback().Update().Before("gorm:update").Register("tenant:update", p.filterWrite)
		}},
		{"delete", func() error {
			return db.Callback().Delete().Before("gorm:delete").Register("tenant:delete", p.filterWrite)
		}},
		{"create", func() error {
			return db.Callback().Create().Before("gorm:create").Register("tenant:create", p.stamp)
		}},
		{"raw", func() error {
			return db.Callback().Raw().Before("gorm:raw").Register("tenant:raw", p.blockRaw)
		}},
	}
	for _, r := range reg {
		if err := r.add(); err != nil {
			return fmt.Errorf("không gắn được bộ lọc tenant cho %s: %w", r.name, err)
		}
	}

	return nil
}

// filterRead chèn điều kiện tenant vào SELECT.
func (p tenantScope) filterRead(db *gorm.DB) { p.filter(db, false) }

// filterWrite chèn điều kiện tenant vào UPDATE / DELETE, kèm lưới an toàn cho
// lệnh ghi không có điều kiện nào.
func (p tenantScope) filterWrite(db *gorm.DB) { p.filter(db, true) }

// filter chèn `WHERE <bảng>.tenant_id = ?` vào SELECT / UPDATE / DELETE.
func (p tenantScope) filter(db *gorm.DB, write bool) {
	stmt := db.Statement
	if stmt == nil || db.Error != nil {
		return
	}

	// SQL viết tay (db.Raw): GORM không dựng mệnh đề nào cả, chuỗi người viết đưa
	// vào chạy nguyên xi. Không có chỗ để chèn điều kiện nên chỉ còn cách chặn.
	if stmt.SQL.Len() > 0 {
		p.blockRaw(db)

		return
	}

	table, qualifier, derived, err := scopeTarget(stmt)
	if err != nil {
		_ = db.AddError(err)

		return
	}
	// Bảng dẫn xuất: điều kiện đã được chèn vào chính câu truy vấn con (GORM chạy
	// callback cho nó), thêm lần nữa ở ngoài là tham chiếu một cột không tồn tại.
	if derived || globalTables[table] {
		return
	}

	id, ok := tenantOf(db)
	if !ok {
		return
	}

	// Giữ lại lưới an toàn của GORM: UPDATE/DELETE không có điều kiện nào của
	// người viết thì phải hỏng (gorm.ErrMissingWhereClause). Điều kiện mình chèn
	// vào ngay dưới đây sẽ làm GORM tưởng là "đã có WHERE" nên nó không tự bắt
	// được nữa — không kiểm ở đây thì một lệnh xoá viết thiếu sẽ dọn sạch bảng
	// của cả cửa hàng thay vì báo lỗi.
	if write && !db.AllowGlobalUpdate && !hasOwnCondition(stmt) {
		_ = db.AddError(gorm.ErrMissingWhereClause)

		return
	}

	stmt.AddClause(clause.Where{Exprs: []clause.Expression{
		clause.Eq{
			Column: clause.Column{Table: qualifier, Name: tenantColumn},
			Value:  id,
		},
	}})
}

// stamp ghi tenant_id vào các dòng sắp INSERT.
//
// Cố ý KHÔNG lấy giá trị người gọi đặt sẵn trong struct: tenant là thứ do danh
// tính của request quyết định, không phải do dữ liệu gửi lên quyết định. Ghi đè
// thẳng nghĩa là không có đường nào tạo dữ liệu vào cửa hàng của người khác, kể
// cả khi có ai đó lỡ tay gán trường TenantID từ body JSON.
func (p tenantScope) stamp(db *gorm.DB) {
	stmt := db.Statement
	if stmt == nil || db.Error != nil {
		return
	}

	table, _, derived, err := scopeTarget(stmt)
	if err != nil {
		_ = db.AddError(err)

		return
	}
	if derived || globalTables[table] {
		return
	}

	id, ok := tenantOf(db)
	if !ok {
		return
	}

	if stmt.Schema == nil {
		_ = db.AddError(fmt.Errorf("%w: bảng %s (INSERT không đi qua entity nào)", ErrNoTenantColumn, table))

		return
	}
	field := stmt.Schema.LookUpField(tenantColumn)
	if field == nil {
		// Entity quên nhúng domain.TenantOwned. Cột tenant_id sẽ không nằm trong
		// câu INSERT, dòng mới rơi vào một cửa hàng do database tự đoán.
		_ = db.AddError(fmt.Errorf("%w: %s — nhúng domain.TenantOwned vào entity của bảng %s",
			ErrNoTenantColumn, stmt.Schema.Name, table))

		return
	}

	rv := stmt.ReflectValue
	switch rv.Kind() {
	case reflect.Slice, reflect.Array:
		for i := 0; i < rv.Len(); i++ {
			if err := field.Set(stmt.Context, rv.Index(i), id); err != nil {
				_ = db.AddError(err)

				return
			}
		}
	case reflect.Struct:
		if err := field.Set(stmt.Context, rv, id); err != nil {
			_ = db.AddError(err)
		}
	}
}

// blockRaw chặn SQL viết tay.
//
// Không phải vì SQL viết tay là xấu, mà vì bộ lọc này không đọc hiểu được chuỗi
// SQL để chèn điều kiện vào đúng chỗ. Cần viết tay thì tự khai điều kiện tenant
// trong chính chuỗi đó rồi bọc lượt gọi bằng tenant.WithoutScope kèm lý do — làm
// vậy thì chỗ đó hiện ra khi soát lại, thay vì lặng lẽ chạy không lọc.
func (p tenantScope) blockRaw(db *gorm.DB) {
	if db.Statement == nil || db.Error != nil {
		return
	}
	if _, skipped := tenant.SkipReason(db.Statement.Context); skipped {
		return
	}

	_ = db.AddError(fmt.Errorf("%w: tự khai `tenant_id = ?` trong câu lệnh rồi bọc lượt gọi bằng tenant.WithoutScope kèm lý do", ErrRawSQL))
}

// tenantOf đọc cửa hàng từ ctx của câu truy vấn.
//
// ok = false có hai nghĩa hoàn toàn khác nhau và cả hai đều dừng việc chèn điều
// kiện: hoặc ctx đã cố ý tắt bộ lọc (chạy tiếp, không lọc), hoặc chưa xác định
// được khách hàng (đánh lỗi vào db, câu truy vấn sẽ không chạy).
func tenantOf(db *gorm.DB) (uint, bool) {
	ctx := db.Statement.Context
	if _, skipped := tenant.SkipReason(ctx); skipped {
		return 0, false
	}

	id, ok := tenant.ID(ctx)
	if !ok {
		_ = db.AddError(fmt.Errorf(
			"%w (bảng %s): ctx đi xuống repository phải mang tenant — kiểm tra handler có truyền c.Request.Context() không",
			ErrNoTenant, db.Statement.Table))

		return 0, false
	}

	return id, true
}

// hasOwnCondition cho biết câu UPDATE/DELETE đã có điều kiện của NGƯỜI VIẾT chưa.
//
// Hai dạng điều kiện, phải xét cả hai:
//   - Where(...) tường minh — đã nằm sẵn trong mệnh đề WHERE lúc này.
//   - Save(&x) / Delete(&x) — điều kiện khoá chính do GORM thêm vào SAU callback
//     này, nên phải nhìn thẳng vào giá trị khoá chính của bản ghi đích.
func hasOwnCondition(stmt *gorm.Statement) bool {
	if c, ok := stmt.Clauses["WHERE"]; ok {
		if w, ok := c.Expression.(clause.Where); ok && len(w.Exprs) > 0 {
			return true
		}
	}

	if stmt.Schema == nil || stmt.Schema.PrioritizedPrimaryField == nil {
		return false
	}
	field := stmt.Schema.PrioritizedPrimaryField

	rv := stmt.ReflectValue
	switch rv.Kind() {
	case reflect.Slice, reflect.Array:
		for i := 0; i < rv.Len(); i++ {
			if _, zero := field.ValueOf(stmt.Context, rv.Index(i)); !zero {
				return true
			}
		}
	case reflect.Struct:
		if _, zero := field.ValueOf(stmt.Context, rv); !zero {
			return true
		}
	}

	return false
}

// scopeTarget trả về TÊN BẢNG THẬT (để quyết định có lọc hay không) và BÍ DANH
// (để viết ra SQL), vì hai thứ đó thường khác nhau.
//
// Table("orders o") làm GORM đặt Statement.Table = "o" — bí danh, không phải tên
// bảng. Lấy nhầm cái đó đi tra globalTables thì bảng nào cũng "không phải bảng
// toàn cục", còn viết `orders.tenant_id` vào câu có bí danh thì MySQL báo lỗi
// unknown column. Nên phải đọc tên thật từ TableExpr và giữ bí danh riêng.
//
// derived = true nghĩa là FROM là một câu truy vấn con chứ không phải bảng. Câu
// con đó do GORM chạy callback riêng nên đã tự lọc; chèn thêm điều kiện ở vòng
// ngoài là tham chiếu một cột không có trong kết quả trả về.
func scopeTarget(stmt *gorm.Statement) (table, qualifier string, derived bool, err error) {
	qualifier = stmt.Table

	switch {
	case stmt.TableExpr != nil:
		raw := strings.TrimSpace(stmt.TableExpr.SQL)
		if strings.HasPrefix(raw, "(") {
			// Bảng dẫn xuất dựng từ một *gorm.DB thì an toàn — GORM chạy callback
			// cho câu con đó. Dẫn xuất viết bằng chuỗi SQL thuần thì KHÔNG, và
			// không có cách nào chèn điều kiện vào giữa chuỗi ấy.
			for _, v := range stmt.TableExpr.Vars {
				if _, ok := v.(*gorm.DB); ok {
					return "", "", true, nil
				}
			}

			return "", "", false, fmt.Errorf(
				"%w: FROM là câu truy vấn con viết tay (%s) — dựng nó bằng *gorm.DB để nó tự lọc theo cửa hàng",
				ErrUnknownTable, raw)
		}
		table = cleanTableName(raw)

	case stmt.Table != "":
		table = cleanTableName(stmt.Table)

	case stmt.Schema != nil:
		table = stmt.Schema.Table
		qualifier = table
	}

	if table == "" {
		return "", "", false, fmt.Errorf("%w: không có Model() lẫn Table()", ErrUnknownTable)
	}
	if qualifier == "" {
		qualifier = table
	}

	return table, qualifier, false, nil
}

// cleanTableName rút tên bảng ra khỏi mảnh SQL của mệnh đề FROM.
//
// Ba dạng gặp trong dự án: "`orders`" (GORM tự bọc), "orders o" và
// "order_return_items AS ri". Lấy từ đầu tiên rồi bóc dấu bọc là đủ cho cả ba;
// phần còn lại của chuỗi chỉ là bí danh, mà bí danh đã có sẵn ở Statement.Table.
func cleanTableName(s string) string {
	s = strings.TrimSpace(s)
	if i := strings.IndexAny(s, " \t\r\n,("); i >= 0 {
		s = s[:i]
	}
	s = strings.Trim(s, "`\"[]")
	// "database.bang" — tên database là của môi trường, chỉ phần sau mới là bảng.
	if i := strings.LastIndex(s, "."); i >= 0 {
		s = strings.Trim(s[i+1:], "`\"[]")
	}

	return s
}
