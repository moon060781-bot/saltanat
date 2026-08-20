# سلطنت نیوز کراچی — فوری استعمال کی ہدایات

یہ فولڈر **cPanel/FTP پر اپ لوڈ کرنے کے لیے تیار** سادہ اخبار ویب سائٹ ہے۔ ہر سوموار کا PDF شمارہ موبائل اور ڈیسک ٹاپ دونوں پر فوراً دکھایا جا سکتا ہے۔

## ہفتہ وار اپ ڈیٹ

FTP workflow میں نیا PDF `media/` میں اپ لوڈ کریں اور `media/issues.json` میں `issues` array کے شروع میں نئی entry شامل کریں۔ مثال:

```json
{
  "id": "11",
  "label": "شمارہ 11",
  "date": "24 اگست 2026",
  "file": "Saltanat-11_24Aug26.pdf"
}
```

> سب سے اوپر والی entry خود بخود **تازہ شمارہ** بن جاتی ہے، جبکہ باقی entries ڈراپ ڈاؤن آرکائیو میں رہتی ہیں۔

`admin.php` فعال ہو تو PDF، شمارہ نمبر اور تاریخ وہیں سے اپ لوڈ کریں۔ یہ file کو `media/` میں محفوظ اور `issues.json` کو خودکار طور پر update کرتا ہے۔

| فائل یا فولڈر | مقصد | ہفتہ وار تبدیلی |
|---|---|---|
| `index.html` | ہوم پیج اور PDF viewer | عموماً کوئی تبدیلی نہیں |
| `media/` | تمام PDF شمارے اور `issues.json` | نئی PDF اور ایک JSON entry |
| `admin.php` | پاس ورڈ کے ساتھ PDF uploader | اختیاری مگر آسان |
| `deploy.php` | مستقبل کے محفوظ Git deploy controls کا starter | صرف تکنیکی update پر |
| `.cred/` | حساس config کی مثالیں | **public folder میں نہیں** |

## cPanel پر اپ لوڈ

`saltanatnewskarachi.com.pk/` فولڈر کے **اندر والی تمام فائلیں** اپنے domain کے document root، مثلاً `/home/noorgeec/saltanatnewskarachi.com.pk/` یا متعلقہ `public_html/` folder میں اپ لوڈ کریں۔ Domain کو اسی folder سے point کریں۔ `.htaccess` بھی ضرور اپ لوڈ کریں۔

اگر cPanel Git Version Control میں repository path `/home/noorgeec/saltanat` پر موجود ہے تو `.cpanel.yml` پہلے `index.html`، `admin.php`، `deploy.php` اور `.htaccess` کو document root میں publish کرے گی۔ یہ deployment موجودہ `media/` PDFs اور live `media/issues.json` کو محفوظ رکھتی ہے۔ cPanel میں **Update from Remote** کے بعد **Deploy HEAD Commit** استعمال کریں۔

Admin password کے لیے document root سے **باہر** موجود `/home/noorgeec/cred/slt.env` استعمال کریں۔ اس file میں صرف ایک line رکھیں:

```ini
ADMIN_PASS=آپ کا مضبوط Admin پاس ورڈ
```

`admin.php` اسی path سے value پڑھتا ہے اور credential file کو public web directory یا Git repository میں شامل نہیں کرتا۔ `slt.env` کی permission `0600` رکھی جائے۔ حقیقی password، API key، یا `.env` file کو کبھی Git repository یا public web folder میں نہ رکھیں۔

ابتدائی `issues.json` میں دستیاب نمونہ PDF کا URL رکھا گیا ہے تاکہ viewer فوراً دکھائی دے۔ مستقل استعمال سے پہلے PDF کو `media/` میں اپ لوڈ کر کے `file` value کو صرف local file name سے بدل دیں۔
