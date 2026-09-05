<?php

namespace App\Support;

use ZipArchive;

/**
 * Ghi tệp .xlsx THẬT mà không kéo thêm thư viện nào.
 *
 * VÌ SAO KHÔNG DÙNG CSV NHƯ CÁC MÀN KHÁC:
 *
 * Nút trên màn ghi "Xuất Excel" mà tệp tải về là .csv — người nhận bấm đúp thì
 * Excel hỏi lại một lượt về dấu phân cách, số tiền dài bị hiểu thành ngày, và
 * cột nào cũng là chữ. Đủ dùng cho một bảng danh sách, nhưng không đủ cho một
 * chứng từ đưa cho nhà cung cấp.
 *
 * VÌ SAO KHÔNG KÉO PhpSpreadsheet:
 *
 * Thư viện ấy nặng vài chục MB và mang theo cả bộ đọc/ghi cho tám định dạng,
 * trong khi ở đây chỉ cần một sheet, một hàng tiêu đề, mấy hàng số. Một tệp
 * .xlsx tối giản là một tệp zip gồm bốn phần XML — ZipArchive của PHP dựng
 * được, và toàn bộ chuyện đó gói gọn trong lớp này.
 *
 * GIỚI HẠN, nói trước để không ai trông đợi nhầm: một sheet, không định dạng ô
 * ngoài hàng tiêu đề in đậm, không công thức, không gộp ô, không ảnh. Cần hơn
 * thế thì lúc ấy mới đáng kéo thư viện thật vào.
 */
class XlsxDon
{
    /**
     * Dựng nội dung tệp .xlsx từ các hàng.
     *
     * Ô nào là số (int/float) thì ghi kiểu SỐ, còn lại ghi kiểu chuỗi. Nhờ vậy
     * Excel cộng được cột tiền mà không phải "chuyển đổi định dạng" bằng tay —
     * đúng cái CSV không làm được.
     *
     * @param  array<int, array<int, mixed>>  $hang  hàng đầu tiên là tiêu đề
     */
    public static function noiDung(array $hang, string $tenSheet = 'Sheet1'): string
    {
        $tam = tempnam(sys_get_temp_dir(), 'xlsx');

        $zip = new ZipArchive;
        // OVERWRITE: tempnam đã tạo sẵn một tệp rỗng, không có cờ này thì
        // ZipArchive coi đó là zip hỏng và từ chối mở.
        if ($zip->open($tam, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Không mở được tệp tạm để dựng .xlsx.');
        }

        $zip->addFromString('[Content_Types].xml', self::kieuNoiDung());
        $zip->addFromString('_rels/.rels', self::relGoc());
        $zip->addFromString('xl/workbook.xml', self::workbook($tenSheet));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::relWorkbook());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($hang));
        $zip->close();

        $noiDung = (string) file_get_contents($tam);
        @unlink($tam);

        return $noiDung;
    }

    /** Một hàng của sheet. Hàng đầu (index 0) dùng kiểu in đậm. */
    protected static function sheet(array $hang): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>';

        foreach (array_values($hang) as $r => $cot) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach (array_values($cot) as $c => $o) {
                $oRef = self::tenCot($c).($r + 1);
                $dam = $r === 0 ? ' s="1"' : '';

                if (is_int($o) || is_float($o)) {
                    $xml .= '<c r="'.$oRef.'"'.$dam.'><v>'.$o.'</v></c>';

                    continue;
                }

                // t="inlineStr": nhét chữ thẳng vào ô thay vì qua bảng chuỗi dùng
                // chung. Tệp to hơn một chút nhưng bỏ được hẳn một phần XML nữa,
                // và ở cỡ một chứng từ thì chênh lệch ấy không đáng kể.
                $xml .= '<c r="'.$oRef.'" t="inlineStr"'.$dam.'><is><t xml:space="preserve">'
                    .htmlspecialchars((string) $o, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                    .'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    protected static function tenCot(int $i): string
    {
        $ten = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $ten = chr(65 + ($n - 1) % 26).$ten;
        }

        return $ten;
    }

    protected static function kieuNoiDung(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    protected static function relGoc(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    protected static function workbook(string $tenSheet): string
    {
        // Tên sheet của Excel: tối đa 31 ký tự và không nhận : \ / ? * [ ]
        $ten = mb_substr(preg_replace('/[:\\\\\\/?*\[\]]/', '', $tenSheet) ?: 'Sheet1', 0, 31);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.htmlspecialchars($ten, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    protected static function relWorkbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /** Hai kiểu: 0 = thường (Excel bắt buộc phải có), 1 = in đậm cho hàng tiêu đề. */
    protected static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="2"><xf xfId="0"/><xf xfId="0" fontId="1" applyFont="1"/></cellXfs>'
            // cellStyles: thiếu khối này thì bộ đọc nào chặt tay sẽ kêu "workbook
            // contains no default style". Excel bỏ qua được, nhưng tệp rời khỏi
            // phần mềm và ai mở bằng gì thì mình không biết.
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
