package handler

import (
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type ProductHandler struct {
	svc service.ProductService
	// promos gắn thêm giá sau khuyến mãi vào sản phẩm sắp trả về. Đặt ở tầng
	// handler chứ không trộn vào ProductService: giá khuyến mãi là thứ TÍNH LÚC
	// ĐỌC, không được lẫn vào đường tạo/sửa sản phẩm — trang quản trị nạp sản phẩm
	// từ chính API này rồi lưu lại, lẫn vào là giá tạm thời bị đóng đinh thành giá thật.
	promos service.PromotionService
}

func NewProductHandler(svc service.ProductService, promos service.PromotionService) *ProductHandler {
	return &ProductHandler{svc: svc, promos: promos}
}

// stripCost xoá giá vốn khỏi một sản phẩm sắp trả về.
//
// Hai endpoint sản phẩm là CÔNG KHAI và trả thẳng domain.Product, nên giá vốn có
// thẻ json sẽ đi ra Internet nếu không chặn ở đây. Lọc ở tầng trả lời chứ không
// gắn `json:"-"` vào entity, vì khu quản trị vẫn cần đọc lại số này để sửa sản
// phẩm — và repository ghi cả dòng, đọc thiếu là lưu xong mất luôn giá vốn.
func stripCost(p *domain.Product) {
	if p == nil {
		return
	}
	p.CostPrice = nil
	for i := range p.Variants {
		p.Variants[i].CostPrice = nil
	}
}

// List godoc
//
//	@Summary		Danh sách sản phẩm
//	@Description	Lọc theo từ khóa, danh mục, thương hiệu, loại áo, khoảng giá; hỗ trợ phân trang & sắp xếp.
//	@Description	`cost_price` (giá vốn, ở cả sản phẩm lẫn biến thể) CHỈ trả về khi gọi kèm token của nhân viên quản trị; các trường hợp khác luôn là null.
//	@Tags			Products
//	@Produce		json
//	@Param			keyword		query		string	false	"Tìm theo tên/đội/SKU"
//	@Param			category_id	query		int		false	"ID danh mục (tự gộp sản phẩm của mọi danh mục con/cháu)"
//	@Param			brand_id	query		int		false	"ID thương hiệu"
//	@Param			kit_type	query		string	false	"Loại áo: fan (FAN) | player (PLAYER)"
//	@Param			min_price	query		number	false	"Giá tối thiểu"
//	@Param			max_price	query		number	false	"Giá tối đa"
//	@Param			featured	query		bool	false	"Chỉ sản phẩm nổi bật"
//	@Param			on_sale		query		bool	false	"Chỉ sản phẩm đang giảm giá"
//	@Param			active		query		bool	false	"Lọc theo cờ hiển thị (true=đang bán, false=không hiện)"
//	@Param			status		query		string	false	"Lọc theo trạng thái kinh doanh: active | hidden | discontinued"
//	@Param			all			query		bool	false	"true = admin lấy cả sản phẩm đã ẩn"
//	@Param			slim		query		bool	false	"true = không trả kèm biến thể & thư viện ảnh (nhẹ hơn nhiều cho trang danh sách)"
//	@Param			sort		query		string	false	"newest|price_asc|price_desc|best_selling"
//	@Param			page		query		int		false	"Trang (mặc định 1)"
//	@Param			page_size	query		int		false	"Số item/trang (mặc định 20, tối đa 100)"
//	@Success		200			{object}	response.Body
//	@Router			/products [get]
func (h *ProductHandler) List(c *gin.Context) {
	f := domain.ProductFilter{
		Keyword:         c.Query("keyword"),
		KitType:         c.Query("kit_type"),
		Status:          c.Query("status"),
		Slim:            c.Query("slim") == "true",
		Sort:            c.Query("sort"),
		CategoryID:      queryUintPtr(c, "category_id"),
		BrandID:         queryUintPtr(c, "brand_id"),
		MinPrice:        queryFloatPtr(c, "min_price"),
		MaxPrice:        queryFloatPtr(c, "max_price"),
		IsFeatured:      queryBoolPtr(c, "featured"),
		IsActive:        queryBoolPtr(c, "active"),
		OnSale:          queryBoolPtr(c, "on_sale"),
		IncludeInactive: c.Query("all") == "true",
		Page:            queryInt(c, "page", 1),
		PageSize:        queryInt(c, "page_size", 20),
	}

	// Kẹp phân trang tại đây để offset ở repo và meta trả về luôn nhất quán
	// (giống order handler). page >= 1; 1 <= page_size <= 100.
	if f.Page < 1 {
		f.Page = 1
	}
	if f.PageSize < 1 || f.PageSize > 100 {
		f.PageSize = 20
	}

	products, total, err := h.svc.List(c.Request.Context(), f)
	if err != nil {
		handleServiceError(c, err)
		return
	}

	if !isAdminRole(c.GetString(middleware.CtxRole)) {
		for i := range products {
			stripCost(&products[i])
		}
	}
	h.promos.DecorateProducts(c.Request.Context(), products)

	totalPages := int((total + int64(f.PageSize) - 1) / int64(f.PageSize))
	response.Paginated(c, products, response.Pagination{
		Page:       f.Page,
		PageSize:   f.PageSize,
		Total:      total,
		TotalPages: totalPages,
	})
}

// GetBySlug godoc
//
//	@Summary		Chi tiết sản phẩm
//	@Description	Lấy chi tiết sản phẩm theo slug (kèm biến thể, ảnh) và tăng lượt xem.
//	@Description	`cost_price` (giá vốn) CHỈ trả về khi gọi kèm token của nhân viên quản trị; các trường hợp khác luôn là null.
//	@Tags			Products
//	@Produce		json
//	@Param			slug	path		string	true	"Slug sản phẩm"
//	@Success		200		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Router			/products/{slug} [get]
func (h *ProductHandler) GetBySlug(c *gin.Context) {
	slug := c.Param("slug")
	p, err := h.svc.GetBySlug(c.Request.Context(), slug)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	if !isAdminRole(c.GetString(middleware.CtxRole)) {
		// Sản phẩm đang tạm ẩn / ngừng kinh doanh thì coi như không có: trước đây
		// danh sách lọc nó ra nhưng ai cầm đường dẫn cũ vẫn mở được trang chi tiết
		// và bấm mua (tầng thanh toán mới chặn, sau khi khách đã điền xong đơn).
		// Nhân viên quản trị vẫn xem được để soát lại trước khi cho bán.
		if !p.IsActive {
			handleServiceError(c, domain.ErrNotFound)
			return
		}
		stripCost(p)
	}
	h.promos.DecorateProduct(c.Request.Context(), p)
	response.OK(c, p)
}

// Get godoc
//
//	@Summary		Chi tiết sản phẩm cho trang quản trị
//	@Description	Lấy theo ID, kèm giá vốn và toàn bộ biến thể/ảnh. Dùng khi mở form sửa để làm việc trên dữ liệu MỚI NHẤT thay vì bản đã nạp cùng danh sách.
//	@Tags			Admin - Products
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID sản phẩm"
//	@Success		200	{object}	response.Body
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/products/{id} [get]
func (h *ProductHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	p, err := h.svc.Get(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	// Cố ý KHÔNG gọi stripCost: đây là đường của khu quản trị, form sửa cần đọc
	// lại giá vốn — repository ghi cả dòng, đọc thiếu là lưu xong mất luôn số đó.
	h.promos.DecorateProduct(c.Request.Context(), p)
	response.OK(c, p)
}

// Create godoc
//
//	@Summary		Tạo sản phẩm
//	@Description	Biến thể chỉ khai size + màu. Tồn kho của biến thể mới luôn bằng 0 — muốn có hàng bán phải qua nghiệp vụ kho (`POST /admin/purchases` rồi nhận hàng, hoặc `POST /admin/inventory/adjust`).
//	@Tags			Admin - Products
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.ProductRequest	true	"Dữ liệu sản phẩm"
//	@Success		201		{object}	response.Body
//	@Failure	400		{object}	response.Body
//	@Failure	401		{object}	response.Body
//	@Failure	403		{object}	response.Body
//	@Failure	404		{object}	response.Body
//	@Failure	409		{object}	response.Body
//	@Failure	422		{object}	response.Body
//	@Router		/admin/products [post]
func (h *ProductHandler) Create(c *gin.Context) {
	var req dto.ProductRequest
	if !bindJSON(c, &req) {
		return
	}
	p, err := h.svc.Create(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.Created(c, p)
}

// Update godoc
//
//	@Summary		Cập nhật sản phẩm
//	@Description	Gửi kèm `variants` sẽ đồng bộ lại danh sách biến thể (thêm/sửa/xoá theo đúng danh sách). Tồn kho của các biến thể KHÔNG bị ảnh hưởng — cột này chỉ do nghiệp vụ kho ghi.
//	@Tags			Admin - Products
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int					true	"ID sản phẩm"
//	@Param			body	body		dto.ProductRequest	true	"Dữ liệu sản phẩm"
//	@Success	200		{object}	response.Body
//	@Failure	400		{object}	response.Body
//	@Failure	401		{object}	response.Body
//	@Failure	403		{object}	response.Body
//	@Failure	404		{object}	response.Body
//	@Failure	422		{object}	response.Body
//	@Router		/admin/products/{id} [put]
func (h *ProductHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.ProductRequest
	if !bindJSON(c, &req) {
		return
	}
	p, err := h.svc.Update(c.Request.Context(), id, req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Cập nhật thành công", p)
}

// UpdateStatus godoc
//
//	@Summary	Đổi trạng thái kinh doanh của sản phẩm
//	@Description	Chỉ cập nhật status + cờ hiển thị, không đụng tới biến thể, ảnh hay giá. Nhận `status` (active | hidden | discontinued) hoặc cờ cũ `is_active`.
//	@Tags		Admin - Products
//	@Accept		json
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id		path		int							true	"ID sản phẩm"
//	@Param		body	body		dto.ProductStatusRequest	true	"Trạng thái kinh doanh"
//	@Success	200		{object}	response.Body
//	@Failure	400		{object}	response.Body
//	@Failure	401		{object}	response.Body
//	@Failure	403		{object}	response.Body
//	@Failure	404		{object}	response.Body
//	@Failure	422		{object}	response.Body
//	@Router		/admin/products/{id}/status [put]
func (h *ProductHandler) UpdateStatus(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.ProductStatusRequest
	if !bindJSON(c, &req) {
		return
	}
	status, ok := req.Resolve()
	if !ok {
		response.ValidationError(c, map[string]string{"status": "Vui lòng cho biết trạng thái cần đặt"})
		return
	}
	p, err := h.svc.SetStatus(c.Request.Context(), id, status)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đã cập nhật trạng thái sản phẩm", p)
}

// BulkDelete godoc
//
//	@Summary	Xoá nhiều sản phẩm
//	@Description	Xoá theo danh sách id trong MỘT giao dịch: hoặc xoá được hết, hoặc không đụng gì cả. Tối đa 200 id mỗi lượt.
//	@Tags		Admin - Products
//	@Accept		json
//	@Produce	json
//	@Security	BearerAuth
//	@Param		body	body		dto.ProductBulkDeleteRequest	true	"Danh sách id"
//	@Success	200		{object}	response.Body
//	@Failure	400		{object}	response.Body
//	@Failure	401		{object}	response.Body
//	@Failure	403		{object}	response.Body
//	@Failure	422		{object}	response.Body
//	@Router		/admin/products/bulk-delete [post]
func (h *ProductHandler) BulkDelete(c *gin.Context) {
	var req dto.ProductBulkDeleteRequest
	if !bindJSON(c, &req) {
		return
	}
	deleted, err := h.svc.DeleteMany(c.Request.Context(), req.IDs)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Xóa thành công", gin.H{"deleted": deleted, "requested": len(req.IDs)})
}

// Delete godoc
//
//	@Summary	Xóa sản phẩm
//	@Tags		Admin - Products
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID sản phẩm"
//	@Success	200	{object}	response.Body
//	@Failure	400	{object}	response.Body
//	@Failure	401	{object}	response.Body
//	@Failure	403	{object}	response.Body
//	@Failure	404	{object}	response.Body
//	@Router		/admin/products/{id} [delete]
func (h *ProductHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Xóa thành công", nil)
}

// Duplicate godoc
// @Summary      Sao chép sản phẩm
// @Description  Tạo một bản sao mới của sản phẩm (bao gồm các biến thể & thư viện ảnh) với trạng thái tạm ẩn.
// @Tags         Admin - Products
// @Accept       json
// @Produce      json
// @Security     BearerAuth
// @Param        id   path      int  true  "ID sản phẩm"
// @Success      201  {object}  response.Body
// @Failure      400  {object}  response.Body
// @Failure      401  {object}  response.Body
// @Failure      404  {object}  response.Body
// @Router       /admin/products/{id}/duplicate [post]
func (h *ProductHandler) Duplicate(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	p, err := h.svc.Duplicate(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.Created(c, p)
}

// ---------- query helpers ----------

func queryInt(c *gin.Context, key string, def int) int {
	if v, err := strconv.Atoi(c.Query(key)); err == nil {
		return v
	}
	return def
}

func queryUintPtr(c *gin.Context, key string) *uint {
	s := c.Query(key)
	if s == "" {
		return nil
	}
	if v, err := strconv.ParseUint(s, 10, 64); err == nil {
		u := uint(v)
		return &u
	}
	return nil
}

func queryFloatPtr(c *gin.Context, key string) *float64 {
	s := c.Query(key)
	if s == "" {
		return nil
	}
	if v, err := strconv.ParseFloat(s, 64); err == nil {
		return &v
	}
	return nil
}

func queryBoolPtr(c *gin.Context, key string) *bool {
	s := c.Query(key)
	if s == "" {
		return nil
	}
	b := s == "true" || s == "1"
	return &b
}
