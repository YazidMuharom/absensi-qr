-- Aman dijalankan berulang kali. Menyamakan database lama dengan versi terbaru (XAMPP/MariaDB).
USE absensi_qr;

ALTER TABLE peserta ADD COLUMN IF NOT EXISTS aktif TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE absensi ADD INDEX IF NOT EXISTS idx_peserta (peserta_id);
ALTER TABLE absensi
  DROP INDEX IF EXISTS uq_peserta_tanggal,
  ADD COLUMN IF NOT EXISTS id_perjalanan VARCHAR(50) NULL,
  ADD COLUMN IF NOT EXISTS nominal INT NULL,
  ADD COLUMN IF NOT EXISTS diubah_pada DATETIME NULL,
  ADD UNIQUE KEY IF NOT EXISTS uq_tgl_no (tanggal, no_antrian),
  ADD UNIQUE KEY IF NOT EXISTS uq_perjalanan (id_perjalanan);
