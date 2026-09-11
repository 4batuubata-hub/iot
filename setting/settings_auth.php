<?php
session_start();
require_once __DIR__ . '/../koneksi.php';

// Cek autentikasi
$auth_enabled = 1;
$sql = "SELECT auth_enabled FROM setting_pabrik LIMIT 1";
$res = $conn->query($sql);
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $auth_enabled = (int)$row['auth_enabled'];
}

// Wajib login untuk masuk ke halaman pengaturan keamanan
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "login.php?force=1");
    exit;
}

// Cek session role = admin / it
$isAdmin = isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'it');

// Handle Toggle Auth
if ($isAdmin && isset($_POST['toggle_auth'])) {
    $new_status = (int)$_POST['auth_status'];
    $conn->query("UPDATE setting_pabrik SET auth_enabled = $new_status");
    echo "<script>alert('Status Autentikasi berhasil diubah!'); window.location.href='settings_auth.php';</script>";
    exit;
}

// Handle Add/Edit User
if ($isAdmin && isset($_POST['save_user'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $role = $conn->real_escape_string($_POST['role']);
    
    if (!empty($_POST['password'])) {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        if (!empty($_POST['user_id'])) {
            $id = (int)$_POST['user_id'];
            if (!$conn->query("UPDATE users SET username='$username', password='$password', role='$role' WHERE id=$id")) {
                echo "<script>alert('Error: " . $conn->error . "'); window.location.href='settings_auth.php';</script>";
                exit;
            }
        } else {
            if (!$conn->query("INSERT INTO users (username, password, role) VALUES ('$username', '$password', '$role')")) {
                echo "<script>alert('Error: " . $conn->error . "'); window.location.href='settings_auth.php';</script>";
                exit;
            }
        }
    } else {
        if (!empty($_POST['user_id'])) {
            $id = (int)$_POST['user_id'];
            if (!$conn->query("UPDATE users SET username='$username', role='$role' WHERE id=$id")) {
                echo "<script>alert('Error: " . $conn->error . "'); window.location.href='settings_auth.php';</script>";
                exit;
            }
        }
    }
    echo "<script>alert('User berhasil disimpan!'); window.location.href='settings_auth.php';</script>";
    exit;
}

// Handle Delete User
if ($isAdmin && isset($_GET['delete_user'])) {
    $id = (int)$_GET['delete_user'];
    if ($id !== (int)$_SESSION['user_id']) { // Jangan hapus diri sendiri
        $conn->query("DELETE FROM users WHERE id=$id");
    }
    header("Location: settings_auth.php");
    exit;
}

// Handle Delete Operator
if ($isAdmin && isset($_POST['btn_delete_operator']) && !empty($_POST['delete_operator_nik'])) {
    $nik = $conn->real_escape_string($_POST['delete_operator_nik']);
    
    // Hapus foto
    $res = $conn->query("SELECT foto FROM master_operator WHERE nik = '$nik'");
    if ($res && $res->num_rows > 0) {
        $old_foto = $res->fetch_assoc()['foto'];
        $upload_dir = __DIR__ . '/../assets/foto_operator/';
        if ($old_foto && file_exists($upload_dir . $old_foto)) {
            unlink($upload_dir . $old_foto);
        }
    }
    
    // Hapus dari skill matrix
    $conn->query("DELETE FROM skill_matrix WHERE nik_operator = '$nik'");
    
    // Hapus dari master_operator
    $conn->query("DELETE FROM master_operator WHERE nik = '$nik'");
    
    echo "<script>alert('Operator berhasil dihapus!'); window.location.href='settings_auth.php';</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Keamanan - PT CNC</title>
    <style>
        :root { --bg: #000; --card: #111; --text: #fff; --primary: #3b82f6; --border: #334155; --danger: #ef4444; }
        body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', Tahoma, sans-serif; margin: 0; padding: 20px; }
        .header { display: flex; justify-content: space-between; border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 20px; }
        .btn { padding: 8px 15px; border-radius: 6px; border: none; color: white; cursor: pointer; text-decoration: none; font-size: 14px; }
        .btn-primary { background: var(--primary); }
        .btn-danger { background: var(--danger); }
        .card { background: var(--card); border: 1px solid var(--border); padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid var(--border); padding: 10px; text-align: left; }
        th { background: #1e293b; }
        .input-group { margin-bottom: 15px; }
        .input-group label { display: block; margin-bottom: 5px; }
        .input-group input, .input-group select { width: 100%; padding: 8px; background: #1e293b; color: white; border: 1px solid var(--border); border-radius: 4px; box-sizing: border-box;}
    </style>
</head>
<body>
    <div class="header">
        <h2>🔒 Pengaturan Keamanan & User</h2>
        <a href="<?= BASE_URL ?>user/index.php" class="btn btn-primary">← Kembali ke Dashboard</a>
    </div>

    <?php if (!$isAdmin): ?>
        <div class="card" style="border-color: var(--danger);">
            <h3 style="color: var(--danger); margin:0;">Akses Ditolak</h3>
            <p>private.</p>
        </div>
    <?php else: ?>
        
        <div class="card">
            <h3>Status Login Sistem (RBAC)</h3>
            <p>Jika dimatikan, semua orang bisa mengakses dashboard tanpa login.</p>
            <form method="POST">
                <input type="hidden" name="toggle_auth" value="1">
                <select name="auth_status" style="padding: 10px; background: #1e293b; color: white; border: 1px solid var(--border); border-radius: 4px;">
                    <option value="1" <?= $auth_enabled == 1 ? 'selected' : '' ?>>AKTIF - Wajib Login</option>
                    <option value="0" <?= $auth_enabled == 0 ? 'selected' : '' ?>>TIDAK AKTIF - Bebas Akses</option>
                </select>
                <button type="submit" class="btn btn-primary" style="margin-left: 10px;">Simpan Status</button>
            </form>
        </div>

        <div class="card">
            <h3>Daftar Pengguna</h3>
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Dibuat Pada</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $users = $conn->query("SELECT * FROM users ORDER BY role ASC");
                    while ($u = $users->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>{$u['username']}</td>";
                        echo "<td>" . strtoupper($u['role']) . "</td>";
                        echo "<td>{$u['created_at']}</td>";
                        echo "<td>";
                        if ($u['id'] !== $_SESSION['user_id']) {
                            echo "<a href='?delete_user={$u['id']}' class='btn btn-danger' onclick='return confirm(\"Hapus user ini?\")'>Hapus</a>";
                        }
                        echo "</td>";
                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3>Tambah User Baru</h3>
            <form method="POST">
                <div class="input-group">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="input-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <div class="input-group">
                    <label>Role</label>
                    <select name="role">
                        <option value="user">User Biasa</option>
                        <option value="admin">Admin</option>
                        <option value="it">setting</option>
                    </select>
                </div>
                <button type="submit" name="save_user" class="btn btn-primary">Simpan User</button>
            </form>
        </div>

        <div class="card">
            <h3>Hapus Data Operator</h3>
            <p>Pilih operator yang ingin dihapus dari sistem secara permanen.</p>
            <form method="POST">
                <div class="input-group">
                    <label>Pilih Operator (NIK - Nama)</label>
                    <select name="delete_operator_nik" required>
                        <option value="">-- Pilih Operator --</option>
                        <?php
                        $ops = $conn->query("SELECT nik, nama FROM master_operator ORDER BY nama ASC");
                        while ($o = $ops->fetch_assoc()) {
                            echo "<option value='{$o['nik']}'>{$o['nik']} - {$o['nama']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" name="btn_delete_operator" class="btn btn-danger" onclick="return confirm('Yakin ingin menghapus operator ini secara permanen? Data skill matrix yang terkait dengan operator ini juga akan dihapus.')">Hapus Operator</button>
            </form>
        </div>

    <?php endif; ?>
</body>
</html>
