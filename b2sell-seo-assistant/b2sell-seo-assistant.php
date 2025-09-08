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

class B2Sell_SEO_Assistant {
    private $analysis;
    private $gpt;
    private $sem;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
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
        echo '<p style="font-size:12px;color:#666;">Desarrollado por B2Sell SPA.</p>';
        echo '</div>';
    }

    public function dashboard_page() {
        $this->render_section( 'Dashboard' );
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
        echo '<p style="font-size:12px;color:#666;">Desarrollado por B2Sell SPA.</p>';
        echo '</div>';
    }
}

new B2Sell_SEO_Assistant();
