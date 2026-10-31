<?php
// Ubah PIN dan QR_SECRET sebelum dipakai. Jangan diubah lagi setelah QR dicetak.
const ADMIN_PIN   = 'AdminGojo8888';
const PETUGAS_PIN = 'DispatcherGojo8888';
const QR_SECRET   = 'ganti-dengan-teks-acak-yang-panjang';

// true = tampilkan pesan error database asli (untuk uji coba). Ubah ke false saat dipakai sungguhan.
const DEBUG = false;

date_default_timezone_set('Asia/Jakarta');

$pdo = new PDO('mysql:host=localhost;dbname=absensi_qr;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
