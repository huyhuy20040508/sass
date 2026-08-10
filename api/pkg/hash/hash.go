// Package hash bọc bcrypt cho việc băm & so khớp mật khẩu.
package hash

import "golang.org/x/crypto/bcrypt"

// Hash băm mật khẩu bằng bcrypt (cost mặc định).
func Hash(password string) (string, error) {
	b, err := bcrypt.GenerateFromPassword([]byte(password), bcrypt.DefaultCost)
	if err != nil {
		return "", err
	}
	return string(b), nil
}

// Check so khớp mật khẩu thô với hash đã lưu.
func Check(password, hashed string) bool {
	return bcrypt.CompareHashAndPassword([]byte(hashed), []byte(password)) == nil
}
