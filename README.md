# 📦 FSHub → Discord Webhook (WordPress Plugin)

A WordPress plugin that receives **FSHub webhook events** and sends **Discord embed notifications**.  
Perfect for Virtual Airlines and FSHub pilots who want live flight updates.

---

## ✨ Features
- Supports FSHub events:
  - `flight.departed`
  - `flight.completed`
  - `airline.achievement`
  - `screenshots.uploaded`
- Clean and dynamic Discord embeds
- Admin page with:
  - Stats & counters  
  - Log tracking  
  - Test notifications  
  - Discord webhook settings  
  - Auto-generated FSHub webhook URL  
- All webhook activity logged in the database

---

## 📥 Installation

### **1. Upload the plugin**
You can install the plugin in two ways:

**• Via WordPress admin**
1. Go to: Plugins → Add New → Upload Plugin  
2. Upload the ZIP file  
3. Install and activate

**• Via FTP / File Manager**
Upload the plugin folder into:
``/wp-content/plugins/``
Then activate it in WordPress.

---

## ⚙️ Setup

### **1. Configure Discord webhook**
Open the admin page:
``FsHub VA Stats → Discord Webhook Settings``
Paste your Discord webhook URL and save.

---

### **2. Configure FSHub webhook**
In the same page, the plugin shows the exact webhook URL to paste in FSHub:
``https://your-site.com/wp-json/fshub/v1/webhook``

Copy this URL into your FSHub webhook settings.  
Your system is now ready.

---

## 🧪 Testing

Inside **FsHub VA Stats**, you can send test events:
- 🛫 Flight departed  
- 🛬 Flight completed  
- 🏆 Achievement unlocked  
- 📸 Screenshot uploaded  

Each test is logged and sent to Discord.

---

## 🔍 Logs

The plugin stores all events in:
``wp_fshub_logs``

The stats panel shows:
- Webhooks received  
- Successful Discord messages  
- Errors  

Useful for debugging and monitoring.

---

## 📄 Requirements
- WordPress 5.0+
- PHP 7.4+
- A Discord webhook URL
- An FSHub account or Virtual Airline with webhook access

---

## 🤝 Contributing
Pull requests and improvements are welcome!  
If you'd like new event types or features, feel free to open an issue.

---

## 📜 License
MIT License — Free to use, modify and distribute.

