-- Instalasi baru (database kosong)
CREATE DATABASE IF NOT EXISTS absensi_qr CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE absensi_qr;

CREATE TABLE peserta (            -- driver
  id VARCHAR(50) PRIMARY KEY,
  nama VARCHAR(150) NOT NULL,
  aktif TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE antrian_counter (
  tanggal DATE PRIMARY KEY,
  last_no INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE absensi (            -- satu baris = satu nomor antrian
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  peserta_id VARCHAR(50) NOT NULL,
  tanggal DATE NOT NULL,
  no_antrian INT NOT NULL,
  waktu DATETIME NOT NULL,
  id_perjalanan VARCHAR(50) NULL,
  nominal INT NULL,
  diubah_pada DATETIME NULL,
  UNIQUE KEY uq_tgl_no (tanggal, no_antrian),
  UNIQUE KEY uq_perjalanan (id_perjalanan),
  KEY idx_peserta (peserta_id),
  FOREIGN KEY (peserta_id) REFERENCES peserta(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE gagal_pin (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  waktu DATETIME NOT NULL,
  KEY idx_ip_waktu (ip, waktu)
) ENGINE=InnoDB;