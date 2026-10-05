-- =====================================================================
--  0073_ghi-chu-dong-ca-2026-09-29.sql
-- =====================================================================
--  note        — ghi chú lúc MỞ ca (giữ tên cũ)
--  close_note  — ghi chú lúc ĐÓNG ca
--  Ca đã đóng trước tệp này: note có thể đang là ghi chú đóng ca, không
--  tách ngược được — để nguyên.
-- =====================================================================

ALTER TABLE work_shifts
    ADD COLUMN close_note VARCHAR(500) NULL COMMENT 'ghi chú lúc đóng ca' AFTER note;
