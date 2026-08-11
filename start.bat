@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul
cd /d "%~dp0"
set "ROOT=%~dp0"
set "SELF=%~f0"

REM  File nay dong 2 vai:
REM    - Khong tham so  -> cua so TONG: kiem tra moi thu, mo cac cua so con,
REM                        roi hien bang dieu khien tu lam moi.
REM    - --service <ten> -> cua so RIENG cua 1 service (api / admin / saas).
REM    --status         -> chi mo bang dieu khien, khong kiem tra, khong mo gi.
REM  Gop lam mot de chi phai phat hanh 1 file, va de cua so con luon dung
REM  cung logic voi cua so tong.
if /i "%~1"=="--service" goto :SERVICE

title Selliotech - Bang dieu khien
if /i "%~1"=="--status" goto :DASHBOARD

echo ============================================================
echo    SELLIOTECH - Khoi dong du an
echo ============================================================

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
call :CHECK_PORT 3306
if defined ST_PID (
    echo     - MySQL da chay, pid !ST_PID!. Bo qua.
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
    echo     Lam theo dong "Cach chua" o tren roi mo lai start.bat.
    echo     Thuong la: cd api ^&^& go run ./cmd/migrate chay
    pause
    exit /b 1
)
echo     - Luoc do database: OK

REM ============================================================
REM  3) Mo cua so rieng cho tung service
REM ============================================================
echo.
echo [3] Mo cua so rieng cho tung service...
call :LAUNCH api
call :LAUNCH admin
call :LAUNCH saas
echo     - Cho cac service len...
timeout /t 4 /nobreak >nul

goto :DASHBOARD


REM ============================================================
REM  BANG DIEU KHIEN (cua so tong)
REM ============================================================
:DASHBOARD
cls
echo ============================================================
echo    SELLIOTECH - Bang dieu khien
echo ============================================================
echo.
echo    Trang thai cac service:
echo.
call :ROW 3306 "MySQL (XAMPP)        " "localhost:3306        "
call :ROW 8080 "API (Go + Gin)       " "http://localhost:8080 "
call :ROW 8001 "Shop Admin (ban hang)" "http://localhost:8001 "
call :ROW 8002 "SaaS Admin (nen tang)" "http://localhost:8002 "
echo.
echo ------------------------------------------------------------
echo    Swagger UI  : http://localhost:8080/swagger/index.html
echo    phpMyAdmin  : http://localhost/phpmyadmin  (database: selliotech)
echo    Landing     : mo truc tiep tep landing\index.html
echo.
echo    Tai khoan quan tri: admin@selliotech.local / Admin@123
echo ------------------------------------------------------------
echo.
echo    [R] Lam moi (tu dong sau 5 giay)
echo    [O] Mo trinh duyet (Shop Admin + SaaS Admin)
echo    [K] Khoi dong lai cac service dang tat
echo    [S] Dung tat ca (tru MySQL)
echo    [Q] Thoat cua so nay, giu service chay
echo.
choice /c ROKSQ /n /t 5 /d R >nul
if errorlevel 5 goto :END
if errorlevel 4 goto :STOPALL
if errorlevel 3 goto :STARTMISSING
if errorlevel 2 goto :OPENBROWSER
goto :DASHBOARD

:OPENBROWSER
start "" http://localhost:8001
start "" http://localhost:8002
goto :DASHBOARD

:STARTMISSING
echo.
echo    Dang mo lai cac service dang tat...
call :LAUNCH api
call :LAUNCH admin
call :LAUNCH saas
timeout /t 4 /nobreak >nul
goto :DASHBOARD

:STOPALL
echo.
echo    Dang dung cac service...
REM  Dong ca cua so con (/t de keo theo go/php ben trong), sau do quet lai theo
REM  cong phong truong hop cua so bi doi ten hoac service duoc chay tay.
for %%S in (api admin saas) do taskkill /f /t /fi "WINDOWTITLE eq Selliotech - %%S" >nul 2>&1
for %%P in (8080 8001 8002) do (
    call :CHECK_PORT %%P
    if defined ST_PID taskkill /f /t /pid !ST_PID! >nul 2>&1
)
timeout /t 2 /nobreak >nul
echo    Xong. MySQL van duoc giu nguyen.
timeout /t 2 /nobreak >nul
goto :DASHBOARD

:END
echo.
echo Cac service van chay trong cua so rieng cua chung.
echo Mo lai start.bat bat cu luc nao de xem bang dieu khien.
timeout /t 3 /nobreak >nul
endlocal
exit /b 0


REM ============================================================
REM  HAM DUNG CHUNG
REM ============================================================

REM  Tim PID dang LISTENING tren 1 cong. Tra ve qua bien ST_PID (rong = khong chay).
:CHECK_PORT
set "ST_PID="
for /f "tokens=5" %%P in ('netstat -ano ^| findstr /r /c:":%~1 " ^| findstr "LISTENING"') do (
    if not defined ST_PID set "ST_PID=%%P"
)
exit /b 0

REM  In 1 dong trang thai:  %1=cong  %2=ten  %3=dia chi
REM  Khong dung khoi if (...) o day: ten service co dau ngoac, khi bung bien
REM  trong khoi thi dau ')' se dong khoi som va cmd bao loi cu phap.
:ROW
call :CHECK_PORT %~1
set "R_MARK=[   TAT   ]"
set "R_PID="
if defined ST_PID set "R_MARK=[DANG CHAY]"
if defined ST_PID set "R_PID=   pid !ST_PID!"
echo      !R_MARK!  %~2  %~3!R_PID!
exit /b 0

REM  Mo cua so rieng cho 1 service, bo qua neu cong da co nguoi giu.
:LAUNCH
set "L_NAME=%~1"
if /i "%L_NAME%"=="api"   set "L_PORT=8080"
if /i "%L_NAME%"=="admin" set "L_PORT=8001"
if /i "%L_NAME%"=="saas"  set "L_PORT=8002"
call :CHECK_PORT !L_PORT!
if defined ST_PID (
    echo     - %L_NAME%: cong !L_PORT! da co tien trinh !ST_PID!. Khong mo them.
    exit /b 0
)
start "Selliotech - %L_NAME%" cmd /k call "%SELF%" --service %L_NAME%
echo     - %L_NAME%: da mo cua so rieng (cong !L_PORT!).
exit /b 0


REM ============================================================
REM  CUA SO RIENG CUA 1 SERVICE
REM ============================================================
:SERVICE
set "SVC=%~2"

if /i "%SVC%"=="api" (
    set "SVC_DIR=api"
    set "SVC_DESC=API - Go + Gin"
    set "SVC_URL=http://localhost:8080"
    set "SVC_RUN=go run ./cmd/api"
)
if /i "%SVC%"=="admin" (
    set "SVC_DIR=admin"
    set "SVC_DESC=Shop Admin - Laravel, khu ban hang"
    set "SVC_URL=http://localhost:8001"
    set "SVC_RUN=php artisan serve --port=8001"
)
if /i "%SVC%"=="saas" (
    set "SVC_DIR=saas"
    set "SVC_DESC=SaaS Admin - Laravel, dieu hanh nen tang"
    set "SVC_URL=http://localhost:8002"
    set "SVC_RUN=php artisan serve --port=8002"
)
if not defined SVC_DIR (
    echo Khong biet service "%SVC%". Chi nhan: api, admin, saas.
    pause
    exit /b 1
)

title Selliotech - %SVC%
cd /d "%ROOT%!SVC_DIR!"

:SERVICE_RUN
cls
echo ============================================================
echo    !SVC_DESC!
echo    Dia chi : !SVC_URL!
echo    Thu muc : %CD%
echo    Lenh    : !SVC_RUN!
echo ============================================================
echo.
!SVC_RUN!
set "RC=!errorlevel!"
echo.
echo ------------------------------------------------------------
echo    Tien trinh da dung (ma thoat !RC!).
echo ------------------------------------------------------------
choice /c RT /n /m "   [R] Chay lai   [T] Dong cua so : "
if errorlevel 2 exit /b !RC!
goto :SERVICE_RUN
