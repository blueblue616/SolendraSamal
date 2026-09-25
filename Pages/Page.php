<?php
session_start();
require_once '../config.php';

// Handle GET requests for fetching calendar bookings
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_calendar_bookings') {
    header('Content-Type: application/json');

    try {
        // Fetch bookings that should block calendar dates (completed and other active statuses)
        $sql = "SELECT checkin, checkout FROM bookings WHERE status IN ('completed', 'booking_confirmed', 'payment_confirmed', 'arrival_notice_sent', 'payment_under_review') AND status != 'cancelled' ORDER BY checkin ASC";
        $result = $conn->query($sql);
        if (!$result) {
            throw new Exception('Query failed: ' . $conn->error);
        }

        $bookings = [];
        while ($row = $result->fetch_assoc()) {
            $bookings[] = [
                'checkin' => $row['checkin'],
                'checkout' => $row['checkout']
            ];
        }

        // Fetch maintenance dates if table exists
        $maintenance_dates = [];
        $check_table = $conn->query("SHOW TABLES LIKE 'maintenance_dates'");
        if ($check_table && $check_table->num_rows > 0) {
            $maintenance_result = $conn->query("SELECT date FROM maintenance_dates ORDER BY date ASC");
            while ($row = $maintenance_result->fetch_assoc()) {
                $maintenance_dates[] = $row['date'];
            }
        }

        echo json_encode([
            'success' => true,
            'bookings' => $bookings,
            'maintenance_dates' => $maintenance_dates
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle GET requests for fetching QR code
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_qr_code') {
    header('Content-Type: application/json');

    try {
        $sql = "SELECT setting_value FROM payment_settings WHERE setting_key = 'qr_code'";
        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            // The path is already stored with ../ prefix for Pages folder access
            echo json_encode(['success' => true, 'qr_code_path' => $row['setting_value']]);
        } else {
            echo json_encode(['success' => true, 'qr_code_path' => null]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle GET requests for fetching reviews
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_reviews') {
    header('Content-Type: application/json');

    try {
        // Fetch only approved reviews
        $sql = "SELECT rating, review_text, created_at, COALESCE(name, 'Guest') AS name
                FROM reviews
                WHERE status = 'approved'
                ORDER BY created_at DESC";
        $result = $conn->query($sql);

        $reviews = [];
        while ($row = $result->fetch_assoc()) {
            $reviews[] = [
                'rating' => (int)$row['rating'],
                'review_text' => $row['review_text'],
                'created_at' => $row['created_at'],
                'name' => $row['name']
            ];
        }

        // Calculate average rating based only on approved reviews
        $avg_result = $conn->query("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM reviews WHERE status = 'approved'");
        $avg_data = $avg_result->fetch_assoc();
        $average_rating = $avg_data['avg_rating'] ? round($avg_data['avg_rating'], 1) : 0;
        $total_reviews = (int)$avg_data['total_reviews'];

        // Get rating distribution
        $rating_distribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $count_result = $conn->query("SELECT COUNT(*) as count FROM reviews WHERE status = 'approved' AND rating = $i");
            $count_data = $count_result->fetch_assoc();
            $rating_distribution[$i] = (int)$count_data['count'];
        }

        echo json_encode([
            'success' => true,
            'reviews' => $reviews,
            'average_rating' => $average_rating,
            'total_reviews' => $total_reviews,
            'rating_distribution' => $rating_distribution
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle POST requests for submitting reviews
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_review') {
    header('Content-Type: application/json');

    try {
        $name = trim($_POST['name'] ?? '');
        $rating = (int)($_POST['rating'] ?? 0);
        $review_text = trim($_POST['review_text'] ?? '');
        $user_id = isset($_SESSION['user_id']) ? $conn->real_escape_string($_SESSION['user_id']) : null;

        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Please enter your name']);
            exit;
        }

        if ($rating < 1 || $rating > 5) {
            echo json_encode(['success' => false, 'message' => 'Rating must be between 1 and 5']);
            exit;
        }

        if ($review_text === '') {
            echo json_encode(['success' => false, 'message' => 'Please write your review']);
            exit;
        }

        $name = $conn->real_escape_string($name);
        $review_text = $conn->real_escape_string($review_text);

        // Check database connection
        if ($conn->connect_error) {
            throw new Exception('Database connection failed: ' . $conn->connect_error);
        }

        $conn->query("CREATE TABLE IF NOT EXISTS reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(50) NULL,
            booking_id VARCHAR(50) NULL,
            name VARCHAR(100) NOT NULL,
            rating INT NOT NULL,
            review_text TEXT NOT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        )");

        // Check and add missing columns
        $columns_to_check = ['user_id', 'booking_id', 'name', 'status'];
        foreach ($columns_to_check as $column) {
            $check = $conn->query("SHOW COLUMNS FROM reviews LIKE '$column'");
            if ($check && $check->num_rows === 0) {
                if ($column === 'user_id') {
                    $conn->query("ALTER TABLE reviews ADD COLUMN user_id VARCHAR(50) NULL AFTER id");
                } elseif ($column === 'booking_id') {
                    $conn->query("ALTER TABLE reviews ADD COLUMN booking_id VARCHAR(50) NULL AFTER user_id");
                } elseif ($column === 'name') {
                    $conn->query("ALTER TABLE reviews ADD COLUMN name VARCHAR(100) NOT NULL DEFAULT 'Guest'");
                } elseif ($column === 'status') {
                    $conn->query("ALTER TABLE reviews ADD COLUMN status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
                }
            }
        }

        $user_sql = $user_id ? "'$user_id'" : 'NULL';
        $sql = "INSERT INTO reviews (user_id, name, rating, review_text, status)
                VALUES ($user_sql, '$name', $rating, '$review_text', 'pending')";

        if ($conn->query($sql)) {
            echo json_encode([
                'success' => true,
                'message' => 'Thank you! Your review was sent to the admin for approval.'
            ]);
        } else {
            throw new Exception('Failed to insert review: ' . $conn->error);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle GET requests for fetching refund policy
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_refund_policy') {
    header('Content-Type: application/json');

    $refundPolicy = "2. CANCELLATION & REFUND POLICY
Refund: Please note that the deposits made is NON-REFUNDABLE and will be forfeited if cancellation is pursued however, reservation is RE-BOOKABLE within 1 year from the date of booking.
No-Show: Failure to arrive on the scheduled check-in date without prior notice will be considered a no-show, and the reservation will be non-refundable.
Early Check-Out: Guests who voluntarily leave before the end of their reservation are not entitled to a refund for unused nights.
Date Changes: Requests to change reservation dates are subject to availability and approval. Any difference in rates must be paid by the guest. Requests made within 7 days of check-in may be treated as a cancellation and new reservation.
Platform Bookings: Reservations made through Airbnb, Booking.com, or other third-party platforms are also subject to the applicable cancellation and refund policies of that platform. Where applicable, the platform's policy will take precedence";

    echo json_encode(['success' => true, 'refund_policy' => $refundPolicy]);
    exit;
}

// Check if user is already logged in
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Solendra Samal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,600;14..32,700&family=Manrope:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../Css/Page.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    /* ----- floating FAQ widget (typing input style) ----- */
    .faq-floating {
      position: fixed;
      bottom: 2rem;
      right: 2rem;
      z-index: 9999;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      max-width: 420px;
      width: calc(100% - 4rem);
      pointer-events: none;
      line-height: 1.5;
    }

    /* main panel */
    .faq-panel {
      background: #ffffff;
      border-radius: 1.75rem;
      box-shadow: 
        0 20px 40px -12px rgba(0, 0, 0, 0.25),
        0 8px 24px -8px rgba(0, 0, 0, 0.15),
        0 0 0 1px rgba(0, 0, 0, 0.02);
      overflow: hidden;
      transition: opacity 0.25s ease, transform 0.3s cubic-bezier(0.2, 0.9, 0.4, 1), max-height 0.3s ease;
      transform-origin: bottom right;
      opacity: 0;
      transform: scale(0.92) translateY(12px);
      pointer-events: none;
      max-height: 0;
      display: flex;
      flex-direction: column;
    }

    .faq-floating.open .faq-panel {
      opacity: 1;
      transform: scale(1) translateY(0);
      pointer-events: auto;
      max-height: 620px;
    }

    /* header */
    .faq-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1.25rem 1.5rem 0.75rem;
      border-bottom: 1px solid #E3C1B1;
      flex-shrink: 0;
    }

    .faq-header h3 {
      font-size: 1.1rem;
      font-weight: 600;
      letter-spacing: -0.01em;
      color: #1A1815;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin: 0;
    }

    .faq-header h3 i {
      color: #1F83A4;
      font-size: 1.2rem;
    }

    .faq-close {
      background: none;
      border: none;
      font-size: 1.25rem;
      color: #6b6b7a;
      cursor: pointer;
      width: 32px;
      height: 32px;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.15s, color 0.15s;
      line-height: 1;
      padding: 0;
      pointer-events: auto;
    }

    .faq-close:hover {
      background: #F5F0E8;
      color: #1A1815;
    }

    /* chat / conversation area */
    .faq-chat {
      padding: 1rem 1.25rem 0.5rem;
      display: flex;
      flex-direction: column;
      gap: 0.85rem;
      max-height: 400px;
      overflow-y: auto;
      scrollbar-width: thin;
      scrollbar-color: #d9d9e3 #ffffff;
      flex: 1;
      scroll-behavior: smooth;
    }

    .faq-chat::-webkit-scrollbar {
      width: 6px;
    }

    .faq-chat::-webkit-scrollbar-track {
      background: #ffffff;
    }

    .faq-chat::-webkit-scrollbar-thumb {
      background: #d9d9e3;
      border-radius: 10px;
    }

    /* message bubbles */
    .faq-msg {
      display: flex;
      gap: 0.6rem;
      animation: msgIn 0.3s ease;
      max-width: 100%;
    }

    @keyframes msgIn {
      from {
        opacity: 0;
        transform: translateY(8px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .faq-msg.user {
      flex-direction: row-reverse;
    }

    .faq-avatar {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      font-size: 0.75rem;
      color: #fff;
    }

    .faq-avatar.bot {
      background: #1F83A4;
    }

    .faq-avatar.user {
      background: #C59F91;
    }

    .faq-bubble {
      padding: 0.75rem 1rem;
      border-radius: 1.1rem;
      font-size: 0.88rem;
      line-height: 1.55;
      color: #1A1815;
      max-width: 82%;
      word-break: break-word;
    }

    .faq-bubble.bot {
      background: #F5F0E8;
      border-bottom-left-radius: 0.3rem;
    }

    .faq-bubble.user {
      background: #C59F91;
      color: #fff;
      border-bottom-right-radius: 0.3rem;
    }

    .faq-bubble strong {
      color: #1A1815;
    }

    .faq-bubble.user strong {
      color: #fff;
    }

    /* typing indicator */
    .faq-typing {
      display: flex;
      gap: 0.6rem;
      align-items: flex-end;
      animation: msgIn 0.3s ease;
    }

    .faq-typing .faq-bubble {
      background: #F5F0E8;
      padding: 0.9rem 1.1rem;
      display: flex;
      gap: 0.3rem;
      align-items: center;
    }

    .faq-typing .dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #b0b0c0;
      animation: bounce 1.2s infinite ease-in-out;
    }

    .faq-typing .dot:nth-child(1) { animation-delay: 0s; }
    .faq-typing .dot:nth-child(2) { animation-delay: 0.15s; }
    .faq-typing .dot:nth-child(3) { animation-delay: 0.3s; }

    @keyframes bounce {
      0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
      30% { transform: translateY(-4px); opacity: 1; }
    }

    /* quick suggestion chips */
    .faq-suggestions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.4rem;
      padding: 0.5rem 1.25rem 0.75rem;
      border-top: 1px solid #E3C1B1;
      flex-shrink: 0;
    }

    .faq-chip {
      background: #F5F0E8;
      border: 1px solid #E3C1B1;
      border-radius: 2rem;
      padding: 0.4rem 0.85rem;
      font-size: 0.78rem;
      color: #1A1815;
      cursor: pointer;
      transition: all 0.15s ease;
      font-family: inherit;
      white-space: nowrap;
    }

    .faq-chip:hover {
      background: #fff;
      border-color: #1F83A4;
      color: #1F83A4;
      transform: translateY(-1px);
    }

    /* input area */
    .faq-input-area {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      padding: 0.85rem 1.25rem 1.1rem;
      border-top: 1px solid #E3C1B1;
      background: #fff;
      flex-shrink: 0;
      border-bottom-left-radius: 1.75rem;
      border-bottom-right-radius: 1.75rem;
    }

    .faq-input {
      flex: 1;
      border: 1.5px solid #E3C1B1;
      border-radius: 3rem;
      padding: 0.7rem 1.1rem;
      font-size: 0.88rem;
      font-family: inherit;
      color: #1A1815;
      outline: none;
      transition: border-color 0.18s, box-shadow 0.18s;
      background: #F5F0E8;
      min-width: 0;
    }

    .faq-input::placeholder {
      color: #9B7B71;
    }

    .faq-input:focus {
      border-color: #1F83A4;
      background: #fff;
      box-shadow: 0 0 0 3px rgba(31, 131, 164, 0.12);
    }

    .faq-send {
      background: #1F83A4;
      color: #fff;
      border: none;
      width: 42px;
      height: 42px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: background 0.18s, transform 0.18s;
      flex-shrink: 0;
      font-size: 0.95rem;
    }

    .faq-send:hover {
      background: #186A87;
      transform: scale(1.05);
    }

    .faq-send:active {
      transform: scale(0.97);
    }

    .faq-send:disabled {
      background: #e0e0e8;
      cursor: not-allowed;
      transform: none;
    }

    /* trigger button */
    .faq-trigger {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.6rem;
      background: #C59F91;
      color: white;
      border: none;
      border-radius: 3rem;
      padding: 0.9rem 1.8rem;
      font-size: 1rem;
      font-weight: 600;
      box-shadow: 0 12px 28px -8px rgba(197, 159, 145, 0.45), 0 4px 12px rgba(0, 0, 0, 0.1);
      cursor: pointer;
      transition: all 0.25s ease;
      letter-spacing: -0.01em;
      margin-left: auto;
      width: fit-content;
      pointer-events: auto;
      border: 1px solid rgba(255, 255, 255, 0.1);
      font-family: inherit;
    }

    .faq-trigger i {
      font-size: 1.1rem;
      transition: transform 0.2s;
    }

    .faq-trigger:hover {
      background: #B08D81;
      transform: translateY(-2px);
      box-shadow: 0 18px 32px -10px rgba(197, 159, 145, 0.55), 0 6px 14px rgba(0, 0, 0, 0.08);
    }

    .faq-trigger:active {
      transform: translateY(0px);
      box-shadow: 0 8px 18px -6px rgba(197, 159, 145, 0.5);
    }

    .faq-floating.open .faq-trigger {
      background: #ffffff;
      color: #C59F91;
      box-shadow: 0 8px 20px -8px rgba(0, 0, 0, 0.2), 0 0 0 1px #f0f0f5;
      border: 1px solid #f0f0f5;
    }

    .faq-floating.open .faq-trigger i {
      transform: rotate(180deg);
    }

    .faq-floating.open .faq-trigger:hover {
      background: #ffffff;
      color: #B08D81;
    }

    /* responsive */
    @media (max-width: 600px) {
      .faq-floating {
        bottom: 1rem;
        right: 1rem;
        left: 1rem;
        max-width: none;
        width: auto;
      }

      .faq-panel {
        max-height: 540px;
      }

      .faq-floating.open .faq-panel {
        max-height: 68vh;
      }

      .faq-trigger {
        width: 100%;
        justify-content: center;
      }

      .faq-chat {
        max-height: 320px;
      }

      .faq-bubble {
        max-width: 88%;
      }
    }

    /* iPhone SE specific */
    @media (max-width: 375px) {
      .faq-floating {
        bottom: 0.8rem;
        right: 0.8rem;
        left: 0.8rem;
      }

      .faq-panel {
        max-height: 480px;
      }

      .faq-floating.open .faq-panel {
        max-height: 60vh;
      }

      .faq-header {
        padding: 1rem 1.2rem 0.6rem;
      }

      .faq-header h3 {
        font-size: 1rem;
      }

      .faq-close {
        width: 28px;
        height: 28px;
        font-size: 1.1rem;
      }

      .faq-chat {
        padding: 0.8rem 1rem 0.4rem;
        max-height: 280px;
      }

      .faq-bubble {
        max-width: 90%;
        font-size: 0.85rem;
        padding: 0.6rem 0.8rem;
      }

      .faq-avatar {
        width: 26px;
        height: 26px;
        font-size: 0.7rem;
      }

      .faq-typing .faq-bubble {
        padding: 0.7rem 0.9rem;
      }

      .faq-typing .dot {
        width: 5px;
        height: 5px;
      }

      .faq-suggestions {
        padding: 0.4rem 1rem 0.6rem;
        gap: 0.3rem;
      }

      .faq-chip {
        padding: 0.3rem 0.7rem;
        font-size: 0.7rem;
      }

      .faq-input-area {
        padding: 0.7rem 1rem 0.9rem;
      }

      .faq-input {
        padding: 0.6rem 0.9rem;
        font-size: 0.85rem;
      }

      .faq-send {
        width: 38px;
        height: 38px;
        font-size: 0.85rem;
      }

      .faq-trigger {
        padding: 0.8rem 1.4rem;
        font-size: 0.9rem;
      }

      .faq-trigger i {
        font-size: 1rem;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .faq-panel,
      .faq-trigger,
      .faq-msg,
      .faq-typing,
      .faq-send {
        transition: none;
        animation: none;
      }
    }

    /* Guest age inputs */
    .guest-age-inputs {
      display: flex;
      gap: 12px;
      margin-top: 8px;
    }

    .guest-age-group {
      flex: 1;
    }

    .guest-age-group label {
      display: block;
      font-size: 0.75rem;
      color: #6a5a52;
      margin-bottom: 4px;
      font-weight: 500;
    }

    .guest-input {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #d4c4b8;
      border-radius: 8px;
      font-size: 0.9rem;
      background: #faf8f5;
      color: #3d322a;
      transition: border-color 0.2s;
    }

    .guest-input:focus {
      outline: none;
      border-color: #c9a86c;
      background: #fff;
    }

    /* iPhone SE specific for guest inputs */
    @media (max-width: 375px) {
      .guest-age-inputs {
        gap: 8px;
      }
      .guest-age-group {
        flex: 1;
      }
      .guest-age-group label {
        font-size: 0.65rem;
        margin-bottom: 3px;
      }
      .guest-input {
        padding: 8px 10px;
        font-size: 0.85rem;
        border-radius: 6px;
      }
    }
  </style>
</head>
<body>
  <div id="progress-bar"></div>
  <nav class="navbar" id="navbar">
    <a href="#home" class="logo">
      <img src="../Picture/LOGO/solendrasamal-removebg-preview.png" alt="Solendra Samal" class="logo-img">
      <span>Solendra<span>Samal</span></span>
    </a>
    <ul class="nav-links" id="navLinks">
      <li><a href="#home">HOME</a></li>
      <li><a href="#about">ABOUT</a></li>
      <li><a href="#gallery">GALLERY</a></li>
      <li><a href="#amenities">AMENITIES</a></li>
      <li><a href="#location">LOCATION</a></li>
      <li><a href="#faq">FAQ</a></li>
      <li><a href="#reviews">REVIEWS</a></li>
      <li><a href="#contact">CONTACT</a></li>
    </ul>
    <div class="nav-right">
     <a href="#booking" class="btn-primary">Book Now <i class="fas fa-arrow-right"></i></a>
      <div class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></div>
    </div>
  </nav>

  <!-- Hero -->
  <section class="hero" id="home">
    <div class="hero-slideshow">
      <div class="slide slide-1 active"></div>
      <video class="slide slide-2" muted loop playsinline>
        <source src="../Picture/Video/Drone/DJI_0438.MP4" type="video/mp4">
      </video>
      <video class="slide slide-3" muted loop playsinline>
        <source src="../Picture/Video/Drone/DJI_0436.MP4" type="video/mp4">
      </video>
    </div>
    <div class="hero-overlay"></div>
    <div class="hero-nav-left"><i class="fas fa-chevron-left"></i></div>
    <div class="hero-nav-right"><i class="fas fa-chevron-right"></i></div>
    <div class="hero-content">
      <h1 class="hero-title"><span class="hero-title-main">Solendra Samal</span><br><span class="hero-title-sub">Private Resort</span></h1>
      <p class="hero-subtitle">Stay productive while surrounded by nature's beauty.</p>
      <div class="hero-buttons">
        <a href="#booking" class="btn-primary">Book Your Stay <i class="fas fa-arrow-right"></i></a>
      </div>
    </div>
    <div class="carousel-indicators">
      <span class="indicator active"></span>
      <span class="indicator"></span>
      <span class="indicator"></span>
    </div>
  </section>

  <!-- About -->
  <section id="about">
    <div class="about-grid">
      <div class="about-text reveal">
        <h2>Private Resort Experience</h2>
        <p>Nestled in a serene valley, Solendra Samal offers a refined escape with panoramic views, curated interiors, and  Fun-Filled Resort Amenities. Designed for groups, families, and retreats.</p>
        <p>Every detail — from the sun-drenched terrace to the soundproofed karaoke lounge — is crafted for rejuvenation.</p>
        <div class="stats-grid">
          <div class="stat-card"><i class="fas fa-users"></i><strong>15-30</strong><span>Guests</span></div>
          <div class="stat-card"><i class="fas fa-bed"></i><strong>2</strong><span>Bedrooms</span></div>
          <div class="stat-card"><i class="fas fa-bath"></i><strong>2</strong><span>Bathrooms</span></div>
          <div class="stat-card"><i class="fas fa-utensils"></i><strong>Kitchen</strong><span>BBQ Area</span></div>
        </div>
      </div>
      <div class="reveal-right villa-panorama-container">
        <div class="video-slideshow" id="villaPanoramaSlideshow">
          <video class="video-slide active" muted loop playsinline>
            <source src="../Picture/Video/Drone/DJI_0436.MP4" type="video/mp4">
          </video>
          <video class="video-slide" muted loop playsinline>
            <source src="../Picture/Video/Drone/panorama2.MP4" type="video/mp4">
          </video>
          <video class="video-slide" muted loop playsinline>
            <source src="../Picture/Video/Drone/DJI_0434.MP4" type="video/mp4">
          </video>
          <video class="video-slide" muted loop playsinline>
            <source src="../Picture/Video/Drone/panorama4.MP4" type="video/mp4">
          </video>
        </div>
        <div class="video-overlay">
          <h3>Villa Panorama</h3>
          <p>Aerial views of paradise</p>
        </div>
        <div class="video-nav-left" onclick="navigateVideoSlideshow(-1)"><i class="fas fa-chevron-left"></i></div>
        <div class="video-nav-right" onclick="navigateVideoSlideshow(1)"><i class="fas fa-chevron-right"></i></div>
        <div class="video-indicators">
          <span class="video-indicator active" data-index="0"></span>
          <span class="video-indicator" data-index="1"></span>
          <span class="video-indicator" data-index="2"></span>
          <span class="video-indicator" data-index="3"></span>
        </div>
      </div>
    </div>
  </section>

  <!-- Gallery -->
  <section id="gallery">
    <h2 class="section-title reveal">Experience Your Own Private Resort</h2>
    <p class="section-sub reveal">Relax, have fun and make memories that last.</p>
    <div class="gallery-grid">
      <div class="gallery-item slideshow-item" onclick="openSlideshow(this)" data-slideshow='["../Picture/Solendra Official Photos/outdoor/0BDF6679-D471-4ABE-9EDC-1F47083C5E5B.png","../Picture/Solendra Official Photos/outdoor/6E7DEE48-A71D-4219-B895-03F0DF8582B0.png","../Picture/Solendra Official Photos/outdoor/B0AAD3D5-E014-465F-909A-F9CD05F85C47.png","../Picture/Solendra Official Photos/outdoor/DSC_4356.jpeg","../Picture/Solendra Official Photos/outdoor/DSC_4666.jpeg","../Picture/Solendra Official Photos/outdoor/EC2ED616-E38B-49EF-B043-79793969ADB6.png","../Picture/Solendra Official Photos/outdoor/F32071C9-D92B-4B13-AF14-269887E3A268.png","../Picture/Solendra Official Photos/outdoor/IMG_6124.JPG","../Picture/Solendra Official Photos/outdoor/IMG_6134.JPG","../Picture/Solendra Official Photos/outdoor/IMG_6188.JPG","../Picture/Solendra Official Photos/outdoor/IMG_6190.JPG","../Picture/Solendra Official Photos/outdoor/IMG_6602.JPG","../Picture/outdoor/DSC_4400.jpg","../Picture/outdoor/DSC_4411.jpg","../Picture/outdoor/DSC_4482.jpg","../Picture/outdoor/DSC_4498.jpg","../Picture/outdoor/DSC_4566.jpg","../Picture/outdoor/DSC_4664.jpg","../Picture/outdoor/DSC_4671.jpg","../Picture/outdoor/IMG20260818165959.jpg","../Picture/outdoor/IMG20260818170453.jpg","../Picture/outdoor/IMG20260818170537.jpg","../Picture/outdoor/IMG20260818171716.jpg"]'><img src="../Picture/Solendra Official Photos/outdoor/0BDF6679-D471-4ABE-9EDC-1F47083C5E5B.png" alt="Outdoor Area"><span class="cat-tag">Outdoor Area <i class="fas fa-play-circle"></i></span></div>
      <div class="gallery-item slideshow-item" onclick="openSlideshow(this)" data-slideshow='["../Picture/Solendra Official Photos/indoor/living room/3F227442-D007-4564-96F2-FF2731F73DB1.png","../Picture/Solendra Official Photos/indoor/living room/E59DF209-EA90-40AE-A57F-6557242E2E0C.png","../Picture/Solendra Official Photos/indoor/living room/livingroom.jpeg","../Picture/living room/IMG20260818155705.jpg","../Picture/living room/IMG20260818155818.jpg","../Picture/living room/IMG20260818155836.jpg","../Picture/living room/IMG20260818204327.jpg","../Picture/living room/IMG20260818204338(1).jpg","../Picture/living room/IMG20260818204510.jpg","../Picture/living room/IMG20260818204522(1).jpg"]'><img src="../Picture/Solendra Official Photos/indoor/living room/3F227442-D007-4564-96F2-FF2731F73DB1.png" alt="Living Room"><span class="cat-tag">Living Room <i class="fas fa-play-circle"></i></span></div>
      <div class="gallery-item slideshow-item" onclick="openSlideshow(this)" data-slideshow='["../Picture/Solendra Official Photos/indoor/bedroom/1EBFF053-D267-43A0-BA69-50C6A0F4D6AA.png","../Picture/Solendra Official Photos/indoor/bedroom/4E1E5C93-5C7D-47A4-9078-0A61E109393D.png","../Picture/Solendra Official Photos/indoor/bedroom/92889829-2CB6-4B07-AEA1-9E533225359F.png","../Picture/Solendra Official Photos/indoor/bedroom/CEEE247C-B1AC-4489-AD6F-50CD781A19F6.png","../Picture/Solendra Official Photos/indoor/bedroom/F153648F-FE7A-48D3-A265-E66703747750.png","../Picture/bedrooms/IMG20260818152044.jpg","../Picture/bedrooms/IMG20260818152147.jpg","../Picture/bedrooms/IMG20260818153955.jpg","../Picture/bedrooms/IMG20260818154128.jpg","../Picture/bedrooms/IMG20260818154200.jpg","../Picture/bedrooms/IMG20260818154218.jpg","../Picture/bedrooms/IMG20260818154238.jpg"]'><img src="../Picture/Solendra Official Photos/indoor/bedroom/1EBFF053-D267-43A0-BA69-50C6A0F4D6AA.png" alt="Bedroom"><span class="cat-tag">Bedroom <i class="fas fa-play-circle"></i></span></div>
      <div class="gallery-item slideshow-item" onclick="openSlideshow(this)" data-slideshow='["../Picture/Solendra Official Photos/indoor/bathroom/2F0CA934-E8CF-4752-B55A-DE4AEA420B64.png","../Picture/Solendra Official Photos/indoor/bathroom/3C6A887A-5FD1-4B5E-A78E-8EF63B29E055.png"]'><img src="../Picture/Solendra Official Photos/indoor/bathroom/2F0CA934-E8CF-4752-B55A-DE4AEA420B64.png" alt="Bathroom"><span class="cat-tag">Bathroom <i class="fas fa-play-circle"></i></span></div>
      <div class="gallery-item slideshow-item" onclick="openSlideshow(this)" data-slideshow='["../Picture/kitchen/DSC_4192.jpg","../Picture/kitchen/DSC_4193.jpg","../Picture/kitchen/DSC_4221.jpg","../Picture/kitchen/DSC_4223.jpg","../Picture/kitchen/DSC_4227.jpg","../Picture/kitchen/DSC_4228.jpg","../Picture/kitchen/DSC_4234.jpg","../Picture/kitchen/DSC_4236.jpg","../Picture/kitchen/DSC_4243.jpg"]'><img src="../Picture/kitchen/DSC_4192.jpg" alt="Kitchen"><span class="cat-tag">Kitchen <i class="fas fa-play-circle"></i></span></div>
      <div class="gallery-item slideshow-item" onclick="openSlideshow(this)" data-slideshow='["../Picture/Solendra Official Photos/amenities/IMG_5963.JPG","../Picture/Solendra Official Photos/amenities/DSC_4073.jpeg","../Picture/Solendra Official Photos/amenities/DSC_4245.jpeg","../Picture/Solendra Official Photos/amenities/DSC_4593.jpeg","../Picture/Solendra Official Photos/amenities/DSC_4603.jpeg","../Picture/Solendra Official Photos/amenities/DSC_4649.jpeg","../Picture/Solendra Official Photos/amenities/DSC_4652.jpeg","../Picture/Solendra Official Photos/amenities/DSC_4659.jpeg","../Picture/Solendra Official Photos/amenities/IMG_5842.PNG","../Picture/Solendra Official Photos/amenities/IMG_5966.JPG","../Picture/Solendra Official Photos/amenities/IMG_5967.JPG","../Picture/Solendra Official Photos/amenities/IMG_5979.JPG","../Picture/Solendra Official Photos/amenities/IMG_5981.JPG","../Picture/Solendra Official Photos/amenities/IMG_5986.JPG","../Picture/Solendra Official Photos/amenities/IMG_5988.JPG","../Picture/Solendra Official Photos/amenities/IMG_5990.JPG","../Picture/Solendra Official Photos/amenities/IMG_6076.JPG","../Picture/Solendra Official Photos/amenities/IMG_6094.JPG","../Picture/Solendra Official Photos/amenities/IMG_6098.PNG","../Picture/Solendra Official Photos/amenities/IMG_6129.PNG","../Picture/Solendra Official Photos/amenities/IMG_6133.JPG"]'><img src="../Picture/Solendra Official Photos/amenities/IMG_5963.JPG" alt="Amenities Slideshow"><span class="cat-tag">Amenities <i class="fas fa-play-circle"></i></span></div>
    </div>
  </section>

  <!-- Lightbox -->
  <div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <span class="close-lightbox">&times;</span>
    <button class="lightbox-nav lightbox-prev" onclick="event.stopPropagation(); navigateLightbox(-1)"><i class="fas fa-chevron-left"></i></button>
    <img src="" alt="preview" id="lightboxImg">
    <button class="lightbox-nav lightbox-next" onclick="event.stopPropagation(); navigateLightbox(1)"><i class="fas fa-chevron-right"></i></button>
  </div>

  <!-- Inclusions -->
  <section id="amenities">
    <h2 class="section-title reveal">Resort Amenities</h2>
    <p class="section-sub reveal">Unlimited access to all resort amenities</p>
    
    <div class="amenities-grid reveal">
      <div class="amenity-item">
        <span class="amenity-icon"><i class="fas fa-table-tennis-paddle-ball"></i></span>
        <span>Pickleball Paddles</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🏐</span>
        <span>Volleyball</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🏀</span>
        <span>Basketball</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🏸</span>
        <span>Badminton</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🌊</span>
        <span>Infinity Pool</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🛟</span>
        <span>Kids' Pool Floaters</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🎱</span>
        <span>Billiards</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🏓</span>
        <span>Ping Pong</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🔴</span>
        <span>Air Hockey</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🎲</span>
        <span>Board Games</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🎤</span>
        <span>Private Videoke Room</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🔥</span>
        <span>Bonfire</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🍒</span>
        <span>Complimentary Snacks & Drinks</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🍳</span>
        <span>Kitchen</span>
      </div>
      <div class="amenity-item">
        <span class="amenity-icon">🍖</span>
        <span>BBQ Area</span>
      </div>
    </div>
  </section>

  <!-- Booking -->
  <section id="booking" class="booking-section">
    <div class="section-header reveal booking-section-header">
      <h2 class="section-title booking-section-title" style="color: rgba(255, 255, 255, 0.95) !important;">Reserve Your Stay</h2>
      <p class="section-subtitle booking-section-subtitle">Select your preferred dates and complete your reservation</p>
    </div>

    <div class="booking-container">
      <!-- Calendar View -->
      <div id="calendarView" class="reveal">
        <div class="section-card">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-calendar-alt"></i> Select Dates</h3>
            <p class="card-subtitle">Choose your check-in and check-out dates</p>
          </div>

          <div class="landing-calendar-container">
            <div class="calendar-header">
              <button class="calendar-nav" onclick="changeMonth(-1)"><i class="fas fa-chevron-left"></i></button>
              <h4 id="calendarMonth">July 2026</h4>
              <button class="calendar-nav" onclick="changeMonth(1)"><i class="fas fa-chevron-right"></i></button>
            </div>
            <div class="calendar-weekdays">
              <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
            </div>
            <div id="calendarDays" class="calendar-days">
              <!-- Calendar days will be generated by JavaScript -->
            </div>
            <div class="calendar-legend">
              <div class="legend-item"><span class="legend-color checkin"></span> Check-in</div>
              <div class="legend-item"><span class="legend-color checkout"></span> Check-out</div>
              <div class="legend-item"><span class="legend-color range"></span> Selected Range</div>
              <div class="legend-item"><span class="legend-color booked"></span> Booked</div>
              <div class="legend-item"><span class="legend-color maintenance"></span> Maintenance</div>
              <div class="legend-item"><span class="legend-color available"></span> Available</div>
            </div>
            <div class="calendar-notice">
              <i class="fas fa-info-circle calendar-notice-icon"></i>
              <span>Red dates are already booked. Yellow dates are under maintenance. Hover over dates for details.</span>
            </div>
          </div>

            <div class="booking-action">
              <button id="proceedToBookingBtn" class="btn-book btn-book-disabled" onclick="proceedToBooking()" disabled>
                <span>Proceed to Booking</span>
                <i class="fas fa-arrow-right"></i>
              </button>
            </div>
          </div>
        </div>
      </div>

          <!-- Multi-Step Booking Form View -->
          <div id="multiStepBookingForm" class="multi-step-booking-form">
            <div class="rounded-3xl border border-border bg-card p-7 shadow-soft booking-form-container">
              <div class="booking-form-header">
                <h3 class="booking-form-title">Complete Your Booking</h3>
              </div>

            <!-- Progress Steps -->
            <div class="progress-steps">
              <!-- Connecting Line -->
              <div class="progress-line"></div>
              
              <div class="step active" data-step="1">
                <div class="step-number">1</div>
                <div class="step-label">Guest Details</div>
              </div>
              <div class="step" data-step="2">
                <div class="step-number">2</div>
                <div class="step-label">Policies</div>
              </div>
              <div class="step" data-step="3">
                <div class="step-number">3</div>
                <div class="step-label">Payment</div>
              </div>
              <div class="step" data-step="4">
                <div class="step-number">4</div>
                <div class="step-label">Confirm</div>
              </div>
            </div>

            <div class="booking-form-content">
              <!-- Step 1: Guest Details -->
              <div class="booking-step booking-step-hidden" id="step1">
                <h4 class="step-header">Guest Information</h4>

                <div class="two-col">
                  <!-- Guest Information -->
                  <div class="guest-section">
                    <form id="guestDetailsForm">
                      <div class="guest-info-grid">
                        <!-- Left Column -->
                        <div class="guest-info-column">
                          <div class="field-row">
                            <label for="bookingName">Full Name</label>
                            <input type="text" id="bookingName" required placeholder="Enter your full name" />
                          </div>

                          <div class="field-row">
                            <label for="bookingEmail">Email Address</label>
                            <input type="email" id="bookingEmail" required placeholder="Enter your email" />
                          </div>
                        </div>

                        <!-- Right Column -->
                        <div class="guest-info-column">
                          <div class="field-row">
                            <label for="bookingPhone">Mobile Number</label>
                            <input type="tel" id="bookingPhone" required placeholder="Enter your mobile number" />
                          </div>

                          <div class="field-row">
                            <label for="bookingGuests">Number of Guests</label>
                            <div class="guest-age-inputs">
                              <div class="guest-age-group">
                                <label>Adults (12+ years)</label>
                                <input type="number" id="adultGuests" min="1" max="30" value="1" required class="guest-input" />
                              </div>
                              <div class="guest-age-group">
                                <label>Children (6-11 years)</label>
                                <input type="number" id="childGuests" min="0" max="30" value="0" class="guest-input" />
                              </div>
                              <div class="guest-age-group">
                                <label>Infants (0-5 years)</label>
                                <input type="number" id="infantGuests" min="0" max="30" value="0" class="guest-input" />
                              </div>
                            </div>
                            <small style="display:block; margin-top:4px; color:#6a5a52; font-size:0.7rem;">Adults 12+: Full rate | Children 6-11: 50% discount | Infants 0-5: Free</small>
                          </div>
                        </div>
                      </div>

                      <!-- Full-width Special Requests -->
                      <div class="field-row full-width">
                        <label for="bookingRequests">Special Requests <span class="optional-badge">(Optional)</span></label>
                        <textarea id="bookingRequests" rows="3" placeholder="Any special requests or notes" class="special-request-textarea"></textarea>
                      </div>

                      <input type="hidden" id="bookingCheckin">
                      <input type="hidden" id="bookingCheckout">
                      <input type="hidden" id="bookingTotalAmount">
                    </form>
                  </div>

                  <!-- Our Rates -->
                  <div class="rates-container">
                    <div class="section-title">Our Rates</div>

                    <div class="rate-item">
                      <span class="rate-label">Weekday Stay (Mon–Thu)</span>
                      <span class="rate-value">₱18,000</span>
                    </div>
                    <div class="rate-item">
                      <span class="rate-label">Weekend Stay (Fri–Sun)</span>
                      <span class="rate-value">₱20,000</span>
                    </div>
                    <div class="rate-item">
                      <span class="rate-label">Additional Guests: ₱800/pax (beyond 15)</span>
                      <span class="rate-value blue">₱800</span>
                    </div>
                    <div class="rate-item">
                      <span class="rate-label">Max Capacity</span>
                      <span class="rate-value blue">30 Guests</span>
                    </div>
                  </div>

                  <!-- Booking Summary -->
                  <div class="summary-container">
                    <div class="section-title">Booking summary</div>

                    <div class="summary-row">
                      <span class="label">Length of Stay</span>
                      <span class="value" id="summaryLength">1 Night</span>
                    </div>

                    <div class="summary-row check-in-out">
                      <div class="line">
                        <span class="label">CHECK-IN</span>
                        <span class="value"><strong id="summaryCheckin">Wednesday, Sep 23, 2026</strong> at 2:00 PM</span>
                      </div>
                      <div class="line">
                        <span class="label">CHECK-OUT</span>
                        <span class="value"><strong id="summaryCheckout">Thursday, Sep 24, 2026</strong> at 11:00 AM</span>
                      </div>
                    </div>

                    <div class="summary-row">
                      <span class="label">Rate type</span>
                      <span class="value" id="summaryRateType">Weekday Stay</span>
                    </div>

                    <div class="summary-row">
                      <span class="label">Guests</span>
                      <span class="value" id="summaryGuests">20</span>
                    </div>

                    <!-- Rate breakdown -->
                    <div class="summary-row subtotal">
                      <span class="label">Base rate (1–15 guests)</span>
                      <span class="value" id="summaryBaseRate">₱18,000</span>
                    </div>
                    <div class="summary-row subtotal">
                      <span class="label">Additional guests (5 × ₱800)</span>
                      <span class="value" id="summaryAdditionalGuests">₱4,000</span>
                    </div>
                    <div class="summary-row subtotal" id="summaryChildDiscountRow" style="display:none;">
                      <span class="label">Children discount (50% off)</span>
                      <span class="value" id="summaryChildDiscount">-₱0</span>
                    </div>

                    <div class="summary-row total">
                      <span class="label">Total booking amount</span>
                      <span class="value" id="summaryTotalAmount">₱22,000</span>
                    </div>

                    <!-- Payment Summary -->
                    <div class="payment-section">
                      <div class="payment-row">
                        <span class="label">Amount to Pay Now — 50% Downpayment</span>
                        <span class="value" id="summaryDownpayment">₱11,000</span>
                      </div>

                      <div class="payment-row">
                        <span class="label">Remaining Balance — Due 2 Days Before Check-in</span>
                        <span class="value" id="summaryRemainingBalance">₱11,000</span>
                      </div>
                      <div class="payment-note">The remaining balance must be paid 2 days before your check-in date.</div>

                      <!-- Security Deposit -->
                      <div class="security-deposit">
                        <i class="fas fa-shield-alt"></i>
                        <span><strong>Security Deposit:</strong> ₱5,000, payable before or upon check-in. Refundable after checkout and property inspection. <span style="display:block; font-size:0.55rem; color:#6a5a52; margin-top:2px;">This deposit is collected separately and is not part of your booking total.</span></span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Action buttons -->
                <div class="action-row">
                  <button type="button" onclick="cancelBooking()" class="btn-back"><i class="fas fa-arrow-left"></i> Back</button>
                  <button type="button" onclick="nextStep(2)" class="btn-continue">Continue <i class="fas fa-arrow-right"></i></button>
                </div>
              </div>

              <!-- Step 2: Terms & Conditions -->
              <div class="booking-step booking-step-hidden" id="step2">
                <h4 class="step-header">Terms & Conditions</h4>

                <!-- Terms Content -->
                <div id="termsContent" class="terms-content">
                  <div class="terms-header">
                    <h5 class="terms-title">SOLENDRA SAMAL</h5>
                    <p class="terms-subtitle">TERMS & CONDITIONS OF BOOKING</p>
                  </div>

                  <div class="terms-section">
                    <p class="terms-text">Thank you for choosing Solendra Samal. To ensure a safe, enjoyable, and comfortable experience for all guests, the following Terms & Conditions apply to every reservation. By making a reservation and/or staying at Solendra Samal, the guest acknowledges and agrees to these terms.</p>
                  </div>

                  <!-- Booking & Reservations Category -->
                  <div class="faq-category">
                    <h3 class="faq-category-title">Booking & Reservations</h3>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Reservation & Payment</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>A reservation is considered confirmed only after the required payment or deposit has been received and the booking has been confirmed by Solendra Samal or the applicable booking platform. The person who makes the reservation is responsible for ensuring that all information provided is accurate, including the number of guests, dates, and contact details.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Cancellation & Refund Policy</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p><strong>Refund:</strong> Please note that the deposits made is NON-REFUNDABLE and will be forfeited if cancellation is pursued however, reservation is RE-BOOKABLE within 1 year from the date of booking.<br><br><strong>Date Changes:</strong> Date changes are permitted, subject to availability and approval. Any rate differences must be paid by the guest.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Security Deposit</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Solendra Samal requires a ₱5,000 refundable security deposit, payable before or upon check-in. The deposit covers damages, missing items, excessive cleaning, unauthorized guests, rule violations, or other costs. Returned after checkout and property inspection if no outstanding charges. Guest responsible for costs exceeding deposit amount.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Check-in & Check-out</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Check-in: 2:00 PM. Early check-in may be requested but is subject to availability. Check-out: 11:00 AM. Guests must vacate the property by the agreed time. Late check-out must be approved in advance.</p>
                      </div>
                    </div>
                  </div>

                  <!-- Property Rules Category -->
                  <div class="faq-category">
                    <h3 class="faq-category-title">Property Rules</h3>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Property Damage & Missing Items</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Guests are responsible for the property, furnishings, equipment, appliances, amenities, and other items provided during their stay. Guests may be charged for damaged or broken furniture, appliances, fixtures, or equipment; lost or damaged sports equipment; lost keys, remotes, access cards; damage to pool or entertainment equipment; stained or damaged linens; missing property; excessive cleaning; and any other damage caused by the guest.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Guest Capacity & Extra Guests</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Solendra Samal can comfortably accommodate up to 15 guests within the standard rate. The maximum permitted occupancy is 30 guests, unless otherwise approved in writing by management. Undeclared overnight guests are not permitted.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Noise & Quiet Hours</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Quiet hours are from 10:00 PM to 7:00 AM. During quiet hours, guests must significantly reduce music volume, shouting, and other disruptive activities. Repeated noise complaints may result in management requiring the activity to stop.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Karaoke & Entertainment</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Guests must use all equipment responsibly and follow instructions provided by management. Damage resulting from misuse, negligence, or intentional acts may be charged to the guest.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Swimming Pool Rules</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>• Children must be supervised by a responsible adult at all times<br>• Running around the pool area is prohibited<br>• Glass containers are not permitted in or around the pool<br>• Guests should not enter the pool while intoxicated<br>• Guests use the swimming pool at their own risk</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Sports & Multipurpose Court</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>The multipurpose court may be used for activities including pickleball, badminton, volleyball, and basketball. Sports equipment provided must remain on the property unless permission is granted. Damaged or missing equipment may be charged to the responsible guest.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Pet Policy</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Pets are not allowed on the property unless management provides prior written approval. Guests who bring an unauthorized pet may be required to remove the pet and may be charged for additional cleaning, damage, or other resulting costs.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Smoking & Vaping</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Smoking and vaping are not permitted inside the house, bedrooms, karaoke room, or other enclosed areas. Guests who smoke or vape must do so only in designated outdoor areas. Damage, odor removal, or additional cleaning caused by smoking or vaping inside prohibited areas may be charged to the guest.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Cleanliness & Proper Use</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Guests are expected to maintain reasonable cleanliness throughout their stay. Excessive mess or cleaning beyond normal turnover may result in an additional cleaning charge.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Prohibited Activities</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Illegal drugs or illegal activities, fighting or violent behavior, theft or intentional damage to property, unauthorized parties or events, firearms or prohibited weapons, activities that create unreasonable safety risk, and unauthorized commercial activities are strictly prohibited.</p>
                      </div>
                    </div>
                  </div>

                  <!-- Safety & Liability Category -->
                  <div class="faq-category">
                    <h3 class="faq-category-title">Safety & Liability</h3>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Children & Minors</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Parents, guardians, and accompanying adults are responsible for supervising children and minors throughout the property. Particular care must be taken around the swimming pool, multipurpose court, stairs, kitchen equipment, and other potentially hazardous areas.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Personal Belongings</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Guests are responsible for their personal belongings during their stay. Solendra Samal is not responsible for lost, stolen, or unattended personal belongings.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Liability & Use of Amenities</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Guests use the swimming pool, sports court, sports equipment, entertainment facilities, and other amenities at their own risk and are responsible for using them properly. Solendra Samal will take reasonable measures to maintain its facilities in a safe and functional condition.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Emergencies & Property Access</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>In the event of an emergency, safety concern, serious rule violation, or necessary maintenance, Solendra Samal management or authorized personnel may enter the property when reasonably necessary. Guests must cooperate with reasonable safety and emergency procedures.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Force Majeure & Unforeseen Events</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Solendra Samal shall not be held responsible for circumstances beyond its reasonable control, including severe weather, natural disasters, government restrictions, power or water interruptions, emergencies, or other extraordinary events.</p>
                      </div>
                    </div>
                  </div>

                  <!-- General Category -->
                  <div class="faq-category">
                    <h3 class="faq-category-title">General</h3>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Violation of Terms</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>Failure to comply with these Terms & Conditions may result in additional charges; deduction from the security deposit; termination of the reservation; removal from the property; loss of refund eligibility; recovery of costs for damages or losses; and reporting of illegal activities to the appropriate authorities when necessary.</p>
                      </div>
                    </div>

                    <div class="faq-item">
                      <div class="faq-question" onclick="toggleFAQ(this)">
                        <span class="faq-question-text">Agreement to Terms</span>
                        <i class="fas fa-chevron-down faq-icon"></i>
                      </div>
                      <div class="faq-answer">
                        <p>By completing a reservation, making payment, checking in, or entering the property, the guest confirms that they have read, understood, and agreed to these Terms & Conditions. The guest is responsible for ensuring that all members of their group are informed of and comply with these rules.</p>
                      </div>
                    </div>
                  </div>

                  <div class="terms-footer">
                    <p class="terms-footer-text">Private Resort • Samal Island, Davao del Norte</p>
                    <p class="terms-footer-highlight">Thank you for respecting our property, our staff, our neighbors, and fellow guests. We hope you enjoy your stay at Solendra Samal!</p>
                  </div>
                </div>
                <div class="form-group booking-form-group">
                  <label class="agreement-checkbox">
                    <input type="checkbox" id="agreePolicies" required>
                    <span class="agreement-text">I have read, understood, and agree to the Terms & Conditions</span>
                  </label>
                </div>
                <div class="action-row">
                  <button type="button" onclick="prevStep(1)" class="btn-back"><i class="fas fa-arrow-left"></i> Back</button>
                  <button type="button" onclick="nextStep(3)" class="btn-continue">Continue <i class="fas fa-arrow-right"></i></button>
                </div>
              </div>

              <!-- Step 3: Payment Method -->
              <div class="booking-step booking-step-hidden" id="step3">
                <h4 class="step-header">Payment Method</h4>

                <div class="two-col">
                  <!-- Payment Form -->
                  <div class="guest-section">
                    <div class="payment-method-grid">
                      <label class="payment-option">
                        <input type="radio" name="paymentMethod" value="qr" onchange="toggleQRCode()">
                        <span class="payment-option-text">QR Payment</span>
                      </label>
                      <label class="payment-option">
                        <input type="radio" name="paymentMethod" value="cash" onchange="toggleQRCode()">
                        <span class="payment-option-text">Cash Payment</span>
                      </label>
                    </div>

                    <!-- QR Code Display -->
                    <div id="qrCodeDisplay" class="qr-code-display">
                      <div class="section-title">Scan to Pay</div>
                      <img src="" alt="Payment QR Code" class="qr-code-image">
                      <p class="payment-note">Scan this QR code using your banking app or e-wallet to send the payment.</p>
                    </div>

                    <!-- Payment Proof Upload (shown only for QR payment) -->
                    <div id="paymentProofGroup" style="display:none;">
                      <div class="field-row">
                        <label for="amountSent">Amount Sent (50% Downpayment)</label>
                        <input type="number" id="amountSent" placeholder="Amount will be auto-filled" />
                        <small style="display:block; margin-top:4px; color:var(--text-muted); font-size:0.7rem;">You may also pay in full if preferred</small>
                      </div>

                      <div class="field-row">
                        <label for="paymentProof">Upload Payment Proof</label>
                        <input type="file" id="paymentProof" accept="image/*" />
                      </div>
                    </div>

                    <div class="field-row full-width">
                      <label for="paymentNotes">Notes (Optional)</label>
                      <input type="text" id="paymentNotes" class="special-request-input" placeholder="Any additional notes about your payment" />
                    </div>
                  </div>

                  <!-- Booking Summary -->
                  <div class="summary-container">
                    <div class="section-title">Booking summary</div>

                    <div class="summary-row">
                      <span class="label">Length of Stay</span>
                      <span class="value" id="paymentSummaryLength">1 Night</span>
                    </div>

                    <div class="summary-row check-in-out">
                      <div class="line">
                        <span class="label">CHECK-IN</span>
                        <span class="value"><strong id="paymentSummaryCheckin">Wednesday, Sep 23, 2026</strong> at 2:00 PM</span>
                      </div>
                      <div class="line">
                        <span class="label">CHECK-OUT</span>
                        <span class="value"><strong id="paymentSummaryCheckout">Thursday, Sep 24, 2026</strong> at 11:00 AM</span>
                      </div>
                    </div>

                    <div class="summary-row">
                      <span class="label">Rate type</span>
                      <span class="value" id="paymentSummaryRateType">Weekday Stay</span>
                    </div>

                    <div class="summary-row">
                      <span class="label">Guests</span>
                      <span class="value" id="paymentSummaryGuests">20</span>
                    </div>

                    <!-- Rate breakdown -->
                    <div class="summary-row subtotal">
                      <span class="label">Base rate (1–15 guests)</span>
                      <span class="value" id="paymentSummaryBaseRate">₱18,000</span>
                    </div>
                    <div class="summary-row subtotal">
                      <span class="label">Additional guests (5 × ₱800)</span>
                      <span class="value" id="paymentSummaryAdditionalGuests">₱4,000</span>
                    </div>
                    <div class="summary-row subtotal" id="paymentSummaryChildDiscountRow" style="display:none;">
                      <span class="label">Children discount (50% off)</span>
                      <span class="value" id="paymentSummaryChildDiscount">-₱0</span>
                    </div>

                    <div class="summary-row total">
                      <span class="label">Total booking amount</span>
                      <span class="value" id="paymentSummaryTotalAmount">₱22,000</span>
                    </div>

                    <!-- Payment Summary -->
                    <div class="payment-section">
                      <div class="payment-row">
                        <span class="label">Amount to Pay Now — 50% Downpayment</span>
                        <span class="value" id="paymentSummaryDownpayment">₱11,000</span>
                      </div>

                      <div class="payment-row">
                        <span class="label">Remaining Balance — Due 2 Days Before Check-in</span>
                        <span class="value" id="paymentSummaryRemainingBalance">₱11,000</span>
                      </div>
                      <div class="payment-note">The remaining balance must be paid 2 days before your check-in date.</div>

                      <!-- Security Deposit -->
                      <div class="security-deposit">
                        <i class="fas fa-shield-alt"></i>
                        <span><strong>Security Deposit:</strong> ₱5,000, payable before or upon check-in. Refundable after checkout and property inspection. <span style="display:block; font-size:0.55rem; color:#6a5a52; margin-top:2px;">This deposit is collected separately and is not part of your booking total.</span></span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Action buttons -->
                <div class="action-row">
                  <button type="button" onclick="prevStep(2)" class="btn-back"><i class="fas fa-arrow-left"></i> Back</button>
                  <button type="button" onclick="nextStep(4)" class="btn-continue">Continue <i class="fas fa-arrow-right"></i></button>
                </div>
              </div>

              <!-- Step 4: Submit Booking -->
              <div class="booking-step booking-step-hidden" id="step4">
                <h4 class="step-header">Confirm Your Booking</h4>

                <div class="two-col">
                  <!-- Guest Information -->
                  <div class="guest-section">
                    <div class="guest-info-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                      <div class="field-row">
                        <label>Full Name</label>
                        <div class="detail-value" id="confirmName"></div>
                      </div>
                      <div class="field-row">
                        <label>Email</label>
                        <div class="detail-value" id="confirmEmail"></div>
                      </div>
                      <div class="field-row">
                        <label>Mobile Number</label>
                        <div class="detail-value" id="confirmPhone"></div>
                      </div>
                      <div class="field-row">
                        <label>Guests</label>
                        <div class="detail-value" id="confirmGuests"></div>
                      </div>
                      <div class="field-row">
                        <label>Special Requests</label>
                        <div class="detail-value" id="confirmRequests">None</div>
                      </div>
                    </div>
                  </div>

                  <!-- Booking Summary -->
                  <div class="summary-container">
                    <div class="section-title">Booking summary</div>

                    <div class="summary-row">
                      <span class="label">Length of Stay</span>
                      <span class="value" id="confirmLength">1 Night</span>
                    </div>

                    <div class="summary-row">
                      <span class="label">Rate type</span>
                      <span class="value" id="confirmRateType">Weekday Stay</span>
                    </div>

                    <div class="summary-row check-in-out">
                      <div class="line">
                        <span class="label">CHECK-IN</span>
                        <span class="value"><strong id="confirmCheckin">Wednesday, Sep 23, 2026</strong> at 2:00 PM</span>
                      </div>
                      <div class="line">
                        <span class="label">CHECK-OUT</span>
                        <span class="value"><strong id="confirmCheckout">Thursday, Sep 24, 2026</strong> at 11:00 AM</span>
                      </div>
                    </div>

                    <div class="summary-row">
                      <span class="label">Rate type</span>
                      <span class="value" id="confirmRateType">Weekday Stay</span>
                    </div>

                    <div class="summary-row">
                      <span class="label">Payment Method</span>
                      <span class="value" id="confirmPaymentMethod">QR Payment</span>
                    </div>

                    <div class="summary-row">
                      <span class="label">Amount Sent</span>
                      <span class="value">₱<span id="confirmAmountSent">11,000</span></span>
                    </div>

                    <!-- Rate breakdown -->
                    <div class="summary-row subtotal">
                      <span class="label">Base rate (1–15 guests)</span>
                      <span class="value" id="confirmBaseRate">₱18,000</span>
                    </div>
                    <div class="summary-row subtotal">
                      <span class="label">Additional guests</span>
                      <span class="value" id="confirmAdditionalGuests">₱0</span>
                    </div>
                    <div class="summary-row subtotal" id="confirmChildDiscountRow" style="display:none;">
                      <span class="label">Children discount (50% off)</span>
                      <span class="value" id="confirmChildDiscount">-₱0</span>
                    </div>

                    <div class="summary-row total">
                      <span class="label">Total booking amount</span>
                      <span class="value" id="confirmTotalAmount">₱22,000</span>
                    </div>

                    <!-- Payment Summary -->
                    <div class="payment-section">
                      <div class="payment-row">
                        <span class="label">Amount Sent</span>
                        <span class="value">₱<span id="confirmAmountSent">11,000</span></span>
                      </div>

                      <div class="payment-row">
                        <span class="label">Downpayment Paid</span>
                        <span class="value" id="confirmDownpayment">₱11,000</span>
                      </div>

                      <div class="payment-row">
                        <span class="label">Remaining Balance</span>
                        <span class="value" id="confirmRemainingBalance">₱11,000 (Due 2 days before check-in)</span>
                      </div>
                      <div class="payment-note">The remaining balance must be paid 2 days before your check-in date.</div>

                      <!-- Security Deposit -->
                      <div class="security-deposit">
                        <i class="fas fa-shield-alt"></i>
                        <span><strong>Security Deposit:</strong> ₱5,000, payable before or upon check-in. Refundable after checkout and property inspection. <span style="display:block; font-size:0.55rem; color:#6a5a52; margin-top:2px;">This deposit is collected separately and is not part of your booking total.</span></span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Info Box -->
                <div class="info-box">
                  <div class="info-box-content">
                    <i class="fas fa-info-circle info-icon"></i>
                    <div>
                      <p class="info-title">Important Information</p>
                      <p class="info-text">By clicking "Submit Booking", you confirm that all information provided is accurate and you agree to the Terms & Conditions. A confirmation email will be sent to your email address with your booking details.</p>
                    </div>
                  </div>
                </div>

                <!-- Action buttons -->
                <div class="action-row">
                  <button type="button" onclick="prevStep(3)" class="btn-back"><i class="fas fa-arrow-left"></i> Back</button>
                  <button type="button" onclick="submitBooking()" class="btn-continue">Submit Booking <i class="fas fa-check"></i></button>
                </div>
              </div>

              <!-- Success Message -->
              <div class="booking-step success-message success-step-hidden" id="stepSuccess">
                <div class="success-icon">
                  <span>✓</span>
                </div>
                <h4 class="success-title">Booking Submitted!</h4>
                <p class="success-subtitle">Your booking reference ID:</p>
                <p class="success-booking-id" id="successBookingId"></p>
                <p class="success-message-text">We'll send a confirmation email to your inbox shortly.</p>
                <button type="button" onclick="cancelBooking()" class="btn-close">Close</button>
              </div>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Location -->
  <section id="location">
    <h2 class="section-title reveal">Location & directions</h2>
    <div class="location-grid">
      <div class="location-info reveal">
        <h3>Location</h3>
        <p class="location-subtitle">Purok 5 Santo Niño, Samal, 8119 Davao del Norte</p>
        <p class="location-description">Tucked into the pristine shores of Samal Island, Solendra Samal is a short boat ride from Davao City — close enough for a quick escape, private enough to feel a world away.</p>
        <p class="location-note">Exact address and directions are shared with confirmed guests.</p>
        
        <div class="distance-cards">
          <div class="dist-item"><span>🚢 Babak Ferry Terminal</span><span>10-15 min</span></div>
          <div class="dist-item"><span>🏖️ Paradise Island Beach</span><span>8 min</span></div>
          <div class="dist-item"><span>🌴 Monfort Bat Cave</span><span>12 min</span></div>
          <div class="dist-item"><span>🏝️ Vanishing Island</span><span>20 min</span></div>
          <div class="dist-item"><span>🏪 Samal Public Market</span><span>10 min</span></div>
        </div>
      </div>
      <div class="map-container reveal-right">
        <iframe
          src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3945.123456789!2d123.456789!3d12.345678!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMTLCsDIwJzQ0LjQiTiAxMjPCsDI3JzI0LjUiRQ!5e0!3m2!1sen!2sph!4v1234567890"
          width="100%"
          height="400"
          class="map-iframe"
          allowfullscreen=""
          loading="lazy"
          referrerpolicy="no-referrer-when-downgrade">
        </iframe>
        <a href="https://maps.app.goo.gl/VMet7zWdWiXvxwML6" target="_blank" class="map-link-btn">
          <i class="fas fa-map-marker-alt"></i> Open in Google Maps
        </a>
      </div>
    </div>
  </section>

  <?php include '../includes/faq-section.php'; ?>

  <!-- Reviews -->
  <section id="reviews">
    <h2 class="section-title reveal">Guest Reviews & Ratings</h2>
    
    <!-- Rating Summary -->
    <div class="rating-summary reveal">
      <div class="rating-overview">
        <div class="rating-main">
          <span class="average-score" id="averageRating">0.0</span>
          <div class="stars-display" id="averageStars">☆☆☆☆☆</div>
          <span class="total-reviews" id="totalReviews">0 reviews</span>
        </div>
        <div class="rating-breakdown">
          <div class="rating-bar">
            <span class="rating-label">5 ★</span>
            <div class="bar-container"><div class="bar-fill" id="bar5"></div></div>
            <span class="rating-count" id="count5">0</span>
          </div>
          <div class="rating-bar">
            <span class="rating-label">4 ★</span>
            <div class="bar-container"><div class="bar-fill" id="bar4"></div></div>
            <span class="rating-count" id="count4">0</span>
          </div>
          <div class="rating-bar">
            <span class="rating-label">3 ★</span>
            <div class="bar-container"><div class="bar-fill" id="bar3"></div></div>
            <span class="rating-count" id="count3">0</span>
          </div>
          <div class="rating-bar">
            <span class="rating-label">2 ★</span>
            <div class="bar-container"><div class="bar-fill" id="bar2"></div></div>
            <span class="rating-count" id="count2">0</span>
          </div>
          <div class="rating-bar">
            <span class="rating-label">1 ★</span>
            <div class="bar-container"><div class="bar-fill" id="bar1"></div></div>
            <span class="rating-count" id="count1">0</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Reviews List -->
    <div class="reviews-container">
      <button class="review-nav-btn review-nav-prev" id="reviewPrevBtn" onclick="navigateReviews(-1)" style="display: none;">
        <i class="fas fa-chevron-left"></i>
      </button>
      <div class="reviews-list" id="reviewsList">
        <div class="no-reviews">No reviews yet. Be the first to review!</div>
      </div>
      <button class="review-nav-btn review-nav-next" id="reviewNextBtn" onclick="navigateReviews(1)" style="display: none;">
        <i class="fas fa-chevron-right"></i>
      </button>
    </div>
    
    <!-- Write Review Button -->
    <div class="write-review-section reveal">
      <button type="button" onclick="openReviewModal()" class="btn-primary">
        <i class="fas fa-pen"></i> Write a Review
      </button>
    </div>
  </section>

  <!-- Review Modal -->
  <div class="modal review-modal" id="reviewModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Write a Review</h3>
        <span class="close-modal" onclick="closeReviewModal()">&times;</span>
      </div>
      <div class="modal-body">
        <form id="reviewForm">
          <div class="form-group">
            <label>Your Name</label>
            <input type="text" id="reviewName" placeholder="Enter your name" required>
          </div>
          <div class="form-group">
            <label>Rating</label>
            <div class="star-rating" id="starRating">
              <span class="star" data-rating="1">★</span>
              <span class="star" data-rating="2">★</span>
              <span class="star" data-rating="3">★</span>
              <span class="star" data-rating="4">★</span>
              <span class="star" data-rating="5">★</span>
            </div>
            <input type="hidden" id="selectedRating" value="0">
          </div>
          <div class="form-group">
            <label>Your Review</label>
            <textarea id="reviewText" rows="4" placeholder="Share your experience..." required></textarea>
          </div>
          <button type="submit" class="btn-primary">Submit Review</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Contact -->
  <section id="contact">
    <div class="contact-info reveal">
      <div class="contact-item"><i class="fas fa-phone-alt"></i>0963 263 8663</div>
      <div class="contact-item"><i class="fas fa-envelope"></i>solendrasamal@gmail.com</div>
      <div class="contact-item"><i class="fab fa-facebook"></i> SolendraSamal</div>
      <div class="contact-item"><i class="fab fa-instagram"></i> SolendraSamal</div>
    </div>
  </section>

  <!-- Foote r -->
  <footer class="footer">
    <div class="footer-inner">
      <div><span class="footer-logo">SolendraSamal<span class="footer-logo-light">·Villa</span></span><p class="footer-tagline">Private Resort.</p></div>
      <div><h4 class="footer-heading">Quick Links</h4><a href="#about">About</a><br><a href="#gallery">Gallery</a><br><a href="#booking">Booking</a></div>
      <div><h4 class="footer-heading">Connect</h4><div class="socials"><a href="#"><i class="fab fa-facebook"></i></a><a href="#"><i class="fab fa-instagram"></i></a><a href="#"><i class="fab fa-whatsapp"></i></a></div><p class="footer-copyright">© 2026  Villa</p></div>
    </div>SolendraSamal
    <div class="back-top"><a href="#home" class="back-top-link"><i class="fas fa-arrow-up"></i> Back to top</a></div>
  </footer>

  <script>
    (function() {
      // Nav scroll
      const navbar = document.getElementById('navbar');
      window.addEventListener('scroll', () => {
        navbar.classList.toggle('scrolled', window.scrollY > 80);
        // progress
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const progress = (scrollTop / docHeight) * 100;
        document.getElementById('progress-bar').style.width = progress + '%';
      });

      // mobile toggle
      const toggle = document.getElementById('menuToggle');
      const navLinks = document.getElementById('navLinks');
      toggle.addEventListener('click', () => navLinks.classList.toggle('open'));

      // slideshow with navigation
      let slideIndex = 0;
      const slides = document.querySelectorAll('.slide');
      const indicators = document.querySelectorAll('.indicator');
      const heroNavLeft = document.querySelector('.hero-nav-left');
      const heroNavRight = document.querySelector('.hero-nav-right');

      function playVideoSafely(video) {
        const playRequest = video.play();
        if (playRequest && typeof playRequest.catch === 'function') {
          playRequest.catch(() => {});
        }
      }

      function goToSlide(index) {
        slides.forEach(s => {
          s.classList.remove('active');
          if (s.tagName === 'VIDEO') s.pause();
        });
        indicators.forEach(i => i.classList.remove('active'));
        slideIndex = index;
        if (slideIndex >= slides.length) slideIndex = 0;
        if (slideIndex < 0) slideIndex = slides.length - 1;
        slides[slideIndex].classList.add('active');
        indicators[slideIndex].classList.add('active');
        if (slides[slideIndex].tagName === 'VIDEO') {
          playVideoSafely(slides[slideIndex]);
        }
      }

      function nextSlide() {
        goToSlide(slideIndex + 1);
      }

      function prevSlide() {
        goToSlide(slideIndex - 1);
      }

      // Auto-play slideshow
      let slideInterval = setInterval(nextSlide, 5000);

      // Navigation arrows
      if (heroNavLeft) {
        heroNavLeft.addEventListener('click', () => {
          clearInterval(slideInterval);
          prevSlide();
          slideInterval = setInterval(nextSlide, 5000);
        });
      }

      if (heroNavRight) {
        heroNavRight.addEventListener('click', () => {
          clearInterval(slideInterval);
          nextSlide();
          slideInterval = setInterval(nextSlide, 5000);
        });
      }

      // Indicator clicks
      indicators.forEach((indicator, index) => {
        indicator.addEventListener('click', () => {
          clearInterval(slideInterval);
          goToSlide(index);
          slideInterval = setInterval(nextSlide, 5000);
        });
      });

      // lightbox with slideshow navigation
      let currentLightboxIndex = 0;
      let isSlideshowMode = false;
      let slideshowImages = [];
      let slideshowInterval = null;
      const galleryImages = [];

      // Collect all gallery images (excluding slideshow items)
      document.querySelectorAll('.gallery-item:not(.slideshow-item) img').forEach((img, index) => {
        galleryImages.push(img.src);
      });

      window.openLightbox = function(el) {
        const img = el.querySelector('img');
        if (img) {
          isSlideshowMode = false;
          if (slideshowInterval) clearInterval(slideshowInterval);
          // Find the index of the clicked image
          currentLightboxIndex = galleryImages.indexOf(img.src);
          document.getElementById('lightboxImg').src = img.src;
          document.getElementById('lightbox').classList.add('open');
        }
      };

      window.openSlideshow = function(el) {
        const img = el.querySelector('img');
        if (img) {
          isSlideshowMode = true;
          slideshowImages = JSON.parse(el.dataset.slideshow);
          currentLightboxIndex = 0;
          document.getElementById('lightboxImg').src = slideshowImages[0];
          document.getElementById('lightbox').classList.add('open');

          // Auto-advance slideshow every 3 seconds
          slideshowInterval = setInterval(() => {
            currentLightboxIndex++;
            if (currentLightboxIndex >= slideshowImages.length) currentLightboxIndex = 0;
            document.getElementById('lightboxImg').src = slideshowImages[currentLightboxIndex];
          }, 3000);
        }
      };

      window.closeLightbox = function() {
        document.getElementById('lightbox').classList.remove('open');
        if (slideshowInterval) clearInterval(slideshowInterval);
        isSlideshowMode = false;
      };

      window.navigateLightbox = function(direction) {
        if (isSlideshowMode) {
          // Reset slideshow timer on manual navigation
          if (slideshowInterval) clearInterval(slideshowInterval);
          slideshowInterval = setInterval(() => {
            currentLightboxIndex++;
            if (currentLightboxIndex >= slideshowImages.length) currentLightboxIndex = 0;
            document.getElementById('lightboxImg').src = slideshowImages[currentLightboxIndex];
          }, 3000);

          currentLightboxIndex += direction;
          if (currentLightboxIndex >= slideshowImages.length) currentLightboxIndex = 0;
          if (currentLightboxIndex < 0) currentLightboxIndex = slideshowImages.length - 1;
          document.getElementById('lightboxImg').src = slideshowImages[currentLightboxIndex];
        } else {
          currentLightboxIndex += direction;
          if (currentLightboxIndex >= galleryImages.length) currentLightboxIndex = 0;
          if (currentLightboxIndex < 0) currentLightboxIndex = galleryImages.length - 1;
          document.getElementById('lightboxImg').src = galleryImages[currentLightboxIndex];
        }
      };

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowRight') navigateLightbox(1);
        if (e.key === 'ArrowLeft') navigateLightbox(-1);
      });

      // Villa panorama video slideshow
      let currentVideoIndex = 0;
      const videoSlides = document.querySelectorAll('.video-slide');
      const videoIndicators = document.querySelectorAll('.video-indicator');
      let videoInterval = null;

      function playVideoSlide(index) {
        videoSlides.forEach(slide => {
          slide.classList.remove('active');
          slide.pause();
        });
        videoIndicators.forEach(ind => ind.classList.remove('active'));

        currentVideoIndex = index;
        if (currentVideoIndex >= videoSlides.length) currentVideoIndex = 0;
        if (currentVideoIndex < 0) currentVideoIndex = videoSlides.length - 1;

        videoSlides[currentVideoIndex].classList.add('active');
        playVideoSafely(videoSlides[currentVideoIndex]);
        videoIndicators[currentVideoIndex].classList.add('active');
      }

      window.navigateVideoSlideshow = function(direction) {
        if (videoInterval) clearInterval(videoInterval);
        playVideoSlide(currentVideoIndex + direction);
        videoInterval = setInterval(() => playVideoSlide(currentVideoIndex + 1), 8000);
      };

      // Auto-play video slideshow when visible
      const panoramaObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            playVideoSlide(0);
            videoInterval = setInterval(() => playVideoSlide(currentVideoIndex + 1), 8000);
          } else {
            if (videoInterval) clearInterval(videoInterval);
            videoSlides.forEach(slide => slide.pause());
          }
        });
      }, { threshold: 0.5 });

      const panoramaContainer = document.querySelector('.villa-panorama-container');
      if (panoramaContainer) {
        panoramaObserver.observe(panoramaContainer);
      }

      // Video indicator clicks
      videoIndicators.forEach((indicator, index) => {
        indicator.addEventListener('click', () => {
          if (videoInterval) clearInterval(videoInterval);
          playVideoSlide(index);
          videoInterval = setInterval(() => playVideoSlide(currentVideoIndex + 1), 8000);
        });
      });

      // Reviews functionality
      let selectedRating = 0;

      // Load reviews on page load
      function loadReviews() {
        fetch('Page.php?action=get_reviews')
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              displayReviews(data.reviews);
              displayRatingSummary(data.average_rating, data.total_reviews, data.rating_distribution);
              checkUserEligibility();
            }
          })
          .catch(error => console.error('Error loading reviews:', error));
      }

      // Refresh reviews after submission
      function refreshReviews() {
        fetch('Page.php?action=get_reviews')
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              displayReviews(data.reviews);
              displayRatingSummary(data.average_rating, data.total_reviews, data.rating_distribution);
            }
          })
          .catch(error => console.error('Error refreshing reviews:', error));
      }

      // Load refund policy
      function loadRefundPolicy() {
        fetch('Page.php?action=get_refund_policy')
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              const refundPolicyDisplay = document.getElementById('refundPolicyDisplay');
              if (refundPolicyDisplay) {
                refundPolicyDisplay.textContent = data.refund_policy;
              }
            }
          })
          .catch(error => console.error('Error loading refund policy:', error));
      }

      // Review pagination
      let currentPage = 0;
      const reviewsPerPage = 3;
      let allReviews = [];

      function displayReviews(reviews) {
        allReviews = reviews;
        const reviewsList = document.getElementById('reviewsList');
        const prevBtn = document.getElementById('reviewPrevBtn');
        const nextBtn = document.getElementById('reviewNextBtn');

        if (reviews.length === 0) {
          reviewsList.innerHTML = '<div class="no-reviews">No reviews yet. Be the first to review!</div>';
          prevBtn.style.display = 'none';
          nextBtn.style.display = 'none';
          return;
        }

        // Show navigation buttons if more than 3 reviews
        if (reviews.length > 3) {
          prevBtn.style.display = 'flex';
          nextBtn.style.display = 'flex';
        } else {
          prevBtn.style.display = 'none';
          nextBtn.style.display = 'none';
        }

        // Reset to first page when reviews are loaded
        currentPage = 0;
        updateReviewsDisplay();
      }

      function updateReviewsDisplay() {
        const reviewsList = document.getElementById('reviewsList');
        const prevBtn = document.getElementById('reviewPrevBtn');
        const nextBtn = document.getElementById('reviewNextBtn');

        const startIndex = currentPage * reviewsPerPage;
        const endIndex = startIndex + reviewsPerPage;
        const currentReviews = allReviews.slice(startIndex, endIndex);

        reviewsList.innerHTML = currentReviews.map((review, index) => `
          <div class="review-card" style="animation-delay: ${index * 0.1}s">
            <div class="review-header">
              <div class="review-stars">${'★'.repeat(review.rating)}${'☆'.repeat(5 - review.rating)}</div>
              <span class="review-date">${new Date(review.created_at).toLocaleDateString()}</span>
            </div>
            <p class="review-text">${review.review_text}</p>
            <div class="reviewer-name">— ${review.name}</div>
          </div>
        `).join('');

        // Update button states
        prevBtn.style.opacity = currentPage === 0 ? '0.5' : '1';
        prevBtn.style.pointerEvents = currentPage === 0 ? 'none' : 'auto';

        const totalPages = Math.ceil(allReviews.length / reviewsPerPage);
        nextBtn.style.opacity = currentPage >= totalPages - 1 ? '0.5' : '1';
        nextBtn.style.pointerEvents = currentPage >= totalPages - 1 ? 'none' : 'auto';
      }

      window.navigateReviews = function(direction) {
        const totalPages = Math.ceil(allReviews.length / reviewsPerPage);
        currentPage += direction;

        if (currentPage < 0) currentPage = 0;
        if (currentPage >= totalPages) currentPage = totalPages - 1;

        updateReviewsDisplay();
      };

      function displayRatingSummary(average, total, distribution) {
        document.getElementById('averageRating').textContent = average.toFixed(1);
        document.getElementById('totalReviews').textContent = `${total} review${total !== 1 ? 's' : ''}`;
        document.getElementById('averageStars').textContent = '★'.repeat(Math.round(average)) + '☆'.repeat(5 - Math.round(average));

        // Update rating bars with animation
        if (distribution) {
          // Reset bars first
          for (let i = 1; i <= 5; i++) {
            const barFill = document.getElementById(`bar${i}`);
            if (barFill) {
              barFill.style.width = '0%';
            }
          }

          // Animate bars after short delay
          setTimeout(() => {
            for (let i = 5; i >= 1; i--) {
              const barFill = document.getElementById(`bar${i}`);
              const countEl = document.getElementById(`count${i}`);
              if (barFill && countEl) {
                const count = distribution[i] || 0;
                const percentage = total > 0 ? (count / total) * 100 : 0;
                barFill.style.width = `${percentage}%`;
                countEl.textContent = count;
              }
            }
          }, 100);
        }
      }

      window.openReviewModal = function() {
        const modal = document.getElementById('reviewModal');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        // Trigger animation
        setTimeout(() => {
          modal.querySelector('.modal-content').style.animation = 'modalSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1)';
        }, 10);
      };

      window.closeReviewModal = function() {
        const modal = document.getElementById('reviewModal');
        const modalContent = modal.querySelector('.modal-content');
        modalContent.style.animation = 'modalSlideDown 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
        setTimeout(() => {
          modal.style.display = 'none';
          document.body.style.overflow = '';
          document.getElementById('reviewForm').reset();
          selectedRating = 0;
          updateStarDisplay();
          modalContent.style.animation = '';
        }, 300);
      };

      // Show refund policy modal
      window.showRefundPolicyModal = function() {
        const refundPolicy = `2. CANCELLATION & REFUND POLICY

Refund:
Please note that the deposits made is NON-REFUNDABLE and will be forfeited if cancellation is pursued however, reservation is RE-BOOKABLE within 1 year from the date of booking.

No-Show:
Failure to arrive on the scheduled check-in date without prior notice will be considered a no-show, and the reservation will be non-refundable.

Early Check-Out:
Guests who voluntarily leave before the end of their reservation are not entitled to a refund for unused nights.

Date Changes:
Requests to change reservation dates are subject to availability and approval. Any difference in rates must be paid by the guest. Requests made within 7 days of check-in may be treated as a cancellation and new reservation.

Platform Bookings:
Reservations made through Airbnb, Booking.com, or other third-party platforms are also subject to the applicable cancellation and refund policies of that platform. Where applicable, the platform's policy will take precedence.`;

        const modal = document.createElement('div');
        modal.className = 'modal-overlay';
        modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);display:flex;align-items:center;justify-content:center;z-index:10000;padding:24px;backdrop-filter:blur(4px);';
        modal.innerHTML = `
          <div class="modal" style="background:linear-gradient(135deg, rgba(20,20,20,0.98) 0%, rgba(15,15,15,0.98) 100%);border:1px solid rgba(201,168,108,0.15);border-radius:24px;padding:48px;max-width:700px;width:100%;max-height:85vh;overflow-y:auto;position:relative;box-shadow:0 25px 50px -12px rgba(0,0,0,0.5),0 0 0 1px rgba(201,168,108,0.05);">
            <button class="modal-close" onclick="this.closest('.modal-overlay').remove()" style="position:absolute;top:20px;right:20px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:50%;width:40px;height:40px;color:rgba(255,255,255,0.5);font-size:18px;cursor:pointer;z-index:10;transition:all 0.2s ease;display:flex;align-items:center;justify-content:center;">&times;</button>
            
            <div style="text-align:center;margin-bottom:36px;">
              <h3 style="font-family:'Playfair Display',serif;font-size:32px;margin:0 0 12px 0;color:var(--text-primary);letter-spacing:0.5px;font-weight:400;">Cancellation & Refund Policy</h3>
              <div style="width:50px;height:2px;background:linear-gradient(90deg, transparent, #c9a86c, transparent);margin:0 auto;"></div>
            </div>
            
            <div style="color:rgba(255,255,255,0.85);line-height:2.4;font-size:15px;white-space:pre-wrap;padding:0 8px;text-align:justify;">
              ${refundPolicy}
            </div>
          </div>
        `;
        document.body.appendChild(modal);
      };

      // Star rating functionality
      const stars = document.querySelectorAll('.star-rating .star');
      stars.forEach(star => {
        star.addEventListener('click', function() {
          selectedRating = parseInt(this.dataset.rating);
          document.getElementById('selectedRating').value = selectedRating;
          updateStarDisplay();
        });
        star.addEventListener('mouseenter', function() {
          const rating = parseInt(this.dataset.rating);
          highlightStars(rating);
        });
        star.addEventListener('mouseleave', function() {
          updateStarDisplay();
        });
      });

      function highlightStars(rating) {
        stars.forEach(star => {
          const starRating = parseInt(star.dataset.rating);
          star.style.color = starRating <= rating ? '#c9a86c' : '#555';
        });
      }

      function updateStarDisplay() {
        highlightStars(selectedRating);
      }

      // Submit review
      document.getElementById('reviewForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const name = document.getElementById('reviewName').value.trim();
        const reviewText = document.getElementById('reviewText').value.trim();

        if (!name || selectedRating === 0 || !reviewText) {
          alert('Please fill in all fields and select a rating');
          return;
        }

        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

        const formData = new FormData();
        formData.append('action', 'submit_review');
        formData.append('name', name);
        formData.append('rating', selectedRating);
        formData.append('review_text', reviewText);

        fetch('Page.php', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert(data.message || 'Review Submitted successfully!');
            closeReviewModal();
            refreshReviews(); // Refresh reviews after successful submission
          } else {
            alert(data.message || 'Failed to submit review');
          }
        })
        .catch(error => {
          console.error('Error submitting review:', error);
          alert('Failed to submit review');
        })
        .finally(() => {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalText;
        });
      });

      // Load reviews on page load
      loadReviews();

      // reveal on scroll (Intersection Observer)
      const reveals = document.querySelectorAll('.reveal, .reveal-left, .reveal-right');
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
          }
        });
      }, { threshold: 0.15, rootMargin: '0px 0px -30px 0px' });
      reveals.forEach(el => observer.observe(el));

      // close mobile menu on link click
      document.querySelectorAll('.nav-links a').forEach(link => {
        link.addEventListener('click', () => navLinks.classList.remove('open'));
      });

      // Set active nav link on scroll
      const sections = document.querySelectorAll('section[id]');
      window.addEventListener('scroll', () => {
        let current = '';
        sections.forEach(section => {
          const sectionTop = section.offsetTop;
          if (scrollY >= sectionTop - 200) {
            current = section.getAttribute('id');
          }
        });
        document.querySelectorAll('.nav-links a').forEach(a => {
          a.classList.remove('active');
          if (a.getAttribute('href') === '#' + current) {
            a.classList.add('active');
          }
        });
      });

      // Submit booking form
      window.submitBooking = function(e) {
        e.preventDefault();
        const booking = {
          id: 'B-' + Date.now().toString().slice(-6),
          name: document.getElementById('bookingName').value,
          email: document.getElementById('bookingEmail').value,
          phone: document.getElementById('bookingPhone').value,
          checkin: document.getElementById('bookingCheckin').value,
          checkout: document.getElementById('bookingCheckout').value,
          guests: document.getElementById('bookingGuests').value,
          requests: document.getElementById('bookingRequests').value,
          status: 'pending',
          createdAt: new Date().toISOString()
        };

        // Validate required fields
        if (!booking.name || !booking.email || !booking.checkin || !booking.checkout) {
          alert('Please fill in all required fields');
          return;
        }

        // Save to localStorage
        const bookings = JSON.parse(localStorage.getItem('solendra_bookings') || '[]');
        bookings.push(booking);
        localStorage.setItem('solendra_bookings', JSON.stringify(bookings));

        // Clear form
        document.getElementById('bookingName').value = '';
        document.getElementById('bookingEmail').value = '';
        document.getElementById('bookingPhone').value = '';
        document.getElementById('bookingRequests').value = '';

        alert('Booking request Submitted successfully! Reference ID: ' + booking.id);
      };

      // Initialize property data if not exists
      if (!localStorage.getItem('solendra_property')) {
        const propertyData = {
          name: 'Solendra Samal',
          description: 'Nestled in a serene valley, Solendra Samal offers a refined escape with panoramic views, curated interiors, and world-class amenities. Designed for groups, families, and retreats.',
          capacity: 12,
          bedrooms: 4,
          bathrooms: 3,
          amenities: ['Wi-Fi', 'Air Conditioning', 'Kitchen', 'Parking', 'Smart TV', 'Swimming Pool', 'Hot Shower', 'Karaoke Room', 'Sports Court', 'Billiards', 'BBQ Area', 'Garden'],
          location: 'Prime Location',
          contact: {
            phone: '+1 (555) 987-6543',
            email: 'SolendraSamal.com',
            facebook: 'SolendraSamal',
            instagram: 'SolendraSamal',
            whatsapp: '+1 (555) 987-6543'
          },
          updatedAt: new Date().toISOString()
        };
        localStorage.setItem('solendra_property', JSON.stringify(propertyData));
      }

      // Landing page calendar state
      let landingCalendarState = {
        currentDate: new Date(),
        selectedCheckin: null,
        selectedCheckout: null,
        bookedDates: [],
        maintenanceDates: []
      };

      // Initialize landing calendar
      function initLandingCalendar() {
        console.log('Initializing landing calendar...');
        loadLandingCalendarBookings();
        renderLandingCalendar();
      }

      // Load calendar bookings from current page
      function loadLandingCalendarBookings() {
        fetch('Page.php?action=get_calendar_bookings')
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            landingCalendarState.bookedDates = data.bookings || [];
            landingCalendarState.maintenanceDates = data.maintenance_dates || [];
            renderLandingCalendar();
          }
        })
        .catch(error => {
          console.error('Error loading calendar bookings:', error);
        });
      }

      // Check if date is booked
      function isLandingDateBooked(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const dateStr = `${year}-${month}-${day}`;

        for (const booking of landingCalendarState.bookedDates) {
          const checkin = new Date(booking.checkin);
          const checkout = new Date(booking.checkout);
          checkin.setHours(0, 0, 0, 0);
          checkout.setHours(0, 0, 0, 0);
          const currentDate = new Date(date);
          currentDate.setHours(0, 0, 0, 0);

          if (currentDate >= checkin && currentDate <= checkout) {
            return true;
          }
        }
        return false;
      }

      // Check if date is maintenance
      function isLandingDateMaintenance(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const dateStr = `${year}-${month}-${day}`;
        return landingCalendarState.maintenanceDates.includes(dateStr);
      }

      // Render landing calendar
      function renderLandingCalendar() {
        const year = landingCalendarState.currentDate.getFullYear();
        const month = landingCalendarState.currentDate.getMonth();

        // Update month header
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                          'July', 'August', 'September', 'October', 'November', 'December'];
        const monthEl = document.getElementById('calendarMonth');
        if (monthEl) {
          monthEl.textContent = `📅 ${monthNames[month]} ${year}`;
        }

        // Get first day of month and total days
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const daysInPrevMonth = new Date(year, month, 0).getDate();

        const calendarDays = document.getElementById('calendarDays');
        if (!calendarDays) return;
        calendarDays.innerHTML = '';

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        // Previous month days
        for (let i = firstDay - 1; i >= 0; i--) {
          const day = daysInPrevMonth - i;
          const dayEl = createLandingDayElement(day, 'prev-month', new Date(year, month - 1, day));
          calendarDays.appendChild(dayEl);
        }

        // Current month days
        for (let day = 1; day <= daysInMonth; day++) {
          const date = new Date(year, month, day);
          let className = 'current-month';

          if (date < today) {
            className += ' disabled';
          }

          if (isLandingDateBooked(date)) {
            className += ' booked';
          }

          if (isLandingDateMaintenance(date)) {
            className += ' maintenance';
          }

          if (landingCalendarState.selectedCheckin && date.getTime() === landingCalendarState.selectedCheckin.getTime()) {
            className += ' checkin';
          }

          if (landingCalendarState.selectedCheckout && date.getTime() === landingCalendarState.selectedCheckout.getTime()) {
            className += ' checkout';
          }

          if (landingCalendarState.selectedCheckin && landingCalendarState.selectedCheckout &&
              date > landingCalendarState.selectedCheckin && date < landingCalendarState.selectedCheckout) {
            className += ' range';
          }

          const dayEl = createLandingDayElement(day, className, date);
          calendarDays.appendChild(dayEl);
        }

        // Next month days
        const totalCells = firstDay + daysInMonth;
        const remainingCells = 42 - totalCells;
        for (let i = 1; i <= remainingCells; i++) {
          const dayEl = createLandingDayElement(i, 'next-month', new Date(year, month + 1, i));
          calendarDays.appendChild(dayEl);
        }
      }

      // Create day element for landing calendar
      function createLandingDayElement(day, className, date) {
        const dayEl = document.createElement('div');
        dayEl.className = `calendar-day ${className}`;
        dayEl.dataset.date = date.toISOString();

        // Add tooltip for booked dates
        if (className.includes('booked')) {
          dayEl.title = 'This date is already booked';
        }

        // Add tooltip for maintenance dates
        if (className.includes('maintenance')) {
          dayEl.title = 'This date is under maintenance';
        }

        const dayNumber = document.createElement('span');
        dayNumber.className = 'day-number';
        dayNumber.textContent = day;
        dayEl.appendChild(dayNumber);

        // Add availability indicator for available dates
        if (!className.includes('disabled') && !className.includes('booked') && !className.includes('maintenance') && !className.includes('prev-month') && !className.includes('next-month') && !className.includes('checkin') && !className.includes('checkout') && !className.includes('range')) {
          const availabilityDot = document.createElement('span');
          availabilityDot.className = 'availability-dot';
          dayEl.appendChild(availabilityDot);
        }

        // Add booked indicator dot for booked dates
        if (className.includes('booked')) {
          const bookedDot = document.createElement('span');
          bookedDot.className = 'booked-dot';
          dayEl.appendChild(bookedDot);
        }

        if (className.includes('checkin')) {
          const indicator = document.createElement('span');
          indicator.className = 'day-indicator checkin-indicator';
          indicator.textContent = 'Check-in';
          dayEl.appendChild(indicator);
        }

        if (className.includes('checkout')) {
          const indicator = document.createElement('span');
          indicator.className = 'day-indicator checkout-indicator';
          indicator.textContent = 'Check-out';
          dayEl.appendChild(indicator);
        }

        if (!className.includes('disabled') && !className.includes('booked') && !className.includes('maintenance')) {
          dayEl.addEventListener('click', () => handleLandingDateClick(date));
        }

        return dayEl;
      }

      // Handle landing date click
      function handleLandingDateClick(date) {
        console.log('Date clicked:', date);
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        if (date < today) {
          console.log('Date is in the past, ignoring');
          return;
        }

        if (isLandingDateBooked(date) || isLandingDateMaintenance(date)) {
          console.log('Date is booked or under maintenance, ignoring');
          return;
        }

        if (!landingCalendarState.selectedCheckin) {
          landingCalendarState.selectedCheckin = date;
          console.log('Check-in selected:', date);
        } else if (!landingCalendarState.selectedCheckout) {
          if (date > landingCalendarState.selectedCheckin) {
            landingCalendarState.selectedCheckout = date;
            console.log('Check-out selected:', date);
          } else if (date.getTime() === landingCalendarState.selectedCheckin.getTime()) {
            landingCalendarState.selectedCheckin = null;
            console.log('Check-in deselected');
          } else {
            landingCalendarState.selectedCheckin = date;
            console.log('Check-in changed to:', date);
          }
        } else {
          landingCalendarState.selectedCheckin = date;
          landingCalendarState.selectedCheckout = null;
          console.log('Reset: new check-in selected:', date);
        }

        console.log('Current state:', {
          checkin: landingCalendarState.selectedCheckin,
          checkout: landingCalendarState.selectedCheckout
        });

        renderLandingCalendar();
        updateLandingDateSummary();
      }

      // Change month
      window.changeMonth = function(delta) {
        landingCalendarState.currentDate.setMonth(landingCalendarState.currentDate.getMonth() + delta);
        renderLandingCalendar();
      };

      // Update landing date summary
      function updateLandingDateSummary() {
        const checkinEl = document.getElementById('summaryCheckin');
        const checkoutEl = document.getElementById('summaryCheckout');
        const nightsEl = document.getElementById('summaryNights');

        if (checkinEl) {
          if (landingCalendarState.selectedCheckin) {
            checkinEl.textContent = landingCalendarState.selectedCheckin.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
          } else {
            checkinEl.textContent = 'Not selected';
          }
        }

        if (checkoutEl) {
          if (landingCalendarState.selectedCheckout) {
            checkoutEl.textContent = landingCalendarState.selectedCheckout.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
          } else {
            checkoutEl.textContent = 'Not selected';
          }
        }

        if (nightsEl) {
          if (landingCalendarState.selectedCheckin && landingCalendarState.selectedCheckout) {
            const nights = Math.ceil((landingCalendarState.selectedCheckout - landingCalendarState.selectedCheckin) / (1000 * 60 * 60 * 24));
            nightsEl.textContent = nights + ' Night' + (nights > 1 ? 's' : '');
          } else {
            nightsEl.textContent = '0 Nights';
          }
        }

        // Update button state
        updateProceedButtonState();
      }

      // Update Proceed to Booking button state
      function updateProceedButtonState() {
        const proceedBtn = document.getElementById('proceedToBookingBtn');
        
        if (!proceedBtn) return;

        const hasDates = landingCalendarState.selectedCheckin && landingCalendarState.selectedCheckout;

        if (hasDates) {
          proceedBtn.disabled = false;
          proceedBtn.style.opacity = '1';
          proceedBtn.style.cursor = 'pointer';
        } else {
          proceedBtn.disabled = true;
          proceedBtn.style.opacity = '0.5';
          proceedBtn.style.cursor = 'not-allowed';
        }
      }

      // Proceed to booking
      window.proceedToBooking = function() {
        try {
          if (!landingCalendarState.selectedCheckin || !landingCalendarState.selectedCheckout) {
            alert('Please select your check-in and check-out dates from the calendar');
            return;
          }

          // Calculate pricing based on dates only (guests will be selected in Guest Details step)
          const nights = Math.ceil((landingCalendarState.selectedCheckout - landingCalendarState.selectedCheckin) / (1000 * 60 * 60 * 24));
          const checkinDay = landingCalendarState.selectedCheckin.getDay();
          
          // Determine rate type (weekday: Mon-Thu, weekend: Fri-Sun)
          const isWeekend = (checkinDay === 5 || checkinDay === 6 || checkinDay === 0); // Fri(5), Sat(6), Sun(0)
          const baseRatePerNight = isWeekend ? 20000 : 18000;
          const rateType = isWeekend ? 'Weekend Stay' : 'Weekday Stay';
          
          // Calculate base total (guest charges will be calculated in Guest Details step)
          const baseTotal = baseRatePerNight * nights;
          const totalAmount = baseTotal;
          const downpayment = totalAmount * 0.5;

          // Format dates
          const checkinDate = landingCalendarState.selectedCheckin.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
          const checkoutDate = landingCalendarState.selectedCheckout.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
          const checkinShort = landingCalendarState.selectedCheckin.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
          const checkoutShort = landingCalendarState.selectedCheckout.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

          // Populate simple date summary in Guest Details step
          const summaryCheckin = document.getElementById('summaryCheckin');
          const summaryCheckout = document.getElementById('summaryCheckout');
          const summaryNights = document.getElementById('summaryNights');
          
          if (summaryCheckin) summaryCheckin.textContent = checkinShort;
          if (summaryCheckout) summaryCheckout.textContent = checkoutShort;
          if (summaryNights) summaryNights.textContent = nights + ' Night' + (nights > 1 ? 's' : '');

          // Populate Step 1 (Guest Details) detailed summary
          const summaryLength = document.getElementById('summaryLength');
          const summaryCheckinDate = document.getElementById('summaryCheckinDate');
          const summaryCheckoutDate = document.getElementById('summaryCheckoutDate');
          const summaryRateType = document.getElementById('summaryRateType');
          const summaryBaseRate = document.getElementById('summaryBaseRate');
          const summaryRatePerNight = document.getElementById('summaryRatePerNight');
          
          if (summaryLength) summaryLength.textContent = nights + ' Night' + (nights > 1 ? 's' : '');
          if (summaryCheckinDate) summaryCheckinDate.textContent = checkinDate;
          if (summaryCheckoutDate) summaryCheckoutDate.textContent = checkoutDate;
          if (summaryRateType) summaryRateType.textContent = rateType;
          if (summaryBaseRate) summaryBaseRate.textContent = '₱' + baseRatePerNight.toLocaleString();
          if (summaryRatePerNight) summaryRatePerNight.textContent = 'per night';
          if (summaryAdditionalGuests) summaryAdditionalGuests.textContent = '₱0';
          if (summaryTotalAmount) summaryTotalAmount.textContent = '₱' + totalAmount.toLocaleString();
          if (summaryDownpayment) summaryDownpayment.textContent = '₱' + downpayment.toLocaleString();
          if (summaryRemainingBalance) summaryRemainingBalance.textContent = '₱' + downpayment.toLocaleString();

          // Populate Step 3 (Payment) summary
          const paymentSummaryLength = document.getElementById('paymentSummaryLength');
          const paymentSummaryCheckin = document.getElementById('paymentSummaryCheckin');
          const paymentSummaryCheckout = document.getElementById('paymentSummaryCheckout');
          const paymentSummaryRateType = document.getElementById('paymentSummaryRateType');
          const paymentSummaryGuests = document.getElementById('paymentSummaryGuests');
          const paymentSummaryBaseRate = document.getElementById('paymentSummaryBaseRate');
          const paymentSummaryRatePerNight = document.getElementById('paymentSummaryRatePerNight');
          const paymentSummaryAdditionalGuests = document.getElementById('paymentSummaryAdditionalGuests');
          const paymentSummaryTotalAmount = document.getElementById('paymentSummaryTotalAmount');
          const paymentSummaryDownpayment = document.getElementById('paymentSummaryDownpayment');
          const paymentSummaryRemainingBalance = document.getElementById('paymentSummaryRemainingBalance');

          if (paymentSummaryLength) paymentSummaryLength.textContent = nights + ' Night' + (nights > 1 ? 's' : '');
          if (paymentSummaryCheckin) paymentSummaryCheckin.textContent = checkinDate + ' at 2:00 PM';
          if (paymentSummaryCheckout) paymentSummaryCheckout.textContent = checkoutDate + ' at 11:00 AM';
          if (paymentSummaryRateType) paymentSummaryRateType.textContent = rateType;
          if (paymentSummaryGuests) paymentSummaryGuests.textContent = 'To be selected';
          if (paymentSummaryBaseRate) paymentSummaryBaseRate.textContent = '₱' + baseRatePerNight.toLocaleString();
          if (paymentSummaryRatePerNight) paymentSummaryRatePerNight.textContent = 'per night';
          if (paymentSummaryAdditionalGuests) paymentSummaryAdditionalGuests.textContent = '₱0';
          if (paymentSummaryTotalAmount) paymentSummaryTotalAmount.textContent = '₱' + totalAmount.toLocaleString();
          if (paymentSummaryDownpayment) paymentSummaryDownpayment.textContent = '₱' + downpayment.toLocaleString();
          if (paymentSummaryRemainingBalance) paymentSummaryRemainingBalance.textContent = '₱' + downpayment.toLocaleString();
          // Set hidden form fields
          const bookingCheckin = document.getElementById('bookingCheckin');
          const bookingCheckout = document.getElementById('bookingCheckout');
          const bookingTotalAmount = document.getElementById('bookingTotalAmount');
          
          // Fix timezone issue: Use local date instead of UTC
          if (bookingCheckin) {
            const year = landingCalendarState.selectedCheckin.getFullYear();
            const month = String(landingCalendarState.selectedCheckin.getMonth() + 1).padStart(2, '0');
            const day = String(landingCalendarState.selectedCheckin.getDate()).padStart(2, '0');
            bookingCheckin.value = `${year}-${month}-${day}`;
          }
          if (bookingCheckout) {
            const year = landingCalendarState.selectedCheckout.getFullYear();
            const month = String(landingCalendarState.selectedCheckout.getMonth() + 1).padStart(2, '0');
            const day = String(landingCalendarState.selectedCheckout.getDate()).padStart(2, '0');
            bookingCheckout.value = `${year}-${month}-${day}`;
          }
          if (bookingTotalAmount) bookingTotalAmount.value = totalAmount;

          // Set amount sent placeholder
          const amountSent = document.getElementById('amountSent');
          if (amountSent) amountSent.placeholder = downpayment.toLocaleString();

          // Toggle views - hide calendar, show booking form
          const calendarView = document.getElementById('calendarView');
          const multiStepBookingForm = document.getElementById('multiStepBookingForm');
          const step1 = document.getElementById('step1');
          
          if (calendarView) calendarView.style.display = 'none';
          if (multiStepBookingForm) multiStepBookingForm.style.display = 'block';
          if (step1) step1.style.display = 'block';
          
          updateStepUI(1);
        } catch (error) {
          console.error('Error in proceedToBooking:', error);
          alert('An error occurred. Please try again.');
        }
      };

      // Cancel booking
      window.cancelBooking = function() {
        document.getElementById('multiStepBookingForm').style.display = 'none';
        document.getElementById('calendarView').style.display = 'block';
        document.getElementById('guestDetailsForm').reset();
        // Reset steps
        document.querySelectorAll('.booking-step').forEach(step => step.style.display = 'none');
        // Show progress steps again
        document.querySelector('.booking-steps').style.display = 'flex';
        // Reset step UI
        updateStepUI(0);
      };

      // Update step UI
      function updateStepUI(currentStep) {
        document.querySelectorAll('.step').forEach(step => {
          const stepNum = parseInt(step.dataset.step);
          const stepNumber = step.querySelector('.step-number');
          const stepLabel = step.querySelector('.step-label');

          if (stepNum <= currentStep) {
            step.style.opacity = '1';
            stepNumber.style.background = 'linear-gradient(135deg, #c9a86c 0%, #b8966a 100%)';
            stepNumber.style.color = '#fff';
            stepNumber.style.boxShadow = '0 4px 12px rgba(201,168,108,0.3)';
            stepNumber.style.border = 'none';
            stepLabel.style.color = 'rgba(255,255,255,0.9)';
          } else {
            step.style.opacity = '0.4';
            stepNumber.style.background = 'rgba(255,255,255,0.08)';
            stepNumber.style.color = 'rgba(255,255,255,0.4)';
            stepNumber.style.boxShadow = 'none';
            stepNumber.style.border = '1px solid rgba(255,255,255,0.1)';
            stepLabel.style.color = 'rgba(255,255,255,0.4)';
          }
        });
      }

      // Toggle QR Code display and payment proof
      window.toggleQRCode = function() {
        const qrDisplay = document.getElementById('qrCodeDisplay');
        const paymentProofGroup = document.getElementById('paymentProofGroup');
        const cashPaymentGroup = document.getElementById('cashPaymentGroup');
        const paymentProofInput = document.getElementById('paymentProof');
        const amountSentInput = document.getElementById('amountSent');
        const amountSentCashInput = document.getElementById('amountSentCash');
        const qrRadio = document.querySelector('input[name="paymentMethod"][value="qr"]');
        const cashRadio = document.querySelector('input[name="paymentMethod"][value="cash"]');
        
        if (qrDisplay && qrRadio) {
          qrDisplay.style.display = qrRadio.checked ? 'block' : 'none';
        }
        
        if (paymentProofGroup && paymentProofInput && qrRadio) {
          if (qrRadio.checked) {
            // QR payment: show payment proof
            paymentProofGroup.style.display = 'block';
            paymentProofInput.required = true;
            // Auto-fill amount with 50% downpayment
            const downpaymentText = document.getElementById('paymentSummaryDownpayment')?.textContent;
            if (downpaymentText && amountSentInput) {
              const downpaymentValue = parseFloat(downpaymentText.replace(/[₱,]/g, ''));
              if (!isNaN(downpaymentValue)) {
                amountSentInput.value = downpaymentValue;
              }
            }
          } else {
            // Cash payment: hide payment proof
            paymentProofGroup.style.display = 'none';
            paymentProofInput.required = false;
            paymentProofInput.value = ''; // Clear any selected file
            if (amountSentInput) amountSentInput.value = '';
          }
        }

      };

      // Toggle individual policy section
      window.togglePolicy = function(policyId) {
        const policyContent = document.getElementById(policyId);
        const policyArrow = document.getElementById('arrow-' + policyId);

        if (policyContent && policyArrow) {
          const isHidden = policyContent.style.display === 'none';
          policyContent.style.display = isHidden ? 'block' : 'none';
          policyArrow.style.transform = isHidden ? 'rotate(180deg)' : 'rotate(0deg)';
        }
      };

      // Toggle FAQ accordion
      window.toggleFAQ = function(element) {
        const answer = element.nextElementSibling;
        const icon = element.querySelector('.faq-icon');

        // Close all other FAQs in the same category
        const category = element.closest('.faq-category');
        if (category) {
          category.querySelectorAll('.faq-answer').forEach(item => {
            if (item !== answer) {
              item.style.maxHeight = null;
              item.classList.remove('active');
              item.previousElementSibling.querySelector('.faq-icon').classList.remove('active');
            }
          });
        }

        // Toggle current FAQ
        if (answer.style.maxHeight) {
          answer.style.maxHeight = null;
          answer.classList.remove('active');
          if (icon) icon.classList.remove('active');
        } else {
          answer.style.maxHeight = answer.scrollHeight + 'px';
          answer.classList.add('active');
          if (icon) icon.classList.add('active');
        }
      };

      // Recalculate pricing when guest count changes
      window.recalculatePricing = function() {
        const adultGuestsEl = document.getElementById('adultGuests');
        const childGuestsEl = document.getElementById('childGuests');
        const infantGuestsEl = document.getElementById('infantGuests');

        if (!adultGuestsEl || !childGuestsEl || !infantGuestsEl) return;

        const adults = parseInt(adultGuestsEl.value) || 0;
        const children = parseInt(childGuestsEl.value) || 0;
        const infants = parseInt(infantGuestsEl.value) || 0;
        const totalGuests = adults + children + infants;

        const checkinEl = document.getElementById('bookingCheckin');
        const checkoutEl = document.getElementById('bookingCheckout');

        if (!checkinEl || !checkoutEl || !checkinEl.value || !checkoutEl.value) return;

        const checkin = new Date(checkinEl.value);
        const checkout = new Date(checkoutEl.value);
        const nights = Math.ceil((checkout - checkin) / (1000 * 60 * 60 * 24));
        const checkinDay = checkin.getDay();

        // Determine rate type (weekday: Mon-Thu, weekend: Fri-Sun)
        const isWeekend = (checkinDay === 5 || checkinDay === 6 || checkinDay === 0);
        const baseRatePerNight = isWeekend ? 20000 : 18000;

        // Calculate additional guest charges (for adults + children beyond 15)
        const payingGuests = adults + children; // Only adults and children count toward occupancy
        const additionalGuests = payingGuests > 15 ? payingGuests - 15 : 0;
        const additionalGuestCharge = additionalGuests * 800 * nights;

        // Calculate children discount (50% off ₱800 = ₱400 per child per night)
        const childDiscount = children * 400 * nights;

        // Calculate total
        const baseTotal = baseRatePerNight * nights;
        const totalAmount = baseTotal + additionalGuestCharge - childDiscount;
        const downpayment = baseTotal * 0.5; // 50% of base rate only (without additional guests)
        const remainingBalance = totalAmount - downpayment;
        
        // Update booking summary
        const summaryGuests = document.getElementById('summaryGuests');
        const summaryAdditionalGuests = document.getElementById('summaryAdditionalGuests');
        const summaryTotalAmount = document.getElementById('summaryTotalAmount');
        const summaryDownpayment = document.getElementById('summaryDownpayment');
        const summaryRemainingBalance = document.getElementById('summaryRemainingBalance');
        const paymentSummaryGuests = document.getElementById('paymentSummaryGuests');
        const paymentSummaryAdditionalGuests = document.getElementById('paymentSummaryAdditionalGuests');
        const paymentSummaryTotalAmount = document.getElementById('paymentSummaryTotalAmount');
        const paymentSummaryDownpayment = document.getElementById('paymentSummaryDownpayment');
        const paymentSummaryRemainingBalance = document.getElementById('paymentSummaryRemainingBalance');
        const bookingTotalAmount = document.getElementById('bookingTotalAmount');
        const amountSent = document.getElementById('amountSent');

        if (summaryGuests) summaryGuests.textContent = totalGuests + ' Guests (' + adults + ' adults, ' + children + ' children, ' + infants + ' infants)';
        if (summaryAdditionalGuests) {
          if (additionalGuests > 0) {
            summaryAdditionalGuests.textContent = additionalGuests + ' additional guests (' + additionalGuests + ' × ₱800 × ' + nights + ' night' + (nights > 1 ? 's' : '') + ') = ₱' + additionalGuestCharge.toLocaleString();
          } else {
            summaryAdditionalGuests.textContent = '₱0';
          }
        }
        if (children > 0) {
          const childDiscountEl = document.getElementById('summaryChildDiscount');
          const childDiscountRow = document.getElementById('summaryChildDiscountRow');
          if (childDiscountEl && childDiscountRow) {
            childDiscountEl.textContent = children + ' children (50% discount: ' + children + ' × ₱400 × ' + nights + ' night' + (nights > 1 ? 's' : '') + ') = -₱' + childDiscount.toLocaleString();
            childDiscountRow.style.display = 'flex';
          }
        } else {
          const childDiscountRow = document.getElementById('summaryChildDiscountRow');
          if (childDiscountRow) {
            childDiscountRow.style.display = 'none';
          }
        }
        if (summaryTotalAmount) summaryTotalAmount.textContent = '₱' + totalAmount.toLocaleString();
        if (summaryDownpayment) summaryDownpayment.textContent = '₱' + downpayment.toLocaleString();
        if (summaryRemainingBalance) summaryRemainingBalance.textContent = '₱' + remainingBalance.toLocaleString();
        if (paymentSummaryGuests) paymentSummaryGuests.textContent = totalGuests + ' Guests (' + adults + ' adults, ' + children + ' children, ' + infants + ' infants)';
        if (paymentSummaryAdditionalGuests) {
          if (additionalGuests > 0) {
            paymentSummaryAdditionalGuests.textContent = additionalGuests + ' additional guests (' + additionalGuests + ' × ₱800 × ' + nights + ' night' + (nights > 1 ? 's' : '') + ') = ₱' + additionalGuestCharge.toLocaleString();
          } else {
            paymentSummaryAdditionalGuests.textContent = '₱0';
          }
        }
        if (children > 0) {
          const childDiscountEl = document.getElementById('paymentSummaryChildDiscount');
          const childDiscountRow = document.getElementById('paymentSummaryChildDiscountRow');
          if (childDiscountEl && childDiscountRow) {
            childDiscountEl.textContent = children + ' children (50% discount: ' + children + ' × ₱400 × ' + nights + ' night' + (nights > 1 ? 's' : '') + ') = -₱' + childDiscount.toLocaleString();
            childDiscountRow.style.display = 'flex';
          }
        } else {
          const childDiscountRow = document.getElementById('paymentSummaryChildDiscountRow');
          if (childDiscountRow) {
            childDiscountRow.style.display = 'none';
          }
        }
        if (paymentSummaryTotalAmount) paymentSummaryTotalAmount.textContent = '₱' + totalAmount.toLocaleString();
        if (paymentSummaryDownpayment) paymentSummaryDownpayment.textContent = '₱' + downpayment.toLocaleString();
        if (paymentSummaryRemainingBalance) paymentSummaryRemainingBalance.textContent = '₱' + remainingBalance.toLocaleString();

        // Update check-in/out dates in payment summary
        if (paymentSummaryCheckin) paymentSummaryCheckin.textContent = checkinDate + ' at 2:00 PM';
        if (paymentSummaryCheckout) paymentSummaryCheckout.textContent = checkoutDate + ' at 11:00 AM';
        if (bookingTotalAmount) bookingTotalAmount.value = totalAmount;
        const downpaymentValue = downpayment; // 50% of base rate only
        
        // Auto-fill both QR and cash amount inputs
        const amountSentInput = document.getElementById('amountSent');
        const amountSentCashInput = document.getElementById('amountSentCash');
        
        if (amountSentInput) {
          amountSentInput.placeholder = downpaymentValue.toLocaleString();
          // If QR payment is selected, auto-fill the value
          const qrRadio = document.querySelector('input[name="paymentMethod"][value="qr"]');
          if (qrRadio && qrRadio.checked) {
            amountSentInput.value = downpaymentValue;
          }
        }
        
        if (amountSentCashInput) {
          amountSentCashInput.placeholder = downpaymentValue.toLocaleString();
          // If cash payment is selected, auto-fill the value
          const cashRadio = document.querySelector('input[name="paymentMethod"][value="cash"]');
          if (cashRadio && cashRadio.checked) {
            amountSentCashInput.value = downpaymentValue;
          }
        }
      };

      // Add event listener for booking guest selection
      document.addEventListener('DOMContentLoaded', function() {
        const adultGuestsEl = document.getElementById('adultGuests');
        const childGuestsEl = document.getElementById('childGuests');
        const infantGuestsEl = document.getElementById('infantGuests');

        if (adultGuestsEl) {
          adultGuestsEl.addEventListener('change', recalculatePricing);
          adultGuestsEl.addEventListener('input', recalculatePricing);
        }
        if (childGuestsEl) {
          childGuestsEl.addEventListener('change', recalculatePricing);
          childGuestsEl.addEventListener('input', recalculatePricing);
        }
        if (infantGuestsEl) {
          infantGuestsEl.addEventListener('change', recalculatePricing);
          infantGuestsEl.addEventListener('input', recalculatePricing);
        }

        // Load QR code from database
        loadQRCode();
      });

      // Load QR code from database
      window.loadQRCode = function() {
        fetch('Page.php?action=get_qr_code')
          .then(response => response.json())
          .then(data => {
            console.log('QR Code Response:', data);
            if (data.success && data.qr_code_path) {
              const qrImg = document.querySelector('#qrCodeDisplay img');
              if (qrImg) {
                qrImg.src = data.qr_code_path;
                console.log('QR Code image src set to:', data.qr_code_path);
              }
            } else {
              console.log('No QR code found in database');
            }
          })
          .catch(error => console.error('Error loading QR code:', error));
      };

      // Next step
      window.nextStep = function(step) {
        // Validate current step before proceeding
        if (step === 2) {
          // Step 1 to 2: Validate guest details
          const name = document.getElementById('bookingName').value;
          const email = document.getElementById('bookingEmail').value;
          const phone = document.getElementById('bookingPhone').value;
          const adults = document.getElementById('adultGuests').value;
          if (!name || !email || !phone || !adults) {
            alert('Please fill in all guest details');
            return;
          }
        } else if (step === 3) {
          // Step 2 to 3: Validate policies agreement
          if (!document.getElementById('agreePolicies').checked) {
            alert('Please agree to the Terms & Conditions');
            return;
          }
        } else if (step === 4) {
          // Step 3 to 4: Validate payment
          const paymentMethod = document.querySelector('input[name="paymentMethod"]:checked');
          const amountSentInput = document.getElementById('amountSent');
          const amountSentCashInput = document.getElementById('amountSentCash');
          const paymentProof = document.getElementById('paymentProof');

          if (!paymentMethod) {
            alert('Please select a payment method');
            return;
          }

          // Get the correct amount based on payment method
          let amountSent = '';
          if (paymentMethod.value === 'qr') {
            amountSent = amountSentInput ? amountSentInput.value : '';
            if (!amountSent) {
              alert('Please enter the amount sent');
              return;
            }
            if (!paymentProof || paymentProof.files.length === 0) {
              alert('Please upload payment proof');
              return;
            }
          } else if (paymentMethod.value === 'cash') {
            // Cash payment: auto-fill with downpayment amount
            const downpaymentText = document.getElementById('paymentSummaryDownpayment')?.textContent;
            if (downpaymentText) {
              const downpaymentValue = parseFloat(downpaymentText.replace(/[₱,]/g, ''));
              if (!isNaN(downpaymentValue)) {
                amountSent = downpaymentValue;
              }
            }
            if (!amountSent) {
              alert('Unable to calculate payment amount');
              return;
            }
          }
          
          // Populate confirmation
          const confirmName = document.getElementById('confirmName');
          const confirmEmail = document.getElementById('confirmEmail');
          const confirmPhone = document.getElementById('confirmPhone');
          const confirmCheckin = document.getElementById('confirmCheckin');
          const confirmCheckout = document.getElementById('confirmCheckout');
          const confirmGuests = document.getElementById('confirmGuests');
          const confirmPaymentMethod = document.getElementById('confirmPaymentMethod');
          const confirmAmountSent = document.getElementById('confirmAmountSent');
          const confirmTotalAmount = document.getElementById('confirmTotalAmount');
          const confirmDownpayment = document.getElementById('confirmDownpayment');
          const confirmRemainingBalance = document.getElementById('confirmRemainingBalance');
          const confirmRateType = document.getElementById('confirmRateType');
          const confirmLength = document.getElementById('confirmLength');
          const confirmRequests = document.getElementById('confirmRequests');

          if (confirmName) confirmName.textContent = document.getElementById('bookingName').value;
          if (confirmEmail) confirmEmail.textContent = document.getElementById('bookingEmail').value;
          if (confirmPhone) confirmPhone.textContent = document.getElementById('bookingPhone').value;
          if (confirmCheckin) {
            const checkinEl = document.getElementById('bookingCheckin');
            if (checkinEl && checkinEl.value) {
              const checkinDate = new Date(checkinEl.value);
              const checkinFormatted = checkinDate.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
              confirmCheckin.textContent = checkinFormatted + ' at 2:00 PM';
            }
          }
          if (confirmCheckout) {
            const checkoutEl = document.getElementById('bookingCheckout');
            if (checkoutEl && checkoutEl.value) {
              const checkoutDate = new Date(checkoutEl.value);
              const checkoutFormatted = checkoutDate.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
              confirmCheckout.textContent = checkoutFormatted + ' at 11:00 AM';
            }
          }
          if (confirmGuests) {
            const adults = document.getElementById('adultGuests').value;
            const children = document.getElementById('childGuests').value;
            const infants = document.getElementById('infantGuests').value;
            const totalGuests = parseInt(adults) + parseInt(children) + parseInt(infants);
            confirmGuests.textContent = totalGuests + ' Guests (' + adults + ' adults, ' + children + ' children, ' + infants + ' infants)';
          }
          if (confirmPaymentMethod) confirmPaymentMethod.textContent = paymentMethod.value === 'qr' ? 'QR Payment' : 'Cash Payment';
          if (confirmAmountSent) confirmAmountSent.textContent = parseInt(amountSent).toLocaleString();
          if (confirmTotalAmount) confirmTotalAmount.textContent = document.getElementById('summaryTotalAmount')?.textContent || '';
          if (confirmDownpayment) confirmDownpayment.textContent = '₱' + parseInt(amountSent).toLocaleString();

          // Populate special requests
          const bookingRequests = document.getElementById('bookingRequests');
          if (confirmRequests) {
            const requestsValue = bookingRequests && bookingRequests.value.trim() ? bookingRequests.value.trim() : 'None';
            confirmRequests.textContent = requestsValue;
          }

          // Populate rate type and length
          const checkinEl = document.getElementById('bookingCheckin');
          const checkoutEl = document.getElementById('bookingCheckout');
          if (checkinEl && checkoutEl && checkinEl.value && checkoutEl.value) {
            const checkin = new Date(checkinEl.value);
            const checkout = new Date(checkoutEl.value);
            const nights = Math.ceil((checkout - checkin) / (1000 * 60 * 60 * 24));
            const checkinDay = checkin.getDay();
            const isWeekend = (checkinDay === 5 || checkinDay === 6 || checkinDay === 0);
            const rateType = isWeekend ? 'Weekend Stay' : 'Weekday Stay';
            if (confirmRateType) confirmRateType.textContent = rateType;
            if (confirmLength) confirmLength.textContent = nights + ' Night' + (nights > 1 ? 's' : '');
          }

          // Populate additional guests with accurate calculation
          const adultsEl = document.getElementById('adultGuests');
          const childrenEl = document.getElementById('childGuests');
          const infantsEl = document.getElementById('infantGuests');
          const confirmAdditionalGuests = document.getElementById('confirmAdditionalGuests');
          const confirmBaseRate = document.getElementById('confirmBaseRate');
          const confirmChildDiscount = document.getElementById('confirmChildDiscount');
          const confirmChildDiscountRow = document.getElementById('confirmChildDiscountRow');

          if (adultsEl && childrenEl && infantsEl) {
            const adults = parseInt(adultsEl.value) || 0;
            const children = parseInt(childrenEl.value) || 0;
            const infants = parseInt(infantsEl.value) || 0;
            const payingGuests = adults + children;
            const additionalGuests = payingGuests > 15 ? payingGuests - 15 : 0;

            const checkinEl = document.getElementById('bookingCheckin');
            const checkoutEl = document.getElementById('bookingCheckout');
            if (checkinEl && checkoutEl && checkinEl.value && checkoutEl.value) {
              const checkin = new Date(checkinEl.value);
              const checkout = new Date(checkoutEl.value);
              const nights = Math.ceil((checkout - checkin) / (1000 * 60 * 60 * 24));
              const checkinDay = checkin.getDay();
              const isWeekend = (checkinDay === 5 || checkinDay === 6 || checkinDay === 0);
              const baseRatePerNight = isWeekend ? 20000 : 18000;
              const additionalGuestCharge = additionalGuests * 800 * nights;
              const childDiscount = children * 400 * nights;

              if (confirmAdditionalGuests) {
                if (additionalGuests > 0) {
                  confirmAdditionalGuests.textContent = additionalGuests + ' additional guests (' + additionalGuests + ' × ₱800 × ' + nights + ' night' + (nights > 1 ? 's' : '') + ') = ₱' + additionalGuestCharge.toLocaleString();
                } else {
                  confirmAdditionalGuests.textContent = '₱0';
                }
              }

              if (children > 0 && confirmChildDiscount && confirmChildDiscountRow) {
                confirmChildDiscount.textContent = children + ' children (50% discount: ' + children + ' × ₱400 × ' + nights + ' night' + (nights > 1 ? 's' : '') + ') = -₱' + childDiscount.toLocaleString();
                confirmChildDiscountRow.style.display = 'flex';
              } else if (confirmChildDiscountRow) {
                confirmChildDiscountRow.style.display = 'none';
              }

              if (confirmBaseRate) {
                confirmBaseRate.textContent = '₱' + baseRatePerNight.toLocaleString();
              }
            }
          }

          // Calculate remaining balance
          const summaryTotalAmount = document.getElementById('summaryTotalAmount');
          const totalAmountText = summaryTotalAmount ? summaryTotalAmount.textContent.replace(/[₱,]/g, '') : '0';
          const totalAmount = parseFloat(totalAmountText) || 0;
          const downpaymentPaid = parseFloat(amountSent) || 0;
          const remainingBalance = totalAmount - downpaymentPaid;
          if (confirmRemainingBalance) confirmRemainingBalance.textContent = '₱' + remainingBalance.toLocaleString() + ' (Due 2 days before check-in)';
        }

        // Hide all steps
        document.querySelectorAll('.booking-step').forEach(s => s.style.display = 'none');
        // Show target step
        document.getElementById('step' + step).style.display = 'block';
        // Update progress
        updateStepUI(step);
        
        // Scroll to top of booking form to keep it in view
        const bookingForm = document.getElementById('multiStepBookingForm');
        if (bookingForm) {
          bookingForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      };

      // Previous step
      window.prevStep = function(step) {
        document.querySelectorAll('.booking-step').forEach(s => s.style.display = 'none');
        document.getElementById('step' + step).style.display = 'block';
        updateStepUI(step);
        
        // Scroll to top of booking form to keep it in view
        const bookingForm = document.getElementById('multiStepBookingForm');
        if (bookingForm) {
          bookingForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      };

      // Submit booking
      let isSubmitting = false;
      window.submitBooking = function() {
        // Prevent multiple submissions
        if (isSubmitting) {
          console.log('Submission already in progress');
          return;
        }
        
        isSubmitting = true;
        
        const submitBtn = event ? event.target : document.querySelector('button[onclick="submitBooking()"]');
        const originalText = submitBtn ? submitBtn.innerHTML : 'Submit';
        
        // Show loading state with spinner
        if (submitBtn) {
          submitBtn.style.transition = 'all 0.3s ease';
          submitBtn.disabled = true;
          submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i> Processing';
          submitBtn.style.opacity = '0.9';
          submitBtn.style.transform = 'scale(0.98)';
        }
        
        const formData = new FormData();
        formData.append('action', 'submit_booking');
        formData.append('name', document.getElementById('bookingName').value);
        formData.append('email', document.getElementById('bookingEmail').value);
        formData.append('phone', document.getElementById('bookingPhone').value);
        formData.append('checkin', document.getElementById('bookingCheckin').value);
        formData.append('checkout', document.getElementById('bookingCheckout').value);
        const adults = document.getElementById('adultGuests').value;
        const children = document.getElementById('childGuests').value;
        const infants = document.getElementById('infantGuests').value;
        const totalGuests = parseInt(adults) + parseInt(children) + parseInt(infants);
        formData.append('guests', totalGuests);
        formData.append('adults', adults);
        formData.append('children', children);
        formData.append('infants', infants);
        formData.append('requests', document.getElementById('bookingRequests').value);
        formData.append('total_amount', document.getElementById('bookingTotalAmount').value);
        
        const paymentMethod = document.querySelector('input[name="paymentMethod"]:checked');
        formData.append('payment_method', paymentMethod ? paymentMethod.value : '');
        
        // Get amount from correct input based on payment method
        let amountSent = '';
        if (paymentMethod && paymentMethod.value === 'qr') {
          amountSent = document.getElementById('amountSent')?.value || '';
        } else {
          amountSent = document.getElementById('amountSentCash')?.value || '';
        }
        formData.append('amount_sent', amountSent);
        formData.append('payment_notes', document.getElementById('paymentNotes').value);
        
        // Add payment proof file
        const paymentProof = document.getElementById('paymentProof');
        if (paymentProof && paymentProof.files.length > 0) {
          formData.append('payment_proof', paymentProof.files[0]);
        }

        fetch('booking.php', {
          method: 'POST',
          body: formData
        })
        .then(response => {
          if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.status);
          }
          return response.json();
        })
        .then(data => {
          console.log('Booking response:', data);
          
          if (data.success) {
            // Fade out all booking steps
            const allSteps = document.querySelectorAll('.booking-step');
            allSteps.forEach(step => {
              step.style.transition = 'opacity 0.5s ease';
              step.style.opacity = '0';
            });
            
            // After fade out, hide steps and show success
            setTimeout(() => {
              allSteps.forEach(s => s.style.display = 'none');
              
              const stepSuccess = document.getElementById('stepSuccess');
              if (stepSuccess) {
                stepSuccess.style.display = 'block';
                stepSuccess.style.opacity = '0';
                stepSuccess.style.transition = 'opacity 0.5s ease';
                setTimeout(() => {
                  stepSuccess.style.opacity = '1';
                  // Scroll to success message
                  stepSuccess.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 50);
              }
              
              const successBookingId = document.getElementById('successBookingId');
              if (successBookingId) successBookingId.textContent = data.booking_id;
              
              // Hide progress steps
              const bookingSteps = document.querySelector('.booking-steps');
              if (bookingSteps) {
                bookingSteps.style.transition = 'opacity 0.5s ease';
                bookingSteps.style.opacity = '0';
                setTimeout(() => {
                  bookingSteps.style.display = 'none';
                }, 500);
              }
              
              // Reset calendar selection
              landingCalendarState.selectedCheckin = null;
              landingCalendarState.selectedCheckout = null;
              renderLandingCalendar();
              updateLandingDateSummary();
            }, 500);
          } else {
            // Reset button state on error with smooth transition
            if (submitBtn) {
              submitBtn.style.transition = 'all 0.3s ease';
              submitBtn.disabled = false;
              submitBtn.innerHTML = originalText;
              submitBtn.style.opacity = '1';
              submitBtn.style.transform = 'scale(1)';
            }
            isSubmitting = false;
            alert(data.message || 'Failed to submit booking');
          }
        })
        .catch(error => {
          console.error('Error submitting booking:', error);
          // Reset button state with smooth transition
          if (submitBtn) {
            submitBtn.style.transition = 'all 0.3s ease';
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            submitBtn.style.opacity = '1';
            submitBtn.style.transform = 'scale(1)';
          }
          isSubmitting = false;
          alert('Failed to submit booking: ' + error.message);
        });
      };

      // Initialize landing calendar on page load
      initLandingCalendar();

      // Load refund policy
      loadRefundPolicy();

      // FAQ Toggle Function
      window.toggleFAQ = function(element) {
        const answer = element.nextElementSibling;
        const icon = element.querySelector('.faq-icon');

        // Close all other FAQs
        document.querySelectorAll('.faq-answer').forEach(item => {
          if (item !== answer) {
            item.style.maxHeight = null;
            item.classList.remove('active');
            item.previousElementSibling.querySelector('.faq-icon').classList.remove('active');
          }
        });

        // Toggle current FAQ
        if (answer.style.maxHeight) {
          answer.style.maxHeight = null;
          answer.classList.remove('active');
          icon.classList.remove('active');
        } else {
          answer.style.maxHeight = answer.scrollHeight + 'px';
          answer.classList.add('active');
          icon.classList.add('active');
        }
      };
    })();
  </script>

  <!-- ——— FLOATING FAQ WIDGET (type your concern) ——— -->
  <div class="faq-floating" id="faqFloating">
    <div class="faq-panel" id="faqPanel" role="dialog" aria-label="FAQ assistant">
      <div class="faq-header">
        <h3><i class="fas fa-comment-dots"></i> Ask a question</h3>
        <button class="faq-close" id="faqCloseBtn" aria-label="Close FAQ panel">
          <i class="fas fa-times"></i>
        </button>
      </div>

      <!-- chat / conversation -->
      <div class="faq-chat" id="faqChat">
        <!-- initial bot greeting is injected by JS -->
      </div>

      <!-- quick suggestions -->
      <div class="faq-suggestions" id="faqSuggestions">
        <button class="faq-chip" data-q="How do I book a stay?">How do I book?</button>
        <button class="faq-chip" data-q="What is the cancellation policy?">Cancellation policy</button>
        <button class="faq-chip" data-q="What is the security deposit?">Security deposit</button>
        <button class="faq-chip" data-q="Can I bring my pet?">Pets allowed?</button>
        <button class="faq-chip" data-q="What are the check-in times?">Check-in times</button>
        <button class="faq-chip" data-q="How many guests can stay?">Guest capacity</button>
        <button class="faq-chip" data-q="What are the pool rules?">Pool rules</button>
        <button class="faq-chip" data-q="Is smoking allowed?">Smoking policy</button>
        <button class="faq-chip" data-q="How will I know if my booking is approved?">Booking approval</button>
        <button class="faq-chip" data-q="What are the age-based pricing rates?">Age-based pricing</button>
      </div>

      <!-- input -->
      <div class="faq-input-area">
        <input 
          type="text" 
          class="faq-input" 
          id="faqInput" 
          placeholder="Type your concern..." 
          autocomplete="off"
          aria-label="Type your question"
        />
        <button class="faq-send" id="faqSend" aria-label="Send question">
          <i class="fas fa-paper-plane"></i>
        </button>
      </div>
    </div>

    <button class="faq-trigger" id="faqTrigger" aria-label="Open FAQ" aria-expanded="false">
      <i class="fas fa-question"></i> FAQs
    </button>
  </div>

  <script>
  (function() {
    const floating = document.getElementById('faqFloating');
    const trigger = document.getElementById('faqTrigger');
    const closeBtn = document.getElementById('faqCloseBtn');
    const panel = document.getElementById('faqPanel');
    const chat = document.getElementById('faqChat');
    const input = document.getElementById('faqInput');
    const sendBtn = document.getElementById('faqSend');
    const suggestions = document.getElementById('faqSuggestions');

    /* -----------------------------------------------------------
       Knowledge base — add / edit Q&A pairs here.
       Matching is keyword-based (case-insensitive).
       ----------------------------------------------------------- */
    const knowledgeBase = [
      {
        keywords: ['book', 'booking', 'reserve', 'reservation', 'how to book'],
        answer: 'A reservation is confirmed only after required payment is received and booking is confirmed by Solendra Samal through email. Ensure all information (guests, dates, contact details) is accurate. Rates vary by date, guests, holidays, weekends, and booking platform. 🏠'
      },
      {
        keywords: ['approved', 'approval', 'confirmation', 'status', 'know', 'confirmed'],
        answer: 'Once your booking has been reviewed and approved, you will receive a confirmation email containing your booking details and confirmation status. You can also check your booking status through your account dashboard. ✅'
      },
      {
        keywords: ['cancel', 'cancellation', 'refund', 'policy'],
        answer: 'Deposits are NON-REFUNDABLE and will be forfeited if cancellation is pursued. However, reservations are RE-BOOKABLE within 1 year from the date of booking. No-shows and early check-outs are not entitled to refunds. Date changes subject to availability and rate differences. ✅'
      },
      {
        keywords: ['utilities', 'wifi', 'wi-fi', 'electricity', 'heating', 'included', 'amenities'],
        answer: 'Yes — Solendra Samal includes Wi-Fi, electricity, and basic utilities. Amenities include infinity pool, karaoke room, sports facilities (pickleball, badminton, volleyball, basketball), billiards, ping-pong, air hockey, and more. Guests are responsible for proper use of all amenities. ⚡'
      },
      {
        keywords: ['pet', 'pets', 'dog', 'cat', 'animal'],
        answer: 'Pets are NOT allowed on the property. This applies to all indoor and outdoor areas unless management provides prior written approval. Unauthorized pets may require removal and additional cleaning/damage charges. 🐾'
      },
      {
        keywords: ['contact', 'host', 'message', 'reach', 'talk'],
        answer: 'Contact us through the booking system or use the contact section on our website. For urgent matters during your stay, property management is available. Management reserves the right to enter property for emergencies, safety concerns, or maintenance. 💬'
      },
      {
        keywords: ['check in', 'check-in', 'checkin', 'arrival', 'arrive'],
        answer: 'Check-in is at 2:00 PM. Early check-in may be requested but is subject to availability and may not always be possible due to cleaning and preparation. Check-out is at 11:00 AM. Late check-out requires advance approval and may incur additional charges. 🔑'
      },
      {
        keywords: ['price', 'cost', 'fee', 'fees', 'expensive', 'cheap', 'rate'],
        answer: 'Rates vary by date, guests, holidays, weekends, promotions, and booking platform. Standard rate accommodates up to 15 guests. Additional guests may be accommodated subject to availability and fees. Maximum occupancy is 30 guests unless approved in writing. Security deposit is ₱5,000. 💰'
      },
      {
        keywords: ['review', 'reviews', 'rating', 'ratings'],
        answer: 'Guests can leave reviews after their stay. All reviews are subject to approval before being displayed. You can read past guest reviews on our website to help you decide. ⭐'
      },
      {
        keywords: ['payment', 'pay', 'credit card', 'card', 'qr', 'deposit'],
        answer: 'We accept QR code payment and cash payment. A 50% downpayment is required to confirm your booking, with the remaining balance due 2 days before check-in. Deposits are NON-REFUNDABLE but RE-BOOKABLE within 1 year from the date of booking. 💳'
      },
      {
        keywords: ['security', 'deposit', 'damage', 'charge'],
        answer: 'A ₱5,000 refundable security deposit is required, payable before or upon check-in. It covers damages, missing items, excessive cleaning, unauthorized guests, rule violations, or other costs. Returned after checkout and property inspection if no outstanding charges. Guest responsible for costs exceeding deposit amount. 🔒'
      },
      {
        keywords: ['guest', 'capacity', 'people', 'occupancy', 'extra'],
        answer: 'Guest count must match reservation. Standard rate accommodates up to 15 guests. Additional guests subject to availability and fees. Maximum occupancy is 30 guests unless approved in writing. Undeclared overnight guests not permitted. Visitors require prior approval. 👥'
      },
      {
        keywords: ['age', 'child', 'children', 'kid', 'kids', 'infant', 'infants', 'baby', 'babies', 'discount', 'rate', 'pricing'],
        answer: 'Our pricing is based on age categories: Free - 5 years old and below; 50% discount - 6 to 11 years old; Adult rate - 12 and above. Children and infants count toward occupancy limits. The system automatically calculates discounts based on the number of guests in each age category. 👶'
      },
      {
        keywords: ['noise', 'quiet', 'hours', 'music', 'loud'],
        answer: 'Quiet hours are 10:00 PM to 7:00 AM. During these hours, significantly reduce music volume, shouting, and disruptive activities. Excessive noise affecting neighbors is prohibited. Repeated complaints may require activity to stop. 🔇'
      },
      {
        keywords: ['pool', 'swimming', 'swim', 'water'],
        answer: 'Pool rules: Children must be supervised by adults at all times. No running around pool area. No glass containers. Do not enter while intoxicated. Follow posted safety rules. Guests use pool at their own risk. Damage to pool facilities may be charged. 🏊'
      },
      {
        keywords: ['sports', 'court', 'game', 'activities'],
        answer: 'Multipurpose court available for pickleball, badminton, volleyball, basketball. Equipment must remain on property unless permitted. Guests responsible for returning equipment. Damaged/missing equipment charged to guest. Use appropriate footwear and avoid damaging activities. 🏀'
      },
      {
        keywords: ['smoke', 'smoking', 'vape', 'vaping', 'cigarette'],
        answer: 'Smoking and vaping are NOT permitted inside house, bedrooms, karaoke room, or enclosed areas. Only allowed in designated outdoor areas. Damage, odor removal, or additional cleaning from indoor smoking may be charged. 🚭'
      },
      {
        keywords: ['clean', 'cleaning', 'mess', 'trash'],
        answer: 'Guests expected to maintain reasonable cleanliness. Dispose of trash properly, avoid inappropriate items in toilets/drains, use appliances as intended, keep food/drinks away from equipment, return items to original locations. Excessive mess may result in additional cleaning charges. 🧹'
      },
      {
        keywords: ['hello', 'hi', 'hey', 'help'],
        answer: 'Hi there! 👋 I\'m here to help with any questions about Solendra Samal. You can type your concern below or tap one of the suggestions. How can I assist?'
      }
    ];

    const fallbackAnswer = 'I\'m not sure about that one. 🤔 Try asking about <strong>booking</strong>, <strong>cancellation</strong>, <strong>security deposit</strong>, <strong>pets</strong>, <strong>check-in times</strong>, <strong>guest capacity</strong>, <strong>pool rules</strong>, <strong>smoking policy</strong>, <strong>noise rules</strong>, <strong>sports facilities</strong>, or <strong>cleaning</strong>. You can also type your question differently.';

    /* -----------------------------------------------------------
       UI helpers
       ----------------------------------------------------------- */

    function scrollChatToBottom() {
      requestAnimationFrame(() => {
        chat.scrollTop = chat.scrollHeight;
      });
    }

    function addMessage(text, sender) {
      const msg = document.createElement('div');
      msg.className = `faq-msg ${sender}`;

      const avatar = document.createElement('div');
      avatar.className = `faq-avatar ${sender}`;
      avatar.innerHTML = sender === 'bot' 
        ? '<i class="fas fa-robot"></i>' 
        : '<i class="fas fa-user"></i>';

      const bubble = document.createElement('div');
      bubble.className = `faq-bubble ${sender}`;
      bubble.innerHTML = text;

      msg.appendChild(avatar);
      msg.appendChild(bubble);
      chat.appendChild(msg);
      scrollChatToBottom();
    }

    function showTyping() {
      const typing = document.createElement('div');
      typing.className = 'faq-typing';
      typing.id = 'faqTyping';
      typing.innerHTML = `
        <div class="faq-avatar bot"><i class="fas fa-robot"></i></div>
        <div class="faq-bubble bot">
          <span class="dot"></span>
          <span class="dot"></span>
          <span class="dot"></span>
        </div>
      `;
      chat.appendChild(typing);
      scrollChatToBottom();
    }

    function hideTyping() {
      const typing = document.getElementById('faqTyping');
      if (typing) typing.remove();
    }

    /* Find the best answer for a user query */
    function findAnswer(query) {
      const q = query.toLowerCase().trim();
      if (!q) return null;

      let bestMatch = null;
      let bestScore = 0;

      for (const entry of knowledgeBase) {
        let score = 0;
        for (const keyword of entry.keywords) {
          if (q.includes(keyword.toLowerCase())) {
            // longer keyword matches are more specific → higher score
            score += keyword.length;
          }
        }
        if (score > bestScore) {
          bestScore = score;
          bestMatch = entry;
        }
      }

      return bestMatch ? bestMatch.answer : fallbackAnswer;
    }

    /* Handle sending a question */
    function handleSend(text) {
      const query = (text !== undefined ? text : input.value).trim();
      if (!query) return;

      // Add user message
      addMessage(query, 'user');
      input.value = '';
      sendBtn.disabled = true;

      // Show typing indicator
      showTyping();

      // Simulate thinking delay, then reply
      const delay = 600 + Math.random() * 600;
      setTimeout(() => {
        hideTyping();
        const answer = findAnswer(query);
        addMessage(answer, 'bot');
        sendBtn.disabled = false;
        input.focus();
      }, delay);
    }

    /* -----------------------------------------------------------
       Event listeners
       ----------------------------------------------------------- */

    // Toggle panel
    function toggleFaq(open) {
      if (open === undefined) {
        floating.classList.toggle('open');
      } else if (open) {
        floating.classList.add('open');
      } else {
        floating.classList.remove('open');
      }
      const isOpen = floating.classList.contains('open');
      trigger.setAttribute('aria-expanded', isOpen);

      if (isOpen) {
        setTimeout(() => input.focus(), 250);
        scrollChatToBottom();
      } else {
        trigger.focus();
      }
    }

    trigger.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleFaq();
    });

    closeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleFaq(false);
    });

    document.addEventListener('click', (e) => {
      if (!floating.classList.contains('open')) return;
      if (!floating.contains(e.target)) toggleFaq(false);
    });

    panel.addEventListener('click', (e) => e.stopPropagation());

    // Send button
    sendBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      handleSend();
    });

    // Enter key in input
    input.addEventListener('keydown', (e) => {
      e.stopPropagation();
      if (e.key === 'Enter') {
        e.preventDefault();
        handleSend();
      }
    });

    // Suggestion chips
    suggestions.addEventListener('click', (e) => {
      const chip = e.target.closest('.faq-chip');
      if (!chip) return;
      e.stopPropagation();
      const q = chip.getAttribute('data-q');
      if (q) handleSend(q);
    });

    // Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && floating.classList.contains('open')) {
        toggleFaq(false);
      }
    });

    /* -----------------------------------------------------------
       Init
       ----------------------------------------------------------- */
    floating.classList.remove('open');
    trigger.setAttribute('aria-expanded', 'false');

    // Welcome message
    addMessage('Hi there! 👋 I\'m your Solendra Samal assistant. I can help with booking, cancellation policy, security deposit, house rules, amenities, and more. Type your concern below or pick a suggestion — I\'ll do my best to help.', 'bot');
  })();
  </script>
</body>
</html>