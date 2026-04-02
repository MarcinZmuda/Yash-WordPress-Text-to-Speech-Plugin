<?php
/**
 * Plugin Name: Yash
 * Description: WordPress TTS plugin — Google Wavenet voices, MP3 caching, text highlighting and Audio Schema SEO.
 * Version:     1.0.0
 * Author:      Marcin Żmuda
 * Author URI:  https://marcinzmuda.pl
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yash
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'YASH_VERSION', '1.0.0' );
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
        add_action( 'wp_enqueue_scripts',           [ $this, 'enqueue_assets' ] );
        add_filter( 'the_content',                  [ $this, 'inject_player' ] );
        add_action( 'wp_head',                      [ $this, 'inject_schema' ] );
        add_action( 'wp_ajax_ar_synthesize',        [ $this, 'ajax_synthesize' ] );
        add_action( 'wp_ajax_nopriv_ar_synthesize', [ $this, 'ajax_synthesize' ] );
        add_action( 'wp_ajax_ar_stat',              [ $this, 'ajax_stat' ] );
        add_action( 'wp_ajax_nopriv_ar_stat',       [ $this, 'ajax_stat' ] );
        add_action( 'save_post',                    [ $this, 'invalidate_cache' ], 10, 2 );
        add_action( 'delete_post',                  'AR_Cache::invalidate' );
        add_action( 'admin_menu',                   [ $this, 'admin_menu' ] );
        add_action( 'admin_init',                   [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts',        [ $this, 'admin_assets' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Assets                                                              */
    /* ------------------------------------------------------------------ */

    public function enqueue_assets() {
        if ( ! is_singular( 'post' ) ) return;
        wp_enqueue_style(  'article-reader', YASH_URL . 'css/style.css', [], YASH_VERSION );
        wp_enqueue_script( 'article-reader', YASH_URL . 'js/tts.js',    [], YASH_VERSION, true );
        $o = get_option( 'article_reader_options', [] );
        wp_localize_script( 'article-reader', 'AR', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'ar_nonce' ),
            'postId'    => get_the_ID(),
            'voice'     => $o['voice']     ?? 'pl-PL-Wavenet-A',
            'rate'      => (float)( $o['rate']  ?? 1.0 ),
            'pitch'     => (float)( $o['pitch'] ?? 0.0 ),
            'hasKey'    => ! empty( $o['api_key'] ),
            'highlight' => (bool)( $o['highlight'] ?? true ),
        ] );
    }

    public function admin_assets( $hook ) {
        if ( strpos( $hook, 'article-reader' ) === false ) return;
        wp_enqueue_style( 'ar-admin', YASH_URL . 'css/admin.css', [], YASH_VERSION );
    }

    /* ------------------------------------------------------------------ */
    /*  Cache invalidation on post save                                    */
    /* ------------------------------------------------------------------ */

    public function invalidate_cache( $post_id, $post ) {
        if ( $post->post_status === 'auto-draft' ) return;
        AR_Cache::invalidate( $post_id );
    }

    /* ------------------------------------------------------------------ */
    /*  Player + sentence wrapping for highlighting                        */
    /* ------------------------------------------------------------------ */

    public function inject_player( $content ) {
        if ( ! is_singular( 'post' ) ) return $content;
        if ( ! in_the_loop() || ! is_main_query() )   return $content;

        $o = get_option( 'article_reader_options', [] );

        // Wrap <p> tags with data attribute for highlighting
        $idx     = 0;
        $wrapped = preg_replace_callback( '/<p(\b[^>]*)>/i', function() use ( &$idx ) {
            return '<p data-ar-p="' . $idx++ . '">';
        }, $content );

        $player = $this->build_player( $content );

        return ( ($o['position'] ?? 'before') === 'after' )
            ? $wrapped . $player
            : $player . $wrapped;
    }

    private function build_player( $raw ) {
        $plain   = wp_strip_all_tags( $raw );
        $words   = str_word_count( $plain );
        $minutes = max( 1, round( $words / 200 ) );
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
                    <button class="ar-btn ar-btn--speed" id="ar-speed" aria-label="Prędkość">1×</button>
                    <button class="ar-btn ar-btn--main"  id="ar-play"  aria-label="Odtwórz">
                        <span class="ar-icon ar-icon--play"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>
                        <span class="ar-icon ar-icon--pause" style="display:none"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg></span>
                        <span class="ar-icon ar-icon--loading" style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="ar-spin"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg></span>
                    </button>
                    <button class="ar-btn ar-btn--stop" id="ar-stop" aria-label="Zatrzymaj">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 6h12v12H6z"/></svg>
                    </button>
                </div>

            </div>
            <div id="ar-text-source" style="display:none"><?php echo esc_html( $plain ); ?></div>
        </div>
        <?php return ob_get_clean();
    }

    /* ------------------------------------------------------------------ */
    /*  Audio Schema JSON-LD for SEO                                       */
    /* ------------------------------------------------------------------ */

    public function inject_schema() {
        if ( ! is_singular( 'post' ) ) return;
        $o    = get_option( 'article_reader_options', [] );
        if ( empty( $o['api_key'] ) ) return;

        $pid   = get_the_ID();
        $post  = get_post( $pid );
        $voice = $o['voice'] ?? 'pl-PL-Wavenet-A';
        $url   = AR_Cache::get( $pid, 0, $voice );
        if ( ! $url ) return;

        // --- Oblicz przybliżony czas trwania z liczby słów ---
        // Zakładamy ~130 słów/min dla polskiego głosu przy rate=1.0
        $word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );
        $rate       = (float) ( $o['rate'] ?? 1.0 );
        $seconds    = max( 1, round( ( $word_count / 130 ) * 60 / $rate ) );
        $duration   = 'PT' . floor( $seconds / 60 ) . 'M' . ( $seconds % 60 ) . 'S'; // ISO 8601

        // --- AudioObject (zagnieżdżony w Article) ---
        $audio_object = [
            '@type'          => 'AudioObject',
            'name'           => get_the_title(),
            'contentUrl'     => $url,
            'encodingFormat' => 'audio/mpeg',
            'duration'       => $duration,
            'inLanguage'     => 'pl',
            'uploadDate'     => get_the_date( 'c' ),
            'description'    => wp_trim_words( $post->post_content, 30 ),
        ];

        // --- Article schema z polem audio ---
        $author_id  = $post->post_author;
        $author_url = get_author_posts_url( $author_id );

        $article = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => get_the_title(),
            'url'              => get_permalink( $pid ),
            'datePublished'    => get_the_date( 'c' ),
            'dateModified'     => get_the_modified_date( 'c' ),
            'inLanguage'       => 'pl',
            'description'      => wp_trim_words( $post->post_content, 30 ),
            'wordCount'        => $word_count,
            'author'           => [
                '@type' => 'Person',
                'name'  => get_the_author_meta( 'display_name', $author_id ),
                'url'   => $author_url,
            ],
            'publisher'        => [
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
                'url'   => home_url(),
            ],
            // Kluczowe połączenie artykułu z audio
            'audio'            => $audio_object,
            // Speakable — wskazuje Google które fragmenty czytać przez TTS
            'speakable'        => [
                '@type'       => 'SpeakableSpecification',
                'cssSelector' => [ 'h1', '.entry-content p:first-of-type', '.post-content p:first-of-type', 'article p:first-of-type' ],
            ],
        ];

        echo '<script type="application/ld+json">' . wp_json_encode( $article, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX — Synthesize with cache                                       */
    /* ------------------------------------------------------------------ */

    public function ajax_synthesize() {
        check_ajax_referer( 'ar_nonce', 'nonce' );
        $o       = get_option( 'article_reader_options', [] );
        $api_key = trim( $o['api_key'] ?? '' );
        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => 'Brak klucza API. Uzupełnij w Ustawienia → Article Reader.' ], 400 );
        }

        $post_id   = (int) ( $_POST['post_id']   ?? 0 );
        $chunk_idx = (int) ( $_POST['chunk_idx'] ?? 0 );
        $text      = sanitize_textarea_field( wp_unslash( $_POST['text']  ?? '' ) );
        $voice     = sanitize_text_field( $_POST['voice'] ?? $o['voice'] ?? 'pl-PL-Wavenet-A' );
        $rate      = (float)( $_POST['rate']  ?? $o['rate']  ?? 1.0 );
        $pitch     = (float)( $_POST['pitch'] ?? $o['pitch'] ?? 0.0 );
        $lang      = $o['lang'] ?? 'pl-PL';

        if ( empty( $text ) ) { wp_send_json_error( [ 'message' => 'Brak tekstu.' ], 400 ); }

        // --- Check cache — odczytaj plik i zwróć jako base64 ---
        if ( $post_id ) {
            $cached_url = AR_Cache::get( $post_id, $chunk_idx, $voice );
            if ( $cached_url ) {
                // Odczytaj plik z dysku i zwróć base64 — unikamy problemów z fetch URL
                $cached_file = AR_Cache::get_file_path( $post_id, $chunk_idx, $voice );
                if ( $cached_file && file_exists( $cached_file ) ) {
                    $b64 = base64_encode( file_get_contents( $cached_file ) );
                    wp_send_json_success( [ 'audio' => $b64, 'cached' => true ] );
                }
            }
        }

        // --- Google Cloud TTS ---
        $text    = mb_substr( $text, 0, 4800 );
        $payload = wp_json_encode( [
            'input'       => [ 'text' => $text ],
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
            [ 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => $payload, 'timeout' => 25, 'data_format' => 'body' ]
        );

        if ( is_wp_error( $response ) ) { wp_send_json_error( [ 'message' => $response->get_error_message() ], 500 ); }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $data['audioContent'] ) ) {
            wp_send_json_error( [ 'message' => $data['error']['message'] ?? "Błąd Google TTS ($code)." ], $code );
        }

        // --- Zapisz do cache (w tle), zawsze zwróć base64 ---
        if ( $post_id ) {
            AR_Cache::put( $post_id, $chunk_idx, $voice, $data['audioContent'] );
        }

        wp_send_json_success( [ 'audio' => $data['audioContent'], 'cached' => false ] );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX — Stats                                                        */
    /* ------------------------------------------------------------------ */

    public function ajax_stat() {
        check_ajax_referer( 'ar_nonce', 'nonce' );
        $post_id  = (int)( $_POST['post_id']    ?? 0 );
        $action   = sanitize_key( $_POST['action_type'] ?? 'play' );
        $listened = (int)( $_POST['listened_s'] ?? 0 );
        if ( $post_id && in_array( $action, ['play','complete','pause'] ) ) {
            AR_Stats::record( $post_id, $action, $listened );
        }
        wp_send_json_success();
    }

    /* ------------------------------------------------------------------ */
    /*  Admin                                                               */
    /* ------------------------------------------------------------------ */

    public function admin_menu() {
        add_options_page( 'Yash', 'Yash', 'manage_options', 'article-reader', [ $this, 'settings_page' ] );
        add_submenu_page( 'options-general.php', 'Yash — Statistics', null, 'manage_options', 'article-reader-stats', [ $this, 'stats_page' ] );
    }

    public function register_settings() {
        register_setting( 'article_reader_group', 'article_reader_options', [ 'sanitize_callback' => [ $this, 'sanitize_options' ] ] );
    }

    public function sanitize_options( $in ) {
        return [
            'api_key'   => sanitize_text_field( $in['api_key']   ?? '' ),
            'lang'      => sanitize_text_field( $in['lang']      ?? 'pl-PL' ),
            'voice'     => sanitize_text_field( $in['voice']     ?? 'pl-PL-Wavenet-A' ),
            'rate'      => min( 4.0,  max( 0.25,  (float)( $in['rate']  ?? 0.85 ) ) ),
            'pitch'     => min( 20.0, max( -20.0, (float)( $in['pitch'] ?? 0.0 ) ) ),
            'position'  => in_array( $in['position'] ?? '', ['before','after'] ) ? $in['position'] : 'before',
            'highlight' => ! empty( $in['highlight'] ),
        ];
    }

    public function settings_page() {
        $o = get_option( 'article_reader_options', [] );
        $voices = [
            'pl-PL-Wavenet-A'  => 'Wavenet-A — kobieta ⭐ (najlepsza jakość)',
            'pl-PL-Wavenet-B'  => 'Wavenet-B — mężczyzna',
            'pl-PL-Wavenet-C'  => 'Wavenet-C — mężczyzna',
            'pl-PL-Wavenet-D'  => 'Wavenet-D — kobieta',
            'pl-PL-Wavenet-E'  => 'Wavenet-E — kobieta',
            'pl-PL-Standard-A' => 'Standard-A — kobieta (4M znaków/mies.)',
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
        <div class="notice notice-warning"><p><strong>Wklej klucz API Google Cloud</strong> — <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Utwórz klucz →</a></p></div>
        <?php endif; ?>
        <div class="ar-admin-cards">
            <div class="ar-card"><div class="ar-card__val"><?php echo number_format($stats['plays']); ?></div><div class="ar-card__label">Odtworzeń (30 dni)</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo $stats['unique']; ?></div><div class="ar-card__label">Unikalnych</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo $stats['completes']; ?></div><div class="ar-card__label">Ukończeń</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo size_format($cache_size); ?></div><div class="ar-card__label">Cache MP3</div></div>
        </div>
        <form method="post" action="options.php"><?php settings_fields('article_reader_group'); ?>
        <table class="form-table">
            <tr><th>Klucz API Google Cloud</th><td>
                <input type="password" name="article_reader_options[api_key]" value="<?php echo esc_attr($o['api_key']??''); ?>" class="regular-text" autocomplete="off">
                <p class="description">Neural2: <strong>1 mln znaków/mies.</strong> za darmo. Audio jest cachowane — kolejne odtworzenia nie zużywają limitu.</p>
            </td></tr>
            <tr><th>Głos</th><td>
                <select name="article_reader_options[voice]"><?php foreach($voices as $v=>$l): ?><option value="<?php echo esc_attr($v);?>" <?php selected($o['voice']??'',$v);?>><?php echo esc_html($l);?></option><?php endforeach;?></select>
            </td></tr>
            <tr><th>Prędkość</th><td><input type="number" name="article_reader_options[rate]" value="<?php echo esc_attr($o['rate']??1.0);?>" min="0.25" max="4.0" step="0.05" style="width:80px"> ×</td></tr>
            <tr><th>Ton (pitch)</th><td><input type="number" name="article_reader_options[pitch]" value="<?php echo esc_attr($o['pitch']??0.0);?>" min="-20" max="20" step="0.5" style="width:80px"> semitony</td></tr>
            <tr><th>Podświetlanie</th><td><label><input type="checkbox" name="article_reader_options[highlight]" value="1" <?php checked(!empty($o['highlight']));?>> Podświetlaj akapit podczas czytania</label></td></tr>
            <tr><th>Pozycja playera</th><td>
                <label><input type="radio" name="article_reader_options[position]" value="before" <?php checked(($o['position']??'before'),'before');?>> Przed artykułem</label><br>
                <label><input type="radio" name="article_reader_options[position]" value="after"  <?php checked(($o['position']??'before'),'after');?>>  Za artykułem</label>
            </td></tr>
        </table><?php submit_button('Zapisz ustawienia'); ?>
        </form>
        <hr><h2>Cache MP3 (<?php echo size_format($cache_size); ?>)</h2>
        <form method="post"><?php wp_nonce_field('ar_clear_cache'); ?><input type="hidden" name="ar_clear_cache" value="1"><?php submit_button('Wyczyść cały cache','secondary');?></form>
        <p><a href="<?php echo admin_url('options-general.php?page=article-reader-stats'); ?>">→ Zobacz pełne statystyki</a></p>
        </div><?php
    }

    public function stats_page() {
        $days  = (int)($_GET['days'] ?? 30);
        $stats = AR_Stats::get_summary($days);
        $top   = AR_Stats::get_top_posts($days);
        $daily = AR_Stats::get_daily($days);
        ?>
        <div class="wrap"><h1>📊 Yash — Statistics</h1>
        <p>Zakres: <?php foreach([7,30,90] as $d): ?><a href="?page=article-reader-stats&days=<?php echo $d;?>" <?php if($days===$d) echo 'style="font-weight:bold"';?>><?php echo $d;?> dni</a> &nbsp;<?php endforeach;?></p>
        <div class="ar-admin-cards">
            <div class="ar-card"><div class="ar-card__val"><?php echo number_format($stats['plays']);?></div><div class="ar-card__label">Odtworzeń</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo $stats['unique'];?></div><div class="ar-card__label">Unikalnych</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo $stats['completes'];?></div><div class="ar-card__label">Ukończeń</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo $stats['plays']?round($stats['completes']/$stats['plays']*100).'%':'—';?></div><div class="ar-card__label">Completion</div></div>
            <div class="ar-card"><div class="ar-card__val"><?php echo gmdate('i:s',$stats['avg_s']);?></div><div class="ar-card__label">Śr. czas słuchania</div></div>
        </div>
        <h2>Top artykuły</h2>
        <table class="widefat striped"><thead><tr><th>Artykuł</th><th>Odtworzeń</th></tr></thead><tbody>
        <?php foreach($top as $r): $t=get_the_title($r->post_id)?:'#'.$r->post_id; ?>
        <tr><td><a href="<?php echo esc_url(get_permalink($r->post_id));?>" target="_blank"><?php echo esc_html($t);?></a></td><td><strong><?php echo(int)$r->plays;?></strong></td></tr>
        <?php endforeach; if(empty($top)) echo '<tr><td colspan="2">Brak danych.</td></tr>'; ?>
        </tbody></table>
        <h2 style="margin-top:24px">Dzienne odtworzenia</h2>
        <div class="ar-chart-wrap"><?php
        if(!empty($daily)){
            $max=max(array_column((array)$daily,'plays'));
            foreach($daily as $d){
                $h=$max>0?round(($d->plays/$max)*80):0;
                echo '<div class="ar-chart-bar" title="'.esc_attr($d->day.': '.$d->plays).'"><div class="ar-chart-fill" style="height:'.$h.'px"></div><div class="ar-chart-val">'.$d->plays.'</div></div>';
            }
        } else { echo '<p>Brak danych.</p>'; }
        ?></div></div><?php
    }
}

new Yash();
