<?php
session_start();
require_once '../config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Frequently Asked Questions - Solendra Samal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,600;14..32,700&family=Manrope:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../Css/Page.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
  <div id="progress-bar"></div>
  <nav class="navbar" id="navbar">
    <a href="Page.php#home" class="logo">Solendra<span>Samal</span></a>
    <ul class="nav-links" id="navLinks">
      <li><a href="Page.php#home">HOME</a></li>
      <li><a href="Page.php#about">ABOUT</a></li>
      <li><a href="Page.php#gallery">GALLERY</a></li>
      <li><a href="Page.php#amenities">AMENITIES</a></li>
      <li><a href="Page.php#location">LOCATION</a></li>
      <li><a href="Page.php#reviews">REVIEWS</a></li>
      <li><a href="Page.php#contact">CONTACT</a></li>
    </ul>
    <div class="nav-right">
     <a href="Page.php#booking" class="btn-primary">Book Now <i class="fas fa-arrow-right"></i></a>
      <div class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></div>
    </div>
  </nav>

  <!-- FAQ Page Header -->
  <section class="faq-page-header">
    <div class="faq-header-content">
      <h1 class="faq-page-title">Frequently Asked Questions</h1>
      <p class="faq-page-subtitle">Find answers to common questions about your stay at Solendra Samal</p>
    </div>
  </section>

  <!-- FAQ Content -->
  <section class="faq-page-content">
    <div class="faq-page-container">
      
      <!-- Booking & Reservations -->
      <div class="faq-category">
        <h3 class="faq-category-title">Booking & Reservations</h3>
        
        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">What time is check-in and check-out?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Check-in is at 2:00 PM and check-out is at 11:00 AM. Early check-in may be requested subject to availability. Late check-out must be approved in advance and unauthorized late check-out may result in additional charges.</p>
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">What is the cancellation policy?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Deposits are non-refundable and will be forfeited if cancellation is pursued. However, the reservation is re-bookable within 1 year from the date of booking. Failure to arrive on the scheduled check-in date without prior notice is considered a no-show and is non-refundable. Guests who voluntarily leave before the end of their reservation are not entitled to a refund for unused nights.</p>
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">Can I change my booking dates?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Date changes are subject to availability and approval. Any difference in rates caused by a date change must be paid by the guest. Requests made within 7 days of check-in may be treated as a cancellation and new reservation.</p>
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">How is my booking confirmed?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>A reservation is confirmed only after the required payment/deposit is received and the booking is confirmed by Solendra Samal through email. The person making the reservation is responsible for accurate information, including number of guests, dates, and contact details.</p>
          </div>
        </div>
      </div>

      <!-- Guest Capacity -->
      <div class="faq-category">
        <h3 class="faq-category-title">Guest Capacity</h3>
        
        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">How many guests can stay?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>The standard rate comfortably accommodates up to 15 guests. Maximum permitted occupancy is 30 guests unless otherwise approved in writing by management. Additional guests may be accommodated depending on availability and applicable fees.</p>
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">Can I bring extra guests?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Undeclared overnight guests are not allowed. Visitors not included in the reservation require prior management approval. Management may refuse entry to guests exceeding the approved maximum capacity.</p>
          </div>
        </div>
      </div>

      <!-- Policies & Rules -->
      <div class="faq-category">
        <h3 class="faq-category-title">Policies & Rules</h3>
        
        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">Is a security deposit required?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Yes, a ₱5,000 refundable security deposit is required before or upon check-in. The deposit covers damages, missing items, excessive cleaning, unauthorized guests, house-rule violations, or other costs resulting from the stay.</p>
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">Is the security deposit refundable?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Yes, the deposit is returned after check-out and property inspection if there are no outstanding charges or damages. If damages or other charges exceed ₱5,000, the guest must pay the remaining balance.</p>
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">Are pets allowed?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>No, pets are not allowed on the property. This applies to indoor and outdoor areas unless management provides prior written approval. Unauthorized pets may need to be removed, and additional cleaning, damage, or other resulting costs may be charged to the guest.</p>
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">Are smoking and vaping allowed?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Smoking and vaping are not permitted inside the house, bedrooms, karaoke room, or other enclosed areas. Smoking and vaping are only allowed in designated outdoor areas, where applicable. Damage, odor removal, or additional cleaning caused by smoking or vaping in prohibited areas may be charged to the guest.</p>
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">What are the quiet hours?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Quiet hours are from 10:00 PM to 7:00 AM. During quiet hours, guests must significantly reduce music volume, shouting, and other disruptive activities. Excessive noise, shouting, loud music, or disturbances affecting neighboring properties are prohibited. Repeated or serious noise complaints may result in management requiring the activity to stop.</p>
          </div>
        </div>
      </div>

      <!-- Amenities & Facilities -->
      <div class="faq-category">
        <h3 class="faq-category-title">Amenities & Facilities</h3>
        
        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">What are the swimming pool rules?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Children must be supervised by a responsible adult at all times. Running around the pool area is prohibited. Glass containers are not allowed in or around the pool. Guests should not enter the pool while intoxicated. Guests must follow posted pool rules and safety instructions. Guests use the swimming pool at their own risk.</p>
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">What are the karaoke and entertainment rules?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Guests must use all equipment responsibly and follow management instructions. Karaoke, billiards, ping-pong, air hockey, and other entertainment facilities are available for guest use. Damage caused by misuse, negligence, or intentional acts may be charged to the guest.</p>
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">What are the sports and multipurpose court rules?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>The multipurpose court may be used for pickleball, badminton, volleyball, basketball, and other activities, subject to availability and proper use. Sports equipment provided by Solendra Samal must remain on the property unless management gives permission. Guests must return all equipment after use. Damaged or missing equipment may be charged to the responsible guest.</p>
          </div>
        </div>
      </div>

      <!-- Property & Damages -->
      <div class="faq-category">
        <h3 class="faq-category-title">Property & Damages</h3>
        
        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">What am I responsible for if something gets damaged?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Guests are responsible for the property's furnishings, equipment, appliances, amenities, and other provided items. You may be charged for damaged or broken furniture, appliances, fixtures, or equipment; lost or damaged sports equipment; lost keys, remotes, access cards; swimming pool or pool equipment damage; damage to entertainment equipment; damage to the multipurpose court or sports equipment; stained, burned, or excessively damaged linens, mattresses, towels, or furniture; missing property; excessive cleaning; or any other damage caused by the guest or their group.</p>
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">What activities are prohibited?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>The following are strictly prohibited: illegal drugs or illegal activities; fighting or violent behavior; theft or intentional property damage; unauthorized parties or events; firearms or other prohibited weapons; activities creating an unreasonable safety risk; unauthorized commercial or promotional activities; and activities violating Philippine laws or local regulations. Management may immediately terminate a guest's stay without refund for serious violations or when safety is at risk.</p>
          </div>
        </div>
      </div>

      <!-- Children & Safety -->
      <div class="faq-category">
        <h3 class="faq-category-title">Children & Safety</h3>
        
        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">Are children allowed?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Yes, children are allowed. Parents, guardians, and accompanying adults are responsible for supervising children and minors. Extra care is required around the swimming pool, multipurpose court, stairs, bunk beds, kitchen equipment, electrical equipment, and other potentially hazardous areas.</p>
          </div>
        </div>
      </div>

      <!-- Personal Belongings & Liability -->
      <div class="faq-category">
        <h3 class="faq-category-title">Personal Belongings & Liability</h3>
        
        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">Am I responsible for my personal belongings?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Yes, guests are responsible for their personal belongings. Solendra Samal is not responsible for lost, stolen, or unattended personal belongings. Guests should secure valuable items and properly secure doors and access points when leaving the property.</p>
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFAQ(this)">
            <span class="faq-question-text">What are the risks of using amenities?</span>
            <i class="fas fa-chevron-down faq-icon"></i>
          </div>
          <div class="faq-answer">
            <p>Guests use the swimming pool, sports court, sports equipment, entertainment facilities, and other amenities at their own risk. Guests are responsible for themselves and members of their group. Guests should exercise appropriate care when using the property. Solendra Samal will take reasonable measures to maintain facilities in a safe and functional condition. Recreational activities involve inherent risks.</p>
          </div>
        </div>
      </div>

    </div>
  </section>

  <!-- Back to Home -->
  <div class="faq-back-home">
    <a href="Page.php#home" class="btn-back-home">
      <i class="fas fa-arrow-left"></i> Back to Home
    </a>
  </div>

  <!-- Footer -->
  <footer class="footer">
    <div class="footer-inner">
      <div><span class="footer-logo">SolendraSamal<span class="footer-logo-light">·Villa</span></span><p class="footer-tagline">Premium private retreats.</p></div>
      <div><h4 class="footer-heading">Quick Links</h4><a href="Page.php#about">About</a><br><a href="Page.php#gallery">Gallery</a><br><a href="Page.php#booking">Booking</a></div>
      <div><h4 class="footer-heading">Connect</h4><div class="socials"><a href="#"><i class="fab fa-facebook"></i></a><a href="#"><i class="fab fa-instagram"></i></a><a href="#"><i class="fab fa-whatsapp"></i></a></div><p class="footer-copyright">© 2026 SolendraSamal Villa</p></div>
    </div>
    <div class="back-top"><a href="#" class="back-top-link"><i class="fas fa-arrow-up"></i> Back to top</a></div>
  </footer>

  <script>
    // FAQ Toggle Function
    function toggleFAQ(element) {
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
    }

    // Navbar scroll effect
    window.addEventListener('scroll', function() {
      const navbar = document.getElementById('navbar');
      if (window.scrollY > 50) {
        navbar.classList.add('scrolled');
      } else {
        navbar.classList.remove('scrolled');
      }
    });

    // Mobile menu toggle
    document.getElementById('menuToggle').addEventListener('click', function() {
      document.getElementById('navLinks').classList.toggle('active');
    });
  </script>
</body>
</html>
