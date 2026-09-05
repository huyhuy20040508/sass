const currentPath = window.location.pathname;
const loginPaths = ['/login', '/m/login'];


let startY = 0;
let isPulling = false;
let pulledDistance = 0;
const threshold = 100;
const refreshEl = document.getElementById("pullToRefresh");

let currentScrollEl = null;

const ignoreClasses = ['modal', 'body-collapse-menu-for-mobile', 'offcanvas', 'index-finish-product-page', 'content', 'list-menu', 'product-cotainer'];

function isPullRefreshBlocked(el) {
    if (currentPath.includes('/system/history')) {
        return true;
    }
    const productModal = document.getElementById('productDetailModal');
    if (productModal && productModal.classList.contains('show')) {
        return true;
    }
    if (!el) return false;
    // Đang kéo thả (jQuery UI sortable tự gắn class "ui-sortable" vào phần tử chứa khi khởi tạo,
    // vd. bảng danh sách món #sortableTable, các nhóm thuộc tính trong modal) - không cho pull-to-refresh
    // ăn lẫn với thao tác kéo thả, tránh bị load lại trang giữa chừng khi đang kéo.
    if (el.closest('.ui-sortable')) {
        return true;
    }
    return !!el.closest('.modal.show');
}

function resetPullToRefresh() {
    isPulling = false;
    pulledDistance = 0;
    currentScrollEl = null;
    document.body.style.overscrollBehavior = 'auto';

    if (!refreshEl) return;

    refreshEl.classList.remove('refreshing');
    refreshEl.style.top = '-60px';

    const icon = refreshEl.querySelector('.icon');
    if (icon) {
        icon.style.transform = 'rotate(0deg)';
    }
}

// Hàm xác định phần tử cuộn gần nhất
function getScrollableAncestor(el) {
    while (el && el !== document.body) {
        const overflowY = window.getComputedStyle(el).overflowY;
        if (overflowY === "auto" || overflowY === "scroll") {
            return el;
        }
        el = el.parentElement;
    }
    return window;
}

// Kiểm tra phần tử cuộn đang ở đầu
function isAtTop() {
    // console.log('currentScrollEl==>', currentScrollEl);

    // if (!currentScrollEl) return false;

    const classNames = currentScrollEl?.className || '';

    const avc = ignoreClasses?.filter(item => {
        return classNames.includes(item);
    });

    if (avc.length) return false;

    // if (window.scrollY === 0) {
    //     return window.scrollY === 0;
    // } else {
    //     return false;
    // }

    if (!currentScrollEl) return false;

    // scrollTop với window xử lý khác
    if (currentScrollEl === window) {
        return window.scrollY === 0;
    } else {
        return currentScrollEl.scrollTop === 0;
    }
}

window.addEventListener("touchstart", function (e) {
    if (isPullRefreshBlocked(e.target)) {
        resetPullToRefresh();
        return;
    }

    currentScrollEl = getScrollableAncestor(e.target);

    if (isAtTop()) {
        startY = e.touches[0].clientY;
        isPulling = true;

        document.body.style.overscrollBehavior = 'none';
    } else {
        isPulling = false;
    }
});

window.addEventListener("touchmove", function (e) {
    if (!isPulling || isPullRefreshBlocked(e.target)) {
        if (isPullRefreshBlocked(e.target)) {
            resetPullToRefresh();
        }
        return;
    }

    const currentY = e.touches[0].clientY;
    pulledDistance = currentY - startY;

    if (pulledDistance > 0 && isAtTop()) {
        // Ngăn cuộn mặc định để hiển thị hiệu ứng kéo
        e.preventDefault();
        const top = Math.min(pulledDistance, 100) - 60;
        if (refreshEl) { 
            refreshEl.style.top = top + "px";
            refreshEl.querySelector(
                ".icon"
            ).style.transform = `rotate(${pulledDistance}deg)`;
        }
    }
});

window.addEventListener("touchend", function () {
    if (!isPulling) return;

    isPulling = false;

    if (pulledDistance > threshold && isAtTop() && !isPullRefreshBlocked()) {
        // Bắt đầu hiệu ứng refresh
        if (refreshEl) {
            refreshEl.classList.add("refreshing");
            refreshEl.style.top = "10px";
        }
        setTimeout(() => {
            location.reload();
        }, 500);
    } else {
        // Reset lại trạng thái
        if (refreshEl) {
            refreshEl.style.top = "-60px";
            refreshEl.querySelector(".icon").style.transform = "rotate(0deg)";
        }
        
    }

    document.body.style.overscrollBehavior = 'auto';

    pulledDistance = 0;
    currentScrollEl = null;
});

// window.addEventListener('pageshow', () => {
//     if (loginPaths.includes(currentPath)) {
//         checkLoginScreen();
//     }
// });

function checkLoginScreen() {
    const device = getMobileOperatingSystem();
    const isMobile = device !== 'Desktop Browser';
    const targetPath = isMobile ? '/m/login' : '/login';
    
    if (currentPath !== targetPath) {
        window.location.href = targetPath;
    }
}

function getMobileOperatingSystem() {
    const userAgent = navigator.userAgent || navigator.vendor || window.opera;
    const isAndroid = /android/i.test(userAgent);
    const isIOS = /iPad|iPhone|iPod/.test(userAgent) && !window.MSStream;
    const isWindowsPhone = /windows phone/i.test(userAgent);
    // const isAndroidWebView = (isAndroid && /\bwv\b/.test(userAgent)) || (/Version\/[\d.]+/.test(userAgent) && !/Chrome/.test(userAgent));
    const isAndroidWebView = isAndroid && (/\bwv\b/.test(userAgent) || (/Version\/[\d.]+/.test(userAgent) && !/Chrome/.test(userAgent)));
    const isIOSWebView = isIOS && !userAgent.includes("Safari");

    if (isWindowsPhone) return "Windows Phone";
    if (isAndroidWebView) return "Android WebView";
    if (isIOSWebView) return "iOS WebView";
    if (isAndroid) return "Android Browser";
    if (isIOS) return "iOS Browser";
    
    return "Desktop Browser";
}

