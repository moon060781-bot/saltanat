# Project Rules — سلطنت نیوز کراچی

1. حساس معلومات، passwords، private API keys، hosting login، SSH keys اور `.env` files کو کبھی بھی source code، Markdown، browser output یا public web folder میں شامل نہیں کیا جائے گا۔
2. `media/issues.json` کی پہلی entry ہمیشہ سب سے نیا شمارہ ہوگی۔ نئی entry شامل کرتے وقت پرانی entries حذف نہیں کی جائیں گی۔
3. ہر PDF کا نام readable اور unique ہونا چاہیے، مثلاً `Saltanat-11_24Aug26.pdf`۔
4. `index.html` کو framework، database یا غیر ضروری dependencies کے بغیر رکھنا ہے تاکہ cPanel اور FTP deployment آسان رہے۔
5. AI اگر site یا document میں تبدیلی کرے تو مختصر commit title اور واضح extended description تیار کرے، مگر خود سے حقیقی credentials یا destructive server commands استعمال نہ کرے۔
6. PDF upload کے بعد desktop اور mobile دونوں میں viewer، download link اور archive dropdown ضرور چیک کیے جائیں۔
7. حقیقی deploy automation صرف reviewed PHP code، authenticated access، branch allow-list اور audit logging کے بعد فعال کیا جائے گا۔
