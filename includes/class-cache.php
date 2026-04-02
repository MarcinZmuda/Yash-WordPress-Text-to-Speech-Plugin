<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AR_Cache {

    private static $upload_dir = null;
    private static $upload_url = null;

    /* ------------------------------------------------------------------ */
    /*  Init upload directory                                               */
    /* ------------------------------------------------------------------ */

    public static function init_dirs() {
        $uploads = wp_upload_dir();
        self::$upload_dir = trailingslashit( $uploads['basedir'] ) . 'article-reader/';
        self::$upload_url = trailingslashit( $uploads['baseurl'] ) . 'article-reader/';

        if ( ! file_exists( self::$upload_dir ) ) {
            wp_mkdir_p( self::$upload_dir );
            // Protect directory
            file_put_contents( self::$upload_dir . '.htaccess', "Options -Indexes\n" );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Cache key for a chunk                                               */
    /* ------------------------------------------------------------------ */

    public static function get_chunk_key( $post_id, $chunk_idx, $voice ) {
        $voice_slug = sanitize_file_name( $voice );
        return "post{$post_id}-chunk{$chunk_idx}-{$voice_slug}";
    }

    /* ------------------------------------------------------------------ */
    /*  Check if cached MP3 exists, return URL or false                    */
    /* ------------------------------------------------------------------ */

    public static function get( $post_id, $chunk_idx, $voice ) {
        self::init_dirs();
        $key  = self::get_chunk_key( $post_id, $chunk_idx, $voice );
        $file = self::$upload_dir . $key . '.mp3';

        if ( file_exists( $file ) ) {
            return self::$upload_url . $key . '.mp3';
        }
        return false;
    }

    /* ------------------------------------------------------------------ */
    /*  Zwróć ścieżkę pliku na dysku                                      */
    /* ------------------------------------------------------------------ */

    public static function get_file_path( $post_id, $chunk_idx, $voice ) {
        self::init_dirs();
        $key = self::get_chunk_key( $post_id, $chunk_idx, $voice );
        return self::$upload_dir . $key . '.mp3';
    }

    /* ------------------------------------------------------------------ */
    /*  Save base64 audio to disk, return URL                              */
    /* ------------------------------------------------------------------ */

    public static function put( $post_id, $chunk_idx, $voice, $base64_audio ) {
        self::init_dirs();
        $key  = self::get_chunk_key( $post_id, $chunk_idx, $voice );
        $file = self::$upload_dir . $key . '.mp3';

        $bytes = base64_decode( $base64_audio );
        if ( ! $bytes ) return false;

        if ( file_put_contents( $file, $bytes ) === false ) return false;

        return self::$upload_url . $key . '.mp3';
    }

    /* ------------------------------------------------------------------ */
    /*  Delete all chunks for a post (on update/delete)                    */
    /* ------------------------------------------------------------------ */

    public static function invalidate( $post_id ) {
        self::init_dirs();
        $pattern = self::$upload_dir . "post{$post_id}-chunk*.mp3";
        foreach ( glob( $pattern ) ?: [] as $file ) {
            @unlink( $file );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Get total cache size (for admin)                                   */
    /* ------------------------------------------------------------------ */

    public static function total_size() {
        self::init_dirs();
        $size = 0;
        foreach ( glob( self::$upload_dir . '*.mp3' ) ?: [] as $file ) {
            $size += filesize( $file );
        }
        return $size;
    }

    /* ------------------------------------------------------------------ */
    /*  Clear entire cache                                                  */
    /* ------------------------------------------------------------------ */

    public static function clear_all() {
        self::init_dirs();
        foreach ( glob( self::$upload_dir . '*.mp3' ) ?: [] as $file ) {
            @unlink( $file );
        }
    }
}
