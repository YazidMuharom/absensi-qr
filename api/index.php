<?php
require __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function sign($id) { return substr(hash_hmac('sha256', $id, QR_SECRET), 0, 16); }
function pinKirim() { return $_SERVER['HTTP_X_PIN'] ?? ''; }
function isAdmin() { return hash_equals(ADMIN_PIN, pinKirim()); }
function butuhPin($petugasBoleh = false) {
    if (!isAdmin() && !($petugasBoleh && hash_equals(PETUGAS_PIN, pinKirim()))) out(['error' => 'PIN salah'], 401);
}
function rapi($rows) {
    foreach ($rows as &$x) {
        if (isset($x['id'])) $x['id'] = (int)$x['id'];
        if (isset($x['no_antrian'])) $x['no_antrian'] = (int)$x['no_antrian'];
        if (array_key_exists('nominal', $x)) $x['nominal'] = $x['nominal'] === null ? null : (int)$x['nominal'];
        if (isset($x['waktu'])) $x['waktu'] = date('c', strtotime($x['waktu']));
    }
    return $rows;
}

function cekTrip($trip, $nom) { // ID perjalanan dan nominal: isi keduanya, atau kosongkan keduanya
    $trip = trim((string)$trip); $nom = trim((string)$nom);
    if ($trip === '' && $nom === '') return [null, null];
    if ($trip === '' || strlen($trip) > 50) out(['error' => 'ID perjalanan wajib diisi (maks. 50 karakter)'], 400);
    if (!ctype_digit($nom) || (int)$nom > 100000000) out(['error' => 'Nominal wajib berupa angka bulat, tanpa titik atau koma'], 400);
    return [$trip, (int)$nom];
}

$r = $_GET['r'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$tgl = date('Y-m-d');

try {
    switch ($r) {
        case 'peserta': // driver: daftar, tambah, ubah, hapus
            butuhPin();
            $m = $_SERVER['REQUEST_METHOD'];
            $id = trim($body['id'] ?? '');
            $nama = trim($body['nama'] ?? '');
            $punyaRiwayat = function ($pid) use ($pdo) {
                $c = $pdo->prepare('SELECT COUNT(*) FROM absensi WHERE peserta_id=?'); $c->execute([$pid]);
                return $c->fetchColumn() > 0;
            };
            if ($m === 'POST') {
                if ($id === '' || $nama === '') out(['error' => 'ID dan nama wajib diisi'], 400);
                try {
                    $pdo->prepare('INSERT INTO peserta(id,nama) VALUES(?,?)')->execute([$id, $nama]);
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') out(['error' => 'ID sudah terdaftar'], 409);
                    throw $e;
                }
                out(['ok' => true]);
            }
            if ($m === 'PUT') {
                $baru = trim($body['id_baru'] ?? $id);
                if ($id === '' || $baru === '' || $nama === '') out(['error' => 'ID dan nama wajib diisi'], 400);
                if ($baru !== $id && $punyaRiwayat($id))
                    out(['error' => 'ID tidak bisa diubah karena driver sudah punya riwayat antrian. Ubah nama saja.'], 409);
                try {
                    $pdo->prepare('UPDATE peserta SET id=?, nama=? WHERE id=? AND aktif=1')->execute([$baru, $nama, $id]);
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') out(['error' => 'ID sudah dipakai driver lain'], 409);
                    throw $e;
                }
                out(['ok' => true]);
            }
            if ($m === 'DELETE') {
                if ($id === '') out(['error' => 'ID wajib diisi'], 400);
                if ($punyaRiwayat($id)) { // riwayat perjalanan dan pembayaran jangan sampai hilang
                    $pdo->prepare('UPDATE peserta SET aktif=0 WHERE id=?')->execute([$id]);
                    out(['ok' => true, 'nonaktif' => true]);
                }
                $pdo->prepare('DELETE FROM peserta WHERE id=?')->execute([$id]);
                out(['ok' => true, 'nonaktif' => false]);
            }
            $rows = $pdo->query('SELECT id, nama FROM peserta WHERE aktif=1 ORDER BY id')->fetchAll();
            foreach ($rows as &$p) $p['kode'] = $p['id'] . '.' . sign($p['id']);
            out($rows);

        case 'scan':
            butuhPin(true);
            $parts = explode('.', (string)($body['kode'] ?? ''));
            $id = $parts[0] ?? ''; $sig = $parts[1] ?? '';
            if ($id === '' || !hash_equals(sign($id), $sig)) out(['error' => 'QR tidak valid'], 400);

            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT nama, aktif FROM peserta WHERE id=? FOR UPDATE'); // kunci baris driver
            $st->execute([$id]);
            $drv = $st->fetch();
            if ($drv === false) { $pdo->rollBack(); out(['error' => 'Driver tidak ditemukan'], 404); }
            if (!$drv['aktif']) { $pdo->rollBack(); out(['error' => 'Driver sudah tidak aktif'], 403); }
            $nama = $drv['nama'];

            // Masih ada antrian hari ini yang belum diisi perjalanan? Pakai nomor itu lagi.
            $cek = $pdo->prepare('SELECT id, no_antrian FROM absensi
                                  WHERE peserta_id=? AND tanggal=? AND id_perjalanan IS NULL ORDER BY id DESC LIMIT 1');
            $cek->execute([$id, $tgl]);
            if ($row = $cek->fetch()) {
                $pdo->commit();
                out(['absen_id' => (int)$row['id'], 'id' => $id, 'nama' => $nama, 'no' => (int)$row['no_antrian'], 'sudah' => true]);
            }
            // Nomor baru: penaikan counter atomik
            $pdo->prepare('INSERT INTO antrian_counter(tanggal,last_no) VALUES(?, LAST_INSERT_ID(1))
                           ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)')->execute([$tgl]);
            $no = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
            $pdo->prepare('INSERT INTO absensi(peserta_id,tanggal,no_antrian,waktu) VALUES(?,?,?,?)')
                ->execute([$id, $tgl, $no, date('Y-m-d H:i:s')]);
            $absenId = (int)$pdo->lastInsertId();
            $pdo->commit();
            out(['absen_id' => $absenId, 'id' => $id, 'nama' => $nama, 'no' => $no, 'sudah' => false]);

        case 'absensi': // admin: tambah, ubah, hapus data antrian
            butuhPin();
            $m = $_SERVER['REQUEST_METHOD'];
            $aid = (int)($body['id'] ?? 0);
            if ($m === 'DELETE') {
                $d = $pdo->prepare('DELETE FROM absensi WHERE id=?'); $d->execute([$aid]);
                if (!$d->rowCount()) out(['error' => 'Data tidak ditemukan'], 404);
                out(['ok' => true]);
            }
            if ($m !== 'POST' && $m !== 'PUT') out(['error' => 'Metode tidak didukung'], 405);
            $pid = trim($body['peserta_id'] ?? '');
            [$trip, $nom] = cekTrip($body['id_perjalanan'] ?? '', $body['nominal'] ?? '');
            $st = $pdo->prepare('SELECT aktif FROM peserta WHERE id=?'); $st->execute([$pid]);
            $aktif = $st->fetchColumn();
            if ($aktif === false) out(['error' => 'ID driver tidak ditemukan'], 404);
            try {
                if ($m === 'POST') {
                    $t = (string)($body['tanggal'] ?? '');
                    $p = explode('-', $t);
                    if (count($p) !== 3 || !checkdate((int)$p[1], (int)$p[2], (int)$p[0])) out(['error' => 'Tanggal tidak valid'], 400);
                    if (!$aktif) out(['error' => 'Driver sudah tidak aktif'], 403);
                    $pdo->beginTransaction();
                    $pdo->prepare('INSERT INTO antrian_counter(tanggal,last_no) VALUES(?, LAST_INSERT_ID(1))
                                   ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)')->execute([$t]);
                    $no = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
                    $pdo->prepare('INSERT INTO absensi(peserta_id,tanggal,no_antrian,waktu,id_perjalanan,nominal,diubah_pada) VALUES(?,?,?,?,?,?,?)')
                        ->execute([$pid, $t, $no, $t . ' ' . date('H:i:s'), $trip, $nom, date('Y-m-d H:i:s')]);
                    $pdo->commit();
                    out(['ok' => true, 'no' => $no]);
                }
                $c = $pdo->prepare('SELECT peserta_id FROM absensi WHERE id=?'); $c->execute([$aid]);
                $lama = $c->fetchColumn();
                if ($lama === false) out(['error' => 'Data tidak ditemukan'], 404);
                if (!$aktif && $pid !== $lama) out(['error' => 'Driver sudah tidak aktif'], 403);
                $pdo->prepare('UPDATE absensi SET peserta_id=?, id_perjalanan=?, nominal=?, diubah_pada=? WHERE id=?')
                    ->execute([$pid, $trip, $nom, date('Y-m-d H:i:s'), $aid]);
                out(['ok' => true]);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e->getCode() === '23000') out(['error' => 'ID perjalanan "' . $trip . '" sudah dipakai'], 409);
                throw $e;
            }

        case 'antrian': // daftar antrian hari ini untuk dispatcher
            butuhPin(true);
            $st = $pdo->prepare('SELECT a.id, a.no_antrian, p.id AS driver_id, p.nama, a.id_perjalanan, a.nominal, a.waktu
                                 FROM absensi a JOIN peserta p ON p.id=a.peserta_id
                                 WHERE a.tanggal=? ORDER BY a.no_antrian DESC');
            $st->execute([$tgl]);
            out(rapi($st->fetchAll()));

        case 'perjalanan': // isi / ubah ID perjalanan dan nominal
            butuhPin(true);
            $aid = (int)($body['absen_id'] ?? 0);
            $trip = trim($body['id_perjalanan'] ?? '');
            $nom = $body['nominal'] ?? null;
            if ($trip === '' || strlen($trip) > 50) out(['error' => 'ID perjalanan wajib diisi (maks. 50 karakter)'], 400);
            if (!is_numeric($nom) || $nom < 0 || $nom > 100000000 || floor($nom) != $nom) out(['error' => 'Nominal harus berupa angka bulat, tanpa titik atau koma'], 400);
            $st = $pdo->prepare('SELECT tanggal FROM absensi WHERE id=?'); $st->execute([$aid]);
            $tglData = $st->fetchColumn();
            if ($tglData === false) out(['error' => 'Data antrian tidak ditemukan'], 404);
            if (!isAdmin() && $tglData !== $tgl) out(['error' => 'Petugas hanya bisa mengubah data hari ini. Hubungi admin.'], 403);
            try {
                $pdo->prepare('UPDATE absensi SET id_perjalanan=?, nominal=?, diubah_pada=? WHERE id=?')
                    ->execute([$trip, (int)$nom, date('Y-m-d H:i:s'), $aid]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') out(['error' => 'ID perjalanan "' . $trip . '" sudah dipakai'], 409);
                throw $e;
            }
            out(['ok' => true]);

        case 'rekap': // admin, rentang tanggal
            butuhPin();
            $re = '/^\d{4}-\d{2}-\d{2}$/';
            $dari = preg_match($re, $_GET['dari'] ?? '') ? $_GET['dari'] : $tgl;
            $sampai = preg_match($re, $_GET['sampai'] ?? '') ? $_GET['sampai'] : $dari;
            if ($sampai < $dari) [$dari, $sampai] = [$sampai, $dari];
            $st = $pdo->prepare('SELECT a.id, a.tanggal, a.no_antrian, p.id AS driver_id, p.nama, a.id_perjalanan, a.nominal, a.waktu
                                 FROM absensi a JOIN peserta p ON p.id=a.peserta_id
                                 WHERE a.tanggal BETWEEN ? AND ? ORDER BY a.tanggal, a.no_antrian');
            $st->execute([$dari, $sampai]);
            out(rapi($st->fetchAll()));

        case 'tampil': // layar umum, tanpa PIN
            $st = $pdo->prepare('SELECT a.no_antrian, p.nama FROM absensi a
                                 JOIN peserta p ON p.id=a.peserta_id WHERE a.tanggal=?
                                 ORDER BY a.no_antrian DESC LIMIT 5');
            $st->execute([$tgl]);
            out(rapi($st->fetchAll()));

        default:
            out(['error' => 'Endpoint tidak dikenal'], 404);
    }
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    out(['error' => 'Kesalahan database. ' . (defined('DEBUG') && DEBUG ? $e->getMessage() : 'Pastikan MySQL di XAMPP menyala.')], 500);
}
