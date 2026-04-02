=== Yash ===
Contributors: marcinzmuda
Tags: text-to-speech, tts, audio, accessibility, google cloud, wavenet, article reader
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reads your articles aloud using Google Wavenet. MP3 caching, text highlighting, listening stats and Audio Schema SEO.

== Description ==

**Yash** is a WordPress plugin that turns your articles into audio using Google Cloud Text-to-Speech (Wavenet) — the highest quality TTS engine available for Polish.

= Key Features =

* 🎙 **Google Wavenet** — natural-sounding voice, independent of user browser or OS
* 💾 **MP3 Cache** — audio generated once, stored on your server, zero repeated API costs
* 🖊 **Text Highlighting** — active paragraph highlights and auto-scrolls during playback
* 📊 **Statistics** — plays, completions, listening time and unique listeners dashboard
* 🔍 **SEO Audio Schema** — auto JSON-LD: Article + AudioObject + Speakable
* ⏩ **Seek** — click the progress bar to jump to any position in the article
* 🔄 **Speed Control** — 0.75× to 2.0× directly in the player
* 🎨 **Adaptive Design** — player inherits colors from the active theme

= Requirements =

* Google Cloud account with Cloud Text-to-Speech API enabled
* Free tier: 1 million characters per month for Wavenet voices

== Installation ==

1. Download and upload via **Plugins → Add New → Upload Plugin**
2. Activate the plugin
3. Go to **Settings → Yash** and paste your Google Cloud API key
4. Choose a voice and save

Full API key setup guide: [GitHub README](https://github.com/marcinzmuda/yash-wp)

== Frequently Asked Questions ==

= Is the plugin free? =

The plugin is free and open-source (GPL2). It requires a Google Cloud API key. The free tier allows ~200 articles per month without any charges.

= Is the audio cached? =

Yes. The MP3 file is generated only on first playback and saved to your server. Replays do not consume any API quota.

= Which voices are available for Polish? =

Wavenet-A (female), Wavenet-B (male), Wavenet-C (male), Wavenet-D (female), Wavenet-E (female). Standard voices are also available with a higher free character limit.

= Does the plugin help with SEO? =

Yes — indirectly. It automatically adds Article + AudioObject + Speakable structured data, which makes the post eligible for an audio rich result in Google Search. Audio also significantly increases user session duration.

== Screenshots ==

1. Audio player on a post page
2. Plugin settings screen
3. Statistics dashboard

== Changelog ==

= 1.0.0 =
* Initial public release

== Upgrade Notice ==

= 1.0.0 =
First release.
