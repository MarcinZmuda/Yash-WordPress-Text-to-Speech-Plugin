<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AR_Stats {

    const TABLE = 'article_reader_stats';

    /* ------------------------------------------------------------------ */
    /*  Create table on activation                                          */
    /* ------------------------------------------------------------------ */

    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id     BIGINT UNSIGNED NOT NULL,
            action      VARCHAR(20)     NOT NULL DEFAULT 'play',
            listened_s  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            ip_hash     VARCHAR(64)     NOT NULL DEFAULT '',
            ua_short    VARCHAR(100)    NOT NULL DEFAULT '',
            created_at  DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ------------------------------------------------------------------ */
    /*  Record an event                                                     */
    /* ------------------------------------------------------------------ */

    public static function record( $post_id, $action, $listened_s = 0 ) {
        global $wpdb;

        $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            [
                'post_id'    => (int) $post_id,
                'action'     => sanitize_key( $action ),
                'listened_s' => min( 7200, max( 0, (int) $listened_s ) ),
                'ip_hash'    => hash( 'sha256', $ip . AUTH_SALT ),
                'ua_short'   => mb_substr( $ua, 0, 100 ),
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s' ]
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Summary stats for admin dashboard                                  */
    /* ------------------------------------------------------------------ */

    public static function get_summary( $days = 30 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $since = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $plays     = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE action='play' AND created_at >= %s", $since
        ) );
        $completes = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE action='complete' AND created_at >= %s", $since
        ) );
        $avg_s     = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(listened_s) FROM {$table} WHERE action='complete' AND created_at >= %s", $since
        ) );
        $unique    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT ip_hash) FROM {$table} WHERE action='play' AND created_at >= %s", $since
        ) );

        return compact( 'plays', 'completes', 'avg_s', 'unique' );
    }

    /* ------------------------------------------------------------------ */
    /*  Top posts by plays                                                  */
    /* ------------------------------------------------------------------ */

    public static function get_top_posts( $days = 30, $limit = 10 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $since = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, COUNT(*) as plays
             FROM {$table}
             WHERE action='play' AND created_at >= %s
             GROUP BY post_id
             ORDER BY plays DESC
             LIMIT %d",
            $since, $limit
        ) );
    }

    /* ------------------------------------------------------------------ */
    /*  Daily plays chart data                                              */
    /* ------------------------------------------------------------------ */

    public static function get_daily( $days = 30 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $since = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) as day, COUNT(*) as plays
             FROM {$table}
             WHERE action='play' AND created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY day ASC",
            $since
        ) );
    }
}
