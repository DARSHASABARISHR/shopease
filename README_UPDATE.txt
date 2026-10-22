SHOPEASE UPDATE: Analytics Charts + Pincode Auto-fill + WhatsApp OTP
=====================================================================

STEP 1  Run the SQL (ONCE)   phpMyAdmin -> database "shopease" -> SQL tab -> paste sql/analytics_whatsapp_update.sql -> Go
STEP 2  Copy this "shopease" folder into C:\xampp\htdocs\  (choose Replace)
STEP 3  (Optional, for WhatsApp) add the Twilio block below to the BOTTOM of config\config.php
        (config.php is NOT in this zip so your Gmail / Razorpay keys are not overwritten)

----- paste at the bottom of config/config.php ---------------------------------
define('WHATSAPP_ENABLED',     true);
define('TWILIO_ACCOUNT_SID',   'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');   // Twilio Console -> Account SID
define('TWILIO_AUTH_TOKEN',    'your_auth_token_here');                 // Twilio Console -> Auth Token (click "show")
define('TWILIO_WHATSAPP_FROM', '+14155238886');                         // sandbox number shown in the console
define('TWILIO_SANDBOX_JOIN',  'join your-sandbox-code');               // exact "join xxx-xxx" phrase from the sandbox page
define('WHATSAPP_COUNTRY_CODE','91');
// define('TWILIO_CONTENT_SID', 'HXxxxxxxxx');   // production only: approved authentication template
---------------------------------------------------------------------------------

TWILIO SANDBOX SETUP
1. Sign up free at https://www.twilio.com/try-twilio  (verify your email + phone).
2. Console -> Messaging -> Try it out -> "Send a WhatsApp message".
3. The page shows the sandbox number (+1 415 523 8886) and a join phrase like "join happy-tiger".
4. On the WhatsApp of EACH phone you want to test with, send that exact phrase to the sandbox number.
   Twilio replies "You are all set". (The join lasts 72 hours; send it again when it expires.)
5. Console home -> copy Account SID and Auth Token into the config block above.
6. Register / sign in with that same phone number.

TROUBLESHOOTING
- "number has not joined the sandbox"  -> do step 4 for that phone.
- "SSL certificate problem" on XAMPP/Windows -> download https://curl.se/ca/cacert.pem to C:\xampp\php\extras\ssl\ ,
  in C:\xampp\php\php.ini set:  curl.cainfo = "C:\xampp\php\extras\ssl\cacert.pem"  then restart Apache.
- Nothing happens / "cURL disabled" -> php.ini: make sure  extension=curl  is not commented out.
- Charts empty -> make sure the SQL in STEP 1 ran; place a few orders.
- Pincode not filling -> needs internet; if the API is down you can type city/state manually.
