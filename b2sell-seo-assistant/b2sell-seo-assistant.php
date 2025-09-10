<?php
/*
Plugin Name: B2Sell SEO Assistant
Description: Plugin para gestionar secciones de SEO y SEM.
Version: 1.0.0
Author: B2Sell SPA
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salir si se accede directamente.
}
require_once plugin_dir_path( __FILE__ ) . 'includes/class-b2sell-seo-analysis.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-b2sell-gpt.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-b2sell-sem.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-b2sell-editor-metabox.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-b2sell-competencia.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-b2sell-seo-meta.php';

register_activation_hook( __FILE__, array( 'B2Sell_Competencia', 'install' ) );

class B2Sell_SEO_Assistant {
    private $analysis;
    private $gpt;
    private $sem;
    private $competencia;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_footer', array( $this, 'render_footer' ) );
        add_action( 'wp_ajax_b2sell_refresh_pagespeed', array( $this, 'ajax_refresh_pagespeed' ) );
        $this->analysis = new B2Sell_SEO_Analysis();
        $this->gpt      = new B2Sell_GPT_Generator();
        $this->sem      = new B2Sell_SEM_Campaigns();
        $this->competencia = new B2Sell_Competencia();
    }

    public function register_menu() {
        add_menu_page(
            'B2Sell SEO & SEM',
            'B2Sell SEO & SEM',
            'manage_options',
            'b2sell-seo-assistant',
            array( $this, 'dashboard_page' ),
            'dashicons-chart-area'
        );

        add_submenu_page(
            'b2sell-seo-assistant',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'b2sell-seo-assistant',
            array( $this, 'dashboard_page' )
        );

        add_submenu_page(
            'b2sell-seo-assistant',
            'Análisis SEO',
            'Análisis SEO',
            'manage_options',
            'b2sell-seo-analisis',
            array( $this, 'analisis_page' )
        );

        add_submenu_page(
            'b2sell-seo-assistant',
            'Generador de Contenido (GPT)',
            'Generador de Contenido (GPT)',
            'manage_options',
            'b2sell-seo-gpt',
            array( $this, 'gpt_page' )
        );

        add_submenu_page(
            'b2sell-seo-assistant',
            'Campañas SEM',
            'Campañas SEM',
            'manage_options',
            'b2sell-seo-campanas',
            array( $this, 'sem_page' )
        );

        add_submenu_page(
            'b2sell-seo-assistant',
            'Competencia',
            'Competencia',
            'manage_options',
            'b2sell-seo-competencia',
            array( $this, 'competencia_page' )
        );

        add_submenu_page(
            'b2sell-seo-assistant',
            'Configuración',
            'Configuración',
            'manage_options',
            'b2sell-seo-config',
            array( $this, 'config_page' )
        );
    }

    private function render_section( $title ) {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $title ) . '</h1>';
        echo '<p>Sección en desarrollo – B2SELL</p>';
        echo '<hr />';
        echo '</div>';
    }

    public function dashboard_page() {
        if ( isset( $_GET['run'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'], 'b2sell_run_site_analysis' ) ) {
                $cache = $this->analysis->run_full_site_analysis();
                echo '<div class="updated"><p>Análisis global completado.</p></div>';
            }
        }

        if ( empty( $cache ) ) {
            $cache = get_option( 'b2sell_seo_dashboard_cache', array() );
        }

        $history      = $this->analysis->get_dashboard_history();
        $onpage_avg   = $cache['onpage_avg'] ?? 0;
        $technical    = $cache['technical'] ?? array( 'metrics' => array(), 'score' => 0 );
        $images       = $cache['images'] ?? array( 'total' => 0, 'missing_alt' => 0, 'oversized' => 0 );
        $global_score = $cache['global_score'] ?? 0;
        $score_color  = $cache['score_color'] ?? 'red';
        $recs         = $cache['recommendations'] ?? array();

        echo '<div class="wrap b2sell-dashboard">';
        echo '<h1>Dashboard SEO</h1>';
        echo '<div class="b2sell-dashboard-grid">';

        echo '<div class="b2sell-card b2sell-score-card">';
        echo '<h2>Puntaje SEO Global</h2>';
        printf(
            '<svg viewBox="0 0 36 36" class="b2sell-gauge"><path class="b2sell-gauge-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" /><path class="b2sell-gauge-bar b2sell-%1$s" stroke-dasharray="%2$d,100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" /><text x="18" y="20.35" class="b2sell-gauge-text">%2$d%%</text></svg>',
            esc_attr( $score_color ),
            esc_attr( $global_score )
        );
        echo '</div>';

        echo '<div class="b2sell-card">';
        echo '<h2>SEO Técnico</h2>';
        echo '<table class="widefat"><tbody>';
        foreach ( $technical['metrics'] as $key => $m ) {
            $id = ( 'pagespeed' === $key ) ? ' id="b2sell-ps-row"' : '';
            echo '<tr' . $id . ' class="b2sell-' . esc_attr( $m['color'] ) . '"><th>' . esc_html( $m['label'] ) . '</th><td>' . esc_html( $m['value'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';

        echo '<div class="b2sell-card">';
        echo '<h2>Imágenes</h2>';
        echo '<table class="widefat"><tbody>';
        echo '<tr><th>Total analizadas</th><td>' . esc_html( $images['total'] ) . '</td></tr>';
        $alt_color = $images['missing_alt'] ? 'red' : 'green';
        echo '<tr class="b2sell-' . esc_attr( $alt_color ) . '"><th>Sin ALT</th><td>' . esc_html( $images['missing_alt'] ) . '</td></tr>';
        $size_color = $images['oversized'] ? 'yellow' : 'green';
        echo '<tr class="b2sell-' . esc_attr( $size_color ) . '"><th>Sobre peso</th><td>' . esc_html( $images['oversized'] ) . '</td></tr>';
        echo '</tbody></table>';
        echo '</div>';

        echo '<div class="b2sell-card b2sell-recs">';
        echo '<h2>Recomendaciones urgentes</h2>';
        if ( $recs ) {
            echo '<ul>';
            foreach ( $recs as $r ) {
                echo '<li><span class="dashicons dashicons-warning"></span>' . esc_html( $r ) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p>No hay recomendaciones.</p>';
        }
        echo '</div>';

        echo '</div>';

        if ( $history ) {
            echo '<h2>Histórico de análisis</h2>';
            echo '<canvas id="b2sell-history-chart" height="100"></canvas>';
            echo '<table class="widefat" style="margin-top:20px"><thead><tr><th>Fecha</th><th>Puntaje Global</th><th>On-page</th><th>Técnico</th><th>Imágenes</th></tr></thead><tbody>';
            foreach ( array_reverse( $history ) as $h ) {
                echo '<tr><td>' . esc_html( $h['date'] ) . '</td><td>' . esc_html( $h['global_score'] ) . '</td><td>' . esc_html( $h['onpage'] ) . '</td><td>' . esc_html( $h['technical'] ) . '</td><td>' . esc_html( $h['images'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        $run_url = wp_nonce_url( admin_url( 'admin.php?page=b2sell-seo-assistant&run=1' ), 'b2sell_run_site_analysis' );
        echo '<p><a class="button button-primary button-hero b2sell-analyze-button" href="' . esc_url( $run_url ) . '">Actualizar análisis</a></p>';
        echo '</div>';

        $chart_data = array_slice( $history, -10 );
        $labels     = wp_json_encode( wp_list_pluck( $chart_data, 'date' ) );
        $scores     = wp_json_encode( array_map( 'intval', wp_list_pluck( $chart_data, 'global_score' ) ) );

        echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
        echo '<script>jQuery(function($){$.post(ajaxurl,{action:"b2sell_refresh_pagespeed"},function(res){if(res.success){var r=$("#b2sell-ps-row");r.removeClass("b2sell-red b2sell-yellow b2sell-green").addClass("b2sell-"+res.data.color);r.find("td").text(res.data.score);}});});var ctx=document.getElementById("b2sell-history-chart");if(ctx){new Chart(ctx.getContext("2d"),{type:"line",data:{labels:' . $labels . ',datasets:[{label:"Puntaje SEO Global",data:' . $scores . ',borderColor:"#0073aa",fill:false}]},options:{scales:{y:{beginAtZero:true,max:100}}}});}</script>';
    }

    public function analisis_page() {
        $this->analysis->render_admin_page();
    }

    public function gpt_page() {
        $this->gpt->render_admin_page();
    }

    public function sem_page() {
        $this->sem->render_admin_page();
    }

    public function competencia_page() {
        $this->competencia->render_admin_page();
    }

    public function config_page() {
        $openai_key    = get_option( 'b2sell_openai_api_key', '' );
        $pagespeed_key = get_option( 'b2sell_pagespeed_api_key', '' );
        $google_key    = get_option( 'b2sell_google_api_key', '' );
        $google_cx     = get_option( 'b2sell_google_cx', '' );
        $google_gl     = get_option( 'b2sell_google_gl', 'cl' );
        $google_hl     = get_option( 'b2sell_google_hl', 'es' );
        $ads_dev       = get_option( 'b2sell_ads_developer_token', '' );
        $ads_client    = get_option( 'b2sell_ads_client_id', '' );
        $ads_secret    = get_option( 'b2sell_ads_client_secret', '' );
        $ads_refresh   = get_option( 'b2sell_ads_refresh_token', '' );
        $ads_customer  = get_option( 'b2sell_ads_customer_id', '' );
        if ( isset( $_POST['b2sell_openai_api_key'] ) || isset( $_POST['b2sell_pagespeed_api_key'] ) || isset( $_POST['b2sell_google_api_key'] ) || isset( $_POST['b2sell_google_cx'] ) || isset( $_POST['b2sell_google_gl'] ) || isset( $_POST['b2sell_google_hl'] ) || isset( $_POST['b2sell_ads_developer_token'] ) ) {
            check_admin_referer( 'b2sell_save_api_key' );
            $openai_key    = sanitize_text_field( $_POST['b2sell_openai_api_key'] ?? '' );
            $pagespeed_key = sanitize_text_field( $_POST['b2sell_pagespeed_api_key'] ?? '' );
            $google_key    = sanitize_text_field( $_POST['b2sell_google_api_key'] ?? '' );
            $google_cx     = sanitize_text_field( $_POST['b2sell_google_cx'] ?? '' );
            $google_gl     = sanitize_text_field( $_POST['b2sell_google_gl'] ?? '' );
            $google_hl     = sanitize_text_field( $_POST['b2sell_google_hl'] ?? '' );
            if ( '' === $google_gl ) {
                $google_gl = 'cl';
            }
            if ( '' === $google_hl ) {
                $google_hl = 'es';
            }
            $ads_dev       = sanitize_text_field( $_POST['b2sell_ads_developer_token'] ?? '' );
            $ads_client    = sanitize_text_field( $_POST['b2sell_ads_client_id'] ?? '' );
            $ads_secret    = sanitize_text_field( $_POST['b2sell_ads_client_secret'] ?? '' );
            $ads_refresh   = sanitize_text_field( $_POST['b2sell_ads_refresh_token'] ?? '' );
            $ads_customer  = sanitize_text_field( $_POST['b2sell_ads_customer_id'] ?? '' );
            update_option( 'b2sell_openai_api_key', $openai_key );
            update_option( 'b2sell_pagespeed_api_key', $pagespeed_key );
            update_option( 'b2sell_google_api_key', $google_key );
            update_option( 'b2sell_google_cx', $google_cx );
            update_option( 'b2sell_google_gl', $google_gl );
            update_option( 'b2sell_google_hl', $google_hl );
            update_option( 'b2sell_ads_developer_token', $ads_dev );
            update_option( 'b2sell_ads_client_id', $ads_client );
            update_option( 'b2sell_ads_client_secret', $ads_secret );
            update_option( 'b2sell_ads_refresh_token', $ads_refresh );
            update_option( 'b2sell_ads_customer_id', $ads_customer );
            echo '<div class="updated"><p>API Keys guardadas.</p></div>';
        }
        echo '<div class="wrap">';
        echo '<h1>Configuración</h1>';
        echo '<form method="post">';
        wp_nonce_field( 'b2sell_save_api_key' );
        echo '<p><label for="b2sell_openai_api_key">OpenAI API Key:</label> ';
        echo '<input type="text" id="b2sell_openai_api_key" name="b2sell_openai_api_key" value="' . esc_attr( $openai_key ) . '" style="width:400px;" /></p>';
        echo '<p><label for="b2sell_pagespeed_api_key">Google PageSpeed API Key:</label> ';
        echo '<input type="text" id="b2sell_pagespeed_api_key" name="b2sell_pagespeed_api_key" value="' . esc_attr( $pagespeed_key ) . '" style="width:400px;" /></p>';
        echo '<h2>Google Custom Search</h2>';
        echo '<p>La <strong>API Key</strong> y el <strong>ID del motor de búsqueda (CX)</strong> son necesarios para realizar la búsqueda de competencia. Obtén estos valores en <a href="https://developers.google.com/custom-search/v1/introduction" target="_blank">Google Custom Search</a>.</p>';
        echo '<p><label for="b2sell_google_api_key">Google Custom Search API Key:</label> ';
        echo '<input type="text" id="b2sell_google_api_key" name="b2sell_google_api_key" value="' . esc_attr( $google_key ) . '" style="width:400px;" /></p>';
        echo '<p><label for="b2sell_google_cx">ID del motor de búsqueda (CX):</label> ';
        echo '<input type="text" id="b2sell_google_cx" name="b2sell_google_cx" value="' . esc_attr( $google_cx ) . '" style="width:400px;" /></p>';
        echo '<p><label for="b2sell_google_gl">Código de país para búsqueda (gl):</label> ';
        echo '<input type="text" id="b2sell_google_gl" name="b2sell_google_gl" value="' . esc_attr( $google_gl ) . '" style="width:400px;" /></p>';
        echo '<p><label for="b2sell_google_hl">Idioma (hl):</label> ';
        echo '<input type="text" id="b2sell_google_hl" name="b2sell_google_hl" value="' . esc_attr( $google_hl ) . '" style="width:400px;" /></p>';
        echo '<h2>Google Ads Keyword Planner</h2>';
        echo '<p><label for="b2sell_ads_developer_token">Developer Token:</label> ';
        echo '<input type="text" id="b2sell_ads_developer_token" name="b2sell_ads_developer_token" value="' . esc_attr( $ads_dev ) . '" style="width:400px;" /></p>';
        echo '<p><label for="b2sell_ads_client_id">Client ID:</label> ';
        echo '<input type="text" id="b2sell_ads_client_id" name="b2sell_ads_client_id" value="' . esc_attr( $ads_client ) . '" style="width:400px;" /></p>';
        echo '<p><label for="b2sell_ads_client_secret">Client Secret:</label> ';
        echo '<input type="text" id="b2sell_ads_client_secret" name="b2sell_ads_client_secret" value="' . esc_attr( $ads_secret ) . '" style="width:400px;" /></p>';
        echo '<p><label for="b2sell_ads_refresh_token">Refresh Token:</label> ';
        echo '<input type="text" id="b2sell_ads_refresh_token" name="b2sell_ads_refresh_token" value="' . esc_attr( $ads_refresh ) . '" style="width:400px;" /></p>';
        echo '<p><label for="b2sell_ads_customer_id">Customer ID:</label> ';
        echo '<input type="text" id="b2sell_ads_customer_id" name="b2sell_ads_customer_id" value="' . esc_attr( $ads_customer ) . '" style="width:400px;" /></p>';
        submit_button( 'Guardar API Keys' );
        echo '</form>';
        echo '</div>';
    }

    public function enqueue_admin_assets( $hook ) {
        if ( false === strpos( $hook, 'b2sell-seo' ) ) {
            return;
        }
        wp_enqueue_style( 'b2sell-seo-admin', plugin_dir_url( __FILE__ ) . 'assets/css/admin.css', array(), '1.0.0' );
    }

    public function ajax_refresh_pagespeed() {
        $ps = $this->analysis->get_pagespeed_data( home_url() );
        if ( $ps ) {
            $color = ( $ps['score'] >= 80 ) ? 'green' : ( ( $ps['score'] >= 50 ) ? 'yellow' : 'red' );

            $cache = get_option( 'b2sell_seo_dashboard_cache', array() );
            if ( isset( $cache['technical']['metrics']['pagespeed'] ) ) {
                $cache['technical']['metrics']['pagespeed']['value'] = $ps['score'];
                $cache['technical']['metrics']['pagespeed']['color'] = $color;
                update_option( 'b2sell_seo_dashboard_cache', $cache );
            }

            wp_send_json_success(
                array(
                    'score' => $ps['score'],
                    'color' => $color,
                )
            );
        }

        wp_send_json_error();
    }

    public function render_footer() {
        $screen = get_current_screen();
        if ( strpos( $screen->id, 'b2sell-seo' ) !== false ) {
            echo '<div class="b2sell-footer">Desarrollado por B2Sell SPA</div>';
        }
    }
}

new B2Sell_SEO_Assistant();
