package handler

import (
	"github.com/gin-gonic/gin"
)

type HealthHandler struct {
	version string
}

func NewHealthHandler(version string) *HealthHandler {
	return &HealthHandler{version: version}
}

// Health godoc
//
//	@Summary		Health check
//	@Description	Kiểm tra API còn sống và phiên bản hiện tại.
//	@Tags			System
//	@Produce		json
//	@Success		200	{object}	map[string]interface{}
//	@Router			/health [get]
func (h *HealthHandler) Health(c *gin.Context) {
	c.JSON(200, gin.H{
		"success": true,
		"status":  "ok",
		"service": "selliotech-api",
		"version": h.version,
	})
}
