# Project Requirement Document — سلطنت نیوز کراچی

## مقصد

سلطنت نیوز کراچی کے ہفتہ وار چار صفحات کے اردو اخبار کو ایک ہلکی، قابلِ اعتماد اور mobile-friendly ویب سائٹ پر شائع کرنا، جہاں تازہ شمارہ فوراً پڑھا جا سکے اور پرانے شمارے تاریخ کے حساب سے دستیاب رہیں۔

| ضرورت | قبولیت کا معیار |
|---|---|
| تازہ شمارہ | `issues.json` کی پہلی entry viewer میں خودکار دکھے |
| آرکائیو | ڈراپ ڈاؤن سے ہر سابقہ entry کا PDF بدلا جا سکے |
| responsive تجربہ | viewer موبائل اور desktop دونوں پر واضح رہے |
| ادارتی معلومات | نام، ادارہ، founder/publisher/editor اور weekly schedule نمایاں ہوں |
| آسان update | FTP سے PDF + ایک JSON entry، یا `admin.php` سے ایک upload |
| تحفظ | credentials web root سے باہر، admin password hash اور PHP session کے ساتھ |

## دائرۂ کار سے باہر

اس ابتدائی مرحلے میں خبریں database میں محفوظ کرنا، user registration، comments، PDF editing، advertisement billing، اور live Git command execution شامل نہیں ہیں۔
