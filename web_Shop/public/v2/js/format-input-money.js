$(document).ready(function () {
    function formatMoneyInput(amount, options) {
        options = options || {};

        amount = String(amount || '').replace(/\D/g, '');

        if (amount.startsWith('0') && amount.length > 1) {
            amount = amount.replace(/^0+/, '');
        }
    
        // Nếu rỗng thì đặt lại thành '0'
        if (amount === '') {
            amount = '0';
        }

        if (options.maxDigits != null) {
            if (amount.length > options.maxDigits) {
                amount = amount.slice(0, options.maxDigits);
            }
        } else {
            const maxAmount = options.maxAmount != null ? options.maxAmount : 9999999999;
            if (parseInt(amount, 10) > maxAmount) {
                amount = String(maxAmount);
            }
        }

        const separator = options.separator != null ? options.separator : ',';
        return amount.replace(/\B(?=(\d{3})+(?!\d))/g, separator);
    }

    function getFormatMoneyInputOptions(el) {
        var $el = $(el);
        var opts = {};
        var maxDigits = $el.attr('data-max-digits');
        var maxAmount = $el.attr('data-max-amount');
        var separator = $el.attr('data-separator');

        if (maxDigits != null && maxDigits !== '') {
            opts.maxDigits = parseInt(maxDigits, 10);
        }
        if (maxAmount != null && maxAmount !== '') {
            opts.maxAmount = parseInt(maxAmount, 10);
        }
        if (separator != null && separator !== '') {
            opts.separator = separator;
        }

        return opts;
    }

    $(document).on('input', '.format-money', function () {
        let formattedValue = formatMoneyInput($(this).val(), getFormatMoneyInputOptions(this));
        $(this).val(formattedValue);
    });      $(document).on('input', '.format-money-2', function () {
        let formattedValue = formatMoneyInput2($(this).val(), $(this).attr('data-max-decimals'));
        $(this).val(formattedValue);
    });

    // Trả về số thực nếu chuỗi dán vào là số CÓ PHẦN THẬP PHÂN ("196,363.344", "196363,34"),
    // ngược lại trả null để giữ hành vi cũ (dán số nguyên / "196.363" kiểu VN = 196363).
    function parsePastedDecimal(text) {
        var match = String(text || '').replace(/\s/g, '').match(/\d[\d.,]*/);
        if (!match) return null;
        var raw = match[0];
        var sepIndex = Math.max(raw.lastIndexOf('.'), raw.lastIndexOf(','));
        if (sepIndex === -1) return null;
        var sepChar = raw[sepIndex];
        var after = raw.slice(sepIndex + 1);
        var hasBothSeparators = raw.indexOf('.') !== -1 && raw.indexOf(',') !== -1;
        var sepCount = raw.split(sepChar).length - 1;
        var isDecimal;
        if (hasBothSeparators) {
            isDecimal = true; // dấu đứng sau cùng là dấu thập phân, dấu kia là ngăn nghìn
        } else if (sepCount > 1) {
            isDecimal = false; // 1.234.567 / 1,234,567 -> toàn ngăn nghìn
        } else {
            // 1 dấu duy nhất + đúng 3 số phía sau ("196.363") coi là ngăn nghìn (thói quen VN);
            // khác 3 số ("196.34", "196.3446") chắc chắn là thập phân
            isDecimal = after.length !== 3;
        }
        if (!isDecimal) return null;
        var num = parseFloat(raw.slice(0, sepIndex).replace(/[.,]/g, '') + '.' + after);
        return isNaN(num) ? null : num;
    }

    function getPastedText(e) {
        var clipboard = (e.originalEvent && e.originalEvent.clipboardData) || window.clipboardData;
        return clipboard ? clipboard.getData('text') : null;
    }

    $(document).on('paste', '.format-money', function (e) {
        var num = parsePastedDecimal(getPastedText(e));
        if (num === null) return;
        e.preventDefault();
        var formattedValue = formatMoneyInput(String(Math.round(num)), getFormatMoneyInputOptions(this));
        $(this).val(formattedValue).trigger('input');
    });

    $(document).on('paste', '.format-money-2', function (e) {
        var num = parsePastedDecimal(getPastedText(e));
        if (num === null) return;
        e.preventDefault();
        var maxDecimals = parseInt($(this).attr('data-max-decimals'), 10);
        var value = isNaN(maxDecimals) ? String(num) : String(parseFloat(num.toFixed(maxDecimals)));
        var formattedValue = formatMoneyInput2(value, $(this).attr('data-max-decimals'));
        $(this).val(formattedValue).trigger('input');
    });
    function formatQuantityInput(amount) {
        // Loại bỏ ký tự không phải số
        amount = amount.replace(/\D/g, '');
    
        // Xoá số 0 ở đầu nếu có nhiều hơn 1 chữ số
        if (amount.startsWith('0') && amount.length > 1) {
            amount = amount.replace(/^0+/, '');
        }
    
        // Nếu rỗng thì đặt lại thành '0'
        if (amount === '') {
            amount = '0';
        }
    
        // Giới hạn tối đa 9999
        const maxQuantity = 9999999999;
        if (parseInt(amount) > maxQuantity) {
            amount = maxQuantity.toString();
        }
    
        return amount;
    }
    function formatMoneyInput2(value, maxDecimals) {
        // Chỉ giữ số và dấu chấm
        value = value.replace(/[^0-9.]/g, '');

        // Chỉ giữ 1 dấu chấm duy nhất (dấu chấm đầu tiên), bỏ những dấu chấm dư
        const parts = value.split('.');
        let integerPart = parts[0];
        let decimalPart = parts.length > 1 ? parts.slice(1).join('') : undefined;

        // data-max-decimals="2" -> chặn gõ quá số chữ số thập phân cho phép
        const decimalLimit = parseInt(maxDecimals, 10);
        if (!isNaN(decimalLimit) && decimalPart !== undefined && decimalPart.length > decimalLimit) {
            decimalPart = decimalPart.slice(0, decimalLimit);
        }

        // Xoá số 0 ở đầu nếu > 1 chữ số
        if (integerPart.length > 1) {
            integerPart = integerPart.replace(/^0+/, '');
            if (integerPart === '') integerPart = '0';
        }

        // Giới hạn phần nguyên chỉ khi không có phần thập phân
        const maxInteger = 9999999999;
        let numericInteger = parseInt(integerPart || '0', 10);

        if (!isNaN(numericInteger)) {
            if (numericInteger > maxInteger) {
                numericInteger = maxInteger;
                integerPart = numericInteger.toString();
            } else {
                integerPart = numericInteger.toString(); // đảm bảo không chứa dấu cách, etc.
            }
        }

        // Định dạng phần nguyên có dấu phẩy
        let formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        // Nếu có phần thập phân, gộp lại
        if (value.includes('.')) {
            return `${formattedInteger}.${decimalPart ?? ''}`;
        } else {
            return formattedInteger;
        }
    }



    function formatQuantityInputMin1(amount) {
        // Loại bỏ ký tự không phải số
        amount = amount.replace(/\D/g, '');
    
        // Xoá số 0 ở đầu nếu có nhiều hơn 1 chữ số
        if (amount.startsWith('0') && amount.length > 1) {
            amount = amount.replace(/^0+/, '');
        }
    
        // Nếu rỗng thì đặt lại thành '0'
        if (amount === '') {
            amount = '1';
        }
        // Giới hạn tối đa 9999
        const maxQuantity = 9999999999;
        if (parseInt(amount) > maxQuantity) {
            amount = maxQuantity.toString();
        }
        if(amount < 1){
            amount = 1;
        }
        return amount;
    }
    $(document).on('input', '.format-quantity-min-1', function () {
        let formattedValue = formatQuantityInputMin1($(this).val());
        $(this).val(formattedValue);
    });
    $(document).on('input', '.format-quantity', function () {
        let formattedValue = formatQuantityInput($(this).val());
        $(this).val(formattedValue);
    });
    

    $('.scroll-delta-y').on('wheel', function (e) {
        // Danh mục đang mở rộng (wrap nhiều hàng) thì không còn cuộn ngang — trả wheel cho trang
        if ($(this).hasClass('categories-expanded')) return;
        e.preventDefault();
        let scrollAmount = e.originalEvent.deltaY * 2; 
        $(this).stop().animate(
            { scrollLeft: `+=${scrollAmount}` }, 
            200, 
            'swing'
        );
    });
});
