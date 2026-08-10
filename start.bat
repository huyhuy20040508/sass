@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul
title Selliotech - Start All
cd /d "%~dp0"

echo ============================================
echo    SELLIOTECH - Khoi dong du an
echo ============================================

set "LOI=0"

REM ============================================================
REM  0) Kiem tra cong cu can co
REM ============================================================
echo.
echo [0] Kiem tra cong cu...
where go >nul 2>&1
if errorlevel 1 (
    echo     [LOI] Khong tim thay 'go' trong PATH. Cai Go 1.25+ roi mo lai cua so nay.
    set "LOI=1"
) else (
    echo     - Go: OK
)
where php >nul 2>&1
if errorlevel 1 (
    echo     [LOI] Khong tim thay 'php' trong PATH. Them C:\xampp\php vao PATH.
    set "LOI=1"
) else (
    echo     - PHP: OK
)
if "%LOI%"=="1" (
    echo.
    echo Thieu cong cu o tren, dung lai.
    pause
    exit /b 1
)

REM ============================================================
REM  1) MySQL (XAMPP) - chi khoi dong neu chua chay (cong 3306)
REM ============================================================
echo.
echo [1] Kiem tra MySQL (cong 3306)...
netstat -ano | findstr ":3306" | findstr "LISTENING" >nul
if %errorlevel%==0 (
    echo     - MySQL da chay. Bo qua.
) else (
    if exist "C:\xampp\mysql_start.bat" (
        echo     - MySQL chua chay. Dang khoi dong XAMPP MySQL...
        start "MySQL (XAMPP)" /min C:\xampp\mysql_start.bat
        echo     - Cho MySQL san sang...
        timeout /t 6 /nobreak >nul
    ) else (
        echo     [CANH BAO] Khong tim thay C:\xampp\mysql_start.bat
        echo                Hay mo XAMPP Control Panel va bat MySQL thu cong.
    )
)

REM ============================================================
REM  2) Kiem tra cau hinh + luoc do database
REM ============================================================
echo.
echo [2] Kiem tra cau hinh...
if not exist "api\.env" (
    echo     [LOI] Chua co api\.env
    echo           Chay: copy api\.env.example api\.env  roi sua DB_* va JWT_SECRET
    pause
    exit /b 1
)
if not exist "admin\.env" (
    echo     [LOI] Chua co admin\.env
    echo           Chay: copy admin\.env.example admin\.env  roi: php artisan key:generate
    pause
    exit /b 1
)
if not exist "admin\vendor" (
    echo     [LOI] Chua co admin\vendor. Chay: cd admin ^&^& composer install
    pause
    exit /b 1
)
if not exist "saas\.env" (
    echo     [LOI] Chua co saas\.env
    echo           Chay: copy saas\.env.example saas\.env  roi: php artisan key:generate
    pause
    exit /b 1
)
if not exist "saas\vendor" (
    echo     [LOI] Chua co saas\vendor. Chay: cd saas ^&^& composer install
    pause
    exit /b 1
)
echo     - api\.env, admin\.env, saas\.env, vendor: OK

REM  Thu muc runtime cua Laravel. Git KHONG luu duoc thu muc rong, ma thieu
REM  storage\framework\sessions la moi request tra 500 ngay tu trang dang nhap
REM  (file_put_contents ... No such file or directory). Tao lai o day de ban
REM  clone ve chay duoc luon, khong phai di doc stack trace.
for %%D in (
    "admin\storage\app\private"
    "admin\storage\app\public"
    "admin\storage\framework\cache\data"
    "admin\storage\framework\sessions"
    "admin\storage\framework\views"
    "admin\storage\logs"
    "admin\bootstrap\cache"
    "saas\storage\app\private"
    "saas\storage\app\public"
    "saas\storage\framework\cache\data"
    "saas\storage\framework\sessions"
    "saas\storage\framework\views"
    "saas\storage\logs"
    "saas\bootstrap\cache"
) do if not exist "%%~D" mkdir "%%~D"
echo     - Thu muc runtime cua Laravel: OK

REM  Lech migration thi migrate thoat voi ma loi -> chan luon, dung de API chay
REM  tren luoc do thieu bang roi bao loi kho hieu o tan trang quan tri.
echo     - Doi chieu migration voi database...
pushd api
go run ./cmd/migrate >nul 2>&1
set "MIG=!errorlevel!"
popd
if not "!MIG!"=="0" (
    echo.
    echo     [CANH BAO] Database chua khop voi database\migrations. Chi tiet:
    echo.
    pushd api
    go run ./cmd/migrate
    popd
    echo.
    echo     Chay `cd api ^&^& go run ./cmd/migrate chay` de cap nhat, roi mo lai start.bat
    pause
    exit /b 1
)
echo     - Luoc do database: OK

REM ============================================================
REM  3) api  (Go + Gin)  ->  http://localhost:8080
REM ============================================================
echo.
echo [3] Khoi dong api...
netstat -ano | findstr ":8080" | findstr "LISTENING" >nul
if %errorlevel%==0 (
    echo     - Cong 8080 da co thu gi do chay. Bo qua, khong mo them.
) else (
    start "selliotech-api" cmd /k "cd /d api && go run ./cmd/api"
    echo     - Da mo cua so 'selliotech-api'.
)

REM ============================================================
REM  4) admin (Laravel)  ->  http://localhost:8001   [Shop Admin]
REM ============================================================
echo.
echo [4] Khoi dong admin (khu ban hang)...
netstat -ano | findstr ":8001" | findstr "LISTENING" >nul
if %errorlevel%==0 (
    echo     - Cong 8001 da co thu gi do chay. Bo qua, khong mo them.
) else (
    start "selliotech-admin" cmd /k "cd /d admin && php artisan serve --port=8001"
    echo     - Da mo cua so 'selliotech-admin'.
)

REM ============================================================
REM  5) saas (Laravel)  ->  http://localhost:8002   [SaaS Admin]
REM ============================================================
echo.
echo [5] Khoi dong saas (khu dieu hanh nen tang)...
netstat -ano | findstr ":8002" | findstr "LISTENING" >nul
if %errorlevel%==0 (
    echo     - Cong 8002 da co thu gi do chay. Bo qua, khong mo them.
) else (
    start "selliotech-saas" cmd /k "cd /d saas && php artisan serve --port=8002"
    echo     - Da mo cua so 'selliotech-saas'.
)

echo.
echo ============================================
echo    Cac dia chi:
echo      Shop Admin  : http://localhost:8001   (quan ly ban hang)
echo      SaaS Admin  : http://localhost:8002   (dieu hanh nen tang)
echo      API         : http://localhost:8080
echo      Swagger UI  : http://localhost:8080/swagger/index.html
echo      phpMyAdmin  : http://localhost/phpmyadmin  (database: selliotech)
echo ============================================
echo.
echo Tai khoan quan tri: admin@selliotech.local / Admin@123
echo.
echo Moi service chay trong 1 cua so rieng. Dong cua so de tat service do.
echo.
pause
endlocal
