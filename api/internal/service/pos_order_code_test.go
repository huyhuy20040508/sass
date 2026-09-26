package service

import (
	"context"
	"testing"
	"time"

	"sass-api/internal/tenant"
)

// Mã giữ trước chỉ được dùng khi chữ ký khớp ĐÚNG mã, ĐÚNG cửa hàng và còn hạn.
func TestMaDonGiu_ChuKy(t *testing.T) {
	khoa := []byte("khoa-thu")
	ctx := tenant.WithID(context.Background(), 7)
	bay := time.Now()
	token := kyMaDon(ctx, khoa, "DH000123", bay.Add(time.Hour))

	if got := maDonGiuHopLe(ctx, khoa, " DH000123 ", token, bay); got != "DH000123" {
		t.Fatalf("chữ ký đúng phải nhận mã, đang là %q", got)
	}

	cases := map[string]string{
		"đổi sang mã khác":    maDonGiuHopLe(ctx, khoa, "DH000124", token, bay),
		"cửa hàng khác":       maDonGiuHopLe(tenant.WithID(context.Background(), 8), khoa, "DH000123", token, bay),
		"hết hạn":             maDonGiuHopLe(ctx, khoa, "DH000123", token, bay.Add(2*time.Hour)),
		"khoá khác":           maDonGiuHopLe(ctx, []byte("khoa-la"), "DH000123", token, bay),
		"không có khoá":       maDonGiuHopLe(ctx, nil, "DH000123", token, bay),
		"token rác":           maDonGiuHopLe(ctx, khoa, "DH000123", "abc", bay),
		"sửa hạn trong token": maDonGiuHopLe(ctx, khoa, "DH000123", "9999999999"+token[len("0000000000"):], bay),
	}
	for ten, got := range cases {
		if got != "" {
			t.Errorf("%s: phải bỏ qua mã, đang nhận %q", ten, got)
		}
	}
}
