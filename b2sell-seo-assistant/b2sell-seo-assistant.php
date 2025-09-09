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

class B2Sell_SEO_Assistant {
    private $analysis;
    private $gpt;
    private $sem;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_footer', array( $this, 'render_footer' ) );
        $this->analysis = new B2Sell_SEO_Analysis();
        $this->gpt      = new B2Sell_GPT_Generator();
        $this->sem      = new B2Sell_SEM_Campaigns();
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
                $this->analysis->run_full_site_analysis();
                echo '<div class="updated"><p>Análisis global completado.</p></div>';
            }
        }

        $posts = get_posts(
            array(
                'post_type'   => array( 'post', 'page' ),
                'post_status' => 'publish',
                'numberposts' => -1,
            )
        );

        $total_onpage = 0;
        $analyses     = array();
        $recs         = array();
        foreach ( $posts as $p ) {
            $history = get_post_meta( $p->ID, '_b2sell_seo_history', true );
            if ( is_array( $history ) && ! empty( $history ) ) {
                $last        = end( $history );
                $total_onpage += intval( $last['score'] );
                $analyses[]  = $last;
                if ( ! empty( $last['recommendations'] ) ) {
                    $recs = array_merge( $recs, $last['recommendations'] );
                }
            }
        }
        $onpage_avg = $analyses ? round( $total_onpage / count( $analyses ) ) : 0;

        $technical = $this->analysis->get_technical_summary();
        $images    = $this->analysis->get_images_summary();

        $global_score = round( $onpage_avg * 0.4 + $technical['score'] * 0.4 + $images['score'] * 0.2 );
        $score_color  = ( $global_score >= 80 ) ? 'green' : ( ( $global_score >= 50 ) ? 'yellow' : 'red' );

        $recs = array_merge( $recs, $technical['recommendations'], $images['recommendations'] );
        $recs = array_unique( $recs );
        $recs = array_slice( $recs, 0, 5 );

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
        foreach ( $technical['metrics'] as $m ) {
            echo '<tr class="b2sell-' . esc_attr( $m['color'] ) . '"><th>' . esc_html( $m['label'] ) . '</th><td>' . esc_html( $m['value'] ) . '</td></tr>';
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
        $run_url = wp_nonce_url( admin_url( 'admin.php?page=b2sell-seo-assistant&run=1' ), 'b2sell_run_site_analysis' );
        echo '<p><a class="button button-primary button-hero b2sell-analyze-button" href="' . esc_url( $run_url ) . '">Analizar todo el sitio</a></p>';
        echo '</div>';
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

    public function config_page() {
        $openai_key    = get_option( 'b2sell_openai_api_key', '' );
        $pagespeed_key = get_option( 'b2sell_pagespeed_api_key', '' );
        if ( isset( $_POST['b2sell_openai_api_key'] ) || isset( $_POST['b2sell_pagespeed_api_key'] ) ) {
            check_admin_referer( 'b2sell_save_api_key' );
            $openai_key    = sanitize_text_field( $_POST['b2sell_openai_api_key'] ?? '' );
            $pagespeed_key = sanitize_text_field( $_POST['b2sell_pagespeed_api_key'] ?? '' );
            update_option( 'b2sell_openai_api_key', $openai_key );
            update_option( 'b2sell_pagespeed_api_key', $pagespeed_key );
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

    public function render_footer() {
        $screen = get_current_screen();
        if ( strpos( $screen->id, 'b2sell-seo' ) !== false ) {
            echo '<div class="b2sell-footer">Desarrollado por B2Sell SPA</div>';
        }
    }
}

new B2Sell_SEO_Assistant();
