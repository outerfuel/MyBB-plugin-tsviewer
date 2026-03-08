# TeamSpeak 6 Viewer for MyBB

A high-performance, secure, and highly customizable TeamSpeak 6 integration for MyBB 1.8.x. This plugin utilizes the TeamSpeak WebQuery to capture server statistics, channel hierarchies, and user status.

## 📸 Screenshots

### User Interface
![TeamSpeak Viewer Example](screenshots/TeamSpeak%20Viewer%20Example%20with%20default%20settings.png)

### Admin Configuration
![TeamSpeak Viewer Settings](screenshots/TeamSpeak%20Viewer%20Settings.png)

## 🚀 Key Features

* **WebQuery API**: Supports HTTP and HTTPS WebQuery to TeamSpeak 6.
* **Encrypted Caching**: API calls are cached and encrypted every 60 seconds for stability. The cache is keyed to your unique WebQuery API Key. Data is saved in `cache/ts_cache.dat`
* **Theme-Agnostic Architecture**: Leverages CSS inheritance to automatically match any MyBB theme (Light or Dark).
* **Live Component Visualizer**: Built-in Admin CP preview tool for real-time HTML/CSS styling.
* **Channel Nesting**: Indentation for sub-channel structures.
* **Dynamic Idle Tracking**: Idle time coloring.

## 🛠️ Installation

1.  Upload `tsviewer.php` to your MyBB `inc/plugins/` directory.
2.  Go to **Admin CP > Plugins** and click **Install & Activate**.
3.  Navigate to **Settings > TeamSpeak Viewer Settings**.
4.  Enter your **TS6 API Base URL** and **API Key**.

## 📊 Template Variables

Add these variables to your MyBB templates (e.g., `index` or `header`):

| Variable | Description |
| :--- | :--- |
| `{$ts_status}` | Displays the Online/Offline status header. |
| `{$ts_online_users}` | Displays the full channel and user tree. |
| `{$ts_count}` | Returns the integer count of online users. |

Example HTML to add to your theme template.
```html
<div class="ts-viewer-container">
    <div class="ts-status">{$ts_status}</div>
    <div class="ts-tree">{$ts_online_users}</div>
</div>
```
## 🎨 Setting Placeholders

Customize your output in the Admin CP using these placeholders:

* **User Row**: `{username}`, `{idle_time}`, `{idle_color}`, `{away_status}`
* **Status**: `{user_count}`, `{version}`, `{platform}`, `{extra_stats}`
* **Offline**: `{last_seen}`

## API Key Info

* (Recommended) Create a read apikey in your TeamSpeak Server.
```code
apikeyadd scope=read sid=1 lifetime=0
```
* An api key the scope=manage will allow querying for Server Uptime, Server Version, and Packet Loss information but is effectively an admin key to your server. You've been warned :)

## Disclaimer
* **Google Gemini** - AI-assisted code optimization and documentation.

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
