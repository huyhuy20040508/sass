#!/usr/bin/env bash
#
# Kéo bản sao lưu từ máy chủ về máy cá nhân.
#
#     bash deploy/scripts/keo-ve-sao-luu.sh [thư-mục-đích]
#
# Chạy ở MÁY CỦA BẠN (Git Bash trên Windows, hoặc Linux/macOS), không phải trên
# máy chủ. Mặc định để vào ./sao-luu-selliotech.
#
# ---------------------------------------------------------------------------
# Vì sao vẫn cần bước này dù máy chủ đã tự sao lưu mỗi 12 giờ:
#
# Sao lưu nằm trên máy chủ cứu được gần hết các tai nạn thật sự hay xảy ra —
# xoá nhầm, migrate hỏng, một lỗi trong code quét sạch một bảng. Nhưng nó
# KHÔNG cứu được trường hợp mất chính máy chủ: đĩa chết, VPS bị xoá, tài khoản
# nhà cung cấp bị khoá. Lúc đó dữ liệu và bản sao lưu ra đi cùng nhau.
#
# Kéo về đây là lớp thứ hai, và nó cũng không phụ thuộc vào dịch vụ nào: chỉ
# dùng ssh + tar, hai thứ Windows 10/11 và Git Bash đã có sẵn.
#
# Chạy tay mỗi tuần một lần là đủ. Muốn tự động thì Task Scheduler của Windows:
#     Program:   C:\Program Files\Git\bin\bash.exe
#     Arguments: "C:\huy\sass\deploy\scripts\keo-ve-sao-luu.sh" "D:\sao-luu"
# ---------------------------------------------------------------------------
set -euo pipefail

MAY="${SELLIO_MAY:-root@103.78.2.230}"
XA="/var/backups/selliotech"
DICH="${1:-./sao-luu-selliotech}"
KEO_VE=5      # xét bao nhiêu bản mới nhất trên máy chủ
GIU_LAI=12    # giữ bao nhiêu bản dưới máy này

xanh() { printf '\033[32m%s\033[0m\n' "$*"; }
vang() { printf '\033[33m%s\033[0m\n' "$*"; }
chet() { printf '\033[1;31mLỖI: %s\033[0m\n' "$*" >&2; exit 1; }

command -v ssh >/dev/null || chet "Không tìm thấy ssh. Trên Windows hãy chạy bằng Git Bash."
mkdir -p "$DICH"

echo "Máy chủ : $MAY"
echo "Về      : $(cd "$DICH" && pwd)"
echo

# Chưa dùng khoá SSH thì mỗi lệnh ssh dưới đây hỏi mật khẩu một lần. Tạo khoá
# một lần cho xong: ssh-keygen -t ed25519  rồi  ssh-copy-id "$MAY"
if ! ssh -o BatchMode=yes -o ConnectTimeout=10 "$MAY" true 2>/dev/null; then
    vang "Chưa vào được bằng khoá SSH — sẽ phải gõ mật khẩu vài lần."
    vang "Làm một lần cho khỏi phiền:  ssh-keygen -t ed25519 && ssh-copy-id $MAY"
    echo
fi

# Một lượt ssh lấy hết danh sách: tên bản + dấu vân tay kho ảnh của từng bản.
mapfile -t DONG < <(ssh "$MAY" "cd $XA 2>/dev/null && for d in \$(ls -1d 2*-* 2>/dev/null | sort -r | head -$KEO_VE); do echo \"\$d \$(cat \$d/anh.van-tay 2>/dev/null || echo -)\"; done") \
    || chet "Không nối được máy chủ hoặc chưa có bản sao lưu nào."
(( ${#DONG[@]} > 0 )) || chet "Máy chủ chưa có bản sao lưu nào trong $XA."

so_moi=0
for dong in "${DONG[@]}"; do
    ban="${dong%% *}"
    van_tay="${dong##* }"
    [[ -z "$ban" ]] && continue

    if [[ -f "$DICH/$ban/db.sql.gz" ]]; then
        echo "  $ban — đã có"
        continue
    fi

    # Kho ảnh gần như không đổi giữa các bản. Nếu dưới này đã có một bản mang
    # đúng dấu vân tay đó thì chép ngang từ đĩa, khỏi tải lại vài trăm MB qua
    # mạng. Đây là chỗ khiến việc kéo về hằng tuần chỉ tốn vài giây.
    co_san=""
    if [[ "$van_tay" != "-" ]]; then
        for cu in "$DICH"/*/; do
            [[ -f "${cu}anh.van-tay" && -f "${cu}anh.tar" ]] || continue
            if [[ "$(cat "${cu}anh.van-tay")" == "$van_tay" ]]; then co_san="${cu}anh.tar"; break; fi
        done
    fi

    echo -n "  $ban — đang kéo về"
    [[ -n "$co_san" ]] && echo -n " (ảnh dùng lại bản đã có)"
    echo "..."

    # Tải nguyên thư mục qua MỘT kết nối bằng tar, thay vì scp từng tệp: ít
    # lần hỏi mật khẩu hơn, và không vướng chuyện scp diễn giải đường dẫn kiểu
    # Windows (C:\...) thành "tên máy C".
    tmp="$DICH/.dang-tai-$ban"
    rm -rf "$tmp"; mkdir -p "$tmp"
    loai=""
    [[ -n "$co_san" ]] && loai="--exclude=./anh.tar"
    if ssh "$MAY" "tar -cf - -C $XA/$ban $loai ." | tar -xf - -C "$tmp"; then
        [[ -n "$co_san" ]] && cp "$co_san" "$tmp/anh.tar"
        # Kiểm ngay tại chỗ. Tệp tải về dở dang trông y hệt tệp tải xong, và
        # phát hiện ra điều đó vào hôm cần phục hồi thì đã quá muộn.
        if gzip -t "$tmp/db.sql.gz" 2>/dev/null; then
            rm -rf "${DICH:?}/$ban"
            mv "$tmp" "$DICH/$ban"
            xanh "    xong — $(du -sh "$DICH/$ban" 2>/dev/null | cut -f1)"
            so_moi=$((so_moi+1))
        else
            rm -rf "$tmp"
            vang "    db.sql.gz tải về bị lỗi — bỏ, lần sau kéo lại"
        fi
    else
        rm -rf "$tmp"
        vang "    kéo thất bại — bỏ qua"
    fi
done

# Dọn bớt dưới máy này.
mapfile -t co < <(ls -1d "$DICH"/2*-*/ 2>/dev/null | sort -r || true)
if (( ${#co[@]} > GIU_LAI )); then
    for (( i = GIU_LAI; i < ${#co[@]}; i++ )); do rm -rf "${co[$i]}"; done
    echo
    echo "Đã xoá $(( ${#co[@]} - GIU_LAI )) bản cũ dưới máy này."
fi

echo
xanh "Kéo về $so_moi bản mới. Đang giữ $(ls -1d "$DICH"/2*-*/ 2>/dev/null | wc -l) bản tại $(cd "$DICH" && pwd)"
cat <<'HD'

Trong mỗi thư mục:
    db.sql.gz        database (nạp lại:  gzip -dc db.sql.gz | mysql selliotech)
    anh.tar          ảnh người bán tải lên
    cauhinh.tar.gz   ba tệp .env — CHỨA MẬT KHẨU VÀ KHOÁ CỔNG THANH TOÁN
    THONG-TIN.txt    thời điểm, mã commit, số dòng từng bảng

Chỗ để những tệp này cũng chính là chỗ chứa dữ liệu khách hàng và toàn bộ khoá
bí mật của hệ thống. Đừng để vào thư mục đang đồng bộ lên dịch vụ chia sẻ nào.
HD
