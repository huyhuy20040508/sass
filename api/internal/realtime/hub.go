// Package realtime đẩy sự kiện từ server xuống trình duyệt bằng SSE
// (Server-Sent Events) — admin thấy đơn mới và khách thấy đơn đổi trạng thái mà
// không phải tải lại trang.
//
// Hub nằm hoàn toàn trong bộ nhớ tiến trình: đây là lựa chọn có chủ đích cho quy
// mô hiện tại (một tiến trình API duy nhất). Nếu sau này chạy nhiều instance thì
// mỗi instance chỉ đẩy được cho client đang nối vào chính nó, lúc đó phải thay
// phần Publish bằng một kênh chung (Redis pub/sub) — phần còn lại giữ nguyên.
package realtime

import (
	"strconv"
	"sync"
	"time"
)

// Tên kênh (topic). Mỗi client chỉ nhận sự kiện của kênh mình đăng ký.

// TopicAdmin trả kênh quản trị CỦA MỘT CỬA HÀNG.
//
// PHẢI KÈM MÃ CỬA HÀNG. Trước đây đây là một hằng số "admin" dùng chung, nghĩa
// là mọi quản trị viên của MỌI cửa hàng cùng nghe một kênh: tiệm A có đơn mới
// thì tiệm B cũng nhận sự kiện đó và hiện toast "Đơn hàng mới #..." kèm tên
// khách của tiệm A. Danh sách trong chuông thì vẫn đúng (câu truy vấn có lọc
// tenant), nên lỗi này không lộ ra ở đâu ngoài cái toast — chỗ chẳng ai nghĩ tới
// khi kiểm tra ranh giới dữ liệu.
//
// tenantID = 0 (không xác định được cửa hàng) trả về kênh rỗng: KHÔNG rơi về một
// kênh chung. Rơi về kênh chung là mở lại đúng cái vừa đóng.
func TopicAdmin(tenantID uint) string {
	if tenantID == 0 {
		return ""
	}

	return "admin:t" + strconv.FormatUint(uint64(tenantID), 10)
}

// TopicUser trả kênh riêng của một khách hàng.
func TopicUser(userID uint) string { return "user:" + strconv.FormatUint(uint64(userID), 10) }

// Loại sự kiện đẩy xuống trình duyệt (trùng với tên event của EventSource).
const (
	// EventNotification — một thông báo MỚI vừa được lưu vào bảng notifications.
	// Trình duyệt thêm vào chuông và hiện toast.
	EventNotification = "notification"
	// EventOrder — đơn hàng vừa được tạo/đổi trạng thái. Không lưu vào database:
	// đây chỉ là tín hiệu "dữ liệu trên màn hình đã cũ, làm mới đi".
	EventOrder = "order"
	// EventReturn — phiếu trả hàng vừa được tạo/đổi trạng thái. Cùng vai trò với
	// EventOrder nhưng tách riêng để trang Đơn hàng không phải tải lại vì một
	// phiếu trả, và ngược lại.
	EventReturn = "return"
)

// Event là một gói tin gửi xuống client.
type Event struct {
	// Type quyết định trình duyệt xử lý thế nào (xem hằng Event* ở trên).
	Type string `json:"type"`
	// Payload là dữ liệu kèm theo, sẽ được mã hoá JSON.
	Payload any `json:"payload"`
}

// client là một kết nối SSE đang mở.
type client struct {
	topics map[string]bool
	ch     chan Event
}

// Hub giữ danh sách client đang kết nối và phát sự kiện tới đúng kênh.
// An toàn khi gọi từ nhiều goroutine.
type Hub struct {
	mu      sync.RWMutex
	clients map[*client]bool
}

func NewHub() *Hub {
	return &Hub{clients: make(map[*client]bool)}
}

// Subscription là "tay cầm" của một kết nối: đọc từ C, gọi Close khi client ngắt.
type Subscription struct {
	C   <-chan Event
	hub *Hub
	c   *client
}

// Close huỷ đăng ký. Gọi nhiều lần cũng an toàn.
func (s *Subscription) Close() {
	if s == nil || s.hub == nil {
		return
	}
	s.hub.mu.Lock()
	if s.hub.clients[s.c] {
		delete(s.hub.clients, s.c)
		close(s.c.ch)
	}
	s.hub.mu.Unlock()
	s.hub = nil
}

// subscribeBuffer — số sự kiện đệm cho mỗi client.
//
// Đệm để một client đọc chậm (tab bị treo, mạng kẹt) không làm nghẽn goroutine
// đang phát. Đầy đệm thì sự kiện mới bị BỎ chứ không chặn: mất một tín hiệu làm
// mới màn hình là chấp nhận được, chặn luồng đặt hàng thì không.
const subscribeBuffer = 16

// Subscribe mở một kết nối nghe trên các kênh chỉ định.
func (h *Hub) Subscribe(topics ...string) *Subscription {
	c := &client{topics: make(map[string]bool, len(topics)), ch: make(chan Event, subscribeBuffer)}
	for _, t := range topics {
		c.topics[t] = true
	}

	h.mu.Lock()
	h.clients[c] = true
	h.mu.Unlock()

	return &Subscription{C: c.ch, hub: h, c: c}
}

// Publish phát một sự kiện tới mọi client đang nghe kênh topic.
// Không bao giờ chặn: client nào đệm đầy thì bỏ qua sự kiện đó.
func (h *Hub) Publish(topic string, ev Event) {
	h.mu.RLock()
	defer h.mu.RUnlock()
	for c := range h.clients {
		if !c.topics[topic] {
			continue
		}
		select {
		case c.ch <- ev:
		default:
		}
	}
}

// Count trả số kết nối đang mở — dùng cho health check / log.
func (h *Hub) Count() int {
	h.mu.RLock()
	defer h.mu.RUnlock()
	return len(h.clients)
}

// HeartbeatInterval — nhịp gửi comment giữ kết nối.
//
// Proxy và trình duyệt đóng kết nối "im lặng" sau vài chục giây, nên cứ đều đặn
// gửi một dòng comment (`: ping`) để đường truyền không bị coi là chết.
const HeartbeatInterval = 20 * time.Second
