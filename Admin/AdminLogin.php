<?php
require_once '../config.php';

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    if ($action === 'login') {
        $username = $conn->real_escape_string($_POST['username']);
        $password = $_POST['password'];
        
        // Find admin
        $sql = "SELECT * FROM admins WHERE username = '$username' AND password = '$password'";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            session_start();
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            
            // Redirect to admin panel
            header('Location: Admin.php');
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Solendra Samal - Admin Login</title>
  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Inter:wght@300;400;500;600&family=Manrope:wght@300;400;500;600&display=swap" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    body {
      font-family: 'Manrope', 'Inter', sans-serif;
      background: #0a0a0a;
      color: #ffffff;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .login-container {
      width: 100%;
      max-width: 420px;
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 40px;
      padding: 40px;
      backdrop-filter: blur(20px);
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    }
    .logo {
      text-align: center;
      margin-bottom: 32px;
      font-family: 'Playfair Display', serif;
      font-size: 28px;
      font-weight: 600;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
    }
    .logo-img {
      height: 50px;
      width: auto;
      object-fit: contain;
    }
    .logo span {
      font-weight: 400;
      display: flex;
      align-items: baseline;
      gap: 2px;
    }
    .admin-badge {
      text-align: center;
      margin-bottom: 24px;
      padding: 8px 16px;
      background: rgba(201, 168, 108, 0.1);
      border: 1px solid rgba(201, 168, 108, 0.3);
      border-radius: 40px;
      color: #c9a86c;
      font-size: 14px;
      font-weight: 500;
    }
    .form-group {
      margin-bottom: 20px;
    }
    .form-group label {
      display: block;
      margin-bottom: 8px;
      font-size: 14px;
      color: rgba(255, 255, 255, 0.7);
      font-weight: 500;
    }
    .form-group input {
      width: 100%;
      padding: 14px 18px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 40px;
      color: #ffffff;
      font-size: 15px;
      transition: 0.3s;
      outline: none;
    }
    .form-group input:focus {
      border-color: #c9a86c;
      background: rgba(255, 255, 255, 0.08);
    }
    .form-group input::placeholder {
      color: rgba(255, 255, 255, 0.4);
    }
    .btn {
      width: 100%;
      padding: 14px;
      background: #ffffff;
      color: #0a0a0a;
      border: none;
      border-radius: 40px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: 0.3s;
      margin-top: 8px;
    }
    .btn:hover {
      background: #c9a86c;
      color: #ffffff;
    }
    .back-link {
      text-align: center;
      margin-top: 24px;
      color: rgba(255, 255, 255, 0.5);
      font-size: 14px;
    }
    .back-link a {
      color: #c9a86c;
      text-decoration: none;
      transition: 0.3s;
    }
    .back-link a:hover {
      color: #ffffff;
    }
    .error {
      color: #ff6b6b;
      font-size: 13px;
      margin-top: 8px;
      display: none;
    }
    @media (max-width: 480px) {
      .login-container {
        padding: 30px 24px;
      }
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="logo">
      <img src="../Picture/LOGO/solendrasamal-removebg-preview.png" alt="Solendra Samal" class="logo-img">
      <span>Solendra<span>Samal</span></span>
    </div>
    <div class="admin-badge"><i class="fas fa-shield-alt"></i> Admin Access</div>
    
    <form onsubmit="handleLogin(event)">
      <div class="form-group">
        <label>Username</label>
        <input type="text" id="adminUsername" placeholder="Enter admin username" required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" id="adminPassword" placeholder="Enter admin password" required>
      </div>
      <div class="error" id="loginError"></div>
      <button type="submit" class="btn">Admin Login</button>
    </form>
    
    <div class="back-link">
      <a href="Pages/Page.php">← Back to Home</a>
    </div>
  </div>

  <script>
    function handleLogin(e) {
      e.preventDefault();
      const username = document.getElementById('adminUsername').value;
      const password = document.getElementById('adminPassword').value;
      const errorDiv = document.getElementById('loginError');

      const formData = new FormData();
      formData.append('action', 'login');
      formData.append('username', username);
      formData.append('password', password);

      fetch('AdminLogin.php', {
        method: 'POST',
        body: formData,
        redirect: 'follow'
      })
      .then(response => {
        if (response.redirected || response.url.includes('Admin.php')) {
          window.location.href = 'Admin.php';
          return;
        }
        
        return response.json().then(data => {
          if (data.success) {
            window.location.href = 'Admin.php';
          } else {
            errorDiv.textContent = data.message;
            errorDiv.style.display = 'block';
          }
        }).catch(() => {
          errorDiv.textContent = 'An error occurred. Please try again.';
          errorDiv.style.display = 'block';
        });
      })
      .catch(error => {
        errorDiv.textContent = 'An error occurred. Please try again.';
        errorDiv.style.display = 'block';
      });
    }
  </script>
</body>
</html>
