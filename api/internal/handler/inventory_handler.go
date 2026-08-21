package handler

import (
	"errors"
	"net/http"
	"strconv"
	"strings"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type InventoryHandler struct {
	svc service.InventoryService
}

func NewInventoryHandler(svc service.InventoryService) *InventoryHandler {
	return &InventoryHandler{svc: svc}
}

// @Summary		Danh sách tồn kho
// @Description	Liệt kê tồn kho theo TỪNG BIẾN THỂ (tổ hợp thuộc tính) kèm thông tin mặt hàng cha, giá bán hiệu lực, giá vốn hiệu lực và giá trị hàng đang nằm trong kho. Mặc định sắp xếp tồn ít lên trước.
// @Description	`stock_value` tính theo GIÁ VỐN (`cost_price` của biến thể, không có thì của sản phẩm cha) × tồn kho — giá trị tồn kho về kế toán phải theo giá vốn chứ không phải giá bán. `cost_price` rỗng nghĩa là chưa khai giá vốn, biến thể đó đóng góp 0₫ vào giá trị kho.
// @Description	`stock=low` lọc theo ngưỡng `low_stock` (mặc định 5): tồn còn lớn hơn 0 nhưng không vượt ngưỡng. `stock=out` là đã hết sạch, `stock=in` là còn trên ngưỡng.
// @Tags			Admin - Inventory
// @Accept			json
// @Produce		json
// @Param			keyword		query		string	false	"Tên sản phẩm / SKU biến thể / SKU sản phẩm"
// @Param			category_id	query		int		false	"Lọc theo danh mục"
// @Param			stock		query		string	false	"all|in|low|out"
// @Param			cost		query		string	false	"all|missing (chưa khai giá vốn)|set (đã khai)"
// @Param			is_active	query		bool	false	"Lọc theo trạng thái bán của biến thể"
// @Param			low_stock	query		int		false	"Ngưỡng sắp hết (mặc định 5, tối đa 1000)"
// @Param			sort		query		string	false	"stock_asc|stock_desc|name_asc|name_desc|value_desc|newest"
// @Param			page		query		int		false	"Trang (mặc định 1)"
// @Param			page_size	query		int		false	"Số item/trang (mặc định 20, tối đa 100)"
// @Success		200			{object}	response.Body{data=[]domain.InventoryItem,meta=response.Pagination}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/inventory [get]
func (h *InventoryHandler) List(c *gin.Context) {
	filter, page, pageSize := inventoryFilterFrom(c, 20, 100)

	items, total, err := h.svc.List(c.Request.Context(), filter)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn danh sách tồn kho")
		return
	}

	totalPages := 1
	if total > 0 {
		totalPages = int((total + int64(pageSize) - 1) / int64(pageSize))
	}
	response.Paginated(c, items, response.Pagination{
		Page:       page,
		PageSize:   pageSize,
		Total:      total,
		TotalPages: totalPages,
	})
}

// @Summary		Tồn kho theo chi nhánh
// @Description	Cùng số hàng của trang Tồn kho nhưng TÁCH RA theo từng chi nhánh: mỗi biến thể một dòng ở mỗi chi nhánh được chọn. Ô không có dòng trong `variant_stocks` (chi nhánh chưa từng nhập món đó) vẫn hiện với số 0 — đó đúng là dòng người đi nhập hàng cần thấy.
// @Description	`chi_nhanh` là tổng của TỪNG chi nhánh tính trên toàn bộ bộ lọc, không phải trên trang đang xem: tiêu đề nhóm trong bảng ghi "Chi nhánh A (137)" nên con số đó không được đổi khi lật trang.
// @Description	Không gửi `shops` thì lấy mọi chi nhánh ĐANG MỞ. Gửi đích danh thì lấy đúng những chi nhánh đó, kể cả chi nhánh đã đóng — hàng còn kẹt ở một điểm bán vừa đóng là thứ cần tra nhất.
// @Tags			Admin - Inventory
// @Accept			json
// @Produce		json
// @Param			keyword		query		string	false	"Tên sản phẩm / SKU biến thể / SKU sản phẩm"
// @Param			category_id	query		int		false	"Lọc theo danh mục"
// @Param			shops		query		string	false	"Danh sách id chi nhánh, ngăn bằng dấu phẩy"
// @Param			stock		query		string	false	"all|in|low|out"
// @Param			low_stock	query		int		false	"Ngưỡng sắp hết (mặc định 5, tối đa 1000)"
// @Param			sort		query		string	false	"stock_asc|stock_desc|name_asc|name_desc|value_desc"
// @Param			page		query		int		false	"Trang (mặc định 1)"
// @Param			page_size	query		int		false	"Số dòng/trang (mặc định 20, tối đa 200)"
// @Success		200			{object}	response.Body{data=domain.KetQuaTonChiNhanh,meta=response.Pagination}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/inventory/chi-nhanh [get]
func (h *InventoryHandler) TheoChiNhanh(c *gin.Context) {
	page, pageSize := pageParams(c, 20, 200)
	low, _ := strconv.Atoi(c.Query("low_stock"))

	f := domain.TonChiNhanhFilter{
		Keyword:  c.Query("keyword"),
		Stock:    c.Query("stock"),
		Sort:     c.Query("sort"),
		LowStock: low,
		ShopIDs:  idsTuChuoi(c.Query("shops")),
		Page:     page,
		PageSize: pageSize,
	}
	if id, err := strconv.ParseUint(c.Query("category_id"), 10, 64); err == nil && id > 0 {
		v := uint(id)
		f.CategoryID = &v
	}

	res, err := h.svc.TonTheoChiNhanh(c.Request.Context(), f)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn tồn kho theo chi nhánh")
		return
	}

	totalPages := 1
	if res.Total > 0 {
		totalPages = int((res.Total + int64(pageSize) - 1) / int64(pageSize))
	}
	response.Paginated(c, res, response.Pagination{
		Page:       page,
		PageSize:   pageSize,
		Total:      res.Total,
		TotalPages: totalPages,
	})
}

// idsTuChuoi đọc "3,5,8" thành []uint.
//
// Bỏ qua phần không phải số thay vì báo lỗi: chuỗi này đến từ ô lọc trên URL, và
// một dấu phẩy thừa lúc người dùng sửa tay địa chỉ không đáng để cả trang hỏng.
// Số 0 cũng bị bỏ — không chi nhánh nào mang số đó.
func idsTuChuoi(raw string) []uint {
	if strings.TrimSpace(raw) == "" {
		return nil
	}
	phan := strings.Split(raw, ",")
	ids := make([]uint, 0, len(phan))
	for _, p := range phan {
		n, err := strconv.ParseUint(strings.TrimSpace(p), 10, 64)
		if err != nil || n == 0 {
			continue
		}
		ids = append(ids, uint(n))
	}
	return ids
}

// @Summary		Thống kê tồn kho
// @Description	Đếm biến thể theo mức tồn (còn hàng / sắp hết / hết hàng), tổng số lượng đang có và tổng giá trị hàng trong kho theo GIÁ VỐN. Số liệu tính trên TOÀN kho, không phụ thuộc bộ lọc đang áp.
// @Description	`missing_cost` là số biến thể còn hàng nhưng chưa khai giá vốn — phần hàng đó không nằm trong `stock_value`, khác 0 nghĩa là tổng đang thiếu.
// @Tags			Admin - Inventory
// @Accept			json
// @Produce		json
// @Param			low_stock	query		int	false	"Ngưỡng sắp hết (mặc định 5, tối đa 1000)"
// @Success		200			{object}	response.Body{data=domain.InventoryStats}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/inventory/stats [get]
func (h *InventoryHandler) Stats(c *gin.Context) {
	low, _ := strconv.Atoi(c.Query("low_stock"))

	stats, err := h.svc.Stats(c.Request.Context(), low)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi thống kê tồn kho")
		return
	}
	response.OK(c, stats)
}

// @Summary		Chi tiết tồn kho một biến thể
// @Description	Thông tin biến thể kèm 20 bút toán kho gần nhất (nhập / xuất / kiểm kê / hàng trả về), đã ghép sẵn tên người thực hiện và mã chứng từ liên quan.
// @Tags			Admin - Inventory
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID biến thể sản phẩm"
// @Success		200	{object}	response.Body{data=service.InventoryDetail}
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/inventory/{id} [get]
func (h *InventoryHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	res, err := h.svc.Get(c.Request.Context(), id)
	if err != nil {
		respondInventoryError(c, err, "Lỗi truy vấn tồn kho")
		return
	}
	response.OK(c, res)
}

// @Summary		Sổ kho của một biến thể
// @Description	Toàn bộ bút toán nhập/xuất của một biến thể, mới nhất trước, có phân trang. Đây là nguồn sự thật của tồn kho; `stock_quantity` ở biến thể chỉ là số đã cộng dồn sẵn.
// @Tags			Admin - Inventory
// @Accept			json
// @Produce		json
// @Param			id			path		int	true	"ID biến thể sản phẩm"
// @Param			page		query		int	false	"Trang (mặc định 1)"
// @Param			page_size	query		int	false	"Số item/trang (mặc định 20, tối đa 100)"
// @Success		200			{object}	response.Body{data=[]domain.InventoryHistory,meta=response.Pagination}
// @Failure		404			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/inventory/{id}/history [get]
func (h *InventoryHandler) History(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	page, pageSize := pageParams(c, 20, 100)

	rows, total, err := h.svc.Histories(c.Request.Context(), id, page, pageSize)
	if err != nil {
		respondInventoryError(c, err, "Lỗi truy vấn sổ kho")
		return
	}

	totalPages := 1
	if total > 0 {
		totalPages = int((total + int64(pageSize) - 1) / int64(pageSize))
	}
	response.Paginated(c, rows, response.Pagination{
		Page:       page,
		PageSize:   pageSize,
		Total:      total,
		TotalPages: totalPages,
	})
}

// @Summary		Chỉnh tồn kho một biến thể
// @Description	Đặt lại tồn kho (`mode=set`, dùng khi kiểm kê) hoặc cộng/trừ một lượng (`mode=delta`, số âm là xuất bớt). Biến thể được khoá trong lúc tính nên hai nhân viên chỉnh cùng lúc không ghi đè lên nhau.
// @Description	Mỗi lần chỉnh đều ghi một bút toán vào sổ kho với `reference_type='manual'` kèm người thực hiện — tồn kho không bao giờ đổi mà không để lại dấu vết.
// @Description	Tồn kho sau khi chỉnh mà âm thì bị từ chối, không có dòng nào được ghi.
// @Tags			Admin - Inventory
// @Accept			json
// @Produce		json
// @Param			id		path		int							true	"ID biến thể sản phẩm"
// @Param			body	body		dto.InventoryAdjustRequest	true	"Thông tin chỉnh kho"
// @Success		200		{object}	response.Body{data=domain.InventoryAdjustResult}
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/inventory/{id} [put]
func (h *InventoryHandler) Adjust(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.InventoryAdjustRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Adjust(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		respondInventoryError(c, err, "Lỗi chỉnh tồn kho")
		return
	}
	response.OK(c, res)
}

// @Summary		Chỉnh tồn kho hàng loạt
// @Description	Cập nhật tồn của nhiều biến thể trong MỘT lần kiểm kê. Tất-cả-hoặc-không: một dòng sai (biến thể không tồn tại, tồn sau khi chỉnh bị âm) thì toàn bộ bị huỷ, không có dòng nào ghi vào kho.
// @Description	Tối đa 200 dòng mỗi lần. Các biến thể được khoá theo thứ tự ID tăng dần nên thao tác này không khoá chéo với luồng đặt hàng.
// @Tags			Admin - Inventory
// @Accept			json
// @Produce		json
// @Param			body	body		dto.InventoryBulkAdjustRequest	true	"Danh sách biến thể cần chỉnh"
// @Success		200		{object}	response.Body{data=[]domain.InventoryAdjustResult}
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/inventory/adjust [post]
func (h *InventoryHandler) BulkAdjust(c *gin.Context) {
	var req dto.InventoryBulkAdjustRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.BulkAdjust(c.Request.Context(), &req, currentUserID(c))
	if err != nil {
		respondInventoryError(c, err, "Lỗi chỉnh tồn kho hàng loạt")
		return
	}
	response.OKMessage(c, "Đã cập nhật tồn kho", res)
}

// @Summary		Khai giá vốn hàng loạt
// @Description	Ghi giá vốn cho nhiều biến thể trong MỘT lần — dùng cho việc nhập giá vốn từ file. Tất-cả-hoặc-không: một biến thể không tồn tại thì toàn bộ bị huỷ, không dòng nào được ghi. Tối đa 200 dòng mỗi lần.
// @Description	Giá vốn ghi vào mức BIẾN THỂ (ghi đè giá vốn của sản phẩm cha). `cost_price` để null là XOÁ giá vốn riêng, cho biến thể quay về lấy theo sản phẩm cha — khác hẳn với khai giá vốn bằng 0.
// @Description	Thao tác này KHÔNG ghi sổ kho: đây là sửa thuộc tính của hàng, không phải hàng ra vào kho.
// @Tags			Admin - Inventory
// @Accept			json
// @Produce		json
// @Param			body	body		dto.InventoryCostRequest	true	"Danh sách biến thể cần khai giá vốn"
// @Success		200		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/inventory/cost [put]
func (h *InventoryHandler) SetCost(c *gin.Context) {
	var req dto.InventoryCostRequest
	if !bindJSON(c, &req) {
		return
	}

	changed, err := h.svc.SetCosts(c.Request.Context(), &req)
	if err != nil {
		respondInventoryError(c, err, "Lỗi khai giá vốn")
		return
	}
	response.OKMessage(c, "Đã cập nhật giá vốn", gin.H{"changed": changed})
}

// ---------- Helper ----------

// pageParams đọc page/page_size từ query, kẹp về khoảng cho phép.
func pageParams(c *gin.Context, defaultSize, maxSize int) (int, int) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("page_size", strconv.Itoa(defaultSize)))
	if page < 1 {
		page = 1
	}
	if pageSize < 1 || pageSize > maxSize {
		pageSize = defaultSize
	}
	return page, pageSize
}

func inventoryFilterFrom(c *gin.Context, defaultSize, maxSize int) (domain.InventoryFilter, int, int) {
	page, pageSize := pageParams(c, defaultSize, maxSize)
	low, _ := strconv.Atoi(c.Query("low_stock"))

	f := domain.InventoryFilter{
		Keyword:  c.Query("keyword"),
		Stock:    c.Query("stock"),
		Cost:     c.Query("cost"),
		Sort:     c.Query("sort"),
		LowStock: low,
		Page:     page,
		PageSize: pageSize,
	}
	if id, err := strconv.ParseUint(c.Query("category_id"), 10, 64); err == nil && id > 0 {
		v := uint(id)
		f.CategoryID = &v
	}
	if v, err := strconv.ParseBool(c.Query("is_active")); err == nil {
		f.IsActive = &v
	}
	return f, page, pageSize
}

func respondInventoryError(c *gin.Context, err error, fallback string) {
	switch {
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, http.StatusNotFound, "Không tìm thấy biến thể sản phẩm này")
	case errors.Is(err, domain.ErrOutOfStock):
		response.Error(c, http.StatusUnprocessableEntity,
			"Tồn kho sau khi chỉnh sẽ bị âm. Vui lòng kiểm tra lại số lượng xuất.")
	case errors.Is(err, domain.ErrStockNegative):
		response.Error(c, http.StatusUnprocessableEntity, "Tồn kho không được là số âm")
	case errors.Is(err, domain.ErrStockNoChange):
		response.Error(c, http.StatusUnprocessableEntity, "Vui lòng nhập số lượng cần thay đổi")
	default:
		response.Error(c, http.StatusInternalServerError, fallback)
	}
}
