{{-- Một dòng nói thẳng: khối Thu/Chi KHÔNG theo bộ lọc "Nguồn đơn".

     Vì sao không lọc luôn cho khớp: phiếu thu chi là sổ của cả quầy, phần lớn
     phiếu chẳng dính đơn nào (tiền thuê mặt bằng, lương, trả nhà cung cấp). Lọc
     theo nguồn đơn thì những phiếu ấy biến mất sạch, và "Tổng thu chi" thôi
     không còn là sổ quỹ nữa — sai nặng hơn cái nó sửa.

     Chỉ hiện khi ĐANG lọc nguồn: không lọc thì chẳng có gì để hiểu nhầm, in
     thêm một dòng chữ chỉ là nhiễu. --}}
@if (($channel ?? '') !== '')
    <p class="th-ngoai-loc">Không theo bộ lọc Nguồn đơn — sổ thu chi ghi chung cho cả quầy.</p>
@endif
