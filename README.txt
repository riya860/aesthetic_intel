AESTHETIC INTEL — HOSTINGER INSTALLATION
Version 1.0.0

PACKAGE CONTENT
- 55 packaged files in 13 subfolders.
- Public homepage and secure login.
- Super-admin business and user management.
- Multiple users per business.
- Protected 11-file Boulevard upload centre.
- CSV validation using the real supplied Boulevard formats.
- Automatic previous-period comparison.
- Light and dark interface modes.
- Interactive infographic-style dashboard and charts.
- Provider, revenue, retail, membership, financial, and operational reporting.
- Rule-based business insights and email digest copy.
- Browser-generated compressed PDF export targeting approximately 3 MB when practical.

HOSTINGER INSTALLATION
1. Create a beta subdomain or choose the intended folder in Hostinger.
2. Upload the ZIP and extract it so index.php is inside that folder's public_html/document root.
3. Create one MariaDB database and database user in Hostinger.
4. Open https://YOUR-DOMAIN/install.php.
5. Enter the MariaDB details and create the first super-admin login.
6. Sign in, add a business, and create one or more users for that business.
7. Log in as a business user and upload all 11 Boulevard CSV exports.

SERVER REQUIREMENTS
- PHP 8.1 or newer
- MariaDB/MySQL
- PDO MySQL, JSON, fileinfo, and file uploads enabled
- HTTPS strongly recommended
- Browser internet access for the pinned Chart.js, html2canvas, and jsPDF CDN scripts

IMPORTANT
- Uploaded CSV files stay inside the protected storage folder.
- Do not upload the sample Boulevard CSV files into the project folder.
- install.php locks itself after installation by detecting config/database.php and storage/install.lock.
- Keep the ZIP as a clean backup before making live changes.
- A final Hostinger/live-browser test is necessary because hosting configuration cannot be fully reproduced in the build environment.
