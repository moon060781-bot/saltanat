# Project Memory — Saltanat News Karachi

## مستقل سیاق

یہ project `saltanatnewskarachi.com.pk` کے لیے ہے۔ یہ ہفتہ وار اردو PDF اخبار کی website ہے، جس کا نیا شمارہ ہر سوموار شائع ہوتا ہے۔ بنیادی ادارتی شناخت: **سلطنت نیوز کراچی**، **سلطنت میڈیا گروپ**، **کلاسک وینس نیوز ایجنسی**، اور **سید محمد ایاز اسلم — بانی، چیف ایڈیٹر اور پبلشر**۔

## تکنیکی فیصلہ

Website plain static HTML/CSS/JavaScript ہے تاکہ cPanel اور FTP پر آسانی سے چل سکے۔ تازہ اور پرانے شماروں کی فہرست `media/issues.json` میں محفوظ ہے۔ پہلی entry تازہ شمارہ ہے۔ `admin.php` PDF upload + archive update کے لیے optional workflow ہے اور `deploy.php` مستقبل کی محفوظ Git deployment automation کا starter ہے۔

## حساسیت اور حدود

Credential values، hosting access، domain logins، personal identity numbers، passwords، API keys اور private file paths کسی بھی public file یا AI output میں شامل نہیں کیے جائیں گے۔ Credentials public document root سے باہر رہیں گے۔

## اگلی بار یاد رکھنے کی باتیں

نیا PDF ملنے پر پہلے `media/` میں موجود file name confirm کریں، پھر `issues.json` میں entry شامل یا `admin.php` سے upload کریں، اور آخر میں mobile/desktop viewer check کریں۔ UI کو سادہ، cream-and-navy، Urdu-first اور PDF-first رکھیں۔
