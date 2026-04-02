<p align="center">
  <img src="assets/logo.png" alt="Yash" width="220" />
</p>

<h3 align="center">Yash — WordPress Text-to-Speech Plugin</h3>

<p align="center">
  Reads your articles aloud using Google Wavenet. MP3 caching, text highlighting, listening stats and Audio Schema SEO — all in one plugin.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/WordPress-6.0%2B-21759b?style=flat-square&logo=wordpress&logoColor=white" alt="WordPress" />
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?style=flat-square&logo=php&logoColor=white" alt="PHP" />
  <img src="https://img.shields.io/badge/License-GPL%20v2-green?style=flat-square" alt="License" />
  <img src="https://img.shields.io/badge/Version-1.0.0-blue?style=flat-square" alt="Version" />
  <img src="https://img.shields.io/badge/Google%20Cloud-TTS%20Wavenet-4285F4?style=flat-square&logo=google-cloud&logoColor=white" alt="Google Cloud" />
</p>

---

## What is Yash?

Yash adds a clean audio player to your WordPress posts that reads articles aloud using Google Cloud Text-to-Speech (Wavenet). Unlike off-the-shelf solutions (GSpeech, AtlasVoice) — your API key stays yours, your audio files stay on your server, and the code is fully open to modify.

```
User opens an article
        ↓
  Clicks Play
        ↓
WordPress sends text to Google Cloud TTS
        ↓
  Google returns an MP3 file
        ↓
Plugin saves MP3 to server (cache)
        ↓
Browser plays audio locally
        ↓
Next visit → MP3 served from cache, zero API cost
```

---

## Features

| Feature | Description |
|---------|-------------|
| 🎙 **Google Wavenet** | Natural-sounding voice, independent of the user's browser or OS |
| 💾 **MP3 Cache** | Audio generated once, stored on your server — no repeated API costs |
| 🖊 **Text Highlighting** | Active paragraph highlights and page scrolls to the current reading position |
| ⏩ **Seek** | Click anywhere on the progress bar to jump to that position |
| 🔄 **Speed Control** | Change playback speed from 0.75× to 2.0× directly in the player |
| 📊 **Statistics** | Plays, completions, listening time, unique listeners — stored in your own DB |
| 🔍 **SEO Schema** | Auto-generated JSON-LD: `Article` + `AudioObject` + `Speakable` |
| 🎨 **Adaptive Design** | Player inherits colors from the active WordPress theme |
| 🌙 **Dark Mode** | Automatic switching via `prefers-color-scheme` |

---

## Requirements

- WordPress **6.0+**
- PHP **7.4+**
- Google Cloud account with **Cloud Text-to-Speech API** enabled
- Google Cloud API key

---

## Installation

### Via WordPress admin

```
Plugins → Add New → Upload Plugin → yash.zip → Install → Activate
```

### Via FTP / SSH

```bash
unzip yash.zip -d /var/www/html/wp-content/plugins/
```

Then activate through the **Plugins** screen in WordPress admin.

---

## Configuration

### Step 1 — Get a Google Cloud API Key

1. Go to [console.cloud.google.com](https://console.cloud.google.com)
2. Create a new project
3. **APIs & Services → Library** → search for `Cloud Text-to-Speech API` → **Enable**
4. **APIs & Services → Credentials → + Create Credentials → API Key**
5. Copy the generated key

> **Security tip:** restrict the key to `Cloud Text-to-Speech API` only and add your domain as an HTTP referrer.

> **Billing note:** Google requires a credit card on file, but you won't be charged until you exceed the free tier.

### Step 2 — Plugin settings

```
WordPress → Settings → Yash → paste API key → choose voice → Save
```

---

## Available Voices (Polish)

| Voice | Gender | Notes |
|-------|--------|-------|
| `pl-PL-Wavenet-A` | Female | ⭐ Recommended |
| `pl-PL-Wavenet-B` | Male | ✓ |
| `pl-PL-Wavenet-C` | Male | ✓ |
| `pl-PL-Wavenet-D` | Female | ✓ |
| `pl-PL-Wavenet-E` | Female | ✓ |
| `pl-PL-Standard-A` | Female | Lower quality, higher free limit |

> Neural2 voices are not available for Polish. Wavenet is the highest quality option.

---

## Google Cloud TTS Pricing

| Voice type | Free tier | Est. articles\* |
|------------|-----------|----------------|
| Wavenet    | 1,000,000 chars/month | ~200 articles |
| Standard   | 4,000,000 chars/month | ~800 articles |

\* Based on an average article length of ~5,000 characters.

**Audio is cached** — replaying an article does not consume API quota.

---

## SEO

Once audio is generated, Yash automatically injects structured data into `<head>`:

```json
{
  "@context": "https://schema.org",
  "@type": "Article",
  "headline": "Article title",
  "author": { "@type": "Person", "name": "Author Name" },
  "audio": {
    "@type": "AudioObject",
    "contentUrl": "https://yourdomain.com/.../post-1-chunk-0.mp3",
    "encodingFormat": "audio/mpeg",
    "duration": "PT4M30S"
  },
  "speakable": {
    "@type": "SpeakableSpecification",
    "cssSelector": ["h1", "article p:first-of-type"]
  }
}
```

This makes the post eligible for an audio rich result in Google Search.
Validate with [Rich Results Test](https://search.google.com/test/rich-results).

---

## Project Structure

```
yash/
├── yash.php                  # Main plugin file, hook registration
├── js/
│   └── tts.js                # Player: fetch audio, seek, highlighting, stats
├── css/
│   ├── style.css             # Player styles (adaptive to theme)
│   └── admin.css             # Admin panel styles
├── includes/
│   ├── class-cache.php       # MP3 file management on disk
│   └── class-stats.php       # DB table, write and read listening stats
└── assets/
    └── logo.png              # Plugin logo
```

---

## Statistics Dashboard

The plugin logs every event to a custom database table:

| Event | Trigger |
|-------|---------|
| `play` | User starts playback |
| `complete` | User listens to the full article |
| `pause` | User pauses or leaves the page |

**Dashboard:** `Settings → Yash` → Statistics tab

Available views: 7 / 30 / 90 days, daily chart, top articles by plays, completion rate, average listening time.

---

## License

Yash is free software released under the [GPL v2](LICENSE) license or later, in line with the WordPress ecosystem standard.

---

## Author

**Marcin Żmuda**
[marcinzmuda.pl](https://marcinzmuda.pl) · [marcin.zmuda@embasy.pl](mailto:marcin.zmuda@embasy.pl)
