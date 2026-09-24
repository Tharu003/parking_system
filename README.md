# 🚗 Smart Parking Management System (Upgraded + PWA)

A comprehensive Web-based Parking Management System with Progressive Web App (PWA) support, multi-role authentication (Admin, Security, VIP), and real-time report generation. Built using PHP, MySQL, JavaScript, and HTML5/CSS3.

---

## ✨ Features

- 📱 **Progressive Web App (PWA) Support:** Offline capabilities via Service Worker (`sw.js`) and installable app experience across platforms.
- 🔑 **Multi-Role Authentication:** Separate authentication and dashboards for Admins and Security personnel.
- 🎟️ **Vehicle Entry & Ticket Generation:** Register incoming vehicles and print thermal/digital tickets.
- 🏎️ **VIP Requests & Allocations:** Dedicated workflow for VIP parking requests and approvals.
- 🔍 **Vehicle Investigation:** Quickly search and audit parked or exited vehicles.
- 📊 **Reports & Analytics:** Exportable administrative reports tracking parking usage and revenue.
- 🛡️ **Security Features:** Password recovery (`forgot_password`), session handling, and database-level security policies.

---

## 🛠️ Tech Stack

- **Frontend:** HTML5, CSS3, JavaScript (ES6+), PWA (`manifest.json`, Service Workers)
- **Backend:** PHP 7.4+ / PHP 8.x
- **Database:** MySQL / MariaDB
- **Icons & UI:** Custom App Icons & Responsive Web Layout

---

## 📂 Project Structure

```text
parking_system_upgraded/
├── config/
│   ├── db.php                     # Database connection configuration
│   └── auth.php                   # Authentication helper functions
├── icons/                         # PWA & Web icons
│   ├── favicon-48.png
│   ├── icon-192.png
│   ├── icon-512.png
│   ├── icon-maskable-512.png
│   └── apple-touch-icon.png
├── includes/
│   ├── header.php                 # Global layout header
│   ├── footer.php                 # Global layout footer
│   └── pwa.php                    # PWA integration helper
├── admin_dashboard.php            # Main Admin Panel
├── admin_login.php                # Admin login interface
├── admin_register.php             # Admin registration interface
├── admin_reports.php              # Administrative reporting tool
├── admin_security_users.php       # Security staff management
├── admin_vip_requests.php         # VIP parking request approvals
├── dashboard.php                  # User/Staff main dashboard
├── exit.php                       # Vehicle checkout & billing
├── index.php                      # Application entry point
├── manifest.json                  # Web App Manifest for PWA
├── sw.js                          # Service Worker for offline caching
├── offline.html                   # Offline fallback page
├── print_ticket.php               # Entry ticket printer page
├── security_register.php          # Security staff registration
├── vehicle_investigation.php      # Audit and search panel
├── vip_request.php                # Request VIP parking allocation
├── parking_db_full_final.sql      # Database schema (complete)
└── parking_db_auth_v2.sql         # Auth patch/schema updates
