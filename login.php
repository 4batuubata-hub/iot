<?php
session_start();
require_once 'koneksi.php';

// Jika tidak ada pengaturan auth, anggap disabled
$auth_enabled = 0;
$sql = "SELECT auth_enabled FROM setting_pabrik LIMIT 1";
$res = $conn->query($sql);
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $auth_enabled = (int)$row['auth_enabled'];
}

// Jika auth disabled (dan tidak dipaksa) atau sudah login, redirect ke index
if (($auth_enabled == 0 && !isset($_GET['force'])) || isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];

    $sql = "SELECT id, username, password, role FROM users WHERE username = '$username' LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            header("Location: user/index.php");
            exit;
        } else {
            $error = 'Password salah!';
        }
    } else {
        $error = 'Username tidak ditemukan!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PT CNC Dashboard</title>
    <style>
        :root { --bg: #000; --card: #111; --text: #fff; --primary: #3b82f6; --border: #334155; }
        body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', Tahoma, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-card { background: var(--card); padding: 40px; border-radius: 12px; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.5); width: 100%; max-width: 350px; text-align: center; }
        .login-card h2 { margin-top: 0; margin-bottom: 30px; font-size: 24px; color: var(--primary); }
        .input-group { margin-bottom: 20px; text-align: left; }
        .input-group label { display: block; margin-bottom: 8px; font-size: 14px; color: #cbd5e1; }
        .input-group input { width: 100%; padding: 12px; background: #1e293b; border: 1px solid var(--border); color: #fff; border-radius: 6px; box-sizing: border-box; font-size: 16px; outline: none; transition: border 0.3s; }
        .input-group input:focus { border-color: var(--primary); }
        .btn-login { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; transition: background 0.3s; }
        .btn-login:hover { background: #2563eb; }
        .error { color: #ef4444; margin-bottom: 20px; font-size: 14px; background: rgba(239, 68, 68, 0.1); padding: 10px; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>OEE Dashboard<br><span style="color:#fff; font-size:18px;">PT CNC</span></h2>
        
        <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <label>Username</label>
                <input type="text" name="username" required autocomplete="off" autofocus>
            </div>
            <div class="input-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn-login">LOGIN</button>
        </form>
        <div style="margin-top: 20px; font-size: 12px; color: #64748b;">
            admin / admin
        </div>
    </div>
</body>
</html>
