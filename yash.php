<?php
/**
 * Plugin Name: Yash
 * Description: WordPress TTS plugin — Google Wavenet voices, MP3 caching, text highlighting and Audio Schema SEO.
 * Version:     1.1.0
 * Author:      Marcin Żmuda
 * Author URI:  https://marcinzmuda.pl
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yash
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'YASH_VERSION', '1.1.0' );
define( 'YASH_DIR',     plugin_dir_path( __FILE__ ) );
define( 'YASH_URL',     plugin_dir_url( __FILE__ )  );

require_once YASH_DIR . 'includes/class-cache.php';
require_once YASH_DIR . 'includes/class-stats.php';

register_activation_hook( __FILE__, function () {
    AR_Stats::create_table();
    AR_Cache::init_dirs();
} );

class Yash {

    public function __construct() {
        // LiteSpeed — zarejestruj wykluczenia WCZEŚNIE (przed enqueue)
        add_filter( 'litespeed_optm_js_defer_exc',    [ $this, 'ls_exclude' ] );
        add_filter( 'litespeed_optimize_js_excludes', [ $this, 'ls_exclude' ] );
        add_filter( 'litespeed_optm_js_delay_exc',    [ $this, 'ls_exclude' ] );
        add_filter( 'rocket_delay_js_exclusions',     [ $this, 'ls_exclude' ] );
        add_filter( 'autoptimize_filter_js_exclude',  [ $this, 'ls_exclude_str' ] );

        // Frontend
        add_action( 'wp_enqueue_scripts',              [ $this, 'enqueue_assets' ] );
        add_filter( 'the_content',                     [ $this, 'inject_player' ] );
        add_action( 'wp_head',                         [ $this, 'inject_schema' ] );
        add_action( 'wp_footer',                       [ $this, 'inject_floating_player' ] );

        // AJAX — public + zalogowani
        add_action( 'wp_ajax_ar_synthesize',           [ $this, 'ajax_synthesize' ] );
        add_action( 'wp_ajax_nopriv_ar_synthesize',    [ $this, 'ajax_synthesize' ] );
        add_action( 'wp_ajax_ar_stat',                 [ $this, 'ajax_stat' ] );
        add_action( 'wp_ajax_nopriv_ar_stat',          [ $this, 'ajax_stat' ] );
        add_action( 'wp_ajax_ar_bulk_generate',        [ $this, 'ajax_bulk_generate' ] );
        add_action( 'wp_ajax_ar_get_posts_for_bulk',   [ $this, 'ajax_get_posts_for_bulk' ] );

        // Cache
        add_action( 'save_post',                       [ $this, 'invalidate_cache' ], 10, 2 );
        add_action( 'delete_post',                     'AR_Cache::invalidate' );

        // Auto-generowanie przy publikacji
        add_action( 'transition_post_status',          [ $this, 'on_publish' ], 10, 3 );
        add_action( 'yash_auto_generate',              [ $this, 'auto_generate_audio' ] );

        // Admin
        add_action( 'admin_menu',                      [ $this, 'admin_menu' ] );
        add_action( 'admin_init',                      [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts',           [ $this, 'admin_assets' ] );

        // Sitemap audio
        add_action( 'init',                            [ $this, 'register_sitemap_route' ] );
        add_action( 'init',                            [ $this, 'register_podcast_feed' ] );
        add_action( 'template_redirect',               [ $this, 'serve_audio_sitemap' ] );
        add_action( 'wp_head',                         [ $this, 'add_sitemap_link' ] );

        // AI Summary
        add_action( 'wp_ajax_ar_get_summary',          [ $this, 'ajax_get_summary' ] );
        add_action( 'wp_ajax_nopriv_ar_get_summary',   [ $this, 'ajax_get_summary' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Assets                                                              */
    /* ------------------------------------------------------------------ */

    public function enqueue_assets() {
        if ( ! is_singular( 'post' ) ) return;

        wp_enqueue_style(  'article-reader', YASH_URL . 'css/style.css', [], YASH_VERSION );
        wp_enqueue_script( 'article-reader', YASH_URL . 'js/tts.js',    [], YASH_VERSION, true );

        // Atrybut data-no-delay na tagu <script> — LiteSpeed respektuje to
        add_filter( 'script_loader_tag', function( $tag, $handle ) {
            if ( $handle !== 'article-reader' ) return $tag;
            return str_replace(
                '<script ',
                '<script data-no-optimize="1" data-no-defer="1" data-pagespeed-no-defer data-no-delay="1" data-cfasync="false" ',
                $tag
            );
        }, 10, 2 );

        $o = get_option( 'article_reader_options', [] );
        wp_localize_script( 'article-reader', 'AR', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'ar_nonce' ),
            'postId'      => get_the_ID(),
            'voice'       => $o['voice']  ?? 'pl-PL-Wavenet-A',
            'rate'        => (float)( $o['rate']  ?? 1.0 ),
            'pitch'       => (float)( $o['pitch'] ?? 0.0 ),
            'hasKey'      => ! empty( $o['api_key'] ),
            'hasClaude'   => ! empty( $o['claude_key'] ),
            'postId'      => get_the_ID(),
            'autoGen'     => (bool)( $o['auto_gen'] ?? false ),
        ] );
    }

    public function ls_exclude( $list ) {
        if ( ! is_array( $list ) ) $list = [];
        $list[] = 'tts.js';
        $list[] = 'yash/js/tts';
        return $list;
    }

    // Autoptimize używa string zamiast array
    public function ls_exclude_str( $str ) {
        return $str . ', tts.js';
    }

    public function admin_assets( $hook ) {
        if ( strpos( $hook, 'article-reader' ) === false ) return;
        wp_enqueue_style(  'ar-admin', YASH_URL . 'css/admin.css', [], YASH_VERSION );
        wp_enqueue_script( 'ar-admin', YASH_URL . 'js/admin.js',   [], YASH_VERSION, true );
        wp_localize_script( 'ar-admin', 'ARAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ar_nonce' ),
        ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Inwalidacja cache                                                   */
    /* ------------------------------------------------------------------ */

    public function invalidate_cache( $post_id, $post ) {
        if ( $post->post_status === 'auto-draft' ) return;
        AR_Cache::invalidate( $post_id );
    }

    /* ------------------------------------------------------------------ */
    /*  Auto-generowanie przy publikacji                                   */
    /* ------------------------------------------------------------------ */

    public function on_publish( $new_status, $old_status, $post ) {
        if ( $new_status !== 'publish' || $old_status === 'publish' ) return;
        if ( $post->post_type !== 'post' ) return;

        $o = get_option( 'article_reader_options', [] );
        if ( empty( $o['auto_gen'] ) || empty( $o['api_key'] ) ) return;

        // Zaplanuj generowanie za 10 sekund (żeby post był w pełni zapisany)
        wp_schedule_single_event( time() + 10, 'yash_auto_generate', [ $post->ID ] );
    }

    public function auto_generate_audio( $post_id ) {
        $o = get_option( 'article_reader_options', [] );
        if ( empty( $o['api_key'] ) ) return;

        $post  = get_post( $post_id );
        if ( ! $post ) return;

        $plain  = wp_strip_all_tags( $post->post_content );
        $chunks = $this->build_text_chunks( $plain );

        foreach ( $chunks as $idx => $chunk ) {
            if ( AR_Cache::get( $post_id, $idx, $o['voice'] ?? 'pl-PL-Wavenet-A' ) ) continue;
            $ssml = $this->text_to_ssml( $chunk, $idx );
            $this->call_google_tts( $ssml, $post_id, $idx, $o );
            // Pauza między requestami żeby nie przekroczyć limitów
            if ( $idx < count($chunks) - 1 ) sleep( 1 );
        }
    }

    private function build_text_chunks( $text ) {
        $text = preg_replace( '/\s+/', ' ', trim( $text ) );
        $sentences = preg_split( '/(?<=[.!?…])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
        $chunks = [];
        $cur    = '';
        foreach ( $sentences as $s ) {
            if ( mb_strlen( $cur . ' ' . $s ) > 1400 && $cur !== '' ) {
                $chunks[] = trim( $cur );
                $cur      = $s;
            } else {
                $cur .= ( $cur ? ' ' : '' ) . $s;
            }
        }
        if ( trim( $cur ) ) $chunks[] = trim( $cur );
        return $chunks ?: [ $text ];
    }

    /* ------------------------------------------------------------------ */
    /*  Player HTML                                                         */
    /* ------------------------------------------------------------------ */

    public function inject_player( $content ) {
        if ( ! is_singular( 'post' ) ) return $content;
        if ( ! in_the_loop() || ! is_main_query() ) return $content;

        $o = get_option( 'article_reader_options', [] );

        // Dodaj data-ar-p do akapitów
        $idx     = 0;
        $wrapped = preg_replace_callback( '/<p(\b[^>]*)>/i', function() use ( &$idx ) {
            return '<p data-ar-p="' . $idx++ . '">';
        }, $content );

        $player = $this->build_player( $content );

        return ( ( $o['position'] ?? 'before' ) === 'after' )
            ? $wrapped . $player
            : $player . $wrapped;
    }

    private function build_player( $raw ) {
        $plain   = wp_strip_all_tags( $raw );
        $words   = str_word_count( $plain );
        $minutes = max( 1, round( $words / 200 ) );

        // Przygotuj strukturę HTML dla SSML
        $structured = $raw;
        $structured = preg_replace( '/<(script|style)[^>]*>.*?<\/\1>/si', '', $structured );
        $structured = preg_replace( '/<br\s*\/?>/i',   "\n", $structured );
        $structured = preg_replace( '/<\/p>/i',         "\n", $structured );
        $structured = preg_replace( '/<\/li>/i',        "\n", $structured );
        $structured = preg_replace( '/<\/h[1-6]>/i',    "\n", $structured );
        $structured = preg_replace( '/<li[^>]*>/i',     '- ', $structured );
        $structured = preg_replace( '/<h[1-6][^>]*>/i', "\n", $structured );
        $structured = wp_strip_all_tags( $structured );

        // URL do pobrania (pierwszy chunk jeśli jest w cache)
        $o           = get_option( 'article_reader_options', [] );
        $download_url = AR_Cache::get( get_the_ID(), 0, $o['voice'] ?? 'pl-PL-Wavenet-A' );

        ob_start(); ?>
        <div id="article-reader-player" role="region" aria-label="Odtwarzacz artykułu">
            <div class="ar-inner">

                <div class="ar-waveform" aria-hidden="true">
                    <?php for ( $i = 0; $i < 18; $i++ ) : ?><span class="ar-bar" style="--i:<?php echo $i; ?>"></span><?php endfor; ?>
                </div>

                <div class="ar-middle">
                    <div class="ar-info">
                        <span class="ar-label">Słuchaj artykułu</span>
                        <span class="ar-badge">Google Wavenet</span>
                        <span class="ar-read-time"><?php echo $minutes; ?> min</span>
                        <span class="ar-time" id="ar-time">0:00</span>
                    </div>
                    <div class="ar-progress-wrap" title="Kliknij, aby przewinąć">
                        <div class="ar-progress-track">
                            <div class="ar-progress-fill" id="ar-progress"></div>
                        </div>
                    </div>
                    <div class="ar-status" id="ar-status">Naciśnij play, aby słuchać</div>
                </div>

                <div class="ar-controls">
                    <!-- Skip wstecz -->
                    <button class="ar-btn ar-btn--skip" id="ar-skip-back" aria-label="Cofnij 15 sekund" title="−15s">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/><text x="12" y="14" text-anchor="middle" font-size="5" fill="currentColor">15</text></svg>
                    </button>

                    <!-- Speed -->
                    <button class="ar-btn ar-btn--speed" id="ar-speed" aria-label="Prędkość">1×</button>

                    <!-- Play/Pause/Loading -->
                    <button class="ar-btn ar-btn--main" id="ar-play" aria-label="Odtwórz">
                        <span class="ar-icon ar-icon--play"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>
                        <span class="ar-icon ar-icon--pause" style="display:none"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg></span>
                        <span class="ar-icon ar-icon--loading" style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="ar-spin"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg></span>
                    </button>

                    <!-- Skip naprzód -->
                    <button class="ar-btn ar-btn--skip" id="ar-skip-fwd" aria-label="Przewiń 15 sekund" title="+15s">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 5V1l5 5-5 5V7c-3.31 0-6 2.69-6 6s2.69 6 6 6 6-2.69 6-6h2c0 4.42-3.58 8-8 8s-8-3.58-8-8 3.58-8 8-8z"/><text x="12" y="14" text-anchor="middle" font-size="5" fill="currentColor">15</text></svg>
                    </button>

                    <!-- Stop -->
                    <button class="ar-btn ar-btn--stop" id="ar-stop" aria-label="Zatrzymaj">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 6h12v12H6z"/></svg>
                    </button>

                    <!-- Download -->
                    <a class="ar-btn ar-btn--download" id="ar-download"
                       href="<?php echo $download_url ? esc_url( $download_url ) : '#'; ?>"
                       download
                       aria-label="Pobierz MP3"
                       title="Pobierz MP3"
                       style="<?php echo $download_url ? '' : 'opacity:.3;pointer-events:none'; ?>">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zm-8 2V5h2v6h1.17L12 13.17 9.83 11H11zm-6 7h14v2H5v-2z"/></svg>
                    </a>
                </div>

            </div>
            <div id="ar-text-source" style="display:none"><?php echo esc_html( trim( $structured ) ); ?></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------ */
    /*  Floating mini-player                                                */
    /* ------------------------------------------------------------------ */

    public function inject_floating_player() {
        if ( ! is_singular( 'post' ) ) return;
        $o = get_option( 'article_reader_options', [] );
        if ( empty( $o['floating_player'] ) ) return;
        ?>
        <div id="ar-floating" role="region" aria-label="Mini odtwarzacz" aria-hidden="true">
            <div class="ar-float-inner">
                <div class="ar-float-progress" id="ar-float-progress" style="width:0%"></div>
                <div class="ar-float-info">
                    <span class="ar-float-waveform" aria-hidden="true">
                        <?php for($i=0;$i<8;$i++): ?><span class="ar-fbar" style="--i:<?php echo $i;?>"></span><?php endfor; ?>
                    </span>
                    <div class="ar-float-texts">
                        <span class="ar-float-title">Słuchaj artykułu</span>
                        <span class="ar-float-status" id="ar-float-status">Naciśnij play</span>
                    </div>
                </div>
                <span class="ar-float-time" id="ar-float-time"></span>
                <div class="ar-float-controls">
                    <button class="ar-btn ar-btn--skip" id="ar-float-skip-back" aria-label="−15s" title="−15 sekund">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="17" height="17"><path d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg>
                    </button>
                    <button class="ar-btn ar-btn--main" id="ar-float-play" aria-label="Play/Pause">
                        <span class="ar-icon ar-icon--play"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>
                        <span class="ar-icon ar-icon--pause" style="display:none"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg></span>
                    </button>
                    <button class="ar-btn ar-btn--skip" id="ar-float-skip-fwd" aria-label="+15s" title="+15 sekund">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="17" height="17"><path d="M12 5V1l5 5-5 5V7c-3.31 0-6 2.69-6 6s2.69 6 6 6 6-2.69 6-6h2c0 4.42-3.58 8-8 8s-8-3.58-8-8 3.58-8 8-8z"/></svg>
                    </button>
                    <button class="ar-btn ar-btn--float-close" id="ar-float-close" aria-label="Zamknij">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="12" height="12"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Schema JSON-LD                                                      */
    /* ------------------------------------------------------------------ */

    public function inject_schema() {
        if ( ! is_singular( 'post' ) ) return;
        $o   = get_option( 'article_reader_options', [] );
        if ( empty( $o['api_key'] ) ) return;

        $pid   = get_the_ID();
        $post  = get_post( $pid );
        $voice = $o['voice'] ?? 'pl-PL-Wavenet-A';
        $url   = AR_Cache::get( $pid, 0, $voice );
        if ( ! $url ) return;

        $word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );
        $rate       = (float)( $o['rate'] ?? 1.0 );
        $seconds    = max( 1, round( ( $word_count / 130 ) * 60 / $rate ) );
        $duration   = 'PT' . floor( $seconds / 60 ) . 'M' . ( $seconds % 60 ) . 'S';
        $author_id  = $post->post_author;

        $article = [
            '@context'      => 'https://schema.org',
            '@type'         => 'Article',
            'headline'      => get_the_title(),
            'url'           => get_permalink( $pid ),
            'datePublished' => get_the_date( 'c' ),
            'dateModified'  => get_the_modified_date( 'c' ),
            'inLanguage'    => 'pl',
            'wordCount'     => $word_count,
            'description'   => wp_trim_words( $post->post_content, 30 ),
            'author'        => [
                '@type' => 'Person',
                'name'  => get_the_author_meta( 'display_name', $author_id ),
                'url'   => get_author_posts_url( $author_id ),
            ],
            'publisher'     => [
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
                'url'   => home_url(),
            ],
            'audio'         => [
                '@type'          => 'AudioObject',
                'name'           => get_the_title(),
                'contentUrl'     => $url,
                'encodingFormat' => 'audio/mpeg',
                'duration'       => $duration,
                'inLanguage'     => 'pl',
                'uploadDate'     => get_the_date( 'c' ),
                'description'    => wp_trim_words( $post->post_content, 30 ),
            ],
            'speakable'     => [
                '@type'       => 'SpeakableSpecification',
                'cssSelector' => [ 'h1', '.entry-content p:first-of-type', 'article p:first-of-type' ],
            ],
        ];

        echo '<script type="application/ld+json">' . wp_json_encode( $article, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
    }

    /* ------------------------------------------------------------------ */
    /*  Audio Sitemap                                                       */
    /* ------------------------------------------------------------------ */

    public function register_sitemap_route() {
        add_rewrite_rule( '^audio-sitemap\.xml$', 'index.php?ar_audio_sitemap=1', 'top' );
        add_rewrite_tag( '%ar_audio_sitemap%', '([0-9]+)' );
    }

    public function add_sitemap_link() {
        if ( ! is_singular( 'post' ) ) return;
        echo '<link rel="sitemap" type="application/xml" title="Audio Sitemap" href="' . esc_url( home_url( '/audio-sitemap.xml' ) ) . '" />' . "\n";
    }

    public function serve_audio_sitemap() {
        if ( ! get_query_var( 'ar_audio_sitemap' ) ) return;

        $o     = get_option( 'article_reader_options', [] );
        $voice = $o['voice'] ?? 'pl-PL-Wavenet-A';

        // Znajdź posty z wygenerowanym audio
        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'fields'         => 'ids',
        ] );

        header( 'Content-Type: application/xml; charset=UTF-8' );
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
                     xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

        foreach ( $posts as $pid ) {
            $audio_url = AR_Cache::get( $pid, 0, $voice );
            if ( ! $audio_url ) continue;
            $post   = get_post( $pid );
            $words  = str_word_count( wp_strip_all_tags( $post->post_content ) );
            $secs   = max( 1, round( ( $words / 130 ) * 60 ) );
            echo '<url>' . "\n";
            echo '  <loc>' . esc_url( get_permalink( $pid ) ) . '</loc>' . "\n";
            echo '  <video:video>' . "\n";
            echo '    <video:content_loc>' . esc_url( $audio_url ) . '</video:content_loc>' . "\n";
            echo '    <video:title>' . esc_xml( get_the_title( $pid ) ) . '</video:title>' . "\n";
            echo '    <video:duration>' . $secs . '</video:duration>' . "\n";
            echo '  </video:video>' . "\n";
            echo '</url>' . "\n";
        }

        echo '</urlset>';
        exit;
    }

    /* ------------------------------------------------------------------ */
    /*  SSML konwerter                                                      */
    /* ------------------------------------------------------------------ */

    private function text_to_ssml( $text, $chunk_idx = 0 ) {
        $o   = get_option( 'article_reader_options', [] );
        $ph_before  = (int)( $o['pause_heading_before'] ?? 900 );
        $ph_after   = (int)( $o['pause_heading_after']  ?? 600 );
        $p_sentence = (int)( $o['pause_sentence']       ?? 350 );
        $p_para     = (int)( $o['pause_paragraph']      ?? 700 );

        $esc = function( $s ) {
            return str_replace( ['&','<','>','"',"'"], ['&amp;','&lt;','&gt;','&quot;','&apos;'], $s );
        };

        $text  = trim( $text );
        $lines = preg_split( '/\n+/', $text );
        $parts = [];
        $pause_before = $chunk_idx === 0 ? '' : '<break time="' . $p_para . 'ms"/>';

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( ! $line ) { $parts[] = '<break time="' . $p_para . 'ms"/>'; continue; }

            $is_heading = mb_strlen( $line ) < 120
                && ! preg_match( '/[,;:]$/', $line )
                && substr_count( $line, ' ' ) < 14;

            if ( $is_heading && count( $lines ) > 1 ) {
                $parts[] = '<break time="' . $ph_before . 'ms"/>'
                    . '<prosody rate="92%" pitch="+1st">' . $esc( $line ) . '</prosody>'
                    . '<break time="' . $ph_after . 'ms"/>';
            } else {
                $processed = preg_replace(
                    '/([.!?…])(\s+)/',
                    '$1<break time="' . $p_sentence . 'ms"/>$2',
                    $esc( $line )
                );
                $processed = preg_replace( '/([,;])(\s+)/', '$1<break time="150ms"/>$2', $processed );
                if ( preg_match( '/^[-•*]|^\d+\./', $line ) ) {
                    $parts[] = '<break time="300ms"/>' . $processed . '<break time="200ms"/>';
                } else {
                    $parts[] = $processed;
                }
            }
        }

        return '<speak>' . $pause_before . implode( ' ', $parts ) . '</speak>';
    }

    /* ------------------------------------------------------------------ */
    /*  Google TTS call (współdzielona logika)                              */
    /* ------------------------------------------------------------------ */

    private function call_google_tts( $ssml, $post_id, $chunk_idx, $o ) {
        $api_key = trim( $o['api_key'] ?? '' );
        if ( ! $api_key ) return false;

        $voice   = $o['voice'] ?? 'pl-PL-Wavenet-A';
        $lang    = $o['lang']  ?? 'pl-PL';
        $rate    = (float)( $o['rate']  ?? 1.0 );
        $pitch   = (float)( $o['pitch'] ?? 0.0 );

        $payload = wp_json_encode( [
            'input'       => [ 'ssml' => $ssml ],
            'voice'       => [ 'languageCode' => $lang, 'name' => $voice ],
            'audioConfig' => [
                'audioEncoding'    => 'MP3',
                'speakingRate'     => max( 0.25, min( 4.0, $rate ) ),
                'pitch'            => max( -20.0, min( 20.0, $pitch ) ),
                'effectsProfileId' => [ 'headphone-class-device' ],
            ],
        ] );

        $response = wp_remote_post(
            'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . urlencode( $api_key ),
            [ 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => $payload, 'timeout' => 30, 'data_format' => 'body' ]
        );

        if ( is_wp_error( $response ) ) return false;
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || empty( $data['audioContent'] ) ) return false;

        AR_Cache::put( $post_id, $chunk_idx, $voice, $data['audioContent'] );
        return $data['audioContent'];
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX — Synthesize                                                   */
    /* ------------------------------------------------------------------ */

    public function ajax_synthesize() {
        check_ajax_referer( 'ar_nonce', 'nonce' );
        $o       = get_option( 'article_reader_options', [] );
        $api_key = trim( $o['api_key'] ?? '' );
        if ( ! $api_key ) {
            wp_send_json_error( [ 'message' => 'Brak klucza API. Uzupełnij w Ustawienia → Yash.' ], 400 );
        }

        $post_id   = (int)( $_POST['post_id']   ?? 0 );
        $chunk_idx = (int)( $_POST['chunk_idx'] ?? 0 );
        $text      = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
        $voice     = sanitize_text_field( $_POST['voice'] ?? $o['voice'] ?? 'pl-PL-Wavenet-A' );
        $o['rate']  = (float)( $_POST['rate']  ?? $o['rate']  ?? 1.0 );
        $o['pitch'] = (float)( $_POST['pitch'] ?? $o['pitch'] ?? 0.0 );

        if ( ! $text ) { wp_send_json_error( [ 'message' => 'Brak tekstu.' ], 400 ); }

        // Cache hit
        if ( $post_id ) {
            $cached = AR_Cache::get( $post_id, $chunk_idx, $voice );
            if ( $cached ) {
                $file = AR_Cache::get_file_path( $post_id, $chunk_idx, $voice );
                if ( $file && file_exists( $file ) ) {
                    $b64 = base64_encode( file_get_contents( $file ) );
                    // Zwróć też URL do pobrania dla chunk 0
                    wp_send_json_success( [ 'audio' => $b64, 'cached' => true, 'url' => $cached ] );
                }
            }
        }

        $text = mb_substr( $text, 0, 4500 );
        $ssml = $this->text_to_ssml( $text, $chunk_idx );
        $b64  = $this->call_google_tts( $ssml, $post_id, $chunk_idx, $o );

        if ( ! $b64 ) {
            wp_send_json_error( [ 'message' => 'Błąd Google Cloud TTS.' ], 500 );
        }

        $url = $post_id ? AR_Cache::get( $post_id, $chunk_idx, $voice ) : null;
        wp_send_json_success( [ 'audio' => $b64, 'cached' => false, 'url' => $url ] );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX — Stats                                                        */
    /* ------------------------------------------------------------------ */

    public function ajax_stat() {
        check_ajax_referer( 'ar_nonce', 'nonce' );
        $post_id  = (int)( $_POST['post_id']    ?? 0 );
        $action   = sanitize_key( $_POST['action_type'] ?? 'play' );
        $listened = (int)( $_POST['listened_s'] ?? 0 );
        if ( $post_id && in_array( $action, [ 'play', 'complete', 'pause' ] ) ) {
            AR_Stats::record( $post_id, $action, $listened );
        }
        wp_send_json_success();
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX — Bulk generate (lista postów)                                 */
    /* ------------------------------------------------------------------ */

    public function ajax_get_posts_for_bulk() {
        check_ajax_referer( 'ar_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        $o     = get_option( 'article_reader_options', [] );
        $voice = $o['voice'] ?? 'pl-PL-Wavenet-A';

        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
        ] );

        $result = [];
        foreach ( $posts as $p ) {
            $result[] = [
                'id'      => $p->ID,
                'title'   => get_the_title( $p ),
                'url'     => get_permalink( $p ),
                'cached'  => (bool) AR_Cache::get( $p->ID, 0, $voice ),
                'words'   => str_word_count( wp_strip_all_tags( $p->post_content ) ),
            ];
        }
        wp_send_json_success( $result );
    }

    public function ajax_bulk_generate() {
        check_ajax_referer( 'ar_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        $post_id = (int)( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( [ 'message' => 'Brak ID posta.' ] );

        $o = get_option( 'article_reader_options', [] );
        if ( empty( $o['api_key'] ) ) wp_send_json_error( [ 'message' => 'Brak klucza API.' ] );

        $post = get_post( $post_id );
        if ( ! $post ) wp_send_json_error( [ 'message' => 'Post nie istnieje.' ] );

        $this->auto_generate_audio( $post_id );

        $voice = $o['voice'] ?? 'pl-PL-Wavenet-A';
        wp_send_json_success( [
            'post_id' => $post_id,
            'cached'  => (bool) AR_Cache::get( $post_id, 0, $voice ),
        ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Admin                                                               */
    /* ------------------------------------------------------------------ */

    public function admin_menu() {
        add_options_page( 'Yash', 'Yash', 'manage_options', 'article-reader', [ $this, 'settings_page' ] );
        add_submenu_page( 'options-general.php', 'Yash — Statystyki', null, 'manage_options', 'article-reader-stats', [ $this, 'stats_page' ] );
        add_submenu_page( 'options-general.php', 'Yash — Bulk Generate', null, 'manage_options', 'article-reader-bulk', [ $this, 'bulk_page' ] );
    }

    public function register_settings() {
        register_setting( 'article_reader_group', 'article_reader_options', [
            'sanitize_callback' => [ $this, 'sanitize_options' ],
        ] );
    }

    public function sanitize_options( $in ) {
        return [
            'api_key'  => sanitize_text_field( $in['api_key']  ?? '' ),
            'lang'     => sanitize_text_field( $in['lang']     ?? 'pl-PL' ),
            'voice'    => sanitize_text_field( $in['voice']    ?? 'pl-PL-Wavenet-A' ),
            'rate'     => min( 4.0,  max( 0.25,  (float)( $in['rate']  ?? 1.0 ) ) ),
            'pitch'    => min( 20.0, max( -20.0, (float)( $in['pitch'] ?? 0.0 ) ) ),
            'position' => in_array( $in['position'] ?? '', ['before','after'] ) ? $in['position'] : 'before',
            'auto_gen'         => ! empty( $in['auto_gen'] ),
            'floating_player'  => ! empty( $in['floating_player'] ),
            'claude_key'       => sanitize_text_field( $in['claude_key'] ?? '' ),
            'podcast'          => ! empty( $in['podcast'] ),
            'pause_heading_before' => min( 2000, max( 100, (int)( $in['pause_heading_before'] ?? 900 ) ) ),
            'pause_heading_after'  => min( 2000, max( 100, (int)( $in['pause_heading_after']  ?? 600 ) ) ),
            'pause_sentence'       => min( 1000, max( 50,  (int)( $in['pause_sentence']       ?? 350 ) ) ),
            'pause_paragraph'      => min( 2000, max( 100, (int)( $in['pause_paragraph']      ?? 700 ) ) ),
        ];
    }

    public function settings_page() {
        $o = get_option( 'article_reader_options', [] );
        $voices = [
            'pl-PL-Wavenet-A'  => 'Wavenet-A — kobieta ⭐',
            'pl-PL-Wavenet-B'  => 'Wavenet-B — mężczyzna',
            'pl-PL-Wavenet-C'  => 'Wavenet-C — mężczyzna',
            'pl-PL-Wavenet-D'  => 'Wavenet-D — kobieta',
            'pl-PL-Wavenet-E'  => 'Wavenet-E — kobieta',
            'pl-PL-Standard-A' => 'Standard-A — kobieta (4M/mies.)',
            'pl-PL-Standard-B' => 'Standard-B — mężczyzna',
            'pl-PL-Standard-C' => 'Standard-C — mężczyzna',
            'pl-PL-Standard-D' => 'Standard-D — kobieta',
            'pl-PL-Standard-E' => 'Standard-E — kobieta',
        ];
        $cache_size = AR_Cache::total_size();
        $stats      = AR_Stats::get_summary(30);
        if ( isset( $_POST['ar_clear_cache'] ) && check_admin_referer('ar_clear_cache') ) {
            AR_Cache::clear_all();
            echo '<div class="notice notice-success"><p>Cache wyczyszczony.</p></div>';
            $cache_size = 0;
        }
        ?>
        <div class="wrap">
        <h1>🔊 Yash — Settings</h1>
        <?php if ( empty($o['api_key']) ) : ?>
        <div class="notice notice-warning"><p><strong>Wklej klucz API Google Cloud.</strong> <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Utwórz klucz →</a></p></div>
        <?php endif; ?>
        <div class="ar-admin-cards">
            <div class="ar-card"><div class="ar-card__val"><?php echo number_format($stats['plays']);?></div><div class="ar-card__label">Odtworzeń (30 dni)</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo $stats['unique'];?></div><div class="ar-card__label">Unikalnych</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo $stats['completes'];?></div><div class="ar-card__label">Ukończeń</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo size_format($cache_size);?></div><div class="ar-card__label">Cache MP3</div></div>
        </div>
        <form method="post" action="options.php"><?php settings_fields('article_reader_group'); ?>
        <table class="form-table">
            <tr><th>Klucz API Google Cloud</th><td>
                <input type="password" name="article_reader_options[api_key]" value="<?php echo esc_attr($o['api_key']??'');?>" class="regular-text" autocomplete="off">
                <p class="description">Neural/Wavenet: <strong>1 mln znaków/mies.</strong> za darmo (~200 artykułów). Audio cachowane — kolejne odsłuchy bez kosztów.</p>
            </td></tr>
            <tr><th>Głos</th><td>
                <select name="article_reader_options[voice]"><?php foreach($voices as $v=>$l):?><option value="<?php echo esc_attr($v);?>" <?php selected($o['voice']??'',$v);?>><?php echo esc_html($l);?></option><?php endforeach;?></select>
            </td></tr>
            <tr><th>Prędkość</th><td><input type="number" name="article_reader_options[rate]" value="<?php echo esc_attr($o['rate']??1.0);?>" min="0.25" max="4.0" step="0.05" style="width:80px"> ×</td></tr>
            <tr><th>Ton (pitch)</th><td><input type="number" name="article_reader_options[pitch]" value="<?php echo esc_attr($o['pitch']??0.0);?>" min="-20" max="20" step="0.5" style="width:80px"> semitony</td></tr>
            <tr>
                <th>Pauzy przy śródtytułach</th>
                <td>
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                        Przed śródtytułem:
                        <input type="number" name="article_reader_options[pause_heading_before]"
                               value="<?php echo esc_attr($o['pause_heading_before']??900);?>"
                               min="100" max="2000" step="50" style="width:80px"> ms
                    </label>
                    <label style="display:flex;align-items:center;gap:8px">
                        Po śródtytule:
                        <input type="number" name="article_reader_options[pause_heading_after]"
                               value="<?php echo esc_attr($o['pause_heading_after']??600);?>"
                               min="100" max="2000" step="50" style="width:80px"> ms
                    </label>
                    <p class="description">Domyślnie: 900ms przed / 600ms po. Im więcej — tym dłuższa cisza przy przejściu do nowej sekcji.</p>
                </td>
            </tr>
            <tr>
                <th>Pauzy po zdaniach i akapitach</th>
                <td>
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                        Po zdaniu (.!?):
                        <input type="number" name="article_reader_options[pause_sentence]"
                               value="<?php echo esc_attr($o['pause_sentence']??350);?>"
                               min="50" max="1000" step="25" style="width:80px"> ms
                    </label>
                    <label style="display:flex;align-items:center;gap:8px">
                        Między akapitami:
                        <input type="number" name="article_reader_options[pause_paragraph]"
                               value="<?php echo esc_attr($o['pause_paragraph']??700);?>"
                               min="100" max="2000" step="50" style="width:80px"> ms
                    </label>
                    <p class="description">Domyślnie: 350ms po zdaniu / 700ms między akapitami.</p>
                </td>
            </tr>
            <tr><th>Auto-generowanie</th><td>
                <label><input type="checkbox" name="article_reader_options[auto_gen]" value="1" <?php checked(!empty($o['auto_gen']));?>> Generuj audio automatycznie przy publikacji wpisu</label>
            </td></tr>
            <tr><th>Floating player</th><td>
                <label><input type="checkbox" name="article_reader_options[floating_player]" value="1" <?php checked(!empty($o['floating_player']));?>> Pokazuj mini-player na dole ekranu podczas przewijania</label>
                <p class="description">Domyślnie wyłączony. Włącz jeśli Twój motyw jest z nim kompatybilny.</p>
            </td></tr>
            <tr><th>Podcast RSS</th><td>
                <label><input type="checkbox" name="article_reader_options[podcast]" value="1" <?php checked(!empty($o['podcast']));?>> Włącz Podcast RSS feed</label>
                <?php if(!empty($o['podcast'])): ?>
                <p class="description">Feed: <a href="<?php echo esc_url(home_url('?feed=yash-podcast'));?>" target="_blank"><?php echo esc_url(home_url('?feed=yash-podcast'));?></a> — dodaj do Spotify/Apple Podcasts</p>
                <?php endif;?>
            </td></tr>
            <tr><th>Klucz API Claude (AI podsumowania)</th><td>
                <input type="password" name="article_reader_options[claude_key]" value="<?php echo esc_attr($o['claude_key']??'');?>" class="regular-text" autocomplete="off">
                <p class="description">Opcjonalnie — do generowania 2-3 zdaniowego wstępu czytanego przed artykułem. Klucz z <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>.</p>
            </td></tr>
            <tr><th>Pozycja playera</th><td>
                <label><input type="radio" name="article_reader_options[position]" value="before" <?php checked(($o['position']??'before'),'before');?>> Przed artykułem</label><br>
                <label><input type="radio" name="article_reader_options[position]" value="after"  <?php checked(($o['position']??'before'),'after');?>> Za artykułem</label>
            </td></tr>
        </table><?php submit_button('Zapisz ustawienia'); ?>
        </form>
        <hr><h2>Cache MP3 (<?php echo size_format($cache_size);?>)</h2>
        <form method="post"><?php wp_nonce_field('ar_clear_cache');?><input type="hidden" name="ar_clear_cache" value="1"><?php submit_button('Wyczyść cały cache','secondary');?></form>
        <p>
            <a href="<?php echo admin_url('options-general.php?page=article-reader-stats');?>">→ Statystyki</a> &nbsp;
            <a href="<?php echo admin_url('options-general.php?page=article-reader-bulk');?>">→ Bulk Generate</a> &nbsp;
            <a href="<?php echo esc_url(home_url('/audio-sitemap.xml'));?>" target="_blank">→ Audio Sitemap</a> &nbsp;
            <?php if(!empty($o['podcast'])): ?>
            <a href="<?php echo esc_url(home_url('?feed=yash-podcast'));?>" target="_blank">→ Podcast RSS</a>
            <?php endif;?>
        </p>
        </div><?php
    }

    public function stats_page() {
        $days  = (int)($_GET['days'] ?? 30);
        $stats = AR_Stats::get_summary($days);
        $top   = AR_Stats::get_top_posts($days);
        $daily = AR_Stats::get_daily($days);
        ?>
        <div class="wrap"><h1>📊 Yash — Statistics</h1>
        <p><?php foreach([7,30,90] as $d):?><a href="?page=article-reader-stats&days=<?php echo $d;?>" <?php if($days===$d)echo 'style="font-weight:bold"';?>><?php echo $d;?> dni</a> &nbsp;<?php endforeach;?></p>
        <div class="ar-admin-cards">
            <div class="ar-card"><div class="ar-card__val"><?php echo number_format($stats['plays']);?></div><div class="ar-card__label">Odtworzeń</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo $stats['unique'];?></div><div class="ar-card__label">Unikalnych</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo $stats['completes'];?></div><div class="ar-card__label">Ukończeń</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo $stats['plays']?round($stats['completes']/$stats['plays']*100).'%':'—';?></div><div class="ar-card__label">Completion</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo gmdate('i:s',$stats['avg_s']);?></div><div class="ar-card__label">Śr. czas</div></div>
        </div>
        <h2>Top artykuły</h2>
        <table class="widefat striped"><thead><tr><th>Artykuł</th><th>Odtworzeń</th></tr></thead><tbody>
        <?php foreach($top as $r):$t=get_the_title($r->post_id)?:'#'.$r->post_id;?>
        <tr><td><a href="<?php echo esc_url(get_permalink($r->post_id));?>" target="_blank"><?php echo esc_html($t);?></a></td><td><strong><?php echo(int)$r->plays;?></strong></td></tr>
        <?php endforeach;if(empty($top))echo'<tr><td colspan="2">Brak danych.</td></tr>';?>
        </tbody></table>
        <h2 style="margin-top:20px">Dzienne odtworzenia</h2>
        <div class="ar-chart-wrap"><?php
        if(!empty($daily)){$max=max(array_column((array)$daily,'plays'));
        foreach($daily as $d){$h=$max>0?round(($d->plays/$max)*80):0;
        echo'<div class="ar-chart-bar" title="'.esc_attr($d->day.': '.$d->plays).'"><div class="ar-chart-fill" style="height:'.$h.'px"></div><div class="ar-chart-val">'.$d->plays.'</div></div>';}}
        else echo'<p>Brak danych.</p>';?></div></div><?php
    }

    public function bulk_page() { ?>
        <div class="wrap">
        <h1>⚡ Yash — Bulk Generate</h1>
        <p>Generuj audio dla wielu artykułów naraz. Audio które już istnieje w cache zostanie pominięte.</p>
        <div id="ar-bulk-app">
            <div style="margin-bottom:16px">
                <button id="ar-bulk-load" class="button button-primary">Załaduj listę artykułów</button>
                <button id="ar-bulk-start" class="button button-primary" style="display:none">▶ Generuj zaznaczone</button>
                <button id="ar-bulk-select-all" class="button" style="display:none">Zaznacz wszystkie bez audio</button>
            </div>
            <div id="ar-bulk-log" style="background:#f9f9f9;border:1px solid #ddd;padding:12px;max-height:400px;overflow-y:auto;display:none;font-family:monospace;font-size:12px"></div>
            <table class="widefat striped" id="ar-bulk-table" style="display:none">
                <thead><tr><th><input type="checkbox" id="ar-bulk-check-all"></th><th>Artykuł</th><th>Słowa</th><th>Audio</th><th>Status</th></tr></thead>
                <tbody id="ar-bulk-tbody"></tbody>
            </table>
        </div>
        </div><?php
    }

    /* ------------------------------------------------------------------ */
    /*  Podcast RSS Feed                                                    */
    /* ------------------------------------------------------------------ */

    public function register_podcast_feed() {
        add_feed( 'yash-podcast', [ $this, 'serve_podcast_rss' ] );
    }

    public function serve_podcast_rss() {
        $o      = get_option( 'article_reader_options', [] );
        $voice  = $o['voice'] ?? 'pl-PL-Wavenet-A';
        $title  = get_bloginfo( 'name' ) . ' — Podcast';
        $desc   = get_bloginfo( 'description' ) ?: 'Artykuły w wersji audio';
        $link   = home_url();
        $image  = get_site_icon_url( 512 ) ?: '';

        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        header( 'Content-Type: application/rss+xml; charset=UTF-8' );
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
        echo '<channel>' . "\n";
        echo '<title>' . esc_html( $title ) . '</title>' . "\n";
        echo '<link>' . esc_url( $link ) . '</link>' . "\n";
        echo '<description>' . esc_html( $desc ) . '</description>' . "\n";
        echo '<language>pl</language>' . "\n";
        echo '<itunes:author>' . esc_html( get_bloginfo( 'name' ) ) . '</itunes:author>' . "\n";
        echo '<itunes:explicit>false</itunes:explicit>' . "\n";
        if ( $image ) {
            echo '<itunes:image href="' . esc_url( $image ) . '"/>' . "\n";
            echo '<image><url>' . esc_url( $image ) . '</url><title>' . esc_html( $title ) . '</title><link>' . esc_url( $link ) . '</link></image>' . "\n";
        }

        foreach ( $posts as $post ) {
            $audio_url = AR_Cache::get( $post->ID, 0, $voice );
            if ( ! $audio_url ) continue;

            $words    = str_word_count( wp_strip_all_tags( $post->post_content ) );
            $secs     = max( 1, round( ( $words / 130 ) * 60 ) );
            $file     = AR_Cache::get_file_path( $post->ID, 0, $voice );
            $filesize = $file && file_exists( $file ) ? filesize( $file ) : 0;
            $excerpt  = wp_trim_words( $post->post_content, 40 );
            $pub_date = get_the_date( 'r', $post );
            $duration = sprintf( '%d:%02d', floor( $secs / 60 ), $secs % 60 );

            echo '<item>' . "\n";
            echo '  <title>' . esc_html( get_the_title( $post ) ) . '</title>' . "\n";
            echo '  <link>' . esc_url( get_permalink( $post ) ) . '</link>' . "\n";
            echo '  <guid isPermaLink="false">' . esc_url( $audio_url ) . '</guid>' . "\n";
            echo '  <pubDate>' . esc_html( $pub_date ) . '</pubDate>' . "\n";
            echo '  <description>' . esc_html( $excerpt ) . '</description>' . "\n";
            echo '  <enclosure url="' . esc_url( $audio_url ) . '" length="' . $filesize . '" type="audio/mpeg"/>' . "\n";
            echo '  <itunes:duration>' . esc_html( $duration ) . '</itunes:duration>' . "\n";
            echo '  <itunes:explicit>false</itunes:explicit>' . "\n";
            echo '</item>' . "\n";
        }

        echo '</channel>' . "\n";
        echo '</rss>';
        exit;
    }

    /* ------------------------------------------------------------------ */
    /*  AI Summary (Claude API)                                             */
    /* ------------------------------------------------------------------ */

    public function ajax_get_summary() {
        check_ajax_referer( 'ar_nonce', 'nonce' );

        $o          = get_option( 'article_reader_options', [] );
        $claude_key = trim( $o['claude_key'] ?? '' );
        $post_id    = (int)( $_POST['post_id'] ?? 0 );

        if ( ! $claude_key || ! $post_id ) {
            wp_send_json_error( [ 'message' => 'Brak klucza Claude API.' ] );
        }

        // Sprawdź cache podsumowania
        $cached = get_post_meta( $post_id, '_yash_summary', true );
        if ( $cached ) {
            wp_send_json_success( [ 'summary' => $cached, 'cached' => true ] );
        }

        $post    = get_post( $post_id );
        $content = wp_trim_words( wp_strip_all_tags( $post->post_content ), 800 );

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $claude_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode( [
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 150,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => "Napisz 2-3 zdaniowe podsumowanie tego artykułu po polsku. Zacznij od słów 'W tym artykule'. Bądź konkretny i zwięzły.\n\nArtykuł:\n" . $content,
                ]],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $data    = json_decode( wp_remote_retrieve_body( $response ), true );
        $summary = $data['content'][0]['text'] ?? '';

        if ( $summary ) {
            update_post_meta( $post_id, '_yash_summary', $summary );
            wp_send_json_success( [ 'summary' => $summary, 'cached' => false ] );
        }

        wp_send_json_error( [ 'message' => 'Nie udało się wygenerować podsumowania.' ] );
    }
}

new Yash();
