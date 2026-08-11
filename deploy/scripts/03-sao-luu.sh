#!/usr/bin/env bash
#
# 03 — Sao lưu tự động  (chạy bằng root; systemd timer gọi mỗi 12 giờ)
#
#     selliotech-sao-luu chay           tạo một bản sao lưu (timer gọi lệnh này)
#     selliotech-sao-luu trang-thai     bản gần nhất lúc nào, đang giữ bao nhiêu bản
#     selliotech-sao-luu liet-ke        danh sách các bản đang giữ
#     selliotech-sao-luu thu-phuc-hoi   nạp thử bản mới nhất vào database tạm rồi xoá
#
# ---------------------------------------------------------------------------
# Chép đúng ba thứ KHÔNG nằm trong git — tức là mất thì không lấy lại được:
#
#   1. database `selliotech`         toàn bộ dữ liệu bán hàng
#   2. admin/storage/app/public      ảnh người bán tải lên
#   3. ba tệp .env                   DB_PASSWORD, JWT_SECRET, APP_KEY, khoá thanh toán
#
# Mã nguồn, cấu hình nginx và systemd đều có trong git nên không chép lại: kéo
# repo về là có. APP_KEY thì khác hẳn — mất nó thì mọi thứ Laravel đã mã hoá
# trong database thành rác, dù database còn nguyên vẹn từng byte.
#
# Không dùng gì ngoài thứ máy chủ đã có sẵn: mysqldump, tar, gzip, systemd.
# Không cần tài khoản đám mây, không cần cài thêm gói, không có khoá mã hoá để
# làm mất — vì một bản sao lưu chỉ mở được bằng khoá đã thất lạc thì cũng bằng
# không có.
# ---------------------------------------------------------------------------
set -euo pipefail

APP_DIR="/var/www/selliotech"
LUU_DIR="/var/backups/selliotech"
DB_NAME="selliotech"
ANH_DIR="$APP_DIR/admin/storage/app/public"

# Giữ bao nhiêu bản. Ba tầng chồng lên nhau, không phải ba lựa chọn:
#   14 bản gần nhất  = 7 ngày đầy đủ, cứu được sai sót vừa gây ra sáng nay
#   30 bản theo ngày = 1 tháng, cứu được sai sót âm thầm cả tuần mới lộ
#   12 bản theo tháng= 1 năm, cứu được "hoá ra bảng này hỏng từ tháng Ba"
# Tầng dưới cùng quan trọng hơn vẻ ngoài của nó: hỏng dữ liệu kiểu âm thầm
# thường chỉ bị phát hiện sau khi mọi bản sao lưu ngắn hạn đã cuốn qua.
GIU_GAN=14
GIU_NGAY=30
GIU_THANG=12

# Chừa lại trên đĩa sau khi sao lưu xong. Sao lưu làm đầy đĩa là tự tay giết
# máy chủ: MySQL không ghi nổi, nginx không ghi nổi log, cả web chết — do đúng
# cái công cụ đáng lẽ để phòng thân.
DU_PHONG_MB=500

xanh() { printf '\033[32m%s\033[0m\n' "$*"; }
vang() { printf '\033[33m%s\033[0m\n' "$*"; }
do_()  { printf '\033[1;31m%s\033[0m\n' "$*"; }
buoc() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
chet() { printf '\033[1;31mLỖI: %s\033[0m\n' "$*" >&2; ghi_that_bai "$*"; exit 1; }

doc()  { numfmt --to=iec --suffix=B "${1:-0}" 2>/dev/null || echo "${1:-0}B"; }

[[ $EUID -eq 0 ]] || { echo "Phải chạy bằng root: sudo $0 $*"; exit 1; }

# ---------------------------------------------------------------------
# Nối MySQL
# ---------------------------------------------------------------------
# Ưu tiên root qua socket: không có mật khẩu nào tồn tại để mà lộ. Chỉ khi
# cách đó không được (ai đó đã đặt mật khẩu cho root) mới lấy tài khoản trong
# api/.env — và khi đó nhét vào tệp option tạm chmod 600, KHÔNG truyền qua
# dòng lệnh, vì tham số dòng lệnh hiện nguyên văn trong `ps aux`.
MY=()
MY_ROOT=0
CNF=""
don_dep() { [[ -n "$CNF" && -f "$CNF" ]] && rm -f "$CNF"; return 0; }
trap don_dep EXIT

noi_mysql() {
    # Gọi lần thứ hai (lượt nạp thử cuối script) thì dùng lại kết nối đã dựng,
    # không tạo thêm tệp option tạm nữa — trap chỉ dọn được tệp cuối cùng.
    [[ -n "${DA_NOI:-}" ]] && return 0
    DA_NOI=1
    if mysql -N -B -e 'SELECT 1' >/dev/null 2>&1; then
        MY=()
        MY_ROOT=1
        return
    fi
    local env="$APP_DIR/api/.env"
    [[ -r "$env" ]] || chet "root không vào được MySQL mà cũng không đọc được $env"
    local u p h P
    u="$(sed -n 's/^DB_USER=//p'     "$env" | head -1 | tr -d '\r')"
    p="$(sed -n 's/^DB_PASSWORD=//p' "$env" | head -1 | tr -d '\r')"
    h="$(sed -n 's/^DB_HOST=//p'     "$env" | head -1 | tr -d '\r')"
    P="$(sed -n 's/^DB_PORT=//p'     "$env" | head -1 | tr -d '\r')"
    [[ -n "$u" && -n "$p" ]] || chet "api/.env thiếu DB_USER hoặc DB_PASSWORD"
    CNF="$(mktemp /tmp/selliotech-sao-luu-cnf.XXXXXX)"
    chmod 600 "$CNF"
    # Bọc nháy kép: mật khẩu có ký tự # thì không bọc sẽ bị hiểu là chú thích.
    printf '[client]\nuser="%s"\npassword="%s"\nhost=%s\nport=%s\n' \
        "$u" "$p" "${h:-127.0.0.1}" "${P:-3306}" > "$CNF"
    MY=(--defaults-extra-file="$CNF")
    MY_ROOT=0
    mysql "${MY[@]}" -N -B -e 'SELECT 1' >/dev/null 2>&1 \
        || chet "tài khoản trong api/.env cũng không nối được MySQL"
}

sql() { mysql "${MY[@]}" -N -B -D "$DB_NAME" -e "$1"; }

# ---------------------------------------------------------------------
# Ghi lại kết quả để lần sau còn biết
# ---------------------------------------------------------------------
# Sao lưu hỏng âm thầm là kiểu hỏng tệ nhất: nó vẫn "đang chạy" trong suốt sáu
# tháng, và người ta chỉ phát hiện đúng vào hôm cần tới nó. Nên mỗi lượt chạy
# đều để lại dấu vết ở ba chỗ: journal (systemd), tệp trạng thái, và bản tin
# hiện ngay khi SSH vào máy (/etc/update-motd.d).
ghi_trang_thai() {
    mkdir -p "$LUU_DIR"
    printf '%s\n' "$1" > "$LUU_DIR/TRANG-THAI.txt"
    chmod 600 "$LUU_DIR/TRANG-THAI.txt"
}
ghi_that_bai() {
    mkdir -p "$LUU_DIR" 2>/dev/null || return 0
    {
        echo "KET_QUA=THAT_BAI"
        echo "LUC=$(date '+%Y-%m-%d %H:%M:%S %z')"
        echo "LY_DO=$1"
    } > "$LUU_DIR/TRANG-THAI.txt" 2>/dev/null || true
    chmod 600 "$LUU_DIR/TRANG-THAI.txt" 2>/dev/null || true
}

# ---------------------------------------------------------------------
# Danh sách bản sao lưu, mới nhất trước
# ---------------------------------------------------------------------
# Lấy dòng đầu bằng `sed -n 1p` chứ không phải `head -1`. `head` đóng ống ngay
# sau dòng đầu, `sort` phía trước nhận SIGPIPE, và `pipefail` biến chuyện đó
# thành lỗi giết cả script. Chừng nào danh sách còn lọt bộ đệm ống 64KB thì
# không thấy gì, nhưng đó đúng là loại lỗi để dành tới lúc đông bản mới bung.
ban_moi_nhat() { cac_ban | sed -n '1p'; }

cac_ban() {
    [[ -d "$LUU_DIR" ]] || return 0
    find "$LUU_DIR" -mindepth 1 -maxdepth 1 -type d \
         -regextype posix-extended -regex '.*/[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{4}$' \
         -printf '%f\n' 2>/dev/null | sort -r
}

# =====================================================================
#  chay — tạo một bản sao lưu
# =====================================================================
lam_sao_luu() {
    exec 9>/var/lock/selliotech-sao-luu.lock
    # Chạy tay đúng lúc timer cũng vừa nổ thì hai lượt giẫm lên nhau: cùng
    # ghi vào một thư mục, cùng xoay vòng, kết quả là một bản dở dang.
    flock -n 9 || { vang "Đang có lượt sao lưu khác chạy — bỏ qua lượt này."; exit 0; }

    local TS BAN TAM
    TS="$(date '+%Y-%m-%d-%H%M')"
    BAN="$LUU_DIR/$TS"
    # Dựng trong thư mục tên khác rồi mới đổi tên. Mất điện giữa chừng thì thứ
    # còn lại là ".dang-tao-..." — nhìn là biết dở dang, không ai nhầm nó với
    # một bản sao lưu dùng được. Bản dùng được chỉ xuất hiện sau khi đã kiểm.
    TAM="$LUU_DIR/.dang-tao-$TS"

    mkdir -p "$LUU_DIR"
    # Dump chứa mật khẩu băm và thông tin cá nhân khách hàng; .env chứa khoá
    # cổng thanh toán. Cả thư mục chỉ root đọc được.
    chown root:root "$LUU_DIR"
    chmod 700 "$LUU_DIR"
    rm -rf "$TAM"
    mkdir -p "$TAM"

    noi_mysql

    local BAN_TRUOC=""
    BAN_TRUOC="$(ban_moi_nhat)"

    # -----------------------------------------------------------------
    buoc "1/6  Chỗ trống trên đĩa"
    # -----------------------------------------------------------------
    local CO_DB CO_ANH CAN CON
    CO_DB="$(sql "SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema='$DB_NAME'" || echo 0)"
    CO_ANH=0
    [[ -d "$ANH_DIR" ]] && CO_ANH="$(du -sb "$ANH_DIR" 2>/dev/null | cut -f1)"
    # Cần chỗ cho: bản dump (rộng tay tính bằng cỡ database, dù nén xong nhỏ
    # hơn nhiều), bản tar ảnh, cộng phần dự phòng để máy chủ còn thở.
    CAN=$(( CO_DB + CO_ANH + DU_PHONG_MB * 1024 * 1024 ))
    CON="$(df -B1 --output=avail "$LUU_DIR" | tail -1 | tr -d ' ')"

    # Dọn theo hai nấc. Nấc một chỉ bỏ những bản đã quá hạn giữ — vô hại. Chỉ
    # khi vẫn thiếu mới sang nấc hai, vốn cắt sạch cả bản lưu theo tháng, nên
    # không dùng tới thì đừng động.
    if (( CON < CAN )); then
        vang "  còn $(doc "$CON"), cần $(doc "$CAN") — dọn bản quá hạn trước"
        xoay_vong
        CON="$(df -B1 --output=avail "$LUU_DIR" | tail -1 | tr -d ' ')"
    fi
    if (( CON < CAN )); then
        vang "  vẫn thiếu — dọn mạnh tay, chỉ chừa 3 bản gần nhất"
        xoay_vong "giu-nguyen-ban-moi-nhat"
        CON="$(df -B1 --output=avail "$LUU_DIR" | tail -1 | tr -d ' ')"
    fi
    if (( CON < CAN )); then
        rm -rf "$TAM"
        # Cố ý KHÔNG xoá sạch bản cũ để lấy chỗ. Bản cũ là thứ duy nhất đang
        # bảo vệ dữ liệu; đổi nó lấy một bản mới chưa chắc tạo nổi là lỗ vốn.
        chet "Không đủ chỗ: còn $(doc "$CON"), cần $(doc "$CAN"). Bản sao lưu cũ được giữ nguyên."
    fi
    xanh "  còn $(doc "$CON") — đủ"

    # -----------------------------------------------------------------
    buoc "2/6  Database"
    # -----------------------------------------------------------------
    # --single-transaction: chụp ảnh nhất quán mà KHÔNG khoá bảng, nên khách
    #   vẫn đặt hàng bình thường trong lúc dump. Bỏ nó đi thì hoặc là khoá cả
    #   database vài chục giây, hoặc là bản dump lẫn lộn dữ liệu hai thời điểm
    #   — đơn hàng có mà chi tiết đơn không.
    # --no-tablespaces: MySQL 8 đòi quyền PROCESS toàn cục nếu thiếu cờ này,
    #   mà tài khoản `selliotech` cố ý chỉ có quyền trên đúng database của nó.
    # KHÔNG dùng --databases: dump không kèm CREATE DATABASE thì nạp vào tên
    #   database nào cũng được — chính là thứ cần cho việc nạp thử ở dưới.
    mysqldump "${MY[@]}" \
        --single-transaction --quick --no-tablespaces \
        --routines --triggers --events \
        --set-gtid-purged=OFF \
        --default-character-set=utf8mb4 \
        "$DB_NAME" | gzip -6 > "$TAM/db.sql.gz"

    local SO_BANG_THAT SO_BANG_DUMP CO_DUMP
    SO_BANG_THAT="$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_type='BASE TABLE'")"

    # Ba lớp kiểm, mỗi lớp bắt một kiểu hỏng khác nhau:
    gzip -t "$TAM/db.sql.gz" 2>/dev/null \
        || { rm -rf "$TAM"; chet "db.sql.gz hỏng (gzip -t không qua)"; }

    # mysqldump chỉ viết dòng "Dump completed" khi đã chạy trót lọt tới cuối.
    # Thiếu nó = đứt giữa chừng: tệp vẫn có, vẫn giải nén được, vẫn trông như
    # một bản dump bình thường, nhưng thiếu vài bảng cuối bảng chữ cái.
    # So khớp bằng khớp chuỗi thay vì `| grep -q`: grep -q thoát ngay khi thấy,
    # `tail` phía trước ăn SIGPIPE, và pipefail biến đó thành lỗi giả.
    [[ "$(gzip -dc "$TAM/db.sql.gz" | tail -3)" == *'-- Dump completed'* ]] \
        || { rm -rf "$TAM"; chet "db.sql.gz đứt giữa chừng (thiếu dòng 'Dump completed')"; }

    SO_BANG_DUMP="$(gzip -dc "$TAM/db.sql.gz" | grep -c '^-- Table structure for table' || true)"
    (( SO_BANG_DUMP == SO_BANG_THAT )) \
        || { rm -rf "$TAM"; chet "dump có $SO_BANG_DUMP bảng nhưng database có $SO_BANG_THAT bảng"; }

    CO_DUMP="$(stat -c %s "$TAM/db.sql.gz")"
    xanh "  $SO_BANG_THAT bảng, $(doc "$CO_DUMP") (đã nén)"

    # Teo đột ngột so với bản trước là dấu hiệu có người vừa xoá nhầm dữ liệu.
    # Vẫn giữ bản này — dữ liệu ít đi có thể là thật — nhưng phải kêu lên, vì
    # nếu im lặng thì vài vòng xoay nữa là mọi bản còn dữ liệu đều bị cuốn đi.
    if [[ -n "$BAN_TRUOC" && -f "$LUU_DIR/$BAN_TRUOC/db.sql.gz" ]]; then
        local CO_TRUOC
        CO_TRUOC="$(stat -c %s "$LUU_DIR/$BAN_TRUOC/db.sql.gz")"
        if (( CO_TRUOC > 0 && CO_DUMP * 10 < CO_TRUOC * 6 )); then
            do_ "  CẢNH BÁO: bản này chỉ bằng $(( CO_DUMP * 100 / CO_TRUOC ))% bản trước ($(doc "$CO_TRUOC"))."
            do_ "            Có ai vừa xoá dữ liệu không? Kiểm tra TRƯỚC khi các bản cũ bị xoay vòng."
        fi
    fi

    # -----------------------------------------------------------------
    buoc "3/6  Ảnh người bán tải lên"
    # -----------------------------------------------------------------
    if [[ -d "$ANH_DIR" ]]; then
        local VAN_TAY SO_ANH
        # Ảnh gần như không đổi giữa hai lượt, mà chép lại cả kho mỗi 12 giờ
        # thì một năm là 730 bản y hệt nhau. Lấy dấu vân tay (tên + cỡ + giờ
        # sửa của mọi tệp); giống hệt bản trước thì tạo HARDLINK — bản mới vẫn
        # có đủ ảnh, nhưng không tốn thêm byte nào trên đĩa. Xoay vòng xoá bản
        # cũ cũng không sao: dữ liệu chỉ mất khi liên kết cuối cùng bị xoá.
        VAN_TAY="$(find "$ANH_DIR" -type f -printf '%P\t%s\t%T@\n' 2>/dev/null | sort | md5sum | cut -d' ' -f1)"
        SO_ANH="$(find "$ANH_DIR" -type f 2>/dev/null | wc -l)"

        if [[ -n "$BAN_TRUOC" \
              && -f "$LUU_DIR/$BAN_TRUOC/anh.tar" \
              && -f "$LUU_DIR/$BAN_TRUOC/anh.van-tay" \
              && "$VAN_TAY" == "$(cat "$LUU_DIR/$BAN_TRUOC/anh.van-tay")" ]]; then
            ln "$LUU_DIR/$BAN_TRUOC/anh.tar" "$TAM/anh.tar"
            xanh "  $SO_ANH ảnh, không đổi từ $BAN_TRUOC — dùng chung, không tốn thêm đĩa"
        else
            # Không nén: ảnh jpg/webp/png/avif đã nén sẵn, gzip chỉ tốn CPU
            # đổi lấy vài phần trăm.
            tar -cf "$TAM/anh.tar" -C "$ANH_DIR" . 2>/dev/null
            tar -tf "$TAM/anh.tar" >/dev/null 2>&1 \
                || { rm -rf "$TAM"; chet "anh.tar hỏng"; }
            xanh "  $SO_ANH ảnh, $(doc "$(stat -c %s "$TAM/anh.tar")")"
        fi
        printf '%s\n' "$VAN_TAY" > "$TAM/anh.van-tay"
        printf '%s\n' "$SO_ANH"  > "$TAM/anh.so-luong"
    else
        vang "  chưa có $ANH_DIR — bỏ qua"
    fi

    # -----------------------------------------------------------------
    buoc "4/6  Ba tệp .env"
    # -----------------------------------------------------------------
    # Nhỏ xíu nhưng là phần không thay lại được. DB_PASSWORD đặt lại được,
    # JWT_SECRET sinh lại được (chỉ tốn việc mọi người đăng nhập lại), nhưng
    # APP_KEY thì không: Laravel dùng nó mã hoá dữ liệu nằm trong database.
    local CO_ENV=0
    local -a ENVS=()
    for f in api/.env admin/.env saas/.env; do
        [[ -f "$APP_DIR/$f" ]] && { ENVS+=("$f"); CO_ENV=$((CO_ENV+1)); }
    done
    if (( CO_ENV > 0 )); then
        tar -czf "$TAM/cauhinh.tar.gz" -C "$APP_DIR" "${ENVS[@]}"
        chmod 600 "$TAM/cauhinh.tar.gz"
        xanh "  $CO_ENV tệp .env"
    else
        vang "  không thấy tệp .env nào ở $APP_DIR"
    fi

    # -----------------------------------------------------------------
    buoc "5/6  Ghi chú kèm theo"
    # -----------------------------------------------------------------
    # Quan trọng nhất trong tệp này là mã commit. Nạp lại một database của ba
    # tháng trước vào mã nguồn hôm nay là lược đồ lệch nhau — phải biết bản
    # dữ liệu đó khớp với đoạn mã nào thì mới lần ngược được.
    {
        echo "Sao lưu Selliotech"
        echo "thoi_gian    = $(date '+%Y-%m-%d %H:%M:%S %z')"
        echo "may_chu      = $(hostname)"
        echo "ma_nguon     = $(git -C "$APP_DIR" -c safe.directory="$APP_DIR" rev-parse HEAD 2>/dev/null || echo 'khong-ro')"
        echo "mysql        = $(mysql "${MY[@]}" -N -B -e 'SELECT VERSION()' 2>/dev/null || echo '?')"
        echo "so_bang      = $SO_BANG_THAT"
        echo "co_database  = $(doc "$CO_DB")"
        echo
        echo "So dong trong cac bang chinh:"
        for b in users products product_variants orders order_items payments product_images; do
            printf '  %-20s %s\n' "$b" "$(sql "SELECT COUNT(*) FROM \`$b\`" 2>/dev/null || echo '-')"
        done
    } > "$TAM/THONG-TIN.txt"
    sed 's/^/  /' "$TAM/THONG-TIN.txt"

    # Chỉ tới đây, khi mọi thứ đã kiểm xong, bản sao lưu mới được mang tên thật.
    chmod -R go-rwx "$TAM"
    # Tên bản chỉ tính tới PHÚT, nên chạy tay ngay sau một lượt của timer là
    # trùng tên. `mv thư-mục thư-mục-đã-có` không thay thế mà nhét cái mới vào
    # bên trong cái cũ — bản vừa dựng biến mất khỏi danh sách, bản cũ ở lại
    # nguyên chỗ, và không có lấy một dòng báo lỗi. Xoá trước cho dứt khoát:
    # bản mới đã qua đủ ba lớp kiểm, thay bản cùng phút là đúng ý.
    [[ -e "$BAN" ]] && rm -rf "$BAN"
    mv "$TAM" "$BAN"

    # -----------------------------------------------------------------
    buoc "6/6  Xoay vòng bản cũ"
    # -----------------------------------------------------------------
    xoay_vong

    local TONG SO_BAN
    TONG="$(du -sb "$LUU_DIR" 2>/dev/null | cut -f1)"
    SO_BAN="$(cac_ban | wc -l)"
    ghi_trang_thai "$(cat <<EOF
KET_QUA=THANH_CONG
LUC=$(date '+%Y-%m-%d %H:%M:%S %z')
BAN=$TS
SO_BANG=$SO_BANG_THAT
CO_DUMP=$(doc "$CO_DUMP")
SO_BAN_DANG_GIU=$SO_BAN
TONG_DUNG_LUONG=$(doc "$TONG")
EOF
)"

    printf '\n\033[1;32m===== ĐÃ SAO LƯU: %s =====\033[0m\n' "$TS"
    echo "  $LUU_DIR/$TS  —  đang giữ $SO_BAN bản, tổng $(doc "$TONG")"

    # Mỗi tuần một lần nạp thử vào database tạm. Đây là khác biệt giữa "có tệp
    # sao lưu" và "phục hồi được": một bản dump qua hết các lớp kiểm ở trên vẫn
    # có thể chết lúc nạp (sai bộ ký tự, khoá ngoại vòng tròn, câu lệnh cụt).
    # Biết điều đó vào thứ Ba thì còn sửa được; biết vào hôm cần phục hồi thì
    # đã muộn.
    local TUAN
    TUAN="$(date '+%G-%V')"
    if [[ "$(cat "$LUU_DIR/.tuan-da-thu" 2>/dev/null || true)" != "$TUAN" ]]; then
        if thu_phuc_hoi "$TS"; then
            printf '%s\n' "$TUAN" > "$LUU_DIR/.tuan-da-thu"
        else
            do_ "NẠP THỬ THẤT BẠI — bản sao lưu có thể không phục hồi được. Xem ở trên."
            # Ghi đè trạng thái THÀNH CÔNG vừa ghi ở trên. Tệp đã tạo xong thật,
            # nhưng "có tệp" không phải điều cần biết — điều cần biết là nạp lại
            # được hay không. Để dòng xanh trên màn hình đăng nhập trong tình
            # huống này là nói dối đúng chỗ nguy hiểm nhất.
            ghi_that_bai "bản $TS tạo xong nhưng NẠP THỬ THẤT BẠI"
            exit 1
        fi
    fi
}

# =====================================================================
#  Xoay vòng
# =====================================================================
xoay_vong() {
    local chi_giu_moi_nhat="${1:-}"
    local -a tatca
    mapfile -t tatca < <(cac_ban)
    (( ${#tatca[@]} == 0 )) && return 0

    declare -A GIU=() da_ngay=() da_thang=()
    local i d ng th

    if [[ "$chi_giu_moi_nhat" == "giu-nguyen-ban-moi-nhat" ]]; then
        # Chỉ dùng khi đang cạn đĩa: vứt bớt cho đủ chỗ, nhưng luôn chừa lại
        # ba bản gần nhất — dọn dẹp mà thành ra không còn gì để phục hồi thì
        # chính việc dọn dẹp là tai nạn.
        for i in 0 1 2; do
            [[ -n "${tatca[$i]:-}" ]] && GIU[${tatca[$i]}]=1
        done
    else
        for i in "${!tatca[@]}"; do
            (( i < GIU_GAN )) && GIU[${tatca[$i]}]=1
        done
        # Danh sách đã sắp giảm dần, nên bản đầu tiên gặp của mỗi ngày / mỗi
        # tháng chính là bản mới nhất của ngày / tháng đó.
        for d in "${tatca[@]}"; do
            ng="${d:0:10}"; th="${d:0:7}"
            if [[ -z "${da_ngay[$ng]:-}" ]]; then
                da_ngay[$ng]=1
                (( ${#da_ngay[@]} <= GIU_NGAY )) && GIU[$d]=1
            fi
            if [[ -z "${da_thang[$th]:-}" ]]; then
                da_thang[$th]=1
                (( ${#da_thang[@]} <= GIU_THANG )) && GIU[$d]=1
            fi
        done
    fi

    local so_xoa=0
    for d in "${tatca[@]}"; do
        [[ -n "${GIU[$d]:-}" ]] && continue
        rm -rf "${LUU_DIR:?}/$d"
        so_xoa=$((so_xoa+1))
    done
    # Thư mục dở dang của lần chạy đứt gánh trước đó.
    find "$LUU_DIR" -maxdepth 1 -type d -name '.dang-tao-*' -mmin +720 -exec rm -rf {} + 2>/dev/null || true

    if (( so_xoa > 0 )); then
        xanh "  xoá $so_xoa bản cũ, còn $(cac_ban | wc -l) bản"
    else
        xanh "  không có bản nào tới hạn, đang giữ $(cac_ban | wc -l) bản"
    fi
}

# =====================================================================
#  Nạp thử vào database tạm — kiểm chứng "phục hồi được", không phải "có tệp"
# =====================================================================
thu_phuc_hoi() {
    local BAN="${1:-}"
    [[ -n "$BAN" ]] || BAN="$(ban_moi_nhat)"
    [[ -n "$BAN" ]] || { do_ "Chưa có bản sao lưu nào để thử."; return 1; }
    local F="$LUU_DIR/$BAN/db.sql.gz"
    [[ -f "$F" ]] || { do_ "Không thấy $F"; return 1; }

    noi_mysql
    if (( MY_ROOT == 0 )); then
        vang "  Bỏ qua nạp thử: cần quyền tạo database, mà đang dùng tài khoản riêng của app."
        return 0
    fi

    buoc "Nạp thử $BAN vào database tạm"
    local THU="${DB_NAME}_thu_phuc_hoi"
    local CON CAN
    # `|| echo 0` chứ không để trần: MySQL có thể để dữ liệu ở chỗ khác, và khi
    # đó df thất bại. Coi như không đủ chỗ rồi bỏ qua lượt nạp thử — thà bỏ qua
    # còn hơn để cả lượt sao lưu chết vì một phép đo phụ.
    CON="$(df -B1 --output=avail /var/lib/mysql 2>/dev/null | tail -1 | tr -d ' ' || echo 0)"
    CAN="$(sql "SELECT COALESCE(SUM(data_length+index_length),0)*2 FROM information_schema.tables WHERE table_schema='$DB_NAME'" || echo 0)"
    CON="${CON:-0}"
    if (( CON < CAN + DU_PHONG_MB * 1024 * 1024 )); then
        vang "  Bỏ qua: /var/lib/mysql còn $(doc "$CON"), cần $(doc "$CAN")."
        return 0
    fi

    mysql -e "DROP DATABASE IF EXISTS \`$THU\`; CREATE DATABASE \`$THU\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    local ok=0
    if gzip -dc "$F" | mysql "$THU" 2>&1; then
        local a b
        a="$(mysql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$THU' AND table_type='BASE TABLE'")"
        b="$(mysql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_type='BASE TABLE'")"
        if [[ "$a" == "$b" ]]; then
            local u
            u="$(mysql -N -B -D "$THU" -e 'SELECT COUNT(*) FROM users' 2>/dev/null || echo '?')"
            xanh "  nạp lại được: $a bảng, $u người dùng — bản sao lưu này dùng thật được"
            ok=1
        else
            do_ "  nạp xong nhưng chỉ có $a/$b bảng"
        fi
    else
        do_ "  nạp thất bại"
    fi
    # Dọn ngay: để lại một database tên na ná bên cạnh database thật là mời
    # gọi tai nạn, chỉ cần một lần gõ nhầm tên.
    mysql -e "DROP DATABASE IF EXISTS \`$THU\`;"
    (( ok == 1 ))
}

# =====================================================================
#  trang-thai / liet-ke
# =====================================================================
xem_trang_thai() {
    if [[ ! -f "$LUU_DIR/TRANG-THAI.txt" ]]; then
        do_ "CHƯA CÓ BẢN SAO LƯU NÀO."
        echo "Chạy thử một lượt: sudo selliotech-sao-luu chay"
        return 1
    fi
    local ket_qua lan_cuoi tuoi
    ket_qua="$(sed -n 's/^KET_QUA=//p' "$LUU_DIR/TRANG-THAI.txt")"
    lan_cuoi="$(stat -c %Y "$LUU_DIR/TRANG-THAI.txt")"
    tuoi=$(( ( $(date +%s) - lan_cuoi ) / 3600 ))

    sed 's/^/  /' "$LUU_DIR/TRANG-THAI.txt"
    echo
    if [[ "$ket_qua" == "THANH_CONG" ]] && (( tuoi < 26 )); then
        xanh "  Lượt gần nhất: THÀNH CÔNG, cách đây $tuoi giờ."
    elif [[ "$ket_qua" == "THANH_CONG" ]]; then
        do_ "  Lượt gần nhất thành công nhưng đã $tuoi GIỜ TRƯỚC (đáng lẽ mỗi 12 giờ)."
        echo "  Kiểm tra: systemctl list-timers selliotech-sao-luu.timer"
    else
        do_ "  Lượt gần nhất THẤT BẠI, cách đây $tuoi giờ."
        echo "  Xem chi tiết: journalctl -u selliotech-sao-luu -n 50 --no-pager"
    fi
    echo
    systemctl list-timers selliotech-sao-luu.timer --no-pager 2>/dev/null | head -3 || true
}

xem_danh_sach() {
    local n=0 d
    printf '%-18s %10s %10s %8s  %s\n' "BẢN" "DATABASE" "ẢNH" "BẢNG" "MÃ NGUỒN"
    while read -r d; do
        [[ -z "$d" ]] && continue
        local cdb canh sob mn
        cdb="$([[ -f "$LUU_DIR/$d/db.sql.gz" ]] && doc "$(stat -c %s "$LUU_DIR/$d/db.sql.gz")" || echo '-')"
        canh="$([[ -f "$LUU_DIR/$d/anh.tar" ]] && doc "$(stat -c %s "$LUU_DIR/$d/anh.tar")" || echo '-')"
        sob="$(sed -n 's/^so_bang *= *//p' "$LUU_DIR/$d/THONG-TIN.txt" 2>/dev/null || echo '-')"
        mn="$(sed -n 's/^ma_nguon *= *//p' "$LUU_DIR/$d/THONG-TIN.txt" 2>/dev/null | cut -c1-8)"
        printf '%-18s %10s %10s %8s  %s\n' "$d" "$cdb" "$canh" "${sob:--}" "${mn:--}"
        n=$((n+1))
    done < <(cac_ban)
    echo
    echo "$n bản, tổng $(doc "$(du -sb "$LUU_DIR" 2>/dev/null | cut -f1)") tại $LUU_DIR"
    echo "(Cột ẢNH trùng dung lượng nhau là do dùng chung một tệp — không nhân đôi trên đĩa.)"
}

# =====================================================================
case "${1:-chay}" in
    chay)          lam_sao_luu ;;
    trang-thai)    xem_trang_thai ;;
    liet-ke)       xem_danh_sach ;;
    thu-phuc-hoi)  thu_phuc_hoi "${2:-}" ;;
    *)
        sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'
        exit 1
        ;;
esac
