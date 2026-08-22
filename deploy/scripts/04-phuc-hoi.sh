#!/usr/bin/env bash
#
# 04 — Phục hồi từ bản sao lưu  (chạy bằng root)
#
#     selliotech-phuc-hoi                       xem có những bản nào
#     selliotech-phuc-hoi 2026-08-11-1500       phục hồi database + ảnh
#     selliotech-phuc-hoi 2026-08-11-1500 --chi-db    chỉ database
#     selliotech-phuc-hoi 2026-08-11-1500 --chi-anh   chỉ ảnh
#     selliotech-phuc-hoi 2026-08-11-1500 --ca-env    kèm ba tệp .env
#
# Một bản sao lưu chưa từng được nạp lại thì chỉ là một tệp nặng. Script này
# tồn tại để lúc thật sự cần, việc phải làm là gõ một dòng lệnh — chứ không
# phải vừa mò cú pháp mysqldump vừa run tay giữa lúc trang web đang chết.
#
# Trước khi ghi đè bất cứ thứ gì, nó chụp lại hiện trạng. Phục hồi nhầm bản là
# chuyện có thật, và khi đó thứ vừa bị ghi đè lại chính là dữ liệu mới nhất.
#
set -euo pipefail

APP_DIR="/var/www/selliotech"
LUU_DIR="/var/backups/selliotech"
DB_NAME="selliotech"
# Control plane — database thứ hai, nằm trong bản sao lưu dưới tên nen-tang.sql.gz.
# Bản sao lưu tạo TRƯỚC khi có control plane thì không có tệp đó; khi ấy script
# để nguyên database này chứ không xoá đi, xem bước 3/5.
DB_NEN_TANG="selliotech_platform"
ANH_DIR="$APP_DIR/web_Shop/storage/app/public"
APP_USER="selliotech"

xanh() { printf '\033[32m%s\033[0m\n' "$*"; }
vang() { printf '\033[33m%s\033[0m\n' "$*"; }
do_()  { printf '\033[1;31m%s\033[0m\n' "$*"; }
buoc() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
chet() { printf '\033[1;31mLỖI: %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || chet "Phải chạy bằng root: sudo $0 $*"

MY=()
MY_ROOT=0
CNF=""
DA_CHAY=0

# Dọn dẹp gói trong HÀM, không viết thẳng vào trap.
#
# `trap '[[ -n "$CNF" ]] && rm -f "$CNF"; exit' EXIT` trông vô hại nhưng sai:
# khi $CNF rỗng, vế `[[ ]] &&` trả về 1, rồi `exit` không tham số lấy đúng mã
# đó — một lượt phục hồi thành công lại kết thúc bằng mã lỗi 1.
don_dep() {
    [[ -n "$CNF" && -f "$CNF" ]] && rm -f "$CNF"
    return 0
}
trap don_dep EXIT

noi_mysql() {
    if mysql -N -B -e 'SELECT 1' >/dev/null 2>&1; then MY=(); MY_ROOT=1; return; fi
    local env="$APP_DIR/api/.env" u p h P
    [[ -r "$env" ]] || chet "root không vào được MySQL mà cũng không đọc được $env"
    u="$(sed -n 's/^DB_USER=//p' "$env" | head -1 | tr -d '\r')"
    p="$(sed -n 's/^DB_PASSWORD=//p' "$env" | head -1 | tr -d '\r')"
    h="$(sed -n 's/^DB_HOST=//p' "$env" | head -1 | tr -d '\r')"
    P="$(sed -n 's/^DB_PORT=//p' "$env" | head -1 | tr -d '\r')"
    CNF="$(mktemp /tmp/selliotech-phuc-hoi-cnf.XXXXXX)"; chmod 600 "$CNF"
    printf '[client]\nuser="%s"\npassword="%s"\nhost=%s\nport=%s\n' "$u" "$p" "${h:-127.0.0.1}" "${P:-3306}" > "$CNF"
    MY=(--defaults-extra-file="$CNF"); MY_ROOT=0
}

BAN="${1:-}"
CHI_DB=0; CHI_ANH=0; CA_ENV=0; KHONG_HOI=0
shift || true
for c in "$@"; do
    case "$c" in
        --chi-db)  CHI_DB=1 ;;
        --chi-anh) CHI_ANH=1 ;;
        --ca-env)  CA_ENV=1 ;;
        --toi-biet-toi-dang-lam-gi) KHONG_HOI=1 ;;
        *) chet "Không hiểu tham số: $c" ;;
    esac
done

# ---------------------------------------------------------------------
# Không đưa tên bản nào thì liệt kê rồi dừng — không đoán hộ.
# ---------------------------------------------------------------------
if [[ -z "$BAN" ]]; then
    echo "Các bản sao lưu đang có:"
    echo
    bash /usr/local/sbin/selliotech-sao-luu liet-ke 2>/dev/null \
        || bash "$APP_DIR/deploy/scripts/03-sao-luu.sh" liet-ke
    cat <<'HD'

Phục hồi:
    sudo selliotech-phuc-hoi <TÊN-BẢN>

Xem trước bản nào chứa gì:
    cat /var/backups/selliotech/<TÊN-BẢN>/THONG-TIN.txt
HD
    exit 0
fi

(( CHI_DB && CHI_ANH )) \
    && chet "--chi-db và --chi-anh loại trừ nhau. Bỏ cả hai nếu muốn phục hồi cả hai."

# Chỉ nhận đúng dạng tên bản. Không có dòng này thì `..` trong tham số đưa
# script ra ngoài thư mục sao lưu, mà đây là công cụ chạy bằng root và có
# quyền xoá cả database.
[[ "$BAN" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{4}$ ]] \
    || chet "Tên bản không đúng dạng (ví dụ đúng: 2026-08-11-1500)."

NGUON="$LUU_DIR/$BAN"
[[ -d "$NGUON" ]] || chet "Không có bản '$BAN'. Chạy không tham số để xem danh sách."

# ---------------------------------------------------------------------
buoc "Bản sắp phục hồi"
# ---------------------------------------------------------------------
sed 's/^/  /' "$NGUON/THONG-TIN.txt" 2>/dev/null || vang "  (không có THONG-TIN.txt)"

# Lược đồ database đi theo mã nguồn. Nạp dữ liệu của ba tháng trước vào bản mã
# hôm nay là cột thiếu, bảng thiếu, app chết ở chỗ khó đoán nhất. Nói trước.
MA_LUU="$(sed -n 's/^ma_nguon *= *//p' "$NGUON/THONG-TIN.txt" 2>/dev/null || true)"
MA_NAY="$(git -C "$APP_DIR" -c safe.directory="$APP_DIR" rev-parse HEAD 2>/dev/null || true)"
if [[ -n "$MA_LUU" && -n "$MA_NAY" && "$MA_LUU" != "$MA_NAY" ]]; then
    echo
    vang "  Mã nguồn lúc sao lưu:  ${MA_LUU:0:12}"
    vang "  Mã nguồn đang chạy:    ${MA_NAY:0:12}"
    vang "  Lược đồ có thể đã đổi. Sau khi phục hồi, chạy lại 02-trien-khai.sh để"
    vang "  công cụ migrate đưa lược đồ về khớp với mã hiện tại."
fi

# ---------------------------------------------------------------------
buoc "Sắp ghi đè những gì"
# ---------------------------------------------------------------------
LAM_DB=1; LAM_ANH=1
(( CHI_DB ))  && LAM_ANH=0
(( CHI_ANH )) && LAM_DB=0
(( LAM_DB ))  && echo "  - database '$DB_NAME'  (toàn bộ dữ liệu hiện tại bị thay thế)"
(( LAM_DB )) && [[ -f "$NGUON/nen-tang.sql.gz" ]] \
              && echo "  - database '$DB_NEN_TANG'  (sổ cái nền tảng, cũng bị thay thế)"
(( LAM_ANH )) && echo "  - $ANH_DIR"
(( CA_ENV ))  && echo "  - api/.env, web_Shop/.env, admin-Selliotech/.env"
echo
echo "  Hiện trạng sẽ được chụp lại trước vào $LUU_DIR/truoc-khi-phuc-hoi-*"

if (( ! KHONG_HOI )); then
    echo
    read -r -p 'Gõ đúng chữ  PHUC HOI  để tiếp tục: ' tra_loi
    [[ "$tra_loi" == "PHUC HOI" ]] || { echo "Đã huỷ, không đụng gì."; exit 0; }
fi

noi_mysql

# ---------------------------------------------------------------------
buoc "1/5  Dừng dịch vụ"
# ---------------------------------------------------------------------
# Nạp database trong lúc API vẫn đang ghi thì cái nhận được là hỗn hợp hai
# thời điểm — thứ tệ hơn cả hai bản gốc.
if systemctl is-active --quiet selliotech-api; then
    systemctl stop selliotech-api
    DA_CHAY=1
    xanh "  đã dừng selliotech-api"
else
    vang "  selliotech-api vốn không chạy"
fi

# Từ đây trở đi, hỏng ở bất kỳ bước nào cũng phải bật lại API. Bỏ script giữa
# chừng mà để dịch vụ nằm im là biến một lần phục hồi trục trặc thành một lần
# sập trang web.
bat_lai() {
    (( DA_CHAY )) || return 0
    DA_CHAY=0
    systemctl start selliotech-api && xanh "  đã bật lại selliotech-api"
    return 0
}
trap 'bat_lai; don_dep' EXIT

# ---------------------------------------------------------------------
buoc "2/5  Chụp lại hiện trạng"
# ---------------------------------------------------------------------
CHUP="$LUU_DIR/truoc-khi-phuc-hoi-$(date '+%Y-%m-%d-%H%M%S')"
mkdir -p "$CHUP"; chmod 700 "$CHUP"
if (( LAM_DB )); then
    mysqldump "${MY[@]}" --single-transaction --quick --no-tablespaces \
        --routines --triggers --events --set-gtid-purged=OFF \
        --default-character-set=utf8mb4 "$DB_NAME" | gzip -6 > "$CHUP/db.sql.gz"
    xanh "  database hiện tại -> $CHUP/db.sql.gz"

    # Chụp cả control plane, nhưng chỉ khi lát nữa thật sự ghi đè lên nó: chụp
    # thừa thì vô hại, còn ghi đè mà không chụp là mất hẳn.
    if [[ -f "$NGUON/nen-tang.sql.gz" ]] && mysql "${MY[@]}" -N -B -e "USE \`$DB_NEN_TANG\`" 2>/dev/null; then
        mysqldump "${MY[@]}" --single-transaction --quick --no-tablespaces \
            --routines --triggers --events --set-gtid-purged=OFF \
            --default-character-set=utf8mb4 "$DB_NEN_TANG" | gzip -6 > "$CHUP/nen-tang.sql.gz"
        xanh "  control plane hiện tại -> $CHUP/nen-tang.sql.gz"
    fi
fi
if (( LAM_ANH )) && [[ -d "$ANH_DIR" ]]; then
    tar -cf "$CHUP/anh.tar" -C "$ANH_DIR" .
    xanh "  ảnh hiện tại -> $CHUP/anh.tar"
fi

# ---------------------------------------------------------------------
buoc "3/5  Nạp database"
# ---------------------------------------------------------------------
if (( LAM_DB )); then
    [[ -f "$NGUON/db.sql.gz" ]] || chet "Bản này không có db.sql.gz"
    gzip -t "$NGUON/db.sql.gz" || chet "db.sql.gz hỏng — đừng nạp, chọn bản khác"

    if (( MY_ROOT )); then
        # Xoá cả database rồi tạo lại, không chỉ dựa vào DROP TABLE trong dump:
        # bảng nào tồn tại hôm nay mà bản sao lưu không có sẽ ở lại lù lù, và
        # kết quả là một database lai giữa hai thời điểm.
        mysql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    else
        # Tài khoản của app không có quyền DROP DATABASE — xoá từng bảng vậy.
        # Tắt kiểm khoá ngoại vì các bảng tham chiếu vòng nhau, xoá theo thứ tự
        # nào cũng vấp.
        mapfile -t bang < <(mysql "${MY[@]}" -N -B -e \
            "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB_NAME'")
        if (( ${#bang[@]} > 0 )); then
            { echo "SET FOREIGN_KEY_CHECKS=0;"
              printf 'DROP TABLE IF EXISTS `%s`;\n' "${bang[@]}"
              echo "SET FOREIGN_KEY_CHECKS=1;"
            } | mysql "${MY[@]}" "$DB_NAME"
        fi
    fi

    gzip -dc "$NGUON/db.sql.gz" | mysql "${MY[@]}" "$DB_NAME"
    SO="$(mysql "${MY[@]}" -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_type='BASE TABLE'")"
    xanh "  đã nạp $SO bảng"

    # Control plane. Bản sao lưu cũ (tạo trước khi có lược đồ này) không có tệp
    # đó — khi ấy ĐỂ NGUYÊN database hiện tại: nó là sổ khách hàng và thuê bao,
    # xoá đi vì "bản sao lưu không nhắc tới" là mất sạch mà chẳng đổi lấy gì.
    if [[ -f "$NGUON/nen-tang.sql.gz" ]]; then
        gzip -t "$NGUON/nen-tang.sql.gz" || chet "nen-tang.sql.gz hỏng — đừng nạp, chọn bản khác"

        if (( MY_ROOT )); then
            mysql -e "DROP DATABASE IF EXISTS \`$DB_NEN_TANG\`; CREATE DATABASE \`$DB_NEN_TANG\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        else
            # Tài khoản của app không có quyền DROP DATABASE. Database phải có
            # sẵn (01-cai-may-chu.sh tạo) — xoá từng bảng như bên trên.
            mysql "${MY[@]}" -N -B -e "USE \`$DB_NEN_TANG\`" 2>/dev/null \
                || chet "chưa có database '$DB_NEN_TANG'. Tạo bằng root rồi chạy lại: CREATE DATABASE \`$DB_NEN_TANG\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
            mapfile -t bang_nt < <(mysql "${MY[@]}" -N -B -e \
                "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB_NEN_TANG'")
            if (( ${#bang_nt[@]} > 0 )); then
                { echo "SET FOREIGN_KEY_CHECKS=0;"
                  printf 'DROP TABLE IF EXISTS `%s`;\n' "${bang_nt[@]}"
                  echo "SET FOREIGN_KEY_CHECKS=1;"
                } | mysql "${MY[@]}" "$DB_NEN_TANG"
            fi
        fi

        gzip -dc "$NGUON/nen-tang.sql.gz" | mysql "${MY[@]}" "$DB_NEN_TANG"
        SO_NT="$(mysql "${MY[@]}" -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NEN_TANG' AND table_type='BASE TABLE'")"
        xanh "  control plane: đã nạp $SO_NT bảng"
    else
        vang "  bản này không có nen-tang.sql.gz (tạo trước khi có control plane) — giữ nguyên '$DB_NEN_TANG'"
    fi
fi

# ---------------------------------------------------------------------
buoc "4/5  Nạp ảnh"
# ---------------------------------------------------------------------
if (( LAM_ANH )); then
    if [[ -f "$NGUON/anh.tar" ]]; then
        # Giải nén ra chỗ mới rồi mới đổi chỗ. Xoá thư mục cũ trước mà tar lỗi
        # giữa chừng thì mất cả hai đằng.
        MOI="$(mktemp -d "${ANH_DIR%/*}/.anh-moi.XXXXXX")"
        tar -xf "$NGUON/anh.tar" -C "$MOI"
        CU="${ANH_DIR}.cu-$(date '+%H%M%S')"
        [[ -d "$ANH_DIR" ]] && mv "$ANH_DIR" "$CU"
        mv "$MOI" "$ANH_DIR"
        # Quyền y như bước 6 của 02-trien-khai.sh: chủ sở hữu là app (PHP ghi),
        # nhóm www-data (nginx đọc thẳng ảnh qua symlink public/storage).
        chown -R "$APP_USER:www-data" "$ANH_DIR"
        chmod -R 775 "$ANH_DIR"
        find "$ANH_DIR" -type d -exec chmod g+s {} +
        xanh "  đã nạp $(find "$ANH_DIR" -type f | wc -l) ảnh (bản cũ để tạm ở $CU)"
    else
        vang "  bản này không có anh.tar — bỏ qua"
    fi
fi

# ---------------------------------------------------------------------
buoc "5/5  Tệp .env"
# ---------------------------------------------------------------------
if (( CA_ENV )); then
    if [[ -f "$NGUON/cauhinh.tar.gz" ]]; then
        for f in api/.env web_Shop/.env admin-Selliotech/.env; do
            [[ -f "$APP_DIR/$f" ]] && cp -p "$APP_DIR/$f" "$CHUP/$(echo "$f" | tr / -)"
        done
        tar -xzf "$NGUON/cauhinh.tar.gz" -C "$APP_DIR"
        chown "$APP_USER:$APP_USER" "$APP_DIR"/{api,web_Shop,admin-Selliotech}/.env 2>/dev/null || true
        chmod 600 "$APP_DIR"/{api,web_Shop,admin-Selliotech}/.env 2>/dev/null || true
        xanh "  đã nạp lại .env (bản cũ nằm trong $CHUP)"
        vang "  Laravel còn cache config cũ — chạy 02-trien-khai.sh để nạp lại."
    else
        vang "  bản này không có cauhinh.tar.gz — bỏ qua"
    fi
fi

bat_lai

printf '\n\033[1;32m===== ĐÃ PHỤC HỒI TỪ %s =====\033[0m\n' "$BAN"
cat <<HD

Hiện trạng ngay trước khi phục hồi nằm ở:
    $CHUP
Phục hồi nhầm bản thì quay lại được từ đó. Kiểm tra xong hãy xoá — nó chứa dữ
liệu khách hàng và không nằm trong vòng xoay tự động.

Việc nên làm ngay:
    1. Mở https://shop.selliotech.store xem dữ liệu có đúng như mong đợi không.
    2. Nếu mã nguồn lệch với bản sao lưu:  sudo bash $APP_DIR/deploy/scripts/02-trien-khai.sh
    3. Xoá thư mục ảnh cũ ${ANH_DIR}.cu-* khi đã yên tâm.
HD
