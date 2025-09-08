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
        $posts        = get_posts(
            array(
                'post_type'   => array( 'post', 'page' ),
                'post_status' => 'publish',
                'numberposts' => -1,
            )
        );

        $analyses    = array();
        $total_score = 0;

        foreach ( $posts as $p ) {
            $history = get_post_meta( $p->ID, '_b2sell_seo_history', true );
            if ( is_array( $history ) && ! empty( $history ) ) {
                $last          = end( $history );
                $analyses[]    = array(
                    'title'          => $p->post_title,
                    'date'           => $last['date'],
                    'score'          => intval( $last['score'] ),
                    'recommendations'=> isset( $last['recommendations'] ) ? $last['recommendations'] : array(),
                );
                $total_score   += intval( $last['score'] );
            }
        }

        $count      = count( $analyses );
        $avg_score  = $count ? round( $total_score / $count ) : 0;
        usort(
            $analyses,
            function( $a, $b ) {
                return strcmp( $b['date'], $a['date'] );
            }
        );
        $recent      = array_slice( $analyses, 0, 5 );
        $latest      = $recent ? $recent[0] : false;
        $avg_color   = ( $avg_score >= 80 ) ? 'green' : ( ( $avg_score >= 50 ) ? 'yellow' : 'red' );

        echo '<div class="wrap b2sell-dashboard">';
        echo '<h1>B2SELL Dashboard</h1>';
        echo '<div class="b2sell-dashboard-grid">';

        echo '<div class="b2sell-card b2sell-score-card">';
        echo '<h2>Puntaje SEO Global</h2>';
        echo '<svg viewBox="0 0 36 36" class="b2sell-gauge">';
        echo '<path class="b2sell-gauge-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />';
        echo '<path class="b2sell-gauge-bar b2sell-' . esc_attr( $avg_color ) . '" stroke-dasharray="' . esc_attr( $avg_score ) . ',100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />';
        echo '<text x="18" y="20.35" class="b2sell-gauge-text">' . esc_html( $avg_score ) . '%</text>';
        echo '</svg>';
        echo '</div>';

        echo '<div class="b2sell-card">';
        echo '<h2>Últimos análisis</h2>';
        if ( $recent ) {
            echo '<table class="widefat"><thead><tr><th>Título</th><th>Fecha</th><th>Puntaje</th></tr></thead><tbody>';
            foreach ( $recent as $item ) {
                $color = ( $item['score'] >= 80 ) ? 'green' : ( ( $item['score'] >= 50 ) ? 'yellow' : 'red' );
                echo '<tr class="b2sell-' . esc_attr( $color ) . '"><td>' . esc_html( $item['title'] ) . '</td><td>' . esc_html( $item['date'] ) . '</td><td>' . esc_html( $item['score'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No hay análisis disponibles.</p>';
        }
        echo '</div>';

        if ( $latest && ! empty( $latest['recommendations'] ) ) {
            echo '<div class="b2sell-card b2sell-recs">';
            echo '<h2>Recomendaciones críticas</h2><ul>';
            $icons = array( 'dashicons-warning', 'dashicons-info', 'dashicons-yes' );
            foreach ( array_slice( $latest['recommendations'], 0, 3 ) as $idx => $rec ) {
                $icon = $icons[ $idx % 3 ];
                echo '<li><span class="dashicons ' . esc_attr( $icon ) . '"></span>' . esc_html( $rec ) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '</div>';
        echo '<p><a class="button button-primary button-hero b2sell-gpt-button" href="' . esc_url( admin_url( 'admin.php?page=b2sell-seo-gpt' ) ) . '">Generar contenido con GPT</a></p>';
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
        $api_key = get_option( 'b2sell_openai_api_key', '' );
        if ( isset( $_POST['b2sell_openai_api_key'] ) ) {
            check_admin_referer( 'b2sell_save_api_key' );
            $api_key = sanitize_text_field( $_POST['b2sell_openai_api_key'] );
            update_option( 'b2sell_openai_api_key', $api_key );
            echo '<div class="updated"><p>API Key guardada.</p></div>';
        }
        echo '<div class="wrap">';
        echo '<h1>Configuración</h1>';
        echo '<form method="post">';
        wp_nonce_field( 'b2sell_save_api_key' );
        echo '<label for="b2sell_openai_api_key">OpenAI API Key:</label> ';
        echo '<input type="text" id="b2sell_openai_api_key" name="b2sell_openai_api_key" value="' . esc_attr( $api_key ) . '" style="width:400px;" />';
        submit_button( 'Guardar API Key' );
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
