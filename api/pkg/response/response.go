// Package response chuẩn hóa định dạng JSON trả về cho toàn bộ API.
package response

import (
	"net/http"

	"github.com/gin-gonic/gin"
)

// Body là cấu trúc phản hồi thống nhất.
//
//	{ "success": true, "message": "...", "data": {...}, "meta": {...} }
//	{ "success": false, "message": "...", "errors": {...} }
type Body struct {
	Success bool        `json:"success"`
	Message string      `json:"message,omitempty"`
	Data    interface{} `json:"data,omitempty"`
	Meta    interface{} `json:"meta,omitempty"`
	Errors  interface{} `json:"errors,omitempty"`
}

// Pagination là metadata phân trang.
type Pagination struct {
	Page       int   `json:"page"`
	PageSize   int   `json:"page_size"`
	Total      int64 `json:"total"`
	TotalPages int   `json:"total_pages"`
}

// OK trả về 200 kèm dữ liệu.
func OK(c *gin.Context, data interface{}) {
	c.JSON(http.StatusOK, Body{Success: true, Data: data})
}

// OKMessage trả về 200 kèm message.
func OKMessage(c *gin.Context, message string, data interface{}) {
	c.JSON(http.StatusOK, Body{Success: true, Message: message, Data: data})
}

// Created trả về 201.
func Created(c *gin.Context, data interface{}) {
	c.JSON(http.StatusCreated, Body{Success: true, Data: data})
}

// CreatedMessage trả về 201 kèm message — cặp đôi của OKMessage.
//
// Có mặt để một lượt tạo vẫn nói được câu của nó mà không phải hạ xuống 200:
// giao diện đọc `message` để hiện thông báo, còn mã 201 mới là thứ phân biệt
// "đã tạo" với "đã sửa" trong log và trong swagger.
func CreatedMessage(c *gin.Context, message string, data interface{}) {
	c.JSON(http.StatusCreated, Body{Success: true, Message: message, Data: data})
}

// Paginated trả về 200 kèm danh sách + metadata phân trang.
func Paginated(c *gin.Context, data interface{}, p Pagination) {
	c.JSON(http.StatusOK, Body{Success: true, Data: data, Meta: p})
}

// Error trả về lỗi với mã HTTP tùy chọn.
func Error(c *gin.Context, status int, message string) {
	c.AbortWithStatusJSON(status, Body{Success: false, Message: message})
}

// Mã lỗi MÁY ĐỌC ĐƯỢC, đi trong `errors.ma`.
//
// Chỉ khai ở đây những lỗi mà ứng dụng phía trước phải XỬ LÝ KHÁC HẲN, không
// phải chỉ in câu chữ ra màn hình. Danh sách này cố tình ngắn: mỗi mã là một
// nhánh code bên kia phải viết, và một mã không ai đọc chỉ là rác trong response.
const (
	// MaCuaHangKhoa: cửa hàng đã hết hạn / bị khoá, nhưng NGƯỜI GỌI vẫn còn phiên
	// hợp lệ và vẫn vào được trang gói dịch vụ. Khác hẳn 401 "cửa hàng đang tạm
	// khoá" của nhân viên — bên đó là mất phiên, còn đây là phiên bị giới hạn
	// xuống đúng một trang. Shop Admin đọc mã này để đưa người dùng về trang gói
	// dịch vụ thay vì xoá session và đá ra màn hình đăng nhập.
	MaCuaHangKhoa = "CUA_HANG_KHOA"
)

// ErrorMa trả lỗi kèm MÃ máy đọc được trong `errors.ma`.
//
// Dùng khi phía trước phải rẽ nhánh theo lỗi. So khớp bằng câu chữ thì mọi lần
// sửa chính tả một thông báo là một lần làm hỏng nhánh xử lý ở đầu bên kia, và
// không có gì báo.
func ErrorMa(c *gin.Context, status int, ma, message string) {
	c.AbortWithStatusJSON(status, Body{
		Success: false,
		Message: message,
		Errors:  gin.H{"ma": ma},
	})
}

// ValidationError trả về 422 kèm chi tiết lỗi từng trường.
func ValidationError(c *gin.Context, errs interface{}) {
	c.AbortWithStatusJSON(http.StatusUnprocessableEntity, Body{
		Success: false,
		Message: "Dữ liệu không hợp lệ",
		Errors:  errs,
	})
}
